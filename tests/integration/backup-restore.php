<?php
/**
 * Task 10c — Hardened backup / restore / verify for the integration suite.
 *
 * This file is included by BOTH:
 *   - tests/integration/integration-test.php      (main integration suite)
 *   - tests/integration/fault-injection-test.php  (controlled fault-injection tests)
 *
 * so that the fault-injection tests exercise the EXACT same code paths as the
 * main suite (no reimplementations).
 *
 * It defines:
 *   - T10c_WpdbProxy        : a fault-injection proxy that wraps the real $wpdb.
 *                             Swapping $GLOBALS['wpdb'] with a proxy instance
 *                             lets the tests simulate query failures WITHOUT
 *                             touching the live database.
 *   - t10b_backup_state()   : captures the full state of every record the suite
 *                             can modify. Task 10c: every read is validated;
 *                             a failed query or an incomplete snapshot throws
 *                             a T10c_BackupException (never a silent empty array).
 *   - t10b_table_engines()  : reports the storage engine of every table the
 *                             restore touches.
 *   - t10b_restore_state()  : restores the captured state inside a single
 *                             transaction. Task 10c: START TRANSACTION and
 *                             COMMIT results are checked explicitly; a
 *                             non-InnoDB table aborts BEFORE any write.
 *   - t10b_verify_restore() : re-snapshots and compares all four tables.
 *                             Task 10c: postmeta is compared by meta_id
 *                             (identity), and every SELECT is checked for
 *                             query errors (a failed query is never a pass).
 *   - t10c_compare_postmeta(): pure postmeta row-set comparison (no DB access),
 *                             extracted so it can be unit-tested in isolation.
 *
 * PHP 7.4 compatible.
 */

// ---------------------------------------------------------------------------
// Controlled failure mechanism
// ---------------------------------------------------------------------------

/**
 * Thrown when the baseline snapshot is incomplete or a backup query fails.
 * The main suite catches this and aborts BEFORE any test mutation.
 */
class T10c_BackupException extends Exception {}

/**
 * Fault-injection proxy for $wpdb.
 *
 * Wraps the real $wpdb and delegates every call to it, EXCEPT when a fault
 * rule matches the (prepared) query string. A matching fault returns the
 * configured fault value (typically `false` or `null`) and sets
 * `last_error` on the real $wpdb so the error-reporting paths under test
 * behave exactly as they would for a genuine database error.
 *
 * No live database mutation ever occurs: a faulted query is never executed.
 */
class T10c_WpdbProxy {

	/** @var object The real wpdb instance. */
	private $real;

	/** @var array[] List of [pattern, fault_value] rules. */
	private $faults = array();

	public function __construct( $real_wpdb ) {
		$this->real = $real_wpdb;
	}

	/**
	 * Register a fault: any query whose prepared string contains $pattern
	 * returns $fault_value instead of being executed.
	 */
	public function add_fault( $pattern, $fault_value ) {
		$this->faults[] = array( $pattern, $fault_value );
	}

	public function clear_faults() {
		$this->faults = array();
	}

	/**
	 * @return mixed The fault value if a rule matches, otherwise null (no fault).
	 */
	private function check_fault( $query ) {
		foreach ( $this->faults as $f ) {
			if ( is_string( $query ) && false !== strpos( $query, $f[0] ) ) {
				$this->real->last_error = 'T10C simulated fault (pattern: ' . $f[0] . ')';
				return $f[1];
			}
		}
		return null;
	}

	// -- Delegated query methods --------------------------------------------

	public function prepare( $query, ...$args ) {
		return $this->real->prepare( $query, ...$args );
	}

	public function get_row( $query, $output = OBJECT ) {
		$fault = $this->check_fault( $query );
		if ( null !== $fault ) {
			return $fault;
		}
		return $this->real->get_row( $query, $output );
	}

	public function get_results( $query, $output = OBJECT ) {
		$fault = $this->check_fault( $query );
		if ( null !== $fault ) {
			return $fault;
		}
		return $this->real->get_results( $query, $output );
	}

	public function get_var( $query ) {
		$fault = $this->check_fault( $query );
		if ( null !== $fault ) {
			return $fault;
		}
		return $this->real->get_var( $query );
	}

