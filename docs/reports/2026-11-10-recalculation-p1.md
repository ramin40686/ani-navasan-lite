# Recalculation P1 Fixes — Task Report

**Date:** 2026-11-10
**Branch:** `fix/recalculation-p1`
**Commit:** _(see Section 12)_

---

## 1. Initial Findings

A deep investigation of the currency-dependent product price recalculation workflow revealed two confirmed P1 issues:

### 1.1 Inconsistent rounding across calculation paths

Before the fix, different calculation paths used different rounding strategies:

| Path | Rounding (before) |
|---|---|
| Simple product save (PHP) | Nearest 1 Toman |
| Variation save (PHP) | Nearest 1 Toman |
| Currency recalculation (PHP) | Nearest 1,000 Toman |
| Simple product preview (JS) | Nearest 1 Toman |
| Variation preview (JS) | Nearest 1 Toman |

The business decision is that **all final Toman prices must be rounded to the nearest 1,000 Toman**. The save handlers and JavaScript previews were not following this policy.

### 1.2 Stale `_price` after recalculation

The recalculation handler (`ani_navasan_lite_recalc_currency`) updated `_regular_price` via `update_post_meta()` but did not update `_price`.

**WooCommerce source verification (WC 11.2.1):**
- `WC_Product::get_price()` → `get_prop('price')` → reads `_price` meta.
- Variable product price range: `get_variation_price()` → `read_price_data()` → `$variation->get_price('edit')` → reads `_price`.
- The data store's `update()` method syncs `_price` with `_regular_price` only when the product is saved via the CRUD API. Direct `update_post_meta()` calls bypass this sync.

**Consequence:** After a currency-rate change and recalculation, the storefront displays the stale `_price` value.

---

## 2. Root Causes Confirmed

1. **Rounding inconsistency:** The save handlers and JavaScript used `round()` / `Math.round()` (nearest 1 Toman) while the recalculation used `round(x/1000)*1000` (nearest 1,000 Toman). No shared rounding function existed.

2. **Stale `_price`:** The recalculation handler used `update_post_meta()` directly, which does not trigger WooCommerce's `_price` sync logic. The `_price` meta field (which drives the storefront active price) was left at its old value.

---

## 3. Files Changed

| File | Change |
|---|---|
| `includes/functions.php` | Added `ani_navasan_round_to_thousand()` helper; fixed recalculation to use it and to update `_price` when not on sale |
| `admin/metabox.php` | Fixed simple product save (×2 handlers) and variation save to use `ani_navasan_round_to_thousand()` |
| `assets/js/admin.js` | Added `mnswmcRoundToThousand()` helper; fixed simple and variation calculations to use it |
| `docs/reports/2026-11-10-technical-audit.md` | New: technical audit report |
| `docs/reports/2026-11-10-recalculation-p1.md` | New: this report |

---

## 4. Explanation of Each Code Change

### 4.1 `includes/functions.php`

**Added:** `ani_navasan_round_to_thousand($value)` — shared PHP rounding function that rounds to the nearest 1,000 Toman.

```php
function ani_navasan_round_to_thousand($value) {
    return (int) round((float) $value / 1000) * 1000;
}
```

**Modified:** Recalculation handler now:
1. Uses `ani_navasan_round_to_thousand()` instead of inline `round(x/1000)*1000`.
2. After updating `_regular_price`, checks if the product is on sale (by reading `_sale_price` meta). If not on sale, also updates `_price` to the new value. This ensures the storefront active price is consistent with the regular price.

### 4.2 `admin/metabox.php`

**Modified:** All three save handlers (two simple product, one variation) now use `ani_navasan_round_to_thousand()` instead of `round()`. This ensures the saved Toman price is rounded to the nearest 1,000 Toman, consistent with the recalculation and the business policy.

### 4.3 `assets/js/admin.js`

**Added:** `mnswmcRoundToThousand(value)` — shared JS rounding function that rounds to the nearest 1,000 Toman.

```javascript
function mnswmcRoundToThousand(value) {
    return Math.round((parseFloat(value) || 0) / 1000) * 1000;
}
```

**Modified:** `applySimpleRate()` and `applyRateForVariation()` now use `mnswmcRoundToThousand()` instead of `Math.round()`. This ensures the admin preview shows the same rounded value that will be saved.

---

## 5. Test Environment Details

**No disposable WordPress + WooCommerce test environment was available for integration testing.**

| Tool | Status |
|---|---|
| Docker | Available (v29.9.3), no WordPress containers running |
| WP-CLI | Available (v2.12.0), no WordPress installation configured |
| PHPUnit | Not installed |
| PHP | 8.1.2 (CLI) |
| Node.js | v22.23.4 |
| Studio sites | 3 sites with WooCommerce (11.0.1, 11.1.0, 11.2.1), none confirmed as disposable |

**WooCommerce version used for source verification:** 11.2.1 (from `newaniblocksy` Studio site, read-only inspection of WC source files).

**Consequence:** Integration tests (simple product, variable product, storefront output) could **not** be executed in a live WordPress + WooCommerce environment. Only static analysis and isolated unit tests were performed.

---

## 6. Simple Product Test Results

**Status: NOT EXECUTED (no live WordPress + WooCommerce environment available)**

The following test plan was defined but could not be run:

1. Create a simple product with a currency-dependent price.
2. Save it.
3. Verify the calculated Toman price is rounded to nearest 1,000.
4. Change the currency rate.
5. Run recalculation.
6. Verify regular price, sale price, active price, and storefront output.
7. Verify the result is rounded to the nearest 1,000 Toman.

