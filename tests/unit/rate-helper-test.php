<?php
/**
 * Task 10 — Unit test: actual ani_navasan_get_currency_rate() from includes/functions.php.
 *
 * Verifies the standard-key-first / legacy-fallback contract.
 * Run: php tests/unit/rate-helper-test.php
 */
require_once __DIR__ . '/bootstrap.php';

$test_passed = 0;
$test_failed = 0;

echo "\n=== Rate helper: ani_navasan_get_currency_rate() (actual plugin code) ===\n";

// Standard key present
$GLOBALS['__test_meta'][100]['_mnswmc_currency_value'] = 2500;
test_check('standard key -> 2500.0', ani_navasan_get_currency_rate(100), 2500.0);

// Standard key empty, legacy present -> fallback
$GLOBALS['__test_meta'][101]['_mnswmc_currency_value'] = '';
$GLOBALS['__test_meta'][101]['_mnswmc_rate'] = 3000;
test_check('standard empty, legacy -> 3000.0', ani_navasan_get_currency_rate(101), 3000.0);

// Both present -> standard wins
$GLOBALS['__test_meta'][102]['_mnswmc_currency_value'] = 2500;
$GLOBALS['__test_meta'][102]['_mnswmc_rate'] = 3000;
test_check('both present -> standard 2500.0', ani_navasan_get_currency_rate(102), 2500.0);

// Neither present -> 0
test_check('neither -> 0', ani_navasan_get_currency_rate(999), 0);

// Standard key null, legacy present -> fallback
$GLOBALS['__test_meta'][103]['_mnswmc_currency_value'] = null;
$GLOBALS['__test_meta'][103]['_mnswmc_rate'] = 4000;
test_check('standard null, legacy -> 4000.0', ani_navasan_get_currency_rate(103), 4000.0);

// Non-numeric standard -> floatval
$GLOBALS['__test_meta'][104]['_mnswmc_currency_value'] = '1500.5';
test_check('string standard -> 1500.5', ani_navasan_get_currency_rate(104), 1500.5);

$ok = test_summary('Rate helper (PHP)');
exit($ok ? 0 : 1);
