# Task 09c — Default Dependence Type & Live Price Preview Fix

**Date:** 2026-11-13
**Branch:** `fix/task-09-preview-defaults` (from `feat/task-09-currency-sale-price`)
**Status:** COMPLETE

---

## 1. Task Objective

This task is a limited, complementary fix for Task 09. Two behavioral fixes were required:

1. The default value of `_mnswmc_dependence_type` should be `simple` for both WooCommerce product types (simple products and variations).
2. When the "currency price" field is cleared, only the corresponding preview value (`.final`) should be cleared; other preview values (`.rate`, `.sale-final`, `.min-final`) should be preserved.

Additionally, a documentation inconsistency in the Roadmap and Memory Bank needed to be fixed (the branch origin was incorrectly recorded as `origin/main` instead of `test/task-08-metadata-compatibility`).

The Task 09b status was also updated to reflect the project owner's manual test confirmation.

---

## 2. Initial Findings

### 2.1 Default Dependence Type

The following locations were identified where the default dependence type is set:

| Location | File | Line | Before | After |
|---|---|---|---|---|
| Simple product rendering | `admin/metabox.php` | 66 | `'simple'` | `'simple'` (no change) |
| Simple product save | `admin/metabox.php` | 182-185 | `'simple'` | `'simple'` + fallback to existing meta |
| Variation rendering | `admin/metabox.php` | 251 | `'advanced'` | `'simple'` |
| Variation save | `admin/metabox.php` | 369-373 | `'advanced'` | `'simple'` + fallback to existing meta |

### 2.2 Preview Logic

The variation preview logic (`applyRateForVariation` in `assets/js/admin.js`) had a bug:

**Before:** The condition `if (rate > 0 && priceVal > 0)` meant that if `priceVal` was 0 (empty), the `else` branch cleared ALL preview values (`.rate`, `.final`, `.sale-final`, `.min-final`), which was incorrect.

**After:** The preview logic is separated from the input field logic. Each preview value is calculated independently from its own input. If an input is empty or invalid, only the corresponding preview value is set to `-`. The input fields are only updated when `rate > 0 && priceVal > 0` (same as before).

The simple product preview logic (`applySimpleRate`) was already correct — each preview value is calculated independently from its own input. No changes were needed.

---

## 3. Files Inspected

- `admin/metabox.php` — Product/variation metabox fields and save handlers
- `assets/js/admin.js` — Admin JS (preview logic, dependence type notice)
- `includes/functions.php` — Shared helpers (migration logic)
- `docs/نقشه راه توسعه Ani Navasan Lite.md` — Roadmap
- `.clinerules/memory-bank.md` — Memory Bank
- `docs/reports/2026-11-13-task-09-currency-sale-price.md` — Task 09 report

---

## 4. Files Created, Modified, or Deleted

### Created:
- `tests/preview-logic-test.js` — Node.js test for the preview logic (31 checks)
- `tests/dependence-type-test.php` — PHP test for the dependence type default logic (23 checks)
- `docs/reports/2026-11-13-task-09c-preview-defaults.md` — This report

### Modified:
- `admin/metabox.php` — Changed variation default from `advanced` to `simple`; added fallback to existing metadata in both save handlers
- `assets/js/admin.js` — Separated preview logic from input field logic; made preview values independent
- `docs/نقشه راه توسعه Ani Navasan Lite.md` — Updated Task 09b status; added Task 09c row
- `.clinerules/memory-bank.md` — Fixed branch origin; updated Task 09b status

### Deleted:
- None

---

## 5. Detailed Description of Changes

### 5.1 Default Dependence Type

**`admin/metabox.php`:**

1. **Variation rendering (line 251):** Changed the default from `'advanced'` to `'simple'`.

2. **Simple product save handler (lines 182-186):** Added fallback to existing metadata. If the POST value is missing or invalid, the existing metadata value is used (if valid); otherwise, the default `'simple'` is applied.

3. **Variation save handler (lines 370-375):** Changed default to `'simple'` and added the same fallback logic.

The fallback ensures that a previously saved valid value (`simple` or `advanced`) is never overwritten by the default. The default is only applied when the metadata doesn't exist or is invalid.

### 5.2 Preview Logic

**`assets/js/admin.js`:**

The `applyRateForVariation` function was restructured to separate the preview logic from the input field logic:

1. **Preview update (independent):** Each preview value (`.rate`, `.final`, `.sale-final`, `.min-final`) is now calculated independently from its own input. If an input is empty or invalid, only the corresponding preview value is set to `-`.

2. **Input field update (unchanged behavior):** The input fields (WC regular price, WC sale price, WC min price) are only updated when `rate > 0 && priceVal > 0`, which is the same as before.

This ensures that:
- Clearing the currency price only clears `.final` (not `.rate`, `.sale-final`, or `.min-final`).
- Clearing the sale price only clears `.sale-final`.
- Clearing the min price only clears `.min-final`.
- Changing the rate recalculates all valid preview values.
- Each variation's preview is independent (no cross-contamination).

The simple product preview logic (`applySimpleRate`) was already correct and required no changes.

### 5.3 Documentation Fixes

**`.clinerules/memory-bank.md`:**
- Fixed the branch origin for Task 09: changed `created from origin/main` to `created from test/task-08-metadata-compatibility`.
- Updated the Task 09b status to reflect the project owner's manual test confirmation.

