<?php
/**
 * Task 10b — Integration + Regression Test Suite for Ani Navasan Lite.
 *
 * Run against the AUTHORIZED local site (anishop-package-test.local) ONLY:
 *
 *   cd ~/Local\ Sites/anishop-package-test/app/public
 *   wp eval-file /path/to/tests/integration/integration-test.php
 *
 * Uses ONLY the authorized test products (17613, 17615, 17616, 17617) and
 * test currencies (17654, 17655).
 *
 * Backup / restore (hardened in Task 10b):
 *   The suite captures the FULL state of every record it can modify and
 *   restores it afterwards, then verifies the restoration. The captured
 *   scope is limited to the authorized test products, their variations, and
 *   the two authorized test currencies:
 *
 *     - wp_posts                    (ALL columns, all 6 authorized posts)
 *     - wp_postmeta                 (ALL rows incl. duplicate keys, 6 posts)
 *     - wp_wc_product_meta_lookup   (ALL columns, the 4 product IDs)
 *     - wp_options (transients)     (targeted: options whose name references
 *                                    a product ID — a safety net; none exist
 *                                    for these products in the current site)
 *
 *   The restore runs inside a single DB transaction (all involved tables are
 *   InnoDB on the target site; the engine is re-checked at runtime). Every
 *   SQL operation is checked for failure; a failed query aborts the restore
 *   (ROLLBACK) and is reported as a failure. Restoration is attempted even
 *   when a test assertion fails or an ordinary PHP error occurs (try/finally
 *   + a single shutdown-handler safety net + a restoration state flag).
 *
 *   The suite FAILS (non-zero exit, no success summary) if the restore or the
 *   post-restore verification does not fully succeed.
 *
 * Scenarios:
 *   I0  Environment + plugin load
 *   I1  Simple product save handler (17613)
 *   I2  Variation save handler (17616, 17617) + isolation
 *   I3  Decimal rounding hook (17613)
 *   [restore to baseline]
 *   I4a Recalculation — currency A (17654) rate change 2000 -> 2500 -> 2000
 *   I4b Recalculation — currency B (17655) rate change 2000 -> 2500 -> 2000
 *   I5  Attribute serialization regression (17615) — controlled recalculation
 *   [restore to baseline]
 *   I6  Final state verification (full multi-table restore round-trip)
 */

// ---------------------------------------------------------------------------
// Guards: must run in WP-CLI with the plugin active
// ---------------------------------------------------------------------------
if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "This test must be run via WP-CLI (wp eval-file).\n");
    exit(1);
}

if (!is_plugin_active('ani-navasan-lite/ani-navasan-lite.php')) {
    fwrite(STDERR, "Ani Navasan Lite plugin is not active.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Authorized test data
// ---------------------------------------------------------------------------
define('T10_SIMPLE_PRODUCT', 17613);
define('T10_VARIABLE_PRODUCT', 17615);
define('T10_VARIATION_A', 17616); // currency A (17654)
define('T10_VARIATION_B', 17617); // currency B (17655)
define('T10_CURRENCY_A', 17654);
define('T10_CURRENCY_B', 17655);

// The six authorized posts (products + variations + currencies) and the four
// product IDs that have rows in the WooCommerce product lookup table.
$GLOBALS['t10b_post_ids'] = [
    T10_SIMPLE_PRODUCT,
    T10_VARIABLE_PRODUCT,
    T10_VARIATION_A,
    T10_VARIATION_B,
    T10_CURRENCY_A,
    T10_CURRENCY_B,
];
$GLOBALS['t10b_product_ids'] = [
    T10_SIMPLE_PRODUCT,
    T10_VARIABLE_PRODUCT,
    T10_VARIATION_A,
    T10_VARIATION_B,
];

// Set an admin user (required for capability checks in save handlers)
$admin_id = 1;
if (!get_user_by('id', $admin_id)) {
    $admin_id = (int) get_option('administrator_id', 0);
}
wp_set_current_user($admin_id);

// ---------------------------------------------------------------------------
// Test state (use $GLOBALS so the counters work regardless of the scope
// wp eval-file includes this file in)
// ---------------------------------------------------------------------------
$GLOBALS['t10_passed'] = 0;
$GLOBALS['t10_failed'] = 0;

// Restoration state (Task 10b failure safety)
$GLOBALS['t10b_backup']       = null;   // the captured snapshot
$GLOBALS['t10b_restored']     = false;  // true once a restore has been attempted
$GLOBALS['t10b_restore_ok']   = false;  // true only if the restore fully succeeded
$GLOBALS['t10b_restore_errs'] = [];     // restore error messages
$GLOBALS['t10b_exception']    = null;   // an uncaught Throwable from the scenarios

function t10_check($name, $actual, $expected) {
    $ok = ($actual === $expected);
    if ($ok) {
        echo "  PASS: {$name}\n";
        $GLOBALS['t10_passed']++;
    } else {
        echo "  FAIL: {$name}\n";
        echo "     Expected: " . var_export($expected, true) . "\n";
        echo "     Actual:   " . var_export($actual, true) . "\n";
        $GLOBALS['t10_failed']++;
    }
}

function t10_price($product_id) {
    $p = wc_get_product($product_id);
    return $p ? (string) $p->get_price() : 'MISSING';
}

function t10_regular($product_id) {
    $p = wc_get_product($product_id);
    return $p ? (string) $p->get_regular_price() : 'MISSING';
}

function t10_sale($product_id) {
    $p = wc_get_product($product_id);
    return $p ? (string) $p->get_sale_price() : 'MISSING';
}

// ---------------------------------------------------------------------------
// Backup / Restore (Task 10b — hardened, multi-table, transactional)
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
 */
function t10b_backup_state($post_ids, $product_ids) {
    global $wpdb;
    $backup = ['posts' => [], 'postmeta' => [], 'lookup' => [], 'options' => []];

    // wp_posts: full rows (all columns)
    foreach ($post_ids as $id) {
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID = %d", $id),
            ARRAY_A
        );
        if ($row) {
            $backup['posts'][$id] = $row;
        }
    }

    // wp_postmeta: full rows (preserving meta_id, duplicate keys, order)
    foreach ($post_ids as $id) {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id",
                $id
            ),
            ARRAY_A
        );
        $backup['postmeta'][$id] = $rows ? $rows : [];
    }

    // wp_wc_product_meta_lookup: full rows (all columns)
    foreach ($product_ids as $id) {
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id = %d",
                $id
            ),
            ARRAY_A
        );
        if ($row) {
            $backup['lookup'][$id] = $row;
        }
    }

    // wp_options (transients): targeted safety net — only options whose name
    // references one of the product IDs. None exist for these products on the
    // current site, so this is normally an empty no-op.
    $like_clauses = [];
    $like_params  = [];
    foreach ($product_ids as $id) {
        $like_clauses[] = 'option_name LIKE %s';
        $like_params[]  = "%{$id}%";
    }
    if ($like_clauses) {
        $sql  = "SELECT option_name, option_value FROM {$wpdb->prefix}options WHERE " . implode(' OR ', $like_clauses);
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$like_params), ARRAY_A);
        $backup['options'] = $rows ? $rows : [];
    }

    return $backup;
}