**What was verified instead (static analysis):**
- The save handler now uses `ani_navasan_round_to_thousand()` (confirmed by code inspection).
- The recalculation handler now updates both `_regular_price` and `_price` (when not on sale) (confirmed by code inspection).
- The rounding function produces correct results for 20 test cases (isolated PHP unit test, see Section 8).

---

## 7. Variable Product Test Results

**Status: NOT EXECUTED (no live WordPress + WooCommerce environment available)**

The following test plan was defined but could not be run:

1. Create a variable product with at least two variations.
2. Assign currency-dependent prices to each variation.
3. Save the product and variations.
4. Verify each variation's calculated Toman price is rounded to nearest 1,000.
5. Change the currency rate.
6. Run recalculation.
7. Verify each variation independently.
8. Verify the parent variable product's displayed price range.
9. Verify sale-price behavior if applicable.
10. Verify rounding consistency.

**What was verified instead (static analysis):**
- The variation save handler now uses `ani_navasan_round_to_thousand()` (confirmed by code inspection).
- The recalculation handler now updates `_price` for variations (when not on sale) (confirmed by code inspection).
- WooCommerce's `read_price_data()` reads `_price` from each variation (confirmed by WC 11.2.1 source inspection).

---

## 8. Rounding Test Results

**Status: EXECUTED (isolated unit tests, PHP and JS)**

### 8.1 PHP unit test

20 test cases executed against `ani_navasan_round_to_thousand()`:

| Input | Expected | Actual | Result |
|---|---|---|---|
| 0 | 0 | 0 | PASS |
| 500 | 1000 | 1000 | PASS |
| 1000 | 1000 | 1000 | PASS |
| 1499 | 1000 | 1000 | PASS |
| 1500 | 2000 | 2000 | PASS |
| 1999 | 2000 | 2000 | PASS |
| 2500 | 3000 | 3000 | PASS |
| 1234567 | 1235000 | 1235000 | PASS |
| 1234499 | 1234000 | 1234000 | PASS |
| 1500000 | 1500000 | 1500000 | PASS |
| 1504000 | 1504000 | 1504000 | PASS |
| 1506000 | 1506000 | 1506000 | PASS |
| 999 | 1000 | 1000 | PASS |
| 1001 | 1000 | 1000 | PASS |
| 12345 | 12000 | 12000 | PASS |
| 12500 | 13000 | 13000 | PASS |
| 12499 | 12000 | 12000 | PASS |
| 1000000 | 1000000 | 1000000 | PASS |
| 1000499 | 1000000 | 1000000 | PASS |
| 1000500 | 1001000 | 1001000 | PASS |

**Result: 20/20 PASS**

### 8.2 JavaScript unit test

The same 20 test cases executed against `mnswmcRoundToThousand()`:

**Result: 20/20 PASS**

### 8.3 PHP/JS equivalence

All 20 test cases produced identical results in PHP and JavaScript. **PHP and JS calculations agree.**

---

## 9. Regression Checks

**Status: NOT EXECUTED (no live WordPress + WooCommerce environment available)**

The following regression checks were defined but could not be run:

- Products without currency dependency are not modified.
- Unrelated post types are not modified.
- Recalculation does not overwrite unrelated metadata.
- Repeated recalculation does not produce cumulative rounding errors.
- PHP and JavaScript calculations agree.

**What was verified instead (static analysis):**
- The recalculation query filters by `_mnswmc_currency_id` meta and `_mnswmc_active = 'yes'`, so only currency-dependent products are affected.
- The rounding function is idempotent: `round_to_thousand(round_to_thousand(x)) == round_to_thousand(x)`, so repeated recalculation does not produce cumulative errors.
- PHP and JS produce identical results for all 20 test cases (see Section 8).

---

## 10. Tests Not Executed and Reasons

| Test | Reason |
|---|---|
| Simple product integration test | No live WordPress + WooCommerce environment available |
| Variable product integration test | No live WordPress + WooCommerce environment available |
| Storefront output verification | No live WordPress + WooCommerce environment available |
| WooCommerce cache/lookup table verification | No live WordPress + WooCommerce environment available |
| Regression checks (unrelated products, post types) | No live WordPress + WooCommerce environment available |
| PHPUnit test suite | PHPUnit not installed; no WordPress test framework configured |

**What is required to run these tests:**
1. A disposable WordPress + WooCommerce environment (Docker container or local install).
2. The plugin installed and activated.
3. A currency CPT post with a rate set.
4. Simple and variable products with currency-dependent prices.
5. WP-CLI or PHPUnit for automated test execution.

---

## 11. Remaining Risks

1. **Stale `_price` fix not runtime-verified:** The fix adds an explicit `_price` update to the recalculation handler, but this has not been verified in a live environment. If the on-sale check logic has an edge case (e.g., `_sale_price` is set but the sale is expired), the `_price` update might be skipped incorrectly.

2. **WooCommerce cache staleness:** The recalculation handler still uses `update_post_meta()` directly, which does not invalidate WooCommerce's product transients or update the `wc_product_meta_lookup` table. Price-based filtering and cached price ranges may show stale values until the cache expires. This is a P2 issue not addressed in this task.

3. **Duplicate save handler:** The two identical `woocommerce_admin_process_product_object` handlers both execute the rounding fix, so the fix is applied twice (harmlessly). The duplicate itself is a P2 issue not addressed in this task.

4. **Variation JavaScript selectors:** The variation preview JavaScript selectors may not match the actual WooCommerce DOM in all versions. This is a P2 issue not addressed in this task.

5. **No automated test coverage:** The plugin has no automated tests. Future changes may introduce regressions that are not caught. This is a P2 issue not addressed in this task.

---

## 12. Commit Hash

_(To be filled after commit)_