	public function query( $query ) {
		$fault = $this->check_fault( $query );
		if ( null !== $fault ) {
			return $fault;
		}
		return $this->real->query( $query );
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$fault = $this->check_fault( 'UPDATE ' . $table );
		if ( null !== $fault ) {
			return $fault;
		}
		return $this->real->update( $table, $data, $where, $format, $where_format );
	}

	public function insert( $table, $data, $format = null ) {
		$fault = $this->check_fault( 'INSERT INTO ' . $table );
		if ( null !== $fault ) {
			return $fault;
		}
		return $this->real->insert( $table, $data, $format );
	}

	// -- Property pass-through (posts, postmeta, prefix, last_error, ...) ----

	public function __get( $name ) {
		return $this->real->$name;
	}

	public function __set( $name, $value ) {
		$this->real->$name = $value;
	}

	public function __isset( $name ) {
		return isset( $this->real->$name );
	}
}

// ---------------------------------------------------------------------------
// Backup (Task 10c: every read validated; incomplete snapshot => exception)
// ---------------------------------------------------------------------------

/**
 * Capture the full state of every record the suite can modify.
 *
 * Returns an array with keys:
 *   posts    => [ post_id => [col => value, ...] ]        (ALL columns)
 *   postmeta => [ post_id => [ [meta_id,post_id,meta_key,meta_value], ... ] ]
 *   lookup   => [ product_id => [col => value, ...] ]     (ALL columns)
 *   options  => [ [option_name, option_value], ... ]      (targeted transients)
 *
 * Values are stored exactly as returned by the database (no serialization or
 * normalization), so the restore can write them back verbatim.
 *
 * Task 10c hardening:
 *   - Every SELECT is checked for a database error ($wpdb->last_error) and a
 *     null return. A failed query is NEVER converted into an empty result.
 *   - All six authorized posts must be captured (a missing post row is an
 *     error, not a silent skip).
 *   - Every authorized post ID must have a postmeta snapshot entry, even when
 *     that entry legitimately contains zero rows.
 *   - Every authorized product ID must have been queried in the lookup table
 *     (a row may legitimately be absent; a failed query may not).
 *   - On any failure a T10c_BackupException is thrown naming the failed
 *     table/query context, so the caller aborts before any test mutation.
 *
 * The authorized scope is read from the globals set by the caller:
 *   $GLOBALS['t10b_post_ids']    — the six authorized post IDs.
 *   $GLOBALS['t10b_product_ids'] — the four authorized product IDs.
 *
 * @throws T10c_BackupException when any query fails or the snapshot is incomplete.
 */