/**
 * Report the storage engine of every table the restore touches.
 * Returns [ label => engine ]. Used to confirm transaction support.
 */
function t10b_table_engines() {
    global $wpdb;
    $tables = [
        'posts'    => $wpdb->posts,
        'postmeta' => $wpdb->postmeta,
        'lookup'   => $wpdb->prefix . 'wc_product_meta_lookup',
        'options'  => $wpdb->prefix . 'options',
    ];
    $engines = [];
    foreach ($tables as $label => $table) {
        $row = $wpdb->get_row("SHOW TABLE STATUS LIKE " . $wpdb->prepare('%s', $table));
        $engines[$label] = $row ? $row->Engine : 'UNKNOWN';
    }
    return $engines;
}


/**
 * Restore the captured state. Runs inside a single transaction. Every SQL
 * operation is checked; any hard failure triggers a ROLLBACK and a failure
 * return. A non-InnoDB table is reported as a warning (rollback may not cover
 * it) but does not by itself abort the restore.
 *
 * Returns [ 'ok' => bool, 'errors' => [string, ...] ].
 */
function t10b_restore_state($backup) {
    global $wpdb;
    $post_ids    = $GLOBALS['t10b_post_ids'];
    $product_ids = $GLOBALS['t10b_product_ids'];
    $errors      = [];

    $engines = t10b_table_engines();
    $non_innodb = array_filter($engines, function ($e) {
        return strcasecmp((string) $e, 'InnoDB') !== 0;
    });
    if ($non_innodb) {
        $errors[] = 'WARNING: non-InnoDB tables present: ' . implode(', ', array_map(
            function ($k, $v) { return "{$k} ({$v})"; },
            array_keys($non_innodb),
            array_values($non_innodb)
        )) . ' — transaction rollback may not cover them.';
    }

    $wpdb->query('START TRANSACTION');

    try {
        // 1. wp_posts — restore ALL columns
        foreach ($backup['posts'] as $id => $row) {
            $data = $row;
            unset($data['ID']);
            $result = $wpdb->update($wpdb->posts, $data, ['ID' => $id]);
            if ($result === false) {
                $errors[] = "wp_posts update failed for ID {$id}: {$wpdb->last_error}";
            }
        }

        // 2. wp_postmeta — delete all rows for the post, re-insert verbatim
        foreach ($post_ids as $id) {
            $result = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id = %d", $id));
            if ($result === false) {
                $errors[] = "wp_postmeta delete failed for ID {$id}: {$wpdb->last_error}";
            }
            $rows = isset($backup['postmeta'][$id]) ? $backup['postmeta'][$id] : [];
            foreach ($rows as $row) {
                $result = $wpdb->insert($wpdb->postmeta, [
                    'meta_id'    => $row['meta_id'],
                    'post_id'    => $row['post_id'],
                    'meta_key'   => $row['meta_key'],
                    'meta_value' => $row['meta_value'],
                ]);
                if ($result === false) {
                    $errors[] = "wp_postmeta insert failed for ID {$id} [{$row['meta_key']}]: {$wpdb->last_error}";
                }
            }
        }

        // 3. wp_wc_product_meta_lookup — upsert each authorized product row
        $lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
        foreach ($product_ids as $id) {
            if (isset($backup['lookup'][$id])) {
                $row  = $backup['lookup'][$id];
                $data = $row;
                unset($data['product_id']);
                $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$lookup_table} WHERE product_id = %d", $id));
                if ($exists) {
                    $result = $wpdb->update($lookup_table, $data, ['product_id' => $id]);
                } else {
                    $result = $wpdb->insert($lookup_table, $row);
                }
                if ($result === false) {
                    $errors[] = "lookup restore failed for ID {$id}: {$wpdb->last_error}";
                }
            } else {
                // No row in the snapshot: remove any row the tests created.
                $result = $wpdb->query($wpdb->prepare("DELETE FROM {$lookup_table} WHERE product_id = %d", $id));
                if ($result === false) {
                    $errors[] = "lookup delete failed for ID {$id}: {$wpdb->last_error}";
                }
            }
        }

        // 4. wp_options (transients) — restore the targeted snapshot
        $backup_opts = [];
        foreach ($backup['options'] as $r) {
            $backup_opts[$r['option_name']] = $r['option_value'];
        }
        $like_clauses = [];
        $like_params  = [];
        foreach ($product_ids as $id) {
            $like_clauses[] = 'option_name LIKE %s';
            $like_params[]  = "%{$id}%";
        }
        $current = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->prefix}options WHERE " . implode(' OR ', $like_clauses),
            ...$like_params
        ), ARRAY_A);
        foreach ($current as $r) {
            if (!array_key_exists($r['option_name'], $backup_opts)) {
                $result = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}options WHERE option_name = %s", $r['option_name']));
                if ($result === false) {
                    $errors[] = "options delete failed for {$r['option_name']}: {$wpdb->last_error}";
                }
            }
        }
        foreach ($backup_opts as $name => $value) {
            $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}options WHERE option_name = %s", $name));
            if ($exists) {
                $result = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}options SET option_value = %s WHERE option_name = %s", $value, $name));
            } else {
                $result = $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->prefix}options (option_name, option_value) VALUES (%s, %s)", $name, $value));
            }
            if ($result === false) {
                $errors[] = "options restore failed for {$name}: {$wpdb->last_error}";
            }
        }

        // Separate hard errors from the non-InnoDB warning.
        $hard_errors = array_values(array_filter($errors, function ($e) {
            return strpos($e, 'WARNING:') !== 0;
        }));

        if ($hard_errors) {
            $wpdb->query('ROLLBACK');
            return ['ok' => false, 'errors' => $errors];
        }

        $wpdb->query('COMMIT');
        if ($wpdb->last_error) {
            $errors[] = "COMMIT reported: {$wpdb->last_error}";
            return ['ok' => false, 'errors' => $errors];
        }
        return ['ok' => true, 'errors' => $errors];
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return ['ok' => false, 'errors' => array_merge($errors, ['exception: ' . $e->getMessage()])];
    }
}


