<?php
/**
 * Task 11 — Unit test: ani_navasan_resolve_dependence_type() from
 * includes/functions.php (the shared dependence-type resolver used by both
 * the simple-product and variation save handlers).
 *
 * Run: php tests/unit/dependence-type-test.php
 */
require_once __DIR__ . '/bootstrap.php';

$test_passed = 0;
$test_failed = 0;

echo "\n=== Dependence type: ani_navasan_resolve_dependence_type() (actual plugin code) ===\n";

// A valid posted value is always accepted (it wins over the existing value).
test_check('posted simple accepted', ani_navasan_resolve_dependence_type('simple', ''), 'simple');
test_check('posted advanced accepted', ani_navasan_resolve_dependence_type('advanced', ''), 'advanced');
test_check('posted simple wins over existing advanced', ani_navasan_resolve_dependence_type('simple', 'advanced'), 'simple');
test_check('posted advanced wins over existing simple', ani_navasan_resolve_dependence_type('advanced', 'simple'), 'advanced');

// An empty/invalid posted value preserves a valid existing value.
test_check('empty posted preserves existing simple', ani_navasan_resolve_dependence_type('', 'simple'), 'simple');
test_check('empty posted preserves existing advanced', ani_navasan_resolve_dependence_type('', 'advanced'), 'advanced');
test_check('legacy variable posted preserves existing advanced', ani_navasan_resolve_dependence_type('variable', 'advanced'), 'advanced');
test_check('bogus posted preserves existing simple', ani_navasan_resolve_dependence_type('bogus', 'simple'), 'simple');

// Wrong case is rejected (strict comparison) and falls back to existing/default.
test_check('wrong-case Simple rejected, preserves existing', ani_navasan_resolve_dependence_type('Simple', 'advanced'), 'advanced');

// No valid posted and no valid existing value -> default 'simple'.
test_check('empty posted + no existing -> default simple', ani_navasan_resolve_dependence_type('', ''), 'simple');
test_check('legacy variable posted + no existing -> default simple', ani_navasan_resolve_dependence_type('variable', ''), 'simple');
test_check('bogus posted + invalid existing -> default simple', ani_navasan_resolve_dependence_type('bogus', 'variable'), 'simple');

$ok = test_summary('Dependence type resolver (PHP)');
exit($ok ? 0 : 1);