function t10b_backup_state() {
	global $wpdb;
	$post_ids    = $GLOBALS['t10b_post_ids'];
	$product_ids = $GLOBALS['t10b_product_ids'];
	$backup = array( 'posts' => array(), 'postmeta' => array(), 'lookup' => array(), 'options' => array() );
	$errors = array();

	// wp_posts: full rows (all columns). Every authorized post MUST exist.
	foreach ( $post_ids as $id ) {
		$query = $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID = %d", $id );
		$row   = $wpdb->get_row( $query, ARRAY_A );
		if ( $wpdb->last_error ) {
			$errors[] = "wp_posts SELECT failed for ID {$id}: {$wpdb->last_error}";
			continue;
		}
		if ( null === $row ) {
			// A failed get_row() returns null; a genuine missing row also
			// returns null — but every authorized post is expected to exist,
			// so null is an error either way.
			$errors[] = "wp_posts: post {$id} was not captured (query returned no row)";
			continue;
		}
		$backup['posts'][ $id ] = $row;
	}

	// wp_postmeta: full rows (preserving meta_id, duplicate keys, order).
	// Every authorized post ID MUST get a snapshot entry, even an empty one.
	foreach ( $post_ids as $id ) {
		$query = $wpdb->prepare(
			"SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id",
			$id
		);
		$rows  = $wpdb->get_results( $query, ARRAY_A );
		if ( $wpdb->last_error ) {
			$errors[] = "wp_postmeta SELECT failed for ID {$id}: {$wpdb->last_error}";
			continue;
		}
		if ( null === $rows ) {
			$errors[] = "wp_postmeta: query for ID {$id} returned null (treated as failure, not empty)";
			continue;
		}
		$backup['postmeta'][ $id ] = $rows; // may legitimately be an empty array
	}

	// wp_wc_product_meta_lookup: full rows (all columns). A row may
	// legitimately be absent; a failed query may not.
	$lookup_queried = array();
	foreach ( $product_ids as $id ) {
		$query = $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id = %d",
			$id
		);
		$row   = $wpdb->get_row( $query, ARRAY_A );
		if ( $wpdb->last_error ) {
			$errors[] = "wp_wc_product_meta_lookup SELECT failed for ID {$id}: {$wpdb->last_error}";
			continue;
		}
		$lookup_queried[ $id ] = true;
		if ( $row ) {
			$backup['lookup'][ $id ] = $row;
		}
	}

	// wp_options (transients): targeted safety net — only options whose name
	// references one of the product IDs. None exist for these products on the
	// current site, so this is normally an empty no-op.
	$like_clauses = array();
	$like_params  = array();
	foreach ( $product_ids as $id ) {
		$like_clauses[] = 'option_name LIKE %s';
		$like_params[]  = "%{$id}%";
	}
	if ( $like_clauses ) {
		$sql   = "SELECT option_name, option_value FROM {$wpdb->prefix}options WHERE " . implode( ' OR ', $like_clauses );
		$query = $wpdb->prepare( $sql, ...$like_params );
		$rows  = $wpdb->get_results( $query, ARRAY_A );
		if ( $wpdb->last_error ) {
			$errors[] = "wp_options (transients) SELECT failed: {$wpdb->last_error}";
		} elseif ( null === $rows ) {
			$errors[] = 'wp_options (transients) SELECT returned null (treated as failure, not empty)';
		} else {
			$backup['options'] = $rows;
		}
	}

	// --- Snapshot completeness validation -----------------------------------

	// 1. All authorized posts must have been captured.
	$missing_posts = array_diff( $post_ids, array_keys( $backup['posts'] ) );
	if ( $missing_posts ) {
		$errors[] = 'wp_posts: incomplete snapshot — missing post IDs: ' . implode( ', ', $missing_posts );
	}

	// 2. Every authorized post ID must have a postmeta snapshot entry
	//    (an entry with zero rows is valid; a missing entry is not).
	foreach ( $post_ids as $id ) {
		if ( ! array_key_exists( $id, $backup['postmeta'] ) ) {
			$errors[] = "wp_postmeta: no snapshot entry for post ID {$id}";
		}
	}

	// 3. Every authorized product ID must have been queried in the lookup table.
	$missing_lookup = array_diff( $product_ids, array_keys( $lookup_queried ) );
	if ( $missing_lookup ) {
		$errors[] = 'wp_wc_product_meta_lookup: not all product IDs were queried — missing: ' . implode( ', ', $missing_lookup );
	}

	if ( $errors ) {
		throw new T10c_BackupException(
			'Backup snapshot is incomplete or a query failed — aborting before any test mutation:' . "\n"
			. "  - " . implode( "\n  - ", $errors )
		);
	}

	return $backup;
}

/**
 * Report the storage engine of every table the restore touches.
 * Returns [ label => engine ]. Used to confirm transaction support.
 *
 * Task 10c: a failed SHOW TABLE STATUS query yields 'UNKNOWN', which the
 * caller must treat as "transaction support NOT established" (never as
 * InnoDB).
 */
function t10b_table_engines() {
	global $wpdb;
	$tables = array(
		'posts'    => $wpdb->posts,
		'postmeta' => $wpdb->postmeta,
		'lookup'   => $wpdb->prefix . 'wc_product_meta_lookup',
		'options'  => $wpdb->prefix . 'options',
	);
	$engines = array();
	foreach ( $tables as $label => $table ) {
		$row = $wpdb->get_row( "SHOW TABLE STATUS LIKE " . $wpdb->prepare( '%s', $table ) );
		if ( $wpdb->last_error || ! $row ) {
			$engines[ $label ] = 'UNKNOWN';
		} else {
			$engines[ $label ] = $row->Engine;
		}
	}
	return $engines;
}