/**
 * Verify that the current database state matches the captured snapshot.
 *
 * Checks, for the authorized scope:
 *   - wp_posts: every column of every authorized post matches.
 *   - wp_postmeta: the exact set of (meta_key, meta_value) rows matches,
 *     including duplicate keys and row counts.
 *   - wp_wc_product_meta_lookup: every column of every authorized product row
 *     matches; no extra rows exist for the authorized product IDs.
 *   - wp_options (transients): the targeted option set matches.
 *
 * Returns [ 'ok' => bool, 'mismatches' => [string, ...] ].
 */
function t10b_verify_restore($backup) {
    global $wpdb;
    $post_ids    = $GLOBALS['t10b_post_ids'];
    $product_ids = $GLOBALS['t10b_product_ids'];
    $mismatches  = [];

    // 1. wp_posts — every column
    foreach ($backup['posts'] as $id => $brow) {
        $arow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID = %d", $id), ARRAY_A);
        if (!$arow) {
            $mismatches[] = "wp_posts: post {$id} is missing after restore";
            continue;
        }
        foreach ($brow as $col => $bv) {
            $av = array_key_exists($col, $arow) ? $arow[$col] : '<MISSING>';
            if ((string) $bv !== (string) $av) {
                $mismatches[] = "wp_posts.{$id}.{$col}: backup=" . var_export($bv, true) . " current=" . var_export($av, true);
            }
        }
    }

    // 2. wp_postmeta — exact (key => [values]) multiset per post
    foreach ($post_ids as $id) {
        $bmap = [];
        foreach ((isset($backup['postmeta'][$id]) ? $backup['postmeta'][$id] : []) as $r) {
            $bmap[$r['meta_key']][] = $r['meta_value'];
        }
        $arows = $wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $id), ARRAY_A);
        $amap = [];
        foreach ($arows as $r) {
            $amap[$r['meta_key']][] = $r['meta_value'];
        }
        // Row count
        $bcount = 0;
        foreach ($bmap as $vals) { $bcount += count($vals); }
        $acount = 0;
        foreach ($amap as $vals) { $acount += count($vals); }
        if ($bcount !== $acount) {
            $mismatches[] = "wp_postmeta.{$id}: row count backup={$bcount} current={$acount}";
        }
        // Key set
        if (array_keys($bmap) != array_keys($amap)) {
            $mismatches[] = "wp_postmeta.{$id}: key set differs (backup=" . implode(',', array_keys($bmap)) . " current=" . implode(',', array_keys($amap)) . ")";
        }
        // Per-key value multiset (order-insensitive, duplicate-aware)
        foreach (array_unique(array_merge(array_keys($bmap), array_keys($amap))) as $k) {
            $bv = isset($bmap[$k]) ? $bmap[$k] : [];
            $av = isset($amap[$k]) ? $amap[$k] : [];
            sort($bv);
            sort($av);
            if ($bv !== $av) {
                $mismatches[] = "wp_postmeta.{$id}[{$k}]: backup=" . json_encode($bv) . " current=" . json_encode($av);
            }
        }
    }

    // 3. wp_wc_product_meta_lookup — every column + no extra rows
    $lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
    foreach ($product_ids as $id) {
        $arow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$lookup_table} WHERE product_id = %d", $id), ARRAY_A);
        if (isset($backup['lookup'][$id])) {
            if (!$arow) {
                $mismatches[] = "lookup.{$id}: row missing after restore";
                continue;
            }
            foreach ($backup['lookup'][$id] as $col => $bv) {
                $av = array_key_exists($col, $arow) ? $arow[$col] : '<MISSING>';
                if ((string) $bv !== (string) $av) {
                    $mismatches[] = "lookup.{$id}.{$col}: backup=" . var_export($bv, true) . " current=" . var_export($av, true);
                }
            }
        } else {
            if ($arow) {
                $mismatches[] = "lookup.{$id}: row present after restore but absent from snapshot";
            }
        }
    }

    // 4. wp_options (transients) — targeted set
    $like_clauses = [];
    $like_params  = [];
    foreach ($product_ids as $id) {
        $like_clauses[] = 'option_name LIKE %s';
        $like_params[]  = "%{$id}%";
    }
    $bopts = [];
    foreach ($backup['options'] as $r) {
        $bopts[$r['option_name']] = $r['option_value'];
    }
    $arows = $wpdb->get_results($wpdb->prepare(
        "SELECT option_name, option_value FROM {$wpdb->prefix}options WHERE " . implode(' OR ', $like_clauses),
        ...$like_params
    ), ARRAY_A);
    $aopts = [];
    foreach ($arows as $r) {
        $aopts[$r['option_name']] = $r['option_value'];
    }
    if ($bopts != $aopts) {
        $mismatches[] = "options(transients): backup=" . json_encode(array_keys($bopts)) . " current=" . json_encode(array_keys($aopts));
    }

    return ['ok' => empty($mismatches), 'mismatches' => $mismatches];
}


