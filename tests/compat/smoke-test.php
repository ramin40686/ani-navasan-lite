<?php
/**
 * Ani Navasan Lite — Task 08 compatibility matrix smoke test.
 *
 * Run inside a WordPress install (WooCommerce + the plugin active) via:
 *   wp eval-file smoke-test.php
 *
 * Prints a single JSON object with all check results to stdout.
 *
 * Methodology (mirrors Task 07):
 *  - Currency create/edit steps are driven through the REAL admin save
 *    workflow: nonce + wp_insert_post()/wp_update_post() -> save_post_mnswmc
 *    -> _mnswmc_currency_value persistence -> ani_navasan_lite_recalc_currency.
 *  - Products are created through the WooCommerce CRUD API (stable since
 *    WC 3.0; wc_create_product() was removed in newer WC releases, so it
 *    is intentionally NOT used here).
 *  - The wp_wc_product_meta_lookup table is NOT used as a verification
 *    source (known CLI-context inconsistency, see Task 07 report);
 *    _price/_regular_price meta and the CRUD API are used instead.
 *
 * The file must stay PHP 7.4 compatible (no arrow functions, no
 * str_contains, no typed properties) because it runs on every PHP
 * version in the matrix.
 */

error_reporting( E_ALL );

/**
 * Exception used to intercept wp_die() calls made by wp_send_json_*()
 * during the AJAX endpoint checks.
 */
class Compat_Ajax_Die extends Exception {}

// wp_die() selects its callback through a context-specific filter. With
// DOING_AJAX defined (set in the S6 section) the 'wp_die_ajax_handler'
// filter is used; the generic 'wp_die_handler' only applies to the
// non-Ajax/JSON/XML fallback branch. Hook every relevant one so the
// interceptor fires regardless of which branch wp_die() takes.
foreach (
	array( 'wp_die_handler', 'wp_die_ajax_handler', 'wp_die_json_handler', 'wp_die_jsonp_handler', 'wp_die_xmlrpc_handler', 'wp_die_xml_handler' ) as $compat_die_filter
) {
	add_filter(
		$compat_die_filter,
		function () {
			return function ( $message = '', $title = '', $args = array() ) {
				throw new Compat_Ajax_Die( is_string( $message ) ? $message : wp_json_encode( $message ) );
			};
		},
		9999
	);
}

$GLOBALS['compat_results'] = array();

/**
 * Record a single check result.
 *
 * @param string $name   Check name.
 * @param mixed  $pass   Truthy = pass.
 * @param string $detail Optional detail for the report.
 */
function compat_check( $name, $pass, $detail = '' ) {
	$GLOBALS['compat_results'][] = array(
		'name'   => $name,
		'pass'   => (bool) $pass,
		'detail' => (string) $detail,
	);
}

/**
 * Save a currency through the REAL admin save workflow.
 *
 * Populates $_POST exactly as the currency metabox form would, then calls
 * wp_insert_post() (new) or wp_update_post() (existing). That fires the
 * genuine save_post_mnswmc handler, which verifies the nonce + capability,
 * persists _mnswmc_symbol/_mnswmc_currency_value and fires ani_navasan_lite_recalc_currency.
 *
 * @param int    $post_id 0 to create, post ID to update.
 * @param string $symbol  ISO symbol.
 * @param string $rate    Rate in Toman.
 * @return int|WP_Error
 */
function compat_save_currency( $post_id, $symbol, $rate ) {
	$_POST['mnswmc_meta_nonce'] = wp_create_nonce( 'mnswmc_meta_save' );
	$_POST['mnswmc_symbol']     = $symbol;
	$_POST['mnswmc_rate']       = (string) $rate;

	if ( $post_id ) {
		return wp_update_post(
			array(
				'ID'          => $post_id,
				'post_type'   => 'mnswmc',
				'post_status' => 'publish',
			),
			true
		);
	}

	return wp_insert_post(
		array(
			'post_title'  => 'COMPAT ' . $symbol . ' ' . wp_rand( 1000, 9999 ),
			'post_type'   => 'mnswmc',
			'post_status' => 'publish',
		),
		true
	);
}