/**
 * True when every required table is confirmed InnoDB (transactional).
 * Any UNKNOWN / non-InnoDB engine means transaction support is NOT
 * established and the caller must abort before mutations.
 */
function t10c_transaction_support_established( $engines ) {
	if ( count( $engines ) !== 4 ) {
		return false;
	}
	foreach ( $engines as $engine ) {
		if ( strcasecmp( (string) $engine, 'InnoDB' ) !== 0 ) {
			return false;
		}
	}
	return true;
}

/**
 * Restore the captured state. Runs inside a single transaction.
 *
 * Task 10c hardening:
 *   - Every required table must be confirmed InnoDB BEFORE any write;
 *     otherwise the restore aborts with a failure (no warning-only path).
 *   - The result of START TRANSACTION is checked; a failed start returns a
 *     failure with NO writes performed.
 *   - Every database write is checked.
 *   - The result of COMMIT is checked explicitly; a failed commit rolls back
 *     and is reported as a restore failure.
 *
 * Returns [ 'ok' => bool, 'errors' => [string, ...] ].
 */
function t10b_restore_state( $backup ) {
	global $wpdb;
	$post_ids    = $GLOBALS['t10b_post_ids'];
	$product_ids = $GLOBALS['t10b_product_ids'];
	$errors      = array();

	// 0. Transaction support must be established BEFORE any write.
	$engines = t10b_table_engines();
	if ( ! t10c_transaction_support_established( $engines ) ) {
		$desc = implode( ', ', array_map(
			function ( $k, $v ) { return "{$k} ({$v})"; },
			array_keys( $engines ),
			array_values( $engines )
		) );
		return array(
			'ok'     => false,
			'errors' => array( "ABORT: transaction support not established — table engines: {$desc}. No writes were performed; atomic restoration cannot be claimed." ),
		);
	}

	// 1. START TRANSACTION — check the result explicitly.
	$start_result = $wpdb->query( 'START TRANSACTION' );
	if ( false === $start_result || $wpdb->last_error ) {
		$err = $wpdb->last_error ? $wpdb->last_error : 'query returned false';
		return array(
			'ok'     => false,
			'errors' => array( "START TRANSACTION failed: {$err}. No writes were performed." ),
		);
	}

	try {
		// 2. wp_posts — restore ALL columns
		foreach ( $backup['posts'] as $id => $row ) {
			$data   = $row;
			unset( $data['ID'] );
			$result = $wpdb->update( $wpdb->posts, $data, array( 'ID' => $id ) );
			if ( false === $result || $wpdb->last_error ) {
				$errors[] = "wp_posts update failed for ID {$id}: " . ( $wpdb->last_error ? $wpdb->last_error : 'query returned false' );
			}
		}

		// 3. wp_postmeta — delete all rows for the post, re-insert verbatim
		foreach ( $post_ids as $id ) {
			$result = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d", $id ) );
			if ( false === $result || $wpdb->last_error ) {
				$errors[] = "wp_postmeta delete failed for ID {$id}: " . ( $wpdb->last_error ? $wpdb->last_error : 'query returned false' );
			}
			$rows = isset( $backup['postmeta'][ $id ] ) ? $backup['postmeta'][ $id ] : array();
			foreach ( $rows as $row ) {
				$result = $wpdb->insert( $wpdb->postmeta, array(
					'meta_id'    => $row['meta_id'],
					'post_id'    => $row['post_id'],
					'meta_key'   => $row['meta_key'],
					'meta_value' => $row['meta_value'],
				) );
				if ( false === $result || $wpdb->last_error ) {
					$errors[] = "wp_postmeta insert failed for ID {$id} [{$row['meta_key']}]: " . ( $wpdb->last_error ? $wpdb->last_error : 'query returned false' );
				}
			}
		}

		// 4. wp_wc_product_meta_lookup — upsert each authorized product row
		$lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
		foreach ( $product_ids as $id ) {
			if ( isset( $backup['lookup'][ $id ] ) ) {
				$row    = $backup['lookup'][ $id ];
				$data   = $row;
				unset( $data['product_id'] );
				$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$lookup_table} WHERE product_id = %d", $id ) );
				if ( $wpdb->last_error ) {
					$errors[] = "lookup existence check failed for ID {$id}: {$wpdb->last_error}";
					continue;
				}
				if ( $exists ) {
					$result = $wpdb->update( $lookup_table, $data, array( 'product_id' => $id ) );
				} else {
					$result = $wpdb->insert( $lookup_table, $row );
				}
				if ( false === $result || $wpdb->last_error ) {
					$errors[] = "lookup restore failed for ID {$id}: " . ( $wpdb->last_error ? $wpdb->last_error : 'query returned false' );
				}
			} else {
				// No row in the snapshot: remove any row the tests created.
				$result = $wpdb->query( $wpdb->prepare( "DELETE FROM {$lookup_table} WHERE product_id = %d", $id ) );
				if ( false === $result || $wpdb->last_error ) {
					$errors[] = "lookup delete failed for ID {$id}: " . ( $wpdb->last_error ? $wpdb->last_error : 'query returned false' );
				}
			}
		}

		// 5. wp_options (transients) — restore the targeted snapshot
		$backup_opts = array();
		foreach ( $backup['options'] as $r ) {
			$backup_opts[ $r['option_name'] ] = $r['option_value'];
		}
		$like_clauses = array();
		$like_params  = array();
		foreach ( $product_ids as $id ) {
			$like_clauses[] = 'option_name LIKE %s';
			$like_params[]  = "%{$id}%";
		}
		$current = $wpdb->get_results( $wpdb->prepare(
			"SELECT option_name FROM {$wpdb->prefix}options WHERE " . implode( ' OR ', $like_clauses ),
			...$like_params
		), ARRAY_A );
		if ( $wpdb->last_error ) {
			$errors[] = "options current-state SELECT failed: {$wpdb->last_error}";
		} else {
			foreach ( $current as $r ) {
				if ( ! array_key_exists( $r['option_name'], $backup_opts ) ) {
					$result = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}options WHERE option_name = %s", $r['option_name'] ) );
					if ( false === $result || $wpdb->last_error ) {
						$errors[] = "options delete failed for {$r['option_name']}: " . ( $wpdb->last_error ? $wpdb->last_error : 'query returned false' );
					}
				}
			}
			foreach ( $backup_opts as $name => $value ) {
				$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}options WHERE option_name = %s", $name ) );
				if ( $wpdb->last_error ) {
					$errors[] = "options existence check failed for {$name}: {$wpdb->last_error}";
					continue;
				}
				if ( $exists ) {
					$result = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}options SET option_value = %s WHERE option_name = %s", $value, $name ) );
				} else {
					$result = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}options (option_name, option_value) VALUES (%s, %s)", $name, $value ) );
				}
				if ( false === $result || $wpdb->last_error ) {
					$errors[] = "options restore failed for {$name}: " . ( $wpdb->last_error ? $wpdb->last_error : 'query returned false' );
				}
			}
		}

		// 6. Any hard error => ROLLBACK and report failure.
		if ( $errors ) {
			$wpdb->query( 'ROLLBACK' );
			return array( 'ok' => false, 'errors' => $errors );
		}

		// 7. COMMIT — check the result explicitly.
		$commit_result = $wpdb->query( 'COMMIT' );
		if ( false === $commit_result || $wpdb->last_error ) {
			$err = $wpdb->last_error ? $wpdb->last_error : 'query returned false';
			$wpdb->query( 'ROLLBACK' );
			return array( 'ok' => false, 'errors' => array( "COMMIT failed: {$err}. Transaction rolled back." ) );
		}

		return array( 'ok' => true, 'errors' => array() );
	} catch ( Exception $e ) {
		$wpdb->query( 'ROLLBACK' );
		return array( 'ok' => false, 'errors' => array_merge( $errors, array( 'exception: ' . $e->getMessage() ) ) );
	}
}

