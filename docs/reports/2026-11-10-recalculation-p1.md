# Recalculation P1 Fixes — Task Report

**Date:** 2026-11-10
**Branch:** `fix/recalculation-p1`
**Commit:** `16d6896` (Task 03) / `800f8e6` (Task 02) / `9085956` (Task 01)

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
| `includes/functions.php` | Added `ani_navasan_round_to_thousand()` helper; rewrote recalculation to use the WooCommerce CRUD API (correct sale-price handling + cache/lookup sync) and to sync the parent variable product's price |
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
2. Uses the **WooCommerce CRUD API** (`wc_get_product()` → `set_regular_price()` → `save()`) instead of direct `update_post_meta()`. This was confirmed necessary by live testing:
   - The previous direct-meta approach left `_price` stale for **scheduled (future) and expired sales**, because it equated the existence of `_sale_price` with an active sale. The CRUD `save()` syncs `_price` based on WooCommerce's real `is_on_sale()` status (which accounts for sale dates) and preserves `_sale_price`.
   - The CRUD `save()` also synchronizes the `wc_product_meta_lookup` table and product caches, which direct `update_post_meta()` did not.
3. The floor price (`_torob_scraper_min_price`) is updated via `update_meta_data()` and saved in the same `save()` call.
4. **Added:** After updating variations, the parent variable product's price is synchronized using the standard WooCommerce API `WC_Product_Variable::sync($parent)`. This updates the parent's `_price` meta (as multiple rows, one per distinct child price), the `wc_product_meta_lookup` table, stock status, and clears caches. (See Section 13 for the full Task 03 review.)

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

**A live local WordPress + WooCommerce environment was used for integration testing.**

| Item | Value |
|---|---|
| Test site | `http://anishop-package-test.local` (Local by Flywheel) |
| WordPress | 7.1.4 |
| WooCommerce | 11.2.2 |
| PHP | 8.3.30 |
| Plugin under test | `ani-navasan-lite` 1.0.0 (installed from local source, branch `fix/recalculation-p1`) |
| Original Navasan plugin | **Not installed** (no conflict) |
| Access method | **WP-CLI 2.12.0** (Novamira MCP tools were not loaded into the AI session, so WP-CLI was used per the task fallback) |
| Node.js | v22.23.4 (for JS rounding equivalence) |

**Note on MCP:** The Novamira MCP server (`@automattic/mcp-wordpress-remote`) passed a direct `npx` stdio protocol test in a prior session, but its tools were not loaded into this AI session. Per the task instructions, WP-CLI was used as the fallback access method. All read and write operations were verified against the live site.

**Note on test data:** The two authorized test products (17613, 17615) referenced currency CPT posts (IDs 4168, 15939) that did **not** exist in the test site (likely a side effect of site duplication). To exercise the recalculation path, two temporary test currencies were created and **deleted after testing**. The original product data was restored after testing (see the Live Integration Tests section).

---

## 6. Simple Product Test Results

**Status: EXECUTED** — see the "Live WordPress + WooCommerce Integration Tests" section (tests A1–A5).

The simple product (ID 17613) was tested live on the local site:
- Save handler (A1): **PASS**
- Recalculation on rate change (A2): **PASS**
- Storefront price via Store API (A3): **PASS**
- Sale price scenarios (A4): **PASS** (5/5, including scheduled and expired sales)
- Rounding (A5): **PASS** (20/20, stable under repeated recalculation)

---

## 7. Variable Product Test Results

**Status: EXECUTED** — see the "Live WordPress + WooCommerce Integration Tests" section (tests B1–B2).

The variable product (ID 17615, variations 17616 and 17617) was tested live on the local site:
- Variation recalculation with different currencies (B1): **PASS** (dependent variation recalculated; other-currency variation unchanged)
- Parent price range (B1): **PASS** (min/max correct)
- Storefront price range via Store API (B1): **PASS**
- Variation save handler (B2): **PASS** (including a rounding-boundary value)

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

## Live WordPress + WooCommerce Integration Tests

**Status: EXECUTED** on `http://anishop-package-test.local` via WP-CLI 2.12.0.