// ---------------------------------------------------------------------------
// S0 — environment + plugin load
// ---------------------------------------------------------------------------
global $wp_version;
$wc_version = defined( 'WC_VERSION' ) ? WC_VERSION : 'MISSING';

compat_check( 'S0.1 php_version', true, PHP_VERSION );
compat_check( 'S0.2 wp_version', true, $wp_version );
compat_check( 'S0.3 wc_version', true, $wc_version );
compat_check( 'S0.4 plugin_active', is_plugin_active( 'ani-navasan-lite/ani-navasan-lite.php' ) );
compat_check( 'S0.5 round_fn_exists', function_exists( 'ani_navasan_round_to_thousand' ) );
compat_check(
	'S0.6 rounding_policy',
	ani_navasan_round_to_thousand( 1499 ) === 1000
		&& ani_navasan_round_to_thousand( 1500 ) === 2000
		&& ani_navasan_round_to_thousand( 249999 ) === 250000
);

// ---------------------------------------------------------------------------
// S1 — currency CPT registration
// ---------------------------------------------------------------------------
compat_check( 'S1.1 cpt_registered', post_type_exists( 'mnswmc' ) );

// ---------------------------------------------------------------------------
// Recalc action logger (priority 1: runs before the plugin handler at 10)
// ---------------------------------------------------------------------------
$recalc_log = array();
add_action(
	'ani_navasan_lite_recalc_currency',
	function ( $currency_id, $rate = null ) use ( &$recalc_log ) {
		$recalc_log[] = array(
			'currency_id' => $currency_id,
			'rate'        => $rate,
		);
	},
	1,
	2
);

wp_set_current_user( 1 );

// ---------------------------------------------------------------------------
// S2 — currency creation through the real save workflow (rate 1500)
// ---------------------------------------------------------------------------
$recalc_log = array();
$cur        = compat_save_currency( 0, 'TST', 1500 );
compat_check( 'S2.1 currency_created', ! is_wp_error( $cur ) && $cur > 0, 'id=' . $cur );
compat_check( 'S2.2 rate_meta', get_post_meta( $cur, '_mnswmc_currency_value', true ) == 1500.0, 'got=' . get_post_meta( $cur, '_mnswmc_currency_value', true ) );
compat_check( 'S2.3 symbol_meta', get_post_meta( $cur, '_mnswmc_symbol', true ) === 'TST' );
compat_check(
	'S2.4 recalc_fired_once',
	count( $recalc_log ) === 1 && $recalc_log[0]['currency_id'] == $cur && $recalc_log[0]['rate'] == 1500.0,
	json_encode( $recalc_log )
);

// ---------------------------------------------------------------------------
// S3 — simple product recalculation (rate 1500 -> 2000)
// ---------------------------------------------------------------------------
$prod = new WC_Product_Simple();
$prod->set_name( 'COMPAT Simple' );
$prod->set_regular_price( '100000' );
$prod->set_status( 'publish' );
$prod->save();
$pid = $prod->get_id();

update_post_meta( $pid, '_mnswmc_active', 'yes' );
update_post_meta( $pid, '_mnswmc_currency_id', $cur );
update_post_meta( $pid, '_mnswmc_regular_price', 100.0 ); // 100 TST

$recalc_log = array();
compat_save_currency( $cur, 'TST', 2000 );

$p = wc_get_product( $pid );
compat_check( 'S3.1 simple_created', $pid > 0, 'id=' . $pid );
compat_check( 'S3.2 simple_regular_200000', $p->get_regular_price() === '200000', 'got=' . $p->get_regular_price() );
compat_check( 'S3.3 simple_price_200000', $p->get_price() === '200000', 'got=' . $p->get_price() );
compat_check( 'S3.4 recalc_fired_at_2000', count( $recalc_log ) === 1 && $recalc_log[0]['rate'] == 2000.0, json_encode( $recalc_log ) );

// ---------------------------------------------------------------------------
// S4 — variable product: dependent + non-dependent variation, parent sync
//     (rate 2000 -> 2500)
// ---------------------------------------------------------------------------
$var = new WC_Product_Variable();
$var->set_name( 'COMPAT Variable' );
$var->set_status( 'publish' );
$var->save();
$var_id = $var->get_id();

