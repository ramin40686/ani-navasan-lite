<?php
/**
 * Task 10 — Integration + Regression Test Suite for Ani Navasan Lite.
 *
 * Run against the AUTHORIZED local site (anishop-package-test.local) ONLY:
 *
 *   cd ~/Local\ Sites/anishop-package-test/app/public
 *   wp eval-file /path/to/tests/integration/integration-test.php
 *
 * Uses ONLY the authorized test products (17613, 17615, 17616, 17617) and
 * test currencies (17654, 17655). State is backed up before the tests and
 * restored byte-for-byte afterwards (direct DB, no hooks fired).
 *
 * Scenarios:
 *   I0  Environment + plugin load
 *   I1  Simple product save handler (17613)
 *   I2  Variation save handler (17616, 17617) + isolation
 *   I3  Decimal rounding hook (17613)
 *   [restore to baseline]
 *   I4a Recalculation — currency A (17654) rate change 2000 -> 2500 -> 2000
 *   I4b Recalculation — currency B (17655) rate change 2000 -> 2500 -> 2000
 *   I5  Attribute serialization regression (17615)
 *   [restore to baseline]
 *   I6  Final state verification (restore round-trip)
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
// Backup / Restore (direct DB, no hooks fired)
// ---------------------------------------------------------------------------
function t10_backup_state($post_ids) {
    global $wpdb;
    $backup = [];
    foreach ($post_ids as $id) {
        $post = get_post($id);
        if (!$post) {
            continue;
        }
        $backup[$id]['post'] = [
            'post_title'   => $post->post_title,
            'post_content' => $post->post_content,
            'post_status'  => $post->post_status,
            'post_parent'  => $post->post_parent,
            'menu_order'   => $post->menu_order,
        ];
        $backup[$id]['meta'] = $wpdb->get_results(
            $wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $id)
        );
    }
    return $backup;
}

function t10_restore_state($backup) {
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    try {
        foreach ($backup as $id => $data) {
            $wpdb->update($wpdb->posts, [
                'post_title'   => $data['post']['post_title'],
                'post_content' => $data['post']['post_content'],
                'post_status'  => $data['post']['post_status'],
                'post_parent'  => $data['post']['post_parent'],
                'menu_order'   => $data['post']['menu_order'],
            ], ['ID' => $id]);

            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id = %d", $id));
            foreach ($data['meta'] as $row) {
                $wpdb->insert($wpdb->postmeta, [
                    'post_id'    => $id,
                    'meta_key'   => $row->meta_key,
                    'meta_value' => $row->meta_value,
                ]);
            }
        }
        $wpdb->query('COMMIT');
        foreach (array_keys($backup) as $id) {
            clean_post_cache($id);
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($id);
            }
        }
        return true;
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return false;
    }
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
// Backup the authorized state (products + currencies)
// ===========================================================================
$t10_backup = t10_backup_state([
    T10_SIMPLE_PRODUCT,
    T10_VARIABLE_PRODUCT,
    T10_VARIATION_A,
    T10_VARIATION_B,
    T10_CURRENCY_A,
    T10_CURRENCY_B,
]);
echo "\n[backup] captured " . count($t10_backup) . " posts\n";

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
t10_check('I2.6 17616 regular price (2000*100=200000)', t10_regular(T10_VARIATION_A), '200000');
t10_check('I2.7 17616 sale price (empty)', t10_sale(T10_VARIATION_A), '');
t10_check('I2.8 17616 _torob_scraper_min_price (2000*86=172000)', get_post_meta(T10_VARIATION_A, '_torob_scraper_min_price', true), '172000');

// Variation B (17617)
t10_check('I2.9 17617 _mnswmc_active', get_post_meta(T10_VARIATION_B, '_mnswmc_active', true), 'yes');
t10_check('I2.10 17617 _mnswmc_currency_id', get_post_meta(T10_VARIATION_B, '_mnswmc_currency_id', true), (string) T10_CURRENCY_B);
t10_check('I2.11 17617 _mnswmc_regular_price', get_post_meta(T10_VARIATION_B, '_mnswmc_regular_price', true), '200');
t10_check('I2.12 17617 _mnswmc_sale_price', get_post_meta(T10_VARIATION_B, '_mnswmc_sale_price', true), '180');
t10_check('I2.13 17617 _mnswmc_dependence_type', get_post_meta(T10_VARIATION_B, '_mnswmc_dependence_type', true), 'simple');
t10_check('I2.14 17617 regular price (2000*200=400000)', t10_regular(T10_VARIATION_B), '400000');
t10_check('I2.15 17617 sale price (2000*180=360000)', t10_sale(T10_VARIATION_B), '360000');
t10_check('I2.16 17617 _torob_scraper_min_price (2000*170=340000)', get_post_meta(T10_VARIATION_B, '_torob_scraper_min_price', true), '340000');

// Isolation: the two variations keep distinct currency ids
t10_check('I2.17 isolation: distinct currency ids', get_post_meta(T10_VARIATION_A, '_mnswmc_currency_id', true) !== get_post_meta(T10_VARIATION_B, '_mnswmc_currency_id', true), true);

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
// Restore to baseline before the recalculation scenarios
// ===========================================================================
t10_restore_state($t10_backup);
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
// ===========================================================================
echo "\n=== I5: Attribute serialization regression (17615) ===\n";

$attrs = get_post_meta(T10_VARIABLE_PRODUCT, '_product_attributes', true);
if ($attrs === '') {
    echo "  SKIP: 17615 has no _product_attributes (nothing to verify)\n";
} else {
    t10_check('I5.1 _product_attributes is an array (not double-serialized)', is_array($attrs), true);
    t10_check('I5.2 _product_attributes is not a serialized string', !is_string($attrs), true);
}

// ===========================================================================
// Restore to baseline (final)
// ===========================================================================
$t10_restore_ok = t10_restore_state($t10_backup);
t10_check('I6.0 restore succeeded', $t10_restore_ok, true);

// ===========================================================================
// I6 — Final state verification (restore round-trip)
// ===========================================================================
echo "\n=== I6: Final state verification (restore round-trip) ===\n";

foreach ($t10_backup as $id => $data) {
    $post = get_post($id);
    t10_check("I6.{$id} post_title", $post->post_title, $data['post']['post_title']);
    t10_check("I6.{$id} post_status", $post->post_status, $data['post']['post_status']);

    // Compare every meta row (key + value) against the backup
    $current = get_post_meta($id);
    $backup_meta = [];
    foreach ($data['meta'] as $row) {
        $backup_meta[$row->meta_key][] = $row->meta_value;
    }
    // Normalize current meta to key => array of values
    $current_meta = [];
    foreach ($current as $key => $values) {
        $current_meta[$key] = is_array($values) ? $values : [$values];
    }
    t10_check("I6.{$id} meta keys match", array_keys($current_meta) == array_keys($backup_meta), true);
    foreach ($backup_meta as $key => $values) {
        sort($values);
        $cur = isset($current_meta[$key]) ? $current_meta[$key] : [];
        sort($cur);
        t10_check("I6.{$id} meta[{$key}] values match", $cur, $values);
    }
}

// ===========================================================================
// Summary
// ===========================================================================
$t10_passed = $GLOBALS['t10_passed'];
$t10_failed = $GLOBALS['t10_failed'];
$total = $t10_passed + $t10_failed;
echo "\n" . str_repeat('=', 50) . "\n";
echo "INTEGRATION SUITE: Total: {$total}, Passed: {$t10_passed}, Failed: {$t10_failed}\n";
echo str_repeat('=', 50) . "\n";

exit($t10_failed === 0 ? 0 : 1);