### Environment

- WordPress 7.1.4, WooCommerce 11.2.2, PHP 8.3.30
- Plugin `ani-navasan-lite` 1.0.0 installed from local source (branch `fix/recalculation-p1`)
- Original Navasan plugin **not installed** (no conflict)
- Access method: WP-CLI (Novamira MCP tools were not loaded into the AI session; WP-CLI used per the task fallback)

### Initial Product States (before testing)

| ID | Type | Currency ID | Currency Price | Regular Price | Active Price | On Sale |
|---|---|---|---|---|---|---|
| 17613 | simple | 4168 (non-existent) | 280 | 19,600,000 | 19,600,000 | No |
| 17615 | variable | — | — | — | 33,600,000 | No |
| 17616 | variation (of 17615) | 15939 (non-existent) | 485 | 33,950,000 | 33,950,000 | No |
| 17617 | variation (of 17615) | 4168 (non-existent) | 480 | 33,600,000 | 33,600,000 | No |

A recoverable snapshot of this state was preserved before testing.

### Test Currencies Created (temporary, deleted after testing)

- Currency A (ID 17618): "دولار تست (USD)", rate 70,000
- Currency B (ID 17619): "درهم تست (AED)", rate 19,000

### A. Simple Product (17613)

| Test | Input | Expected | Actual | Result |
|---|---|---|---|---|
| A1 Save handler | Currency A (70,000), price 280 | 19,600,000 | 19,600,000 | PASS |
| A2 Recalculation | Rate 70,000 → 71,000 | 19,880,000 | 19,880,000 | PASS |
| A3 Storefront price (Store API) | — | 19,880,000 تومان | 19,880,000 تومان | PASS |

**Sale price scenarios (A4)** — recalculation with rate 71,000, currency price 280 (expected Toman 19,880,000):

| Scenario | is_on_sale() | Expected `_price` | Actual `_price` | `_sale_price` | Result |
|---|---|---|---|---|---|
| No sale price | false | 19,880,000 | 19,880,000 | (empty) | PASS |
| Active sale (no dates) | true | 10,000,000 (sale) | 10,000,000 | 10,000,000 | PASS |
| Future scheduled sale | false | 19,880,000 | 19,880,000 | 10,000,000 (preserved) | PASS |
| Expired sale | false | 19,880,000 | 19,880,000 | 10,000,000 (preserved) | PASS |
| Active sale (date range now) | true | 10,000,000 (sale) | 10,000,000 | 10,000,000 | PASS |

**Before the fix**, the "Future scheduled sale" and "Expired sale" scenarios **FAILED** (the recalculation left `_price` stale because it equated the existence of `_sale_price` with an active sale). The fix uses WooCommerce's `is_on_sale()` (via the CRUD API), which correctly handles scheduled and expired sales.

**Rounding (A5):** 20/20 PHP cases pass (including boundary values 500→1000, 1500→2000, 19600500→19601000). Repeated recalculation (5×) is stable (no cumulative error).

### B. Variable Product (17615)

Variations: 17616, 17617.

| Test | Input | Expected | Actual | Result |
|---|---|---|---|---|
| B1 Variation recalculation (different currencies) | V1=Currency A (71,000→72,000) price 485; V2=Currency B (19,000) price 480 | V1=34,920,000; V2=9,120,000 (unchanged) | V1=34,920,000; V2=9,120,000 | PASS |
| B1 Parent price range | (after recalc) | min=9,120,000; max=34,920,000 | min=9,120,000; max=34,920,000 | PASS |
| B1 Storefront price range (Store API) | — | 9,120,000 – 34,920,000 تومان | 9,120,000 – 34,920,000 تومان | PASS |
| B2 Variation save handler | Currency A (72,000), price 485 | 34,920,000 | 34,920,000 | PASS |
| B2 Variation save (boundary) | Currency A (72,000), price 485.005 | 34,920,000 | 34,920,000 | PASS |

### Cache and Lookup Table Results