$v1 = new WC_Product_Variation();
$v1->set_name( 'COMPAT Var A' );
$v1->set_parent_id( $var_id );
$v1->set_regular_price( '50000' );
$v1->set_status( 'publish' );
$v1->save();

$v2 = new WC_Product_Variation();
$v2->set_name( 'COMPAT Var B' );
$v2->set_parent_id( $var_id );
$v2->set_regular_price( '60000' );
$v2->set_status( 'publish' );
$v2->save();

// Variation A: currency dependent (50 TST). Variation B: intentionally NOT dependent.
update_post_meta( $v1->get_id(), '_mnswmc_active', 'yes' );
update_post_meta( $v1->get_id(), '_mnswmc_currency_id', $cur );
update_post_meta( $v1->get_id(), '_mnswmc_regular_price', 50.0 );

$recalc_log = array();
compat_save_currency( $cur, 'TST', 2500 );

$v1_after    = wc_get_product( $v1->get_id() );
$v2_after    = wc_get_product( $v2->get_id() );
$parent_rows = get_post_meta( $var_id, '_price' );

compat_check( 'S4.1 variations_created', $v1->get_id() > 0 && $v2->get_id() > 0, 'v1=' . $v1->get_id() . ' v2=' . $v2->get_id() );
compat_check( 'S4.2 variation_dep_125000', $v1_after->get_regular_price() === '125000', 'got=' . $v1_after->get_regular_price() );
compat_check( 'S4.3 variation_indep_unchanged', $v2_after->get_regular_price() === '60000', 'got=' . $v2_after->get_regular_price() );
compat_check( 'S4.4 simple_cumulative_250000', wc_get_product( $pid )->get_regular_price() === '250000', 'got=' . wc_get_product( $pid )->get_regular_price() );
compat_check( 'S4.5 parent_synced', in_array( '125000', array_map( 'strval', (array) $parent_rows ) ), 'rows=' . implode( ',', (array) $parent_rows ) );


// ---------------------------------------------------------------------------
// S5 — sale price preservation (rate 2500 -> 3000)
// ---------------------------------------------------------------------------
$sale = new WC_Product_Simple();
$sale->set_name( 'COMPAT Sale' );
$sale->set_regular_price( '100000' );
$sale->set_sale_price( '80000' );
$sale->set_status( 'publish' );
$sale->save();

update_post_meta( $sale->get_id(), '_mnswmc_active', 'yes' );
update_post_meta( $sale->get_id(), '_mnswmc_currency_id', $cur );
update_post_meta( $sale->get_id(), '_mnswmc_regular_price', 60.0 ); // 60 TST

$recalc_log = array();
compat_save_currency( $cur, 'TST', 3000 );

$sp = wc_get_product( $sale->get_id() );
compat_check( 'S5.1 sale_regular_180000', $sp->get_regular_price() === '180000', 'got=' . $sp->get_regular_price() );
compat_check( 'S5.2 sale_price_preserved', $sp->get_sale_price() === '80000', 'got=' . $sp->get_sale_price() );
compat_check( 'S5.3 on_sale', $sp->is_on_sale() );
compat_check( 'S5.4 active_price_is_sale', $sp->get_price() === '80000', 'got=' . $sp->get_price() );
compat_check( 'S5.5 variation_cumulative_150000', wc_get_product( $v1->get_id() )->get_regular_price() === '150000', 'got=' . wc_get_product( $v1->get_id() )->get_regular_price() );

// ---------------------------------------------------------------------------
// S6 — AJAX endpoint (nonce + capability)
//    wp_send_json() echoes the JSON and then calls wp_die() only when
//    wp_doing_ajax() is true; otherwise it executes a bare `die` which
//    would terminate this CLI script. Defining DOING_AJAX makes it take
//    the wp_die() path, which the handler above intercepts. The echoed
//    JSON is captured with output buffering.
// ---------------------------------------------------------------------------
define( 'DOING_AJAX', true );

