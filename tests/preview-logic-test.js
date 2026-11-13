/**
 * Task 09c — Preview Logic Test
 *
 * This test verifies the independent preview behavior for variations.
 * Each preview value (.rate, .final, .sale-final, .min-final) should be
 * calculated independently from its own input.
 *
 * Run: node tests/preview-logic-test.js
 */

// ============================
// Helper: rounding (same as admin.js)
// ============================
function mnswmcRoundToThousand(value) {
    return Math.round((parseFloat(value) || 0) / 1000) * 1000;
}

// ============================
// Simulate the preview update logic (same as applyRateForVariation)
// ============================
function simulatePreviewUpdate(rate, priceVal, saleVal, minVal) {
    rate = parseFloat(rate) || 0;
    priceVal = parseFloat(priceVal) || 0;
    saleVal = parseFloat(saleVal) || 0;
    minVal = parseFloat(minVal) || 0;

    const preview = { rate: '-', final: '-', 'sale-final': '-', 'min-final': '-' };

    // آپدیت preview (هر بخش مستقل از ورودی خودش به‌روزرسانی می‌شود)
    if (rate > 0) {
        const final = (priceVal > 0) ? mnswmcRoundToThousand(rate * priceVal) : '';
        const saleFinal = (saleVal > 0) ? mnswmcRoundToThousand(rate * saleVal) : '';
        const minFinal = (minVal > 0) ? mnswmcRoundToThousand(rate * minVal) : '';

        preview.rate = rate ? rate.toLocaleString() : '-';
        preview.final = final ? final.toLocaleString() : '-';
        preview['sale-final'] = saleFinal ? saleFinal.toLocaleString() : '-';
        preview['min-final'] = minFinal ? minFinal.toLocaleString() : '-';
    } else {
        preview.rate = '-';
        preview.final = '-';
        preview['sale-final'] = '-';
        preview['min-final'] = '-';
    }

    return preview;
}

// ============================
// Test helper
// ============================
let passed = 0;
let failed = 0;

function check(name, actual, expected) {
    const actualStr = JSON.stringify(actual);
    const expectedStr = JSON.stringify(expected);
    if (actualStr === expectedStr) {
        console.log(`  ✅ PASS: ${name}`);
        passed++;
    } else {
        console.log(`  ❌ FAIL: ${name}`);
        console.log(`     Expected: ${expectedStr}`);
        console.log(`     Actual:   ${actualStr}`);
        failed++;
    }
}

// ============================
// Scenario A: All inputs valid
// ============================
console.log('\n=== Scenario A: All inputs valid ===');
let p = simulatePreviewUpdate(2000, 100, 90, 86);
check('A: rate', p.rate, '2,000');
check('A: final', p.final, '200,000');
check('A: sale-final', p['sale-final'], '180,000');
check('A: min-final', p['min-final'], '172,000');

// ============================
// Scenario B: Regular price cleared (priceVal=0)
// ============================
console.log('\n=== Scenario B: Regular price cleared ===');
p = simulatePreviewUpdate(2000, 0, 90, 86);
check('B: rate preserved', p.rate, '2,000');
check('B: final cleared', p.final, '-');
check('B: sale-final preserved', p['sale-final'], '180,000');
check('B: min-final preserved', p['min-final'], '172,000');

// ============================
// Scenario C: Sale price cleared (saleVal=0)
// ============================
console.log('\n=== Scenario C: Sale price cleared ===');
p = simulatePreviewUpdate(2000, 100, 0, 86);
check('C: rate preserved', p.rate, '2,000');
check('C: final preserved', p.final, '200,000');
check('C: sale-final cleared', p['sale-final'], '-');
check('C: min-final preserved', p['min-final'], '172,000');

// ============================
// Scenario D: Min price cleared (minVal=0)
// ============================
console.log('\n=== Scenario D: Min price cleared ===');
p = simulatePreviewUpdate(2000, 100, 90, 0);
check('D: rate preserved', p.rate, '2,000');
check('D: final preserved', p.final, '200,000');
check('D: sale-final preserved', p['sale-final'], '180,000');
check('D: min-final cleared', p['min-final'], '-');

// ============================
// Scenario E: Rate changed
// ============================
console.log('\n=== Scenario E: Rate changed ===');
p = simulatePreviewUpdate(2500, 100, 90, 86);
check('E: rate updated', p.rate, '2,500');
check('E: final recalculated', p.final, '250,000');
check('E: sale-final recalculated', p['sale-final'], '225,000');
check('E: min-final recalculated', p['min-final'], '215,000');

// Scenario E2: Rate changed with empty price
p = simulatePreviewUpdate(2500, 0, 90, 86);
check('E2: rate updated', p.rate, '2,500');
check('E2: final stays cleared', p.final, '-');
check('E2: sale-final recalculated', p['sale-final'], '225,000');
check('E2: min-final recalculated', p['min-final'], '215,000');

// ============================
// Scenario F: Multiple variations (independence)
// ============================
console.log('\n=== Scenario F: Multiple variations (independence) ===');
// Variation 0: all valid
const p0 = simulatePreviewUpdate(2000, 100, 90, 86);
// Variation 1: price cleared
const p1 = simulatePreviewUpdate(2000, 0, 90, 86);
check('F: var0 final', p0.final, '200,000');
check('F: var1 final (independent)', p1.final, '-');
check('F: var1 rate (independent)', p1.rate, '2,000');

// ============================
// Scenario G: No rate (rate=0)
// ============================
console.log('\n=== Scenario G: No rate ===');
p = simulatePreviewUpdate(0, 100, 90, 86);
check('G: rate cleared', p.rate, '-');
check('G: final cleared', p.final, '-');
check('G: sale-final cleared', p['sale-final'], '-');
check('G: min-final cleared', p['min-final'], '-');

// ============================
// Summary
// ============================
console.log('\n' + '='.repeat(50));
console.log(`Total: ${passed + failed}, Passed: ${passed}, Failed: ${failed}`);
console.log('='.repeat(50));

if (failed > 0) {
    process.exit(1);
} else {
    console.log('✅ All preview logic tests passed!');
    process.exit(0);
}