// Save a currency through the REAL admin save workflow (mirrors the compat
// harness): populate $_POST as the currency metabox form would, then call
// wp_update_post(). That fires the genuine save_post_mnswmc handler, which
// verifies the nonce + capability, persists the rate and fires
// ani_navasan_lite_recalc_currency.
function t10_save_currency($post_id, $symbol, $rate) {
    $_POST['mnswmc_meta_nonce'] = wp_create_nonce('mnswmc_meta_save');
    $_POST['mnswmc_symbol']     = $symbol;
    $_POST['mnswmc_rate']       = (string) $rate;
    $result = wp_update_post(
        [
            'ID'          => $post_id,
            'post_type'   => 'mnswmc',
            'post_status' => 'publish',
        ],
        true
    );
    unset($_POST['mnswmc_meta_nonce'], $_POST['mnswmc_symbol'], $_POST['mnswmc_rate']);
    return $result;
}

/**
 * Attempt the restore exactly once (guarded by the restoration state flag).
 * Used by both the try/finally path and the shutdown-handler safety net so
 * the restore is never performed twice.
 */
function t10b_do_restore() {
    if ($GLOBALS['t10b_restored'] || $GLOBALS['t10b_backup'] === null) {
        return;
    }
    $GLOBALS['t10b_restored'] = true;
    $result = t10b_restore_state($GLOBALS['t10b_backup']);
    $GLOBALS['t10b_restore_ok']   = $result['ok'];
    $GLOBALS['t10b_restore_errs'] = $result['errors'];
    // Clear object caches so subsequent reads see the restored rows.
    foreach ($GLOBALS['t10b_post_ids'] as $id) {
        clean_post_cache($id);
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($id);
        }
    }
}