// ---------------------------------------------------------------------------
// Postmeta identity comparison (Task 10c — pure function, no DB access)
// ---------------------------------------------------------------------------

/**
 * Compare two sets of wp_postmeta rows for one post, by row IDENTITY.
 *
 * Each row is an associative array with keys: meta_id, post_id, meta_key,
 * meta_value. The comparison:
 *   - compares row counts;
 *   - matches rows by meta_id (the primary key) — a meta_id present in the
 *     snapshot but missing now (or vice versa) is a mismatch;
 *   - for every matched meta_id, compares post_id, meta_key and meta_value;
 *   - duplicate rows (same meta_key, different meta_id) are compared
 *     correctly because identity is the meta_id, not the key.
 *
 * Returns a list of mismatch descriptions (empty list = identical).
 *
 * Extracted as a pure function so it can be unit-tested in isolation with
 * synthetic data (fault-injection test FI-4).
 *
 * @param array $brows Snapshot rows (from the backup).
 * @param array $arows Current rows (from the database).
 * @param int   $post_id Post ID, used only in mismatch messages.
 * @return string[]
 */
function t10c_compare_postmeta( $brows, $arows, $post_id ) {
	$mismatches = array();

	// Row counts.
	if ( count( $brows ) !== count( $arows ) ) {
		$mismatches[] = "wp_postmeta.{$post_id}: row count backup=" . count( $brows ) . ' current=' . count( $arows );
	}

	// Index both sets by meta_id (identity).
	$bmap = array();
	foreach ( $brows as $r ) {
		$bmap[ (int) $r['meta_id'] ] = $r;
	}
	$amap = array();
	foreach ( $arows as $r ) {
		$amap[ (int) $r['meta_id'] ] = $r;
	}

	// meta_ids missing from the current state.
	foreach ( $bmap as $mid => $brow ) {
		if ( ! isset( $amap[ $mid ] ) ) {
			$mismatches[] = "wp_postmeta.{$post_id}: meta_id {$mid} (key={$brow['meta_key']}) missing after restore";
		}
	}

	// meta_ids present now but absent from the snapshot.
	foreach ( $amap as $mid => $arow ) {
		if ( ! isset( $bmap[ $mid ] ) ) {
			$mismatches[] = "wp_postmeta.{$post_id}: meta_id {$mid} (key={$arow['meta_key']}) present after restore but absent from snapshot";
		}
	}

	// Column-by-column comparison for every matched meta_id.
	foreach ( $bmap as $mid => $brow ) {
		if ( ! isset( $amap[ $mid ] ) ) {
			continue;
		}
		$arow = $amap[ $mid ];
		foreach ( array( 'post_id', 'meta_key', 'meta_value' ) as $col ) {
			if ( (string) $brow[ $col ] !== (string) $arow[ $col ] ) {
				$mismatches[] = "wp_postmeta.{$post_id}.meta_id={$mid}.{$col}: backup=" . var_export( $brow[ $col ], true ) . ' current=' . var_export( $arow[ $col ], true );
			}
		}
	}

	return $mismatches;
}