| Check | Result |
|---|---|
| Parent `wc_product_meta_lookup` min/max after recalc | PASS (min=9,120,000, max=34,920,000) |
| Variation `wc_product_meta_lookup` after recalc | PASS |
| Storefront price (simple, Store API) | PASS |
| Storefront price range (variable, Store API) | PASS |

The switch to the WooCommerce CRUD API (`set_regular_price()` + `save()`) ensures the `wc_product_meta_lookup` table and product caches are synchronized, which the previous direct `update_post_meta()` approach did not guarantee.

### Code Changes Made (during live testing)

The live tests confirmed a P1 issue in the sale-price logic (task Section 7). The recalculation handler in `includes/functions.php` was changed from direct `update_post_meta()` to the WooCommerce CRUD API:

- **Before:** `update_post_meta($pid, '_regular_price', ...)` + a manual `_sale_price`-existence check to decide whether to update `_price`.
- **After:** `wc_get_product($pid)` → `set_regular_price($toman)` → `save()`. WooCommerce's data store then syncs `_price` based on the real `is_on_sale()` status (handling scheduled/expired sales) and preserves `_sale_price`. The floor price is updated via `update_meta_data()`.
- **Added:** After updating variations, the parent variable product's price is synchronized using `WC_Product_Variable::sync($parent)` (the standard WooCommerce API). This was changed from the initial `set_price($min_price) + save()` approach, which was found to be ineffective for variable products (see Section 13).

### Tests Not Completed and Reasons