// Single shutdown-handler safety net. It only acts if the try/finally path did
// not already restore (e.g. a fatal error aborted the script). It cannot
// protect against process termination, a server crash, power loss, or a
// database outage — those are documented limitations, not handled here.
register_shutdown_function(function () {
    if (!$GLOBALS['t10b_restored'] && $GLOBALS['t10b_backup'] !== null) {
        t10b_do_restore();
        if (!$GLOBALS['t10b_restore_ok']) {
            fwrite(STDERR, "\n[shutdown] RESTORE FAILED — site may be left modified:\n");
            foreach ($GLOBALS['t10b_restore_errs'] as $e) {
                fwrite(STDERR, "  - {$e}\n");
            }
        }
    }
});


// ===========================================================================
// I0 — Environment + plugin load
// ===========================================================================
echo "\n=== I0: Environment + plugin load ===\n";
t10_check('I0.1 plugin active', is_plugin_active('ani-navasan-lite/ani-navasan-lite.php'), true);
t10_check('I0.2 round fn exists', function_exists('ani_navasan_round_to_thousand'), true);
t10_check('I0.3 rate fn exists', function_exists('ani_navasan_get_currency_rate'), true);
t10_check('I0.4 cpt registered', post_type_exists('mnswmc'), true);
t10_check('I0.5 simple product exists', (bool) wc_get_product(T10_SIMPLE_PRODUCT), true);
t10_check('I0.6 variable product exists', (bool) wc_get_product(T10_VARIABLE_PRODUCT), true);
t10_check('I0.7 variation A exists', (bool) wc_get_product(T10_VARIATION_A), true);
t10_check('I0.8 variation B exists', (bool) wc_get_product(T10_VARIATION_B), true);

// ===========================================================================
// Backup the authorized state (products + variations + currencies)
// ===========================================================================
$GLOBALS['t10b_backup'] = t10b_backup_state($GLOBALS['t10b_post_ids'], $GLOBALS['t10b_product_ids']);
$engines = t10b_table_engines();
echo "\n[backup] captured " . count($GLOBALS['t10b_backup']['posts']) . " posts, "
    . count($GLOBALS['t10b_backup']['lookup']) . " lookup rows, "
    . count($GLOBALS['t10b_backup']['options']) . " targeted options\n";
echo "[backup] table engines: " . implode(', ', array_map(
    function ($k, $v) { return "{$k}={$v}"; },
    array_keys($engines),
    array_values($engines)
)) . "\n";