$_POST['_ajax_nonce'] = wp_create_nonce( 'mnswmc_nonce' );
$_POST['currency_id'] = $cur;
ob_start();
try {
	do_action( 'wp_ajax_mnswmc_get_exchange_rate' );
	$ajax_out = ob_get_clean();
	compat_check( 'S6.1 ajax_success', false, 'no response (wp_die not intercepted): ' . $ajax_out );
} catch ( Compat_Ajax_Die $e ) {
	$ajax_out = ob_get_clean();
	$d = json_decode( $ajax_out, true );
	compat_check( 'S6.1 ajax_success', isset( $d['success'] ) && true === $d['success'], $ajax_out );
	compat_check( 'S6.2 ajax_rate_3000', isset( $d['data']['rate'] ) && $d['data']['rate'] == 3000.0, 'rate=' . ( isset( $d['data']['rate'] ) ? $d['data']['rate'] : 'n/a' ) );
}

$_POST['_ajax_nonce'] = 'invalid-nonce';
ob_start();
try {
	do_action( 'wp_ajax_mnswmc_get_exchange_rate' );
	$ajax_out = ob_get_clean();
	compat_check( 'S6.3 bad_nonce_rejected', false, 'no response: ' . $ajax_out );
} catch ( Compat_Ajax_Die $e ) {
	$ajax_out = ob_get_clean();
	$d = json_decode( $ajax_out, true );
	compat_check( 'S6.3 bad_nonce_rejected', isset( $d['success'] ) && false === $d['success'], $ajax_out );
}

// ---------------------------------------------------------------------------
// S7 — admin screen rendering (metabox callbacks, no PHP errors)
// ---------------------------------------------------------------------------
// The woocommerce_wp_*() form helpers live in WooCommerce's admin includes
// (loaded via WC_Admin::includes() only in the admin context). In this CLI
// context they are not loaded, so require the file explicitly to exercise
// the plugin's real rendering code.
$wc_helpers_loaded = function_exists( 'woocommerce_wp_text_input' );
if ( ! $wc_helpers_loaded && function_exists( 'WC' ) && WC() ) {
	$wc_meta_box_functions = trailingslashit( WC()->plugin_path() ) . 'includes/admin/wc-meta-box-functions.php';
	if ( file_exists( $wc_meta_box_functions ) ) {
		include_once $wc_meta_box_functions;
		$wc_helpers_loaded = function_exists( 'woocommerce_wp_text_input' );
	}
}
compat_check(
	'S7.0 wc_admin_helpers_available',
	$wc_helpers_loaded,
	$wc_helpers_loaded ? 'loaded' : 'wc-meta-box-functions.php could not be loaded in this context'
);

if ( $wc_helpers_loaded ) {
	global $post;
	$post = get_post( $pid );
	ob_start();
	do_action( 'woocommerce_product_options_pricing' );
	$rendered = ob_get_clean();
	compat_check(
		'S7.1 simple_fields_render',
		false !== strpos( $rendered, '_mnswmc_currency_id' ) && false !== strpos( $rendered, '_mnswmc_regular_price' ),
		'len=' . strlen( $rendered )
	);

	$variation_post = get_post( $v1->get_id() );
	ob_start();
	do_action( 'woocommerce_product_after_variable_attributes', 0, array(), $variation_post );
	$rendered = ob_get_clean();
	compat_check(
		'S7.2 variation_fields_render',
		false !== strpos( $rendered, '_mnswmc_regular_price_0' ) && false !== strpos( $rendered, '_mnswmc_active_0' ),
		'len=' . strlen( $rendered )
	);
} else {
	compat_check( 'S7.1 simple_fields_render', false, 'skipped: WC admin form helpers unavailable in CLI context' );
	compat_check( 'S7.2 variation_fields_render', false, 'skipped: WC admin form helpers unavailable in CLI context' );
}