| Test | Reason |
|---|---|
| Direct product page URL verification | The test site's product slugs are percent-encoded (a pre-existing data issue from site duplication), so the single-product page URL returns 404. The Store API was used instead to verify the storefront price. |
| PHPUnit test suite | PHPUnit is not installed; no WordPress test framework configured. |
| Original Navasan conflict test | The original Navasan plugin is not installed; activating it was not done (per the task's safety guidance). |

### Data Restoration

After testing, the original product data for 17613, 17615, 17616, and 17617 was restored from the preserved snapshot, and the two temporary test currencies (17618, 17619) were deleted. The test site was returned to its pre-test state.

---

## 9. Regression Checks

**Status: EXECUTED** (live, via WP-CLI) — see the "Live WordPress + WooCommerce Integration Tests" section.

| Check | Result |
|---|---|
| Products without currency dependency are not modified | PASS (recalculation query returns only posts with the target `_mnswmc_currency_id`) |
| Products using a different currency are not modified | PASS (Currency B variation unchanged when Currency A rate changed) |
| Variations with dependency disabled are not modified | PASS (`_mnswmc_active = 'no'` variation unchanged) |
| Unrelated post types are not modified | PASS (query returned only `product` and `product_variation` posts) |
| Unrelated metadata is preserved | PASS (custom meta and `_mnswmc_dependence_type` preserved) |
| Repeated recalculation produces stable results | PASS (5× recalculation, no cumulative error) |
| Currency changes affect only dependent products/variations | PASS |
| Storefront prices correct after recalculation | PASS (Store API) |
| Product caches and lookup tables synchronized | PASS (`wc_product_meta_lookup` min/max correct) |
| Original Navasan plugin not running simultaneously | PASS (not installed) |

**Note on the recalculation query scope:** The query selects posts by `_mnswmc_currency_id` meta and the handler skips any post where `_mnswmc_active !== 'yes'`. Live testing confirmed only `product` and `product_variation` posts carry this meta, so no unrelated post types were modified. A defensive post-type filter was considered but not added, per the task guidance to change the query only when tests justify it (no issue was demonstrated).

---

## 10. Tests Not Executed and Reasons

| Test | Reason |
|---|---|
| Direct single-product page URL verification | The test site's product slugs are percent-encoded (a pre-existing data issue from site duplication), so the single-product page URL returns 404. The Store API was used instead to verify the storefront price. |
| PHPUnit test suite | PHPUnit is not installed; no WordPress test framework configured. |
| Original Navasan conflict test | The original Navasan plugin is not installed; activating it was not done (per the task's safety guidance to avoid irreversible changes). |
| Novamira MCP-based testing | The MCP tools were not loaded into the AI session; WP-CLI was used as the authorized fallback. |

**Note:** The previously-blocked integration tests (simple product, variable product, storefront output, cache/lookup table, regression) were **all executed** in this task using the live local site via WP-CLI.

---

## 11. Remaining Risks

1. **Resolved — Stale `_price` after recalculation (simple products):** Previously a risk. Now runtime-verified: the recalculation uses the WooCommerce CRUD API, and all five sale-price scenarios (including scheduled and expired sales) were verified live. The `_price` meta is correctly synced based on `is_on_sale()`.

2. **Resolved — WooCommerce cache/lookup-table staleness:** Previously a risk. Now runtime-verified: the CRUD `save()` updates the `wc_product_meta_lookup` table and product caches. The parent variable product's min/max price range was verified correct after recalculation.

3. **Resolved — Variable product parent `_price` not synced (Task 03):** Previously the parent sync used `set_price($min_price) + save()`, which was ineffective for variable products (the `_price` meta was not updated). Now fixed: the parent sync uses `WC_Product_Variable::sync($parent)`, the standard WooCommerce API. Runtime-verified: the parent's `_price` meta, lookup table, and Store API price range are all correctly updated after recalculation. See Section 12 for details.

4. **Duplicate save handler (P2, not addressed):** The two identical `woocommerce_admin_process_product_object` handlers both execute the rounding fix, so the fix is applied twice (harmlessly, idempotent). The duplicate itself is a P2 issue not addressed in this task.

5. **Variation JavaScript selectors (P2, not addressed):** The variation preview JavaScript selectors may not match the actual WooCommerce DOM in all versions. This is a P2 issue not addressed in this task.

6. **No automated test coverage (P2, not addressed):** The plugin has no automated tests. The live integration tests in this task were manual (WP-CLI driven). Future changes may introduce regressions that are not caught. This is a P2 issue not addressed in this task.

7. **Test-site data quirk (environmental, not a plugin issue):** The test site's product slugs are percent-encoded (a side effect of site duplication), which makes single-product page URLs return 404. This is a pre-existing data issue in the test site, not a plugin defect. Storefront prices were verified via the Store API instead.

---

## 12. Task 03 — Variable Product Price Synchronization Review

**Date:** 2026-11-10
**Scope:** Review and correction of the variable product parent price synchronization in the recalculation handler.

### 12.1 Problem Identified

The initial Task 01/02 fix used `set_price($min_price) + save()` to sync the parent variable product's price after updating variations. Live testing in Task 03 revealed this approach is **ineffective** for variable products.

**Root cause (verified against WC 11.2.2 source):**

1. `_price` is **not** in the `meta_key_to_props` mapping in `WC_Product_Data_Store_CPT`. The `handle_updated_props()` method explicitly skips the `_price` sync for variable and grouped products:
   ```php
   $price_is_synced = $product->is_type( array( ProductType::VARIABLE, ProductType::GROUPED ) );
   if ( ! $price_is_synced ) {
       // ... _price sync logic ...
   }
   ```
2. Therefore, calling `set_price($min_price) + save()` on a variable product does **not** update the `_price` meta. The parent's `_price` remains stale.
3. WooCommerce's own code confirms this: the comment at `wc-product-functions.php:653-656` states:
   > "Workaround: `_price` is not in `meta_key_to_props` mapping and only syncs when date/price props change in `handle_updated_props()`. Since we only changed `price` prop, we must update `_price` meta directly."

### 12.2 WooCommerce Source Verification

The following was verified against the installed WooCommerce 11.2.2 source:

| Aspect | Finding |
|---|---|
| `WC_Product_Variable::sync()` | Updates `_price` as multiple rows (one per distinct child price), updates `wc_product_meta_lookup`, syncs stock status, clears caches. |
| `WC_Product_Variable::sync()` call sites | Called by WooCommerce itself at `wc-product-functions.php:682` (after variation price change), `class-wc-ajax.php:3746`, `class-wc-admin-post-types.php:924`. |
| `sync_attributes()` side effect | Legacy no-op for products with `_product_version >= 2.4.0` (all modern products). Safe to call. |
| `handle_updated_props()` for variable | Skips `_price` sync (`$price_is_synced = true`). Confirms `set_price + save` is ineffective. |
| `get_data_for_lookup_table()` | Reads `_price` meta (all rows) to compute `min_price`/`max_price` for the lookup table. |
| `update_lookup_table()` | Uses `wp_cache_get('lookup_table', 'object_' . $id)` to avoid redundant writes. |

### 12.3 Technical Decision

**Decision:** Replace `set_price($min_price) + save()` with `WC_Product_Variable::sync($parent)`.

**Rationale:**
- `WC_Product_Variable::sync()` is the **standard WooCommerce API** for synchronizing a variable product's price with its variations.
- It is used by WooCommerce itself in the standard variation-save flow.
- It correctly updates the `_price` meta (multiple rows), the lookup table, stock status, and caches.
- It has no harmful side effects for modern products (`sync_attributes()` is a no-op for `_product_version >= 2.4.0`).
- It avoids redundant syncs: the method checks if the price has actually changed before writing.

**Code change in `includes/functions.php`:**

```php
// Before (ineffective for variable products):
$parent = wc_get_product($parent_id);
if ($parent && $parent->is_type('variable')) {
    $min_price = $parent->get_variation_price('min');
    if ($min_price !== '' && $min_price !== null) {
        $parent->set_price($min_price);
        $parent->save();
    }
}

// After (standard WooCommerce API):
$parent = wc_get_product($parent_id);
if ($parent && $parent->is_type('variable')) {
    WC_Product_Variable::sync($parent);
}
```

### 12.4 Live Test Results (Task 03)

**Test environment:** `http://anishop-package-test.local` — WordPress 7.1.4, WooCommerce 11.2.2, PHP 8.3.30. Access via WP-CLI.

**Authorized test products:** 17615 (variable), 17616 (variation), 17617 (variation).

**Test currencies created (temporary, deleted after testing):**
- Currency A (ID 17620): rate 70,000 → 80,000 → 90,000
- Currency B (ID 17621): rate 70,000

#### Test A: Current code vs. `WC_Product_Variable::sync` (direct comparison)

| Step | Action | Parent `_price` rows | `get_price()` | Result |
|---|---|---|---|---|
| Initial | — | `["33600000"]` | `33600000` | — |
| A1 | Change var 17616 `_price` to 20,000,000; clear cache | `["33600000"]` (unchanged) | `33600000` (stale) | **BUG confirmed** |
| A2 | Run current code (`set_price + save`) | `["33600000"]` (unchanged) | `33600000` (stale) | **FAIL** |
| B1 | Run `WC_Product_Variable::sync(17615)` | `["20000000", "33600000"]` | `20000000` | **PASS** |

**Conclusion:** The current code (`set_price + save`) does **not** update the parent's `_price` meta. `WC_Product_Variable::sync()` correctly updates it.

#### Test B: Full recalculation flow (with the fix)

| Step | Action | Var 17616 `_price` | Var 17617 `_price` | Parent `_price` rows | `get_price()` | Lookup min/max | Result |
|---|---|---|---|---|---|---|---|
| B1 | Recalc A @ 70,000 (baseline) | 33,950,000 | 33,600,000 (unchanged) | `["33600000", "33950000"]` | 33,600,000 | 33,600,000 / 33,950,000 | **PASS** |
| B2 | Recalc A @ 80,000 | 38,800,000 | 33,600,000 (unchanged) | `["33600000", "38800000"]` | 33,600,000 | 33,600,000 / 38,800,000 | **PASS** |
| B3 | Recalc A @ 80,000 (stability) | 38,800,000 | 33,600,000 (unchanged) | `["33600000", "38800000"]` | 33,600,000 | 33,600,000 / 38,800,000 | **PASS** |

**Store API verification (after B2):**
```json
{
  "price": "33600000",
  "regular_price": "33600000",
  "price_range": { "min_amount": "33600000", "max_amount": "38800000" }
}
```
**Result: PASS** — Store API returns the correct price range.

#### Test C: Sale price scenarios (with the fix)

| Scenario | Var 17616 `_regular` | Var 17616 `_sale` | Var 17616 `_price` | `is_on_sale()` | Parent `_price` rows | Result |
|---|---|---|---|---|---|---|
| Active sale (rate change 80k→90k) | 43,650,000 | 30,000,000 | 30,000,000 (sale) | true | `["30000000", "33600000"]` | **PASS** |
| Scheduled sale (future start) | 38,800,000 | 30,000,000 | 38,800,000 (regular) | false | `["33600000", "38800000"]` | **PASS** |
| Expired sale (past end) | 38,800,000 | 30,000,000 | 38,800,000 (regular) | false | `["33600000", "38800000"]` | **PASS** |

**Note:** The sale price (`_sale_price`) is preserved in all scenarios. The active price (`_price`) correctly reflects the sale price when the sale is active, and the regular price when the sale is scheduled or expired.

**Known limitation (pre-existing, not introduced by this fix):** If the sale price is manually removed (via `delete_post_meta`) and the regular price is unchanged, the `_price` meta may remain stale because `regular_price` is not added to `updated_props` (no value change). This is a pre-existing WooCommerce behavior, not a plugin bug. In practice, the recalculation always changes the regular price when the rate changes, so this scenario does not occur in the normal recalculation flow.

### 12.5 Cache and Lookup Table Status

| Check | Result |
|---|---|
| Parent `wc_product_meta_lookup` min/max after recalc | **PASS** (correctly reflects variation price range) |
| Parent `_price` meta rows after recalc | **PASS** (multiple rows, one per distinct child price) |
| `wc_var_prices_{parent_id}` transient | **PASS** (cleared by `WC_Product_Variable::sync()` via `wc_delete_product_transients()`) |
| Store API price range | **PASS** (correct min/max) |
| Stability under repeated recalculation | **PASS** (no cumulative error) |

### 12.6 Data Restoration

After testing, the original product data for 17615, 17616, and 17617 was restored from the preserved snapshot. The two temporary test currencies (17620, 17621) were deleted. All product caches and the `wc_var_prices_17615` transient were cleared.

**Restoration verification:**
- 17615 `_price`: restored to `["33600000"]` (original single-row state), then re-synced via `WC_Product_Variable::sync()` to `["33600000", "33950000"]` (correct multi-row state reflecting actual variation prices).
- 17616 `_price`: restored to `33950000`, `_regular_price`: `33950000`, `_mnswmc_currency_id`: `15939` (original).
- 17617 `_price`: restored to `33600000`, `_regular_price`: `33600000`, `_mnswmc_currency_id`: `4168` (original).
- Test currencies 17620, 17621: **deleted**.
- No test data remains.

**Note:** The parent's `_price` is now in the correct multi-row state (`["33600000", "33950000"]`) rather than the original single-row state (`["33600000"]`). This is the **correct** state — the original single-row state was itself a symptom of the bug (the parent's `_price` was not properly synced with the variation prices). The fix ensures this state is maintained going forward.

### 12.7 Acceptance Criteria Verification

| Criterion | Result |
|---|---|
| Each variation's price is calculated independently and correctly | **PASS** |
| Unrelated variation's price is not changed | **PASS** (var 17617 unchanged when var 17616's currency rate changed) |
| Parent price is consistent with the actual variation price range | **PASS** (parent `_price` rows match variation prices) |
| Active, regular, and sale prices remain correct | **PASS** (all three sale scenarios verified) |
| Lookup table and cache are consistent with new data | **PASS** |
| Store API displays the correct price | **PASS** |
| Multiple recalculation runs do not produce different results or cumulative errors | **PASS** (stability test) |

---

## 13. Commit Hash

- `16d6896` — `fix: synchronize variable product prices through WooCommerce API` (Task 03: variable product sync review + `WC_Product_Variable::sync` fix + report update)
- `800f8e6` — `fix: use WooCommerce CRUD API in recalculation for correct sale-price and cache sync` (Task 02: live integration testing + CRUD fix + report update)
- `704ea2c` — `docs: add commit hash to recalculation P1 report`
- `9085956` — `fix: correct currency recalculation and rounding` (Task 01: initial P1 fix)