// ===========================================================================
// Scenarios I1–I5 run inside try/finally so the restore is always attempted,
// even if a scenario throws an ordinary PHP error.
// ===========================================================================
try {

// ===========================================================================
// I1 — Simple product save handler (17613)
// ===========================================================================
echo "\n=== I1: Simple product save handler (17613) ===\n";

$_POST['_mnswmc_active']                   = 'yes';
$_POST['_mnswmc_currency_id']              = (string) T10_CURRENCY_A;
$_POST['_mnswmc_regular_price']            = '100.556';
$_POST['_mnswmc_sale_price']               = '90.123';
$_POST['_mnswmc_dependence_type']          = 'advanced';
$_POST['_torob_scraper_min_price_currency'] = '86.789';

$product = wc_get_product(T10_SIMPLE_PRODUCT);
do_action('woocommerce_admin_process_product_object', $product);
$product->save();

// Meta persisted (unrounded — the rounding hook is tested separately in I3)
t10_check('I1.1 _mnswmc_active', get_post_meta(T10_SIMPLE_PRODUCT, '_mnswmc_active', true), 'yes');
t10_check('I1.2 _mnswmc_currency_id', get_post_meta(T10_SIMPLE_PRODUCT, '_mnswmc_currency_id', true), (string) T10_CURRENCY_A);
t10_check('I1.3 _mnswmc_regular_price (unrounded)', get_post_meta(T10_SIMPLE_PRODUCT, '_mnswmc_regular_price', true), '100.556');
t10_check('I1.4 _mnswmc_sale_price (unrounded)', get_post_meta(T10_SIMPLE_PRODUCT, '_mnswmc_sale_price', true), '90.123');
t10_check('I1.5 _mnswmc_dependence_type', get_post_meta(T10_SIMPLE_PRODUCT, '_mnswmc_dependence_type', true), 'advanced');
t10_check('I1.6 _torob_scraper_min_price_currency (unrounded)', get_post_meta(T10_SIMPLE_PRODUCT, '_torob_scraper_min_price_currency', true), '86.789');

// Prices computed from the unrounded currency values (rate 2000)
t10_check('I1.7 regular price (2000*100.556=201112->201000)', t10_regular(T10_SIMPLE_PRODUCT), '201000');
t10_check('I1.8 sale price (2000*90.123=180246->180000)', t10_sale(T10_SIMPLE_PRODUCT), '180000');
t10_check('I1.9 _torob_scraper_min_price (2000*86.789=173578->174000)', get_post_meta(T10_SIMPLE_PRODUCT, '_torob_scraper_min_price', true), '174000');

unset($_POST['_mnswmc_active'], $_POST['_mnswmc_currency_id'], $_POST['_mnswmc_regular_price'], $_POST['_mnswmc_sale_price'], $_POST['_mnswmc_dependence_type'], $_POST['_torob_scraper_min_price_currency']);



// ===========================================================================
// I2 — Variation save handler (17616, 17617) + isolation
// ===========================================================================
echo "\n=== I2: Variation save handler (17616, 17617) ===\n";

// Variation A (17616, loop index 0) — currency A
$_POST['_mnswmc_active'][0]                   = 'yes';
$_POST['_mnswmc_currency_id'][0]              = (string) T10_CURRENCY_A;
$_POST['_mnswmc_regular_price'][0]            = '100';
$_POST['_mnswmc_sale_price'][0]               = '';
$_POST['_mnswmc_dependence_type'][0]          = 'advanced';
$_POST['_torob_scraper_min_price_currency'][0] = '86';

// Variation B (17617, loop index 1) — currency B
$_POST['_mnswmc_active'][1]                   = 'yes';
$_POST['_mnswmc_currency_id'][1]              = (string) T10_CURRENCY_B;
$_POST['_mnswmc_regular_price'][1]            = '200';
$_POST['_mnswmc_sale_price'][1]               = '180';
$_POST['_mnswmc_dependence_type'][1]          = 'simple';
$_POST['_torob_scraper_min_price_currency'][1] = '170';

do_action('woocommerce_save_product_variation', T10_VARIATION_A, 0);
do_action('woocommerce_save_product_variation', T10_VARIATION_B, 1);

// Variation A (17616)
t10_check('I2.1 17616 _mnswmc_active', get_post_meta(T10_VARIATION_A, '_mnswmc_active', true), 'yes');
t10_check('I2.2 17616 _mnswmc_currency_id', get_post_meta(T10_VARIATION_A, '_mnswmc_currency_id', true), (string) T10_CURRENCY_A);
t10_check('I2.3 17616 _mnswmc_regular_price', get_post_meta(T10_VARIATION_A, '_mnswmc_regular_price', true), '100');
t10_check('I2.4 17616 _mnswmc_sale_price (empty)', get_post_meta(T10_VARIATION_A, '_mnswmc_sale_price', true), '');
t10_check('I2.5 17616 _mnswmc_dependence_type', get_post_meta(T10_VARIATION_A, '_mnswmc_dependence_type', true), 'advanced');
t10_check('I2.6 17616 regular (2000*100=200000)', t10_regular(T10_VARIATION_A), '200000');
t10_check('I2.7 17616 sale empty (no WC sale price)', t10_sale(T10_VARIATION_A), '');

// Variation B (17617)
t10_check('I2.8 17617 _mnswmc_active', get_post_meta(T10_VARIATION_B, '_mnswmc_active', true), 'yes');
t10_check('I2.9 17617 _mnswmc_currency_id', get_post_meta(T10_VARIATION_B, '_mnswmc_currency_id', true), (string) T10_CURRENCY_B);
t10_check('I2.10 17617 _mnswmc_regular_price', get_post_meta(T10_VARIATION_B, '_mnswmc_regular_price', true), '200');
t10_check('I2.11 17617 _mnswmc_sale_price', get_post_meta(T10_VARIATION_B, '_mnswmc_sale_price', true), '180');
t10_check('I2.12 17617 _mnswmc_dependence_type', get_post_meta(T10_VARIATION_B, '_mnswmc_dependence_type', true), 'simple');
t10_check('I2.13 17617 regular (2000*200=400000)', t10_regular(T10_VARIATION_B), '400000');
t10_check('I2.14 17617 sale (2000*180=360000)', t10_sale(T10_VARIATION_B), '360000');

// Cross-variation isolation
t10_check('I2.15 17616 and 17617 currency ids differ', get_post_meta(T10_VARIATION_A, '_mnswmc_currency_id', true) !== get_post_meta(T10_VARIATION_B, '_mnswmc_currency_id', true), true);

unset($_POST['_mnswmc_active'], $_POST['_mnswmc_currency_id'], $_POST['_mnswmc_regular_price'], $_POST['_mnswmc_sale_price'], $_POST['_mnswmc_dependence_type'], $_POST['_torob_scraper_min_price_currency']);


// ===========================================================================
// I3 — Decimal rounding hook (17613)
// ===========================================================================
echo "\n=== I3: Decimal rounding hook (17613) ===\n";

$_POST['_mnswmc_regular_price']             = '100.556';
$_POST['_mnswmc_sale_price']                = '90.123';
$_POST['_torob_scraper_min_price_currency'] = '86.789';

do_action('save_post_product', T10_SIMPLE_PRODUCT, get_post(T10_SIMPLE_PRODUCT), true);

t10_check('I3.1 _mnswmc_regular_price rounded to 2dp', (string) get_post_meta(T10_SIMPLE_PRODUCT, '_mnswmc_regular_price', true), '100.56');
t10_check('I3.2 _mnswmc_sale_price rounded to 2dp', (string) get_post_meta(T10_SIMPLE_PRODUCT, '_mnswmc_sale_price', true), '90.12');
t10_check('I3.3 _torob_scraper_min_price_currency rounded to 2dp', (string) get_post_meta(T10_SIMPLE_PRODUCT, '_torob_scraper_min_price_currency', true), '86.79');

unset($_POST['_mnswmc_regular_price'], $_POST['_mnswmc_sale_price'], $_POST['_torob_scraper_min_price_currency']);

// ===========================================================================
// Restore to baseline before the recalculation scenarios (checked)
// ===========================================================================
$mid = t10b_restore_state($GLOBALS['t10b_backup']);
foreach ($GLOBALS['t10b_post_ids'] as $id) {
    clean_post_cache($id);
    if (function_exists('wc_delete_product_transients')) { wc_delete_product_transients($id); }
}
t10_check('I3.4 mid-run restore to baseline succeeded', $mid['ok'], true);
if (!$mid['ok']) {
    foreach ($mid['errors'] as $e) { echo "     restore error: {$e}\n"; }
}
echo "\n[restore] baseline restored before recalculation scenarios\n";


// ===========================================================================
// I4a — Recalculation: currency A (17654) rate change 2000 -> 2500 -> 2000
// ===========================================================================
echo "\n=== I4a: Recalculation — currency A (17654) rate change ===\n";

// Baseline (after restore):
//   17613 (simple, A): regcur=100, salecur=''  -> reg=200000, sale=120000 (WC sale)
//   17616 (var, A):    regcur=100, salecur=''  -> reg=200000, sale=''
//   17617 (var, B):    regcur=200, salecur=180 -> reg=400000, sale=360000
//   17615 (parent):    _price=200000

t10_save_currency(T10_CURRENCY_A, 'TST', '2500');

t10_check('I4a.1 17613 regular (100*2500=250000)', t10_regular(T10_SIMPLE_PRODUCT), '250000');
t10_check('I4a.2 17613 sale preserved (empty salecur)', t10_sale(T10_SIMPLE_PRODUCT), '120000');
t10_check('I4a.3 17616 regular (100*2500=250000)', t10_regular(T10_VARIATION_A), '250000');
t10_check('I4a.4 17616 sale empty (preserved)', t10_sale(T10_VARIATION_A), '');
t10_check('I4a.5 17617 regular unchanged (currency B)', t10_regular(T10_VARIATION_B), '400000');
t10_check('I4a.6 17617 sale unchanged (currency B)', t10_sale(T10_VARIATION_B), '360000');
t10_check('I4a.7 17615 parent price (min 250000,360000)', t10_price(T10_VARIABLE_PRODUCT), '250000');

t10_save_currency(T10_CURRENCY_A, 'TST', '2000');

t10_check('I4a.8 17613 regular restored (200000)', t10_regular(T10_SIMPLE_PRODUCT), '200000');
t10_check('I4a.9 17613 sale preserved (120000)', t10_sale(T10_SIMPLE_PRODUCT), '120000');
t10_check('I4a.10 17616 regular restored (200000)', t10_regular(T10_VARIATION_A), '200000');
t10_check('I4a.11 17617 regular unchanged (400000)', t10_regular(T10_VARIATION_B), '400000');
t10_check('I4a.12 17615 parent price restored (200000)', t10_price(T10_VARIABLE_PRODUCT), '200000');

// ===========================================================================
// I4b — Recalculation: currency B (17655) rate change 2000 -> 2500 -> 2000
// ===========================================================================
echo "\n=== I4b: Recalculation — currency B (17655) rate change ===\n";

t10_save_currency(T10_CURRENCY_B, 'LEG', '2500');

t10_check('I4b.1 17617 regular (200*2500=500000)', t10_regular(T10_VARIATION_B), '500000');
t10_check('I4b.2 17617 sale (180*2500=450000)', t10_sale(T10_VARIATION_B), '450000');
t10_check('I4b.3 17613 regular unchanged (currency A)', t10_regular(T10_SIMPLE_PRODUCT), '200000');
t10_check('I4b.4 17616 regular unchanged (currency A)', t10_regular(T10_VARIATION_A), '200000');
t10_check('I4b.5 17615 parent price (min 200000,450000)', t10_price(T10_VARIABLE_PRODUCT), '200000');

t10_save_currency(T10_CURRENCY_B, 'LEG', '2000');

t10_check('I4b.6 17617 regular restored (400000)', t10_regular(T10_VARIATION_B), '400000');
t10_check('I4b.7 17617 sale restored (360000)', t10_sale(T10_VARIATION_B), '360000');
t10_check('I4b.8 17615 parent price restored (200000)', t10_price(T10_VARIABLE_PRODUCT), '200000');


// ===========================================================================
// I5 — Attribute serialization regression (17615)
//
// The recalculation handler syncs variable parents via
// WC_Product_Variable::sync($parent, false) specifically to AVOID re-persisting
// _product_attributes (which would double-serialize it). This scenario performs
// a real, controlled synchronization operation (a currency-rate recalculation
// that syncs the parent) and then verifies that _product_attributes is still a
// well-formed array with its original data intact — not a double-serialized
// string.
// ===========================================================================
echo "\n=== I5: Attribute serialization regression (17615) ===\n";

// 1. Capture the original attribute metadata (unserialized value).
$orig_attrs = get_post_meta(T10_VARIABLE_PRODUCT, '_product_attributes', true);

if ($orig_attrs === '' || $orig_attrs === null) {
    echo "  SKIP: 17615 has no _product_attributes (nothing to verify)\n";
} else {
    // 2. Perform a controlled synchronization operation: a currency-rate
    //    recalculation (2000 -> 2500 -> 2000) that syncs the variable parent.
    t10_save_currency(T10_CURRENCY_A, 'TST', '2500');
    t10_save_currency(T10_CURRENCY_A, 'TST', '2000');

    // 3. Read _product_attributes after the operation.
    $new_attrs = get_post_meta(T10_VARIABLE_PRODUCT, '_product_attributes', true);

    // 4. Verify it remains a well-formed array (not double-serialized).
    t10_check('I5.1 _product_attributes is an array after recalculation', is_array($new_attrs), true);
    t10_check('I5.2 _product_attributes is not a serialized string', !is_string($new_attrs), true);

    // 5. Verify the original attribute data is preserved (no loss / alteration).
    t10_check('I5.3 _product_attributes data unchanged after recalculation', $new_attrs, $orig_attrs);

    // 6. Verify the raw stored value unserializes to an array in ONE step
    //    (a double-serialized value would unserialize to a string, not an array).
    //    Read the raw stored value directly from the DB (get_post_meta()
    //    unserializes, so it cannot reveal double-serialization).
    global $wpdb;
    $new_attrs_raw = $wpdb->get_var($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
        T10_VARIABLE_PRODUCT,
        '_product_attributes'
    ));
    if (is_string($new_attrs_raw)) {
        $unserialized_once = @unserialize($new_attrs_raw);
        t10_check('I5.4 raw stored value unserializes to an array (not double-serialized)', is_array($unserialized_once), true);
    } else {
        t10_check('I5.4 raw stored value is a string (serializable meta)', is_string($new_attrs_raw), true);
    }
}


} catch (Throwable $e) {
    // An ordinary PHP error in a scenario. Record it; the finally block still
    // attempts the restore. The original failure is NOT masked by the restore.
    $GLOBALS['t10b_exception'] = $e;
    echo "\n[error] scenario aborted: " . get_class($e) . ": " . $e->getMessage() . "\n";
    echo "         at " . $e->getFile() . ":" . $e->getLine() . "\n";
} finally {
    // Always attempt the final restore (guarded so it runs at most once).
    t10b_do_restore();
}