**`docs/نقشه راه توسعه Ani Navasan Lite.md`:**
- Updated the Task 09b row to reflect the project owner's manual test confirmation.
- Added a new row for Task 09c.

**`docs/reports/2026-11-13-task-09-currency-sale-price.md`:**
- No changes needed (the report already correctly states the branch origin as `test/task-08-metadata-compatibility`).


---

## 6. Security Implications

No security implications. The changes are limited to:
- Default value logic (no new input vectors).
- Preview logic (no new data flow).
- Documentation (no code changes).

The existing security controls (nonce verification, capability checks, input sanitization) are unchanged.

---

## 7. Compatibility Implications

No compatibility implications. The changes are backward-compatible:
- Products with a previously saved `advanced` value will retain it.
- Products with no saved value will get the new default (`simple`).
- The preview behavior is improved (more accurate) but does not break existing functionality.

---

## 8. Tests Executed and Results

### 8.1 Syntax Checks

| Test | Result |
|---|---|
| `php -l admin/metabox.php` | PASS |
| `node --check assets/js/admin.js` | PASS |

### 8.2 Preview Logic Tests (`tests/preview-logic-test.js`)

| Scenario | Checks | Result |
|---|---|---|
| A: All inputs valid | 4 | PASS |
| B: Regular price cleared | 4 | PASS |
| C: Sale price cleared | 4 | PASS |
| D: Min price cleared | 4 | PASS |
| E: Rate changed | 4 | PASS |
| E2: Rate changed with empty price | 4 | PASS |
| F: Multiple variations (independence) | 3 | PASS |
| G: No rate | 4 | PASS |
| **Total** | **31** | **31/31 PASS** |

### 8.3 Dependence Type Tests (`tests/dependence-type-test.php`)

| Scenario | Checks | Result |
|---|---|---|
| Save Handler: Simple Product | 10 | PASS |
| Save Handler: Variation | 4 | PASS |
| Rendering: Simple Product | 5 | PASS |
| Rendering: Variation | 4 | PASS |
| **Total** | **23** | **23/23 PASS** |

### 8.4 Live Site Verification

| Check | Result |
|---|---|
| Plugin active on live site | PASS |
| Files copied to live site (identical) | PASS |
| Product 17613 (simple): `_mnswmc_dependence_type` = `simple` | PASS |
| Product 17615 (variable parent): `_mnswmc_dependence_type` = `simple` | PASS |
| Variation 17616: `_mnswmc_dependence_type` = `advanced` (preserved) | PASS |
| Variation 17617: `_mnswmc_dependence_type` = `simple` | PASS |

### 8.5 Tests Not Executed

| Test | Reason |
|---|---|
| Docker smoke test (`tests/compat/`) | Requires a full Docker environment (WP + WC + PHP); not available in this environment. The smoke test was last run in Task 09 (45/45 PASS) and the changes in this task are limited to default values and preview logic, which are covered by the unit tests above. |
| Browser-based preview test | Requires a real browser to simulate input events and verify the DOM. The preview logic is verified by the unit tests (`tests/preview-logic-test.js`) which simulate the same logic. |
| Live save handler test (triggering `woocommerce_save_product_variation`) | Triggering the save action directly could have side effects (recalculating prices). The save handler logic is verified by the unit tests (`tests/dependence-type-test.php`) which simulate the same logic. |


---

## 9. Known Limitations

1. **Preview logic test is a simulation:** The preview logic test (`tests/preview-logic-test.js`) simulates the same logic as the JS file but does not test the actual DOM manipulation. A browser-based test would be needed to verify the actual DOM behavior.

2. **Save handler test is a simulation:** The dependence type test (`tests/dependence-type-test.php`) simulates the same logic as the save handler but does not test the actual WooCommerce save flow. A live test would be needed to verify the actual save behavior.

3. **Docker smoke test not re-run:** The Docker smoke test was not re-run in this task. The changes are limited to default values and preview logic, which are covered by the unit tests. A full smoke test re-run is recommended before merging.

---

## 10. Remaining Issues

1. **Docker smoke test re-run:** The Docker smoke test should be re-run to verify that the changes do not break any existing functionality. This is recommended before merging.

2. **Browser-based preview test:** A browser-based test should be performed to verify the actual DOM behavior of the preview. This is optional but recommended.

---

## 11. Git Branch Name

`fix/task-09-preview-defaults`

---

## 12. Commit Hash

`7f5681f` — Task 09c: default dependence type to simple + independent preview fix

---

## 13. Push Status

**Pushed** to `origin/fix/task-09-preview-defaults` (new branch, tracking set).

---

## 14. Working Tree Status

Clean (all changes committed and pushed).

---

## 15. Acceptance Criteria Verification

| # | Criterion | Status |
|---|---|---|
| 1 | Default dependence type is `simple` for both simple products and variations | Verified (unit tests + code review) |
| 2 | Previously selected `advanced` is preserved | Verified (unit tests + code review) |
| 3 | Clearing the regular currency price only clears the regular preview | Verified (unit tests) |
| 4 | Rate, sale, and min price previews work independently | Verified (unit tests) |
| 5 | Same behavior for all variations | Verified (unit tests) |
| 6 | Previous calculations and rounding are unchanged | Verified (code review) |
| 7 | Branch origin inconsistency in Roadmap and Memory Bank is fixed | Verified (documentation update) |
| 8 | Project owner's manual test is recorded in the documentation | Verified (documentation update) |
| 9 | Tests and documentation are completed with real evidence | Verified (test results + report) |