// ---------------------------------------------------------------------------
// S8 — PHP issues from the plugin in the debug log
// ---------------------------------------------------------------------------
$debug_log = WP_CONTENT_DIR . '/debug.log';
$issues    = array();
if ( file_exists( $debug_log ) ) {
	$lines = file( $debug_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	foreach ( $lines as $line ) {
		if ( preg_match( '/(Deprecated|Warning|Notice|Fatal error|Parse error)/', $line ) && false !== strpos( $line, 'ani-navasan-lite' ) ) {
			$issues[] = $line;
		}
	}
}
compat_check( 'S8.1 no_php_issues_from_plugin', empty( $issues ), implode( ' || ', array_slice( $issues, 0, 5 ) ) );

// ---------------------------------------------------------------------------
// S9 — rate key compatibility: legacy-key fallback + one-time migration
// ---------------------------------------------------------------------------
// S9.1 helper reads the standard key (state after S5: rate 3000)
compat_check(
	'S9.1 helper_reads_standard_key',
	ani_navasan_get_currency_rate( $cur ) == 3000.0,
	'got=' . ani_navasan_get_currency_rate( $cur )
);

// S9.2 fallback: legacy key only (pre-migration data)
delete_post_meta( $cur, '_mnswmc_currency_value' );
update_post_meta( $cur, '_mnswmc_rate', 1234.0 );
compat_check(
	'S9.2 helper_legacy_key_fallback',
	ani_navasan_get_currency_rate( $cur ) == 1234.0,
	'got=' . ani_navasan_get_currency_rate( $cur )
);

// S9.3 standard key takes precedence when both keys exist
update_post_meta( $cur, '_mnswmc_currency_value', 3000.0 );
compat_check(
	'S9.3 helper_standard_key_precedence',
	ani_navasan_get_currency_rate( $cur ) == 3000.0,
	'got=' . ani_navasan_get_currency_rate( $cur )
);

// S9.4 migration copies legacy -> standard and keeps the legacy key
// Clear the stale $_POST left by the compat_save_currency() calls above:
// wp_insert_post() fires the real save_post_mnswmc handler, which would
// otherwise persist the stale mnswmc_rate into the new post (in a real
// admin request $_POST is per-request, so this cannot happen there).
unset( $_POST['mnswmc_meta_nonce'], $_POST['mnswmc_symbol'], $_POST['mnswmc_rate'] );
$fb = wp_insert_post(
	array(
		'post_title'  => 'COMPAT MIGRATE ' . wp_rand( 1000, 9999 ),
		'post_type'   => 'mnswmc',
		'post_status' => 'publish',
	),
	true
);
update_post_meta( $fb, '_mnswmc_rate', 777.0 );
ani_navasan_lite_migrate_rate_key();
compat_check(
	'S9.4 migration_copies_legacy_to_standard',
	get_post_meta( $fb, '_mnswmc_currency_value', true ) == 777.0
		&& get_post_meta( $fb, '_mnswmc_rate', true ) == 777.0,
	'std=' . get_post_meta( $fb, '_mnswmc_currency_value', true ) . ' legacy=' . get_post_meta( $fb, '_mnswmc_rate', true )
);

// S9.5 migration is idempotent and never overwrites an existing standard value
update_post_meta( $fb, '_mnswmc_currency_value', 888.0 );
ani_navasan_lite_migrate_rate_key();
compat_check(
	'S9.5 migration_no_overwrite',
	get_post_meta( $fb, '_mnswmc_currency_value', true ) == 888.0,
	'std=' . get_post_meta( $fb, '_mnswmc_currency_value', true )
);

// ---------------------------------------------------------------------------
// Cleanup — remove all test data created by this run
// ---------------------------------------------------------------------------
wp_delete_post( $cur, true );
wp_delete_post( $pid, true );
wp_delete_post( $var_id, true );
wp_delete_post( $sale->get_id(), true );
wp_delete_post( $fb, true );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
$passed = 0;
$failed = 0;
foreach ( $GLOBALS['compat_results'] as $r ) {
	$r['pass'] ? $passed++ : $failed++;
}

echo json_encode(
	array(
		'label'   => getenv( 'COMPAT_LABEL' ) ? getenv( 'COMPAT_LABEL' ) : 'unknown',
		'php'     => PHP_VERSION,
		'wp'      => $wp_version,
		'wc'      => $wc_version,
		'passed'  => $passed,
		'failed'  => $failed,
		'total'   => count( $GLOBALS['compat_results'] ),
		'results' => $GLOBALS['compat_results'],
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
);