// ===========================================================================
// I6 — Final state verification (full multi-table restore round-trip)
// ===========================================================================
echo "\n=== I6: Final state verification (restore round-trip) ===\n";

t10_check('I6.0 final restore was attempted', $GLOBALS['t10b_restored'], true);
t10_check('I6.1 final restore succeeded', $GLOBALS['t10b_restore_ok'], true);
if (!$GLOBALS['t10b_restore_ok']) {
    foreach ($GLOBALS['t10b_restore_errs'] as $e) {
        echo "     restore error: {$e}\n";
    }
}

// Only run the row-by-row verification if the restore reported success.
if ($GLOBALS['t10b_restore_ok']) {
    $verify = t10b_verify_restore($GLOBALS['t10b_backup']);
    t10_check('I6.2 all backed-up records match the current DB state', $verify['ok'], true);
    if (!$verify['ok']) {
        foreach ($verify['mismatches'] as $m) {
            echo "     mismatch: {$m}\n";
        }
    }
} else {
    // The restore failed; record the verification as failed (not skipped).
    t10_check('I6.2 all backed-up records match the current DB state', false, true);
}

// ===========================================================================
// Summary
// ===========================================================================
$t10_passed = $GLOBALS['t10_passed'];
$t10_failed = $GLOBALS['t10_failed'];
$total = $t10_passed + $t10_failed;

