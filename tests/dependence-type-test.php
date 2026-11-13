<?php
/**
 * Task 09c — Dependence Type Default Logic Test
 *
 * This test verifies the default value logic for _mnswmc_dependence_type.
 * The default should be 'simple' for both simple products and variations.
 * A previously saved valid value must be preserved.
 *
 * Run: php tests/dependence-type-test.php
 */

// ============================
// Simulate the save handler logic (same as admin/metabox.php)
// ============================
function simulateSaveHandler($post_value, $existing_meta) {
    // $post_value: the value from $_POST (or null if not set)
    // $existing_meta: the existing metadata value (or '' if not set)

    $dependence_type = ($post_value !== null) ? $post_value : '';

    if (!in_array($dependence_type, array('simple', 'advanced'), true)) {
        $existing_type = $existing_meta;
        $dependence_type = in_array($existing_type, array('simple', 'advanced'), true) ? $existing_type : 'simple';
    }

    return $dependence_type;
}

// ============================
// Simulate the rendering logic (same as admin/metabox.php)
// ============================
function simulateRendering($existing_meta) {
    $current_type = $existing_meta;

    if ($current_type === 'variable') {
        $current_type = 'advanced'; // legacy
    }

    if (!in_array($current_type, array('simple', 'advanced'), true)) {
        $current_type = 'simple'; // default
    }

    return $current_type;
}

// ============================
// Test helper
// ============================
$passed = 0;
$failed = 0;

function check($name, $actual, $expected) {
    global $passed, $failed;
    if ($actual === $expected) {
        echo "  ✅ PASS: {$name}\n";
        $passed++;
    } else {
        echo "  ❌ FAIL: {$name}\n";
        echo "     Expected: " . var_export($expected, true) . "\n";
        echo "     Actual:   " . var_export($actual, true) . "\n";
        $failed++;
    }
}

// ============================
// Save handler tests
// ============================
echo "\n=== Save Handler: Simple Product ===\n";

// No POST, no existing meta → default 'simple'
check('No POST, no meta → simple', simulateSaveHandler(null, ''), 'simple');

// No POST, existing 'advanced' → preserved
check('No POST, existing advanced → advanced', simulateSaveHandler(null, 'advanced'), 'advanced');

// No POST, existing 'simple' → preserved
check('No POST, existing simple → simple', simulateSaveHandler(null, 'simple'), 'simple');

// No POST, existing 'variable' (legacy) → default 'simple'
check('No POST, existing variable → simple', simulateSaveHandler(null, 'variable'), 'simple');

// POST 'advanced', no existing meta → 'advanced'
check('POST advanced, no meta → advanced', simulateSaveHandler('advanced', ''), 'advanced');

// POST 'simple', no existing meta → 'simple'
check('POST simple, no meta → simple', simulateSaveHandler('simple', ''), 'simple');

// POST 'advanced', existing 'simple' → 'advanced' (explicit selection wins)
check('POST advanced, existing simple → advanced', simulateSaveHandler('advanced', 'simple'), 'advanced');

// POST 'simple', existing 'advanced' → 'simple' (explicit selection wins)
check('POST simple, existing advanced → simple', simulateSaveHandler('simple', 'advanced'), 'simple');

// POST invalid value, existing 'advanced' → preserved
check('POST invalid, existing advanced → advanced', simulateSaveHandler('invalid', 'advanced'), 'advanced');

// POST invalid value, no existing meta → default 'simple'
check('POST invalid, no meta → simple', simulateSaveHandler('invalid', ''), 'simple');

echo "\n=== Save Handler: Variation ===\n";

// No POST, no existing meta → default 'simple'
check('No POST, no meta → simple', simulateSaveHandler(null, ''), 'simple');

// No POST, existing 'advanced' → preserved
check('No POST, existing advanced → advanced', simulateSaveHandler(null, 'advanced'), 'advanced');

// POST 'advanced', no existing meta → 'advanced'
check('POST advanced, no meta → advanced', simulateSaveHandler('advanced', ''), 'advanced');

// POST invalid value, existing 'advanced' → preserved
check('POST invalid, existing advanced → advanced', simulateSaveHandler('invalid', 'advanced'), 'advanced');

// ============================
// Rendering tests
// ============================
echo "\n=== Rendering: Simple Product ===\n";

// No existing meta → default 'simple'
check('No meta → simple', simulateRendering(''), 'simple');

// Existing 'advanced' → preserved
check('Existing advanced → advanced', simulateRendering('advanced'), 'advanced');

// Existing 'simple' → preserved
check('Existing simple → simple', simulateRendering('simple'), 'simple');

// Existing 'variable' (legacy) → 'advanced'
check('Existing variable → advanced', simulateRendering('variable'), 'advanced');

// Existing invalid value → default 'simple'
check('Existing invalid → simple', simulateRendering('invalid'), 'simple');

echo "\n=== Rendering: Variation ===\n";

// No existing meta → default 'simple'
check('No meta → simple', simulateRendering(''), 'simple');

// Existing 'advanced' → preserved
check('Existing advanced → advanced', simulateRendering('advanced'), 'advanced');

// Existing 'simple' → preserved
check('Existing simple → simple', simulateRendering('simple'), 'simple');

// Existing 'variable' (legacy) → 'advanced'
check('Existing variable → advanced', simulateRendering('variable'), 'advanced');

// ============================
// Summary
// ============================
echo "\n" . str_repeat('=', 50) . "\n";
echo "Total: " . ($passed + $failed) . ", Passed: {$passed}, Failed: {$failed}\n";
echo str_repeat('=', 50) . "\n";

if ($failed > 0) {
    exit(1);
} else {
    echo "✅ All dependence type tests passed!\n";
    exit(0);
}