/**
 * Verify that the current database state matches the captured snapshot.
 *
 * Checks, for the authorized scope:
 *   - wp_posts: every column of every authorized post matches.
 *   - wp_postmeta: the complete set of rows matches by IDENTITY
 *     (meta_id, post_id, meta_key, meta_value), including duplicate rows
 *     and row counts (Task 10c — no longer key/value-only).
 *   - wp_wc_product_meta_lookup: every column of every authorized product row
 *     matches; no extra rows exist for the authorized product IDs.
 *   - wp_options (transients): the targeted option set matches.
 *
 * Task 10c hardening: EVERY SELECT is checked for a database error
 * ($wpdb->last_error) and a null return. A failed verification query is
 * recorded as a mismatch — it can never produce a successful verification.
 *
 * Returns [ 'ok' => bool, 'mismatches' => [string, ...] ].
 */
function t10b_verify_restore( $backup ) {
	global $wpdb;
	$post_ids    = $GLOBALS['t10b_post_ids'];
	$product_ids = $GLOBALS['t10b_product_ids'];
	$mismatches  = array();

	// 1. wp_posts — every column
	foreach ( $backup['posts'] as $id => $brow ) {
		$query = $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID = %d", $id );
		$arow  = $wpdb->get_row( $query, ARRAY_A );
		if ( $wpdb->last_error ) {
			$mismatches[] = "wp_posts.{$id}: verification SELECT failed: {$wpdb->last_error}";
			continue;
		}
		if ( null === $arow ) {
			$mismatches[] = "wp_posts: post {$id} is missing after restore (or SELECT returned null)";
			continue;
		}
		foreach ( $brow as $col => $bv ) {
			$av = array_key_exists( $col, $arow ) ? $arow[ $col ] : '<MISSING>';
			if ( (string) $bv !== (string) $av ) {
				$mismatches[] = "wp_posts.{$id}.{$col}: backup=" . var_export( $bv, true ) . ' current=' . var_export( $av, true );
			}
		}
	}

	// 2. wp_postmeta — complete row-set match by meta_id identity
	foreach ( $post_ids as $id ) {
		$brows = isset( $backup['postmeta'][ $id ] ) ? $backup['postmeta'][ $id ] : array();
		$query = $wpdb->prepare(
			"SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id",
			$id
		);
		$arows = $wpdb->get_results( $query, ARRAY_A );
		if ( $wpdb->last_error ) {
			$mismatches[] = "wp_postmeta.{$id}: verification SELECT failed: {$wpdb->last_error}";
			continue;
		}
		if ( null === $arows ) {
			$mismatches[] = "wp_postmeta.{$id}: verification SELECT returned null (treated as failure, not empty)";
			continue;
		}
		$mismatches = array_merge( $mismatches, t10c_compare_postmeta( $brows, $arows, $id ) );
	}

	// 3. wp_wc_product_meta_lookup — every column + no extra rows
	$lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
	foreach ( $product_ids as $id ) {
		$query = $wpdb->prepare( "SELECT * FROM {$lookup_table} WHERE product_id = %d", $id );
		$arow  = $wpdb->get_row( $query, ARRAY_A );
		if ( $wpdb->last_error ) {
			$mismatches[] = "lookup.{$id}: verification SELECT failed: {$wpdb->last_error}";
			continue;
		}
		if ( isset( $backup['lookup'][ $id ] ) ) {
			if ( null === $arow ) {
				$mismatches[] = "lookup.{$id}: row missing after restore (or SELECT returned null)";
				continue;
			}
			foreach ( $backup['lookup'][ $id ] as $col => $bv ) {
				$av = array_key_exists( $col, $arow ) ? $arow[ $col ] : '<MISSING>';
				if ( (string) $bv !== (string) $av ) {
					$mismatches[] = "lookup.{$id}.{$col}: backup=" . var_export( $bv, true ) . ' current=' . var_export( $av, true );
				}
			}
		} else {
			if ( $arow ) {
				$mismatches[] = "lookup.{$id}: row present after restore but absent from snapshot";
			}
		}
	}

	// 4. wp_options (transients) — targeted set
	$like_clauses = array();
	$like_params  = array();
	foreach ( $product_ids as $id ) {
		$like_clauses[] = 'option_name LIKE %s';
		$like_params[]  = "%{$id}%";
	}
	$bopts = array();
	foreach ( $backup['options'] as $r ) {
		$bopts[ $r['option_name'] ] = $r['option_value'];
	}
	$query = $wpdb->prepare(
		"SELECT option_name, option_value FROM {$wpdb->prefix}options WHERE " . implode( ' OR ', $like_clauses ),
		...$like_params
	);
	$arows = $wpdb->get_results( $query, ARRAY_A );
	if ( $wpdb->last_error ) {
		$mismatches[] = "options(transients): verification SELECT failed: {$wpdb->last_error}";
	} elseif ( null === $arows ) {
		$mismatches[] = 'options(transients): verification SELECT returned null (treated as failure, not empty)';
	} else {
		$aopts = array();
		foreach ( $arows as $r ) {
			$aopts[ $r['option_name'] ] = $r['option_value'];
		}
		if ( $bopts != $aopts ) {
			$mismatches[] = 'options(transients): backup=' . json_encode( array_keys( $bopts ) ) . ' current=' . json_encode( array_keys( $aopts ) );
		}
	}

	return array( 'ok' => empty( $mismatches ), 'mismatches' => $mismatches );
}
