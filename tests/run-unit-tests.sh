#!/usr/bin/env bash
#
# Task 10 — Ani Navasan Lite unit-test runner.
#
# Runs the full local unit-test suite (no WordPress site required):
#   1. PHP unit tests (rounding, rate helper, migrations)
#   2. JS unit test (mnswmcRoundToThousand extracted from the real admin.js)
#   3. PHP <-> JS rounding consistency (shared vectors, diffed)
#
# Usage:
#   bash tests/run-unit-tests.sh
#
# Exit code 0 = all green, non-zero = at least one failure.
#
set -u

# Resolve the repo root (this script lives in tests/)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
UNIT="${SCRIPT_DIR}/unit"

pass=0
fail=0
declare -a results

run() {
    local label="$1"; shift
    echo ""
    echo "------------------------------------------------------------"
    echo ">> ${label}"
    echo "------------------------------------------------------------"
    if "$@"; then
        results+=("PASS  ${label}")
        pass=$((pass + 1))
    else
        results+=("FAIL  ${label}")
        fail=$((fail + 1))
    fi
}

echo "============================================================"
echo " Ani Navasan Lite — Unit Test Suite"
echo " Root: ${ROOT}"
echo "============================================================"

# 1. PHP unit tests
run "PHP: rounding"        php "${UNIT}/rounding-test.php"
run "PHP: rate helper"     php "${UNIT}/rate-helper-test.php"
run "PHP: migrations"      php "${UNIT}/migration-test.php"
run "PHP: dependence type" php "${UNIT}/dependence-type-test.php"

# 2. JS unit test
run "JS: rounding"         node "${UNIT}/rounding-js-test.js"

# 3. PHP <-> JS consistency (shared vectors)
php "${UNIT}/consistency-php.php" > /tmp/t10-consistency-php.txt 2>&1
node "${UNIT}/consistency-js.js" > /tmp/t10-consistency-js.txt 2>&1
if diff -q /tmp/t10-consistency-php.txt /tmp/t10-consistency-js.txt > /dev/null; then
    results+=("PASS  PHP<->JS consistency")
    pass=$((pass + 1))
    echo ""
    echo "------------------------------------------------------------"
    echo ">> PHP<->JS consistency"
    echo "------------------------------------------------------------"
    echo "  PASS: PHP and JS rounding outputs are identical"
else
    results+=("FAIL  PHP<->JS consistency")
    fail=$((fail + 1))
    echo ""
    echo "------------------------------------------------------------"
    echo ">> PHP<->JS consistency"
    echo "------------------------------------------------------------"
    echo "  FAIL: PHP and JS rounding outputs differ"
    diff /tmp/t10-consistency-php.txt /tmp/t10-consistency-js.txt
fi

# Summary
echo ""
echo "============================================================"
echo " UNIT TEST SUMMARY"
echo "============================================================"
for r in "${results[@]}"; do
    echo "  ${r}"
done
echo "------------------------------------------------------------"
echo " Total: $((pass + fail)), Passed: ${pass}, Failed: ${fail}"
echo "============================================================"

if [ "${fail}" -ne 0 ]; then
    exit 1
fi
exit 0
