<?php
/**
 * Task 10 — Unit test: actual migration functions from includes/functions.php.
 *
 * - ani_navasan_lite_migrate_rate_key()
 * - ani_navasan_lite_migrate_dependence_type()
 *
 * Run: php tests/unit/migration-test.php
 */
require_once __DIR__ . '/bootstrap.php';

$test_passed = 0;
$test_failed = 0;

echo "\n=== Migration: ani_navasan_lite_migrate_rate_key() (actual plugin code) ===\n";

// Setup: currency 200 has legacy rate, no standard key
$GLOBALS['__test_meta'][200]['_mnswmc_rate'] = 2500;
$GLOBALS['wpdb']->mnswmc_ids = [200];
ani_navasan_lite_migrate_rate_key();
test_check('legacy -> standard copied', get_post_meta(200, '_mnswmc_currency_value', true), 2500);
test_check('legacy key preserved', get_post_meta(200, '_mnswmc_rate', true), 2500);
test_check('option set', get_option('ani_navasan_lite_migrated_rate_key'), 1);

// Idempotency: re-run, no change
ani_navasan_lite_migrate_rate_key();
test_check('idempotent (no overwrite)', get_post_meta(200, '_mnswmc_currency_value', true), 2500);

// Never overwrites an existing standard value
$GLOBALS['__test_meta'][201]['_mnswmc_rate'] = 3000;
$GLOBALS['__test_meta'][201]['_mnswmc_currency_value'] = 2500;
$GLOBALS['wpdb']->mnswmc_ids = [201];
ani_navasan_lite_migrate_rate_key();
test_check('existing standard not overwritten', get_post_meta(201, '_mnswmc_currency_value', true), 2500);

echo "\n=== Migration: ani_navasan_lite_migrate_dependence_type() (actual plugin code) ===\n";

// Setup: post 300 has legacy 'variable'
$GLOBALS['__test_meta'][300]['_mnswmc_dependence_type'] = 'variable';
$GLOBALS['wpdb']->dep_type_variable_ids = [300];
ani_navasan_lite_migrate_dependence_type();
test_check('variable -> advanced', get_post_meta(300, '_mnswmc_dependence_type', true), 'advanced');
test_check('dep option set', get_option('ani_navasan_lite_migrated_dependence_type'), 1);

// Idempotency
ani_navasan_lite_migrate_dependence_type();
test_check('idempotent', get_post_meta(300, '_mnswmc_dependence_type', true), 'advanced');

// simple/advanced unchanged (not in the variable list)
$GLOBALS['__test_meta'][301]['_mnswmc_dependence_type'] = 'simple';
$GLOBALS['__test_meta'][302]['_mnswmc_dependence_type'] = 'advanced';
test_check('simple unchanged', get_post_meta(301, '_mnswmc_dependence_type', true), 'simple');
test_check('advanced unchanged', get_post_meta(302, '_mnswmc_dependence_type', true), 'advanced');

$ok = test_summary('Migrations (PHP)');
exit($ok ? 0 : 1);
