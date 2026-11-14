/**
 * Task 10 — JS side of the PHP<->JS rounding consistency check.
 *
 * Reads the shared input vectors and prints "input=result" lines using the
 * ACTUAL mnswmcRoundToThousand() extracted from assets/js/admin.js.
 * The runner diffs this output against the PHP output.
 *
 * Run: node tests/unit/consistency-js.js
 */
const fs = require('fs');
const path = require('path');

const adminJs = fs.readFileSync(path.join(__dirname, '../../assets/js/admin.js'), 'utf8');
const match = adminJs.match(/function mnswmcRoundToThousand\(value\)\s*\{[\s\S]*?\n\s*\}/);
if (!match) {
    console.error('FAIL: could not extract mnswmcRoundToThousand from admin.js');
    process.exit(1);
}
const mnswmcRoundToThousand = eval('(' + match[0] + ')');

const vectors = JSON.parse(fs.readFileSync(path.join(__dirname, 'consistency-vectors.json'), 'utf8'));
for (const v of vectors) {
    console.log(v + '=' + mnswmcRoundToThousand(v));
}