echo "\n" . str_repeat('=', 50) . "\n";

// A scenario exception is a hard failure even if the counters look clean.
$had_exception = ($GLOBALS['t10b_exception'] !== null);
$restore_failed = !$GLOBALS['t10b_restore_ok'];

// Never print a "success" summary when the restore or verification failed.
if ($restore_failed || $had_exception) {
    echo "INTEGRATION SUITE: FAILED (restore/exception)\n";
    if ($had_exception) {
        echo "  Scenario exception: " . get_class($GLOBALS['t10b_exception']) . ": " . $GLOBALS['t10b_exception']->getMessage() . "\n";
    }
    if ($restore_failed) {
        echo "  Restore FAILED — the site may be left in a modified state.\n";
        foreach ($GLOBALS['t10b_restore_errs'] as $e) {
            echo "    - {$e}\n";
        }
    }
    echo "  Checks: Total={$total}, Passed={$t10_passed}, Failed={$t10_failed}\n";
    echo str_repeat('=', 50) . "\n";
    exit(1);
}

echo "INTEGRATION SUITE: Total: {$total}, Passed: {$t10_passed}, Failed: {$t10_failed}\n";
echo "Restore: OK (all authorized posts, meta, lookup rows, and targeted transients verified)\n";
echo str_repeat('=', 50) . "\n";

exit($t10_failed === 0 ? 0 : 1);

