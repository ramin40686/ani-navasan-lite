<?php
/**
 * Task 10 — Unit test bootstrap.
 *
 * Loads the ACTUAL plugin code (includes/functions.php) with minimal WordPress
 * stubs so the real functions can be tested without a full WP environment.
 *
 * This is NOT a simulation: the functions under test are the real plugin
 * functions, included from the plugin source tree.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/');
}

// ---------------------------------------------------------------------------
// In-memory stores
// ---------------------------------------------------------------------------
$GLOBALS['__test_meta']    = [];
$GLOBALS['__test_options'] = [];

// ---------------------------------------------------------------------------
// WP function stubs (only those used by includes/functions.php)
// ---------------------------------------------------------------------------
if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
        $GLOBALS['__test_actions'][$hook][] = [
            'callback' => $callback,
            'priority' => $priority,
            'args'     => $accepted_args,
        ];
        return true;
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key = '', $single = false) {
        if ($key === '') {
            return isset($GLOBALS['__test_meta'][$post_id]) ? $GLOBALS['__test_meta'][$post_id] : [];
        }
        return isset($GLOBALS['__test_meta'][$post_id][$key]) ? $GLOBALS['__test_meta'][$post_id][$key] : '';
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $key, $value) {
        $GLOBALS['__test_meta'][$post_id][$key] = $value;
        return true;
    }
}

if (!function_exists('delete_post_meta')) {
    function delete_post_meta($post_id, $key) {
        unset($GLOBALS['__test_meta'][$post_id][$key]);
        return true;
    }
}

if (!function_exists('update_option')) {
    function update_option($key, $value) {
        $GLOBALS['__test_options'][$key] = $value;
        return true;
    }
}

if (!function_exists('get_option')) {
    function get_option($key, $default = false) {
        return isset($GLOBALS['__test_options'][$key]) ? $GLOBALS['__test_options'][$key] : $default;
    }
}

// ---------------------------------------------------------------------------
// Fake $wpdb for migration tests
// ---------------------------------------------------------------------------
class Test_Wpdb {
    public $posts      = 'wp_posts';
    public $postmeta   = 'wp_postmeta';
    public $mnswmc_ids = [];
    public $dep_type_variable_ids = [];

    public function get_col($sql) {
        if (strpos($sql, "post_type = 'mnswmc'") !== false) {
            return $this->mnswmc_ids;
        }
        if (strpos($sql, '_mnswmc_dependence_type') !== false) {
            return $this->dep_type_variable_ids;
        }
        return [];
    }
}
$GLOBALS['wpdb'] = new Test_Wpdb();

// ---------------------------------------------------------------------------
// Shared test helpers
// ---------------------------------------------------------------------------
function test_check($name, $actual, $expected) {
    global $test_passed, $test_failed;
    if ($actual === $expected) {
        echo "  PASS: {$name}\n";
        $test_passed++;
    } else {
        echo "  FAIL: {$name}\n";
        echo "     Expected: " . var_export($expected, true) . "\n";
        echo "     Actual:   " . var_export($actual, true) . "\n";
        $test_failed++;
    }
}

function test_summary($label) {
    global $test_passed, $test_failed;
    $total = $test_passed + $test_failed;
    echo str_repeat('=', 50) . "\n";
    echo "{$label}: Total: {$total}, Passed: {$test_passed}, Failed: {$test_failed}\n";
    echo str_repeat('=', 50) . "\n";
    return $test_failed === 0;
}

// ---------------------------------------------------------------------------
// Load the ACTUAL plugin functions
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../../includes/functions.php';
