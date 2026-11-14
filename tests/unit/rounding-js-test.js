/**
 * Task 10 — Unit test: actual mnswmcRoundToThousand() extracted from assets/js/admin.js.
 *
 * This tests the REAL plugin JS code (extracted from admin.js), not a simulation.
 * Uses EXPLICIT expected values (not derived from the function under test).
 *
 * Run: node tests/unit/rounding-js-test.js
 */
const fs = require('fs');
const path = require('path');

let passed = 0;
let failed = 0;

function check(name, actual, expected) {
    if (actual === expected) {
        console.log(`  PASS: ${name}`);
        passed++;
    } else {
        console.log(`  FAIL: ${name}`);
        console.log(`     Expected: ${expected}`);
        console.log(`     Actual:   ${actual}`);
        failed++;
    }
}

// Extract the ACTUAL function from admin.js
const adminJsPath = path.join(__dirname, '../../assets/js/admin.js');
const adminJs = fs.readFileSync(adminJsPath, 'utf8');
const match = adminJs.match(/function mnswmcRoundToThousand\(value\)\s*\{[\s\S]*?\n\s*\}/);
if (!match) {
    console.error('FAIL: could not extract mnswmcRoundToThousand from admin.js');
    process.exit(1);
}
const mnswmcRoundToThousand = eval('(' + match[0] + ')');

console.log('\n=== Rounding: mnswmcRoundToThousand() (actual admin.js code) ===');

// Same explicit vectors as the PHP rounding test
check('0 -> 0', mnswmcRoundToThousand(0), 0);
check('1000 -> 1000', mnswmcRoundToThousand(1000), 1000);
check('2000 -> 2000', mnswmcRoundToThousand(2000), 2000);
check('100000 -> 100000', mnswmcRoundToThousand(100000), 100000);
check('1 -> 0', mnswmcRoundToThousand(1), 0);
check('499 -> 0', mnswmcRoundToThousand(499), 0);
check('500 -> 1000 (half up)', mnswmcRoundToThousand(500), 1000);
check('999 -> 1000', mnswmcRoundToThousand(999), 1000);
check('1499 -> 1000', mnswmcRoundToThousand(1499), 1000);
check('1500 -> 2000 (half up)', mnswmcRoundToThousand(1500), 2000);
check('2499 -> 2000', mnswmcRoundToThousand(2499), 2000);
check('2500 -> 3000 (half up)', mnswmcRoundToThousand(2500), 3000);
check('1234.5 -> 1000', mnswmcRoundToThousand(1234.5), 1000);
check('1750 -> 2000', mnswmcRoundToThousand(1750), 2000);
check('2250 -> 2000', mnswmcRoundToThousand(2250), 2000);
check('1499.5 -> 1000', mnswmcRoundToThousand(1499.5), 1000);
check('1500.5 -> 2000', mnswmcRoundToThousand(1500.5), 2000);
check('999999 -> 1000000', mnswmcRoundToThousand(999999), 1000000);
check('1234567 -> 1235000', mnswmcRoundToThousand(1234567), 1235000);
check('999999999 -> 1000000000', mnswmcRoundToThousand(999999999), 1000000000);
check("'1234.5' -> 1000", mnswmcRoundToThousand('1234.5'), 1000);
check("'abc' -> 0", mnswmcRoundToThousand('abc'), 0);
check("'' -> 0", mnswmcRoundToThousand(''), 0);
check('null -> 0', mnswmcRoundToThousand(null), 0);

console.log('\n' + '='.repeat(50));
console.log(`Rounding (JS): Total: ${passed + failed}, Passed: ${passed}, Failed: ${failed}`);
console.log('='.repeat(50));

if (failed > 0) {
    process.exit(1);
} else {
    console.log('All JS rounding tests passed!');
    process.exit(0);
}
