<?php
/**
 * Task 10 — Unit test: actual ani_navasan_round_to_thousand() from includes/functions.php.
 *
 * Uses EXPLICIT expected values (not derived from the function under test).
 * Run: php tests/unit/rounding-test.php
 */
require_once __DIR__ . '/bootstrap.php';

$test_passed = 0;
$test_failed = 0;

echo "\n=== Rounding: ani_navasan_round_to_thousand() (actual plugin code) ===\n";

// Exact multiples of 1000
test_check('0 -> 0', ani_navasan_round_to_thousand(0), 0);
test_check('1000 -> 1000', ani_navasan_round_to_thousand(1000), 1000);
test_check('2000 -> 2000', ani_navasan_round_to_thousand(2000), 2000);
test_check('100000 -> 100000', ani_navasan_round_to_thousand(100000), 100000);

// Below 1000
test_check('1 -> 0', ani_navasan_round_to_thousand(1), 0);
test_check('499 -> 0', ani_navasan_round_to_thousand(499), 0);
test_check('500 -> 1000 (half up)', ani_navasan_round_to_thousand(500), 1000);
test_check('999 -> 1000', ani_navasan_round_to_thousand(999), 1000);

// Boundaries (half-up rounding)
test_check('1499 -> 1000', ani_navasan_round_to_thousand(1499), 1000);
test_check('1500 -> 2000 (half up)', ani_navasan_round_to_thousand(1500), 2000);
test_check('2499 -> 2000', ani_navasan_round_to_thousand(2499), 2000);
test_check('2500 -> 3000 (half up)', ani_navasan_round_to_thousand(2500), 3000);

// Decimals
test_check('1234.5 -> 1000', ani_navasan_round_to_thousand(1234.5), 1000);
test_check('1750 -> 2000', ani_navasan_round_to_thousand(1750), 2000);
test_check('2250 -> 2000', ani_navasan_round_to_thousand(2250), 2000);
test_check('1499.5 -> 1000', ani_navasan_round_to_thousand(1499.5), 1000);
test_check('1500.5 -> 2000', ani_navasan_round_to_thousand(1500.5), 2000);

// Large values
test_check('999999 -> 1000000', ani_navasan_round_to_thousand(999999), 1000000);
test_check('1234567 -> 1235000', ani_navasan_round_to_thousand(1234567), 1235000);
test_check('999999999 -> 1000000000', ani_navasan_round_to_thousand(999999999), 1000000000);

// String / invalid input
test_check("'1234.5' -> 1000", ani_navasan_round_to_thousand('1234.5'), 1000);
test_check("'abc' -> 0", ani_navasan_round_to_thousand('abc'), 0);
test_check("'' -> 0", ani_navasan_round_to_thousand(''), 0);
test_check('null -> 0', ani_navasan_round_to_thousand(null), 0);

$ok = test_summary('Rounding (PHP)');
exit($ok ? 0 : 1);
