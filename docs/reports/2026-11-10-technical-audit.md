# Technical Audit — Ani Navasan Lite

**Date:** 2026-11-10
**Scope:** Static code review of the currency-dependent product price recalculation workflow
**Method:** Source-code inspection + live runtime testing (Tasks 01–03)

> **Update (Task 03, 2026-11-10):** This audit was originally a static-only review. Tasks 01–03 have since implemented the P1 fixes and performed live runtime testing on a local WordPress + WooCommerce environment. The sections below have been updated to reflect the resolved status of each finding. See `2026-11-10-recalculation-p1.md` for the full test results.

---

## 1. Confirmed Findings (Static Code Observations)

These findings are confirmed by direct inspection of the source code. They describe what the code does, not necessarily what happens at runtime.

### 1.1 Duplicate `woocommerce_admin_process_product_object` handler

**File:** `admin/metabox.php`

Two identical `add_action('woocommerce_admin_process_product_object', ...)` handlers are registered. Both perform the same meta-save and price-calculation logic. This means the save logic executes twice on every product save.

**Impact:** Redundant database writes. The second execution overwrites the first with the same values, so no data corruption is expected, but the duplicate is a code-quality issue and a maintenance risk.

**Classification:** Confirmed (static observation). Not a P1 recalculation defect.

### 1.2 Inconsistent rounding between save, client-side calculation, and currency recalculation

**Files:** `admin/metabox.php`, `assets/js/admin.js`, `includes/functions.php`

| Calculation path | Rounding (before fix) | Rounding (after fix) |
|---|---|---|
| Simple product save (PHP) | `round($rate * $price)` → nearest 1 Toman | `ani_navasan_round_to_thousand()` → nearest 1,000 Toman |
| Variation save (PHP) | `round($rate * $price)` → nearest 1 Toman | `ani_navasan_round_to_thousand()` → nearest 1,000 Toman |
| Currency recalculation (PHP) | `round($price * $rate / 1000) * 1000` → nearest 1,000 Toman | `ani_navasan_round_to_thousand()` → nearest 1,000 Toman |
| Simple product preview (JS) | `Math.round(basePrice * rate)` → nearest 1 Toman | `mnswmcRoundToThousand()` → nearest 1,000 Toman |
| Variation preview (JS) | `Math.round(rate * priceVal)` → nearest 1 Toman | `mnswmcRoundToThousand()` → nearest 1,000 Toman |

**Impact:** Before the fix, a product saved via the admin screen would have a price rounded to the nearest 1 Toman, while a subsequent currency-rate recalculation would round to the nearest 1,000 Toman. This produced inconsistent prices depending on which path last wrote the value.

**Classification:** Confirmed (static observation). Fixed in this task.

### 1.3 Recalculation updates `_regular_price` without updating `_price`

**File:** `includes/functions.php`

The original `ani_navasan_lite_recalc_currency` handler updated `_regular_price` via `update_post_meta()` but did not update `_price`.

**WooCommerce behavior (verified against WC 11.2.2 source):**
- `WC_Product::get_price()` returns `get_prop('price')`, which is backed by the `_price` meta field.
- For simple products, the storefront active price is driven by `_price`.
- For variable products, `WC_Product_Variable::get_variation_price()` → `read_price_data()` → `$variation->get_price('edit')` → reads `_price` of each variation.
- When a product is saved via the CRUD API (`$product->save()`), WooCommerce's data store automatically syncs `_price` with `_regular_price` (or `_sale_price` if on sale). This sync does **not** occur when `update_post_meta()` is used directly.
- For variable products, `handle_updated_props()` explicitly skips the `_price` sync (`$price_is_synced = true`). Therefore, `set_price() + save()` on a variable product does **not** update the `_price` meta.

**Impact (confirmed by runtime testing in Tasks 02–03):** After a currency-rate change and recalculation, the storefront displayed the stale `_price` value while `_regular_price` had been updated. This was a confirmed P1 defect.

**Resolution (implemented in Tasks 01–03):**
1. The recalculation handler now uses the WooCommerce CRUD API (`wc_get_product()` → `set_regular_price()` → `save()`) instead of direct `update_post_meta()`. This ensures `_price` is correctly synced based on `is_on_sale()` (handling scheduled and expired sales) and the lookup table and caches are updated.
2. For variable products, the parent's price is synchronized using `WC_Product_Variable::sync($parent)` (the standard WooCommerce API), which correctly updates the parent's `_price` meta (multiple rows), the `wc_product_meta_lookup` table, stock status, and caches.

**Classification:** Confirmed (static + runtime). **Resolved** in Tasks 01–03.

### 1.4 Variation JavaScript selector and input-name inconsistencies

**File:** `assets/js/admin.js`

The variation JavaScript uses multiple selector strategies (ID-based, name-based, class-based) to locate price inputs. The selectors reference `#variable_regular_price_{index}` and `input[name="variable_regular_price[{index}]"]`, which may not match the actual DOM structure produced by WooCommerce's variation edit screen in all versions.

**Impact:** If the selectors do not match, the JavaScript preview may fail to update the Toman price fields for variations.

**Classification:** Suspected (requires runtime verification in a live WooCommerce admin screen).

### 1.5 Possible JavaScript global `$` / WordPress jQuery noConflict issue

**File:** `assets/js/admin.js`

The script uses `jQuery(document).ready(function ($) { ... })`, which is the correct pattern for WordPress (the `$` parameter is scoped to the callback). However, if the script is loaded in a context where jQuery is not available or where noConflict is not properly configured, the `$` reference could fail.

**Classification:** Suspected (requires runtime verification). The current pattern is correct for standard WordPress admin contexts.

---

## 2. Suspected Problems Requiring Runtime Verification

These issues were identified from static code review. Their status has been updated following live runtime testing in Tasks 01–03.

### 2.1 Stale storefront price after recalculation

As described in Section 1.3, the recalculation handler updated `_regular_price` but not `_price`. Since the storefront reads `_price`, the displayed price was stale after recalculation.

**Status: CONFIRMED and RESOLVED.** Live testing in Task 02 confirmed the stale `_price` issue. The fix (WooCommerce CRUD API) was verified to correctly sync `_price` based on `is_on_sale()`, including scheduled and expired sales.

### 2.2 Variation price range staleness

For variable products, the parent's price range is computed from the variations' `_price` values. If the recalculation does not update the variations' `_price`, the parent's price range will be stale.

**Status: CONFIRMED and RESOLVED.** Live testing in Task 03 confirmed that the initial `set_price() + save()` approach did not update the parent's `_price` meta. The fix (`WC_Product_Variable::sync()`) was verified to correctly update the parent's `_price` meta, the lookup table, and the Store API price range.

### 2.3 WooCommerce cache and lookup table staleness

The original recalculation handler used `update_post_meta()` directly, which did not trigger WooCommerce's product save hooks. This meant:
- The `wc_product_meta_lookup` table was not updated.
- Product transients (e.g., `wc_var_prices_{product_id}`) were not invalidated.

**Status: CONFIRMED and RESOLVED.** Live testing in Tasks 02–03 confirmed the cache/lookup table staleness. The fix (WooCommerce CRUD API + `WC_Product_Variable::sync()`) was verified to correctly update the `wc_product_meta_lookup` table and clear product caches.

### 2.4 JavaScript variation selector matching

As described in Section 1.4, the variation JavaScript selectors may not match the actual DOM in all WooCommerce versions.

**Status: NOT VERIFIED (P2, out of scope).** This was not tested in Tasks 01–03. It remains a P2 issue requiring runtime verification in a live admin screen.

---

## 3. Recommendations

1. **Fix the stale `_price` bug in recalculation** (P1) — Use the WooCommerce CRUD API to ensure `_price` is correctly synced. *(Implemented in Task 02. Resolved.)*

2. **Unify rounding to 1,000 Toman** (P1) — Use a shared rounding function across all calculation paths (PHP save, PHP recalculation, JS preview). *(Implemented in Task 01. Resolved.)*

3. **Synchronize variable product parent price via standard API** (P1) — Use `WC_Product_Variable::sync($parent)` instead of `set_price() + save()` to correctly update the parent's `_price` meta, lookup table, and caches. *(Implemented in Task 03. Resolved.)*

4. **Remove the duplicate `woocommerce_admin_process_product_object` handler** (P2) — Consolidate the two identical handlers into one. *(Not implemented; out of scope.)*

5. **Verify variation JavaScript selectors against live WooCommerce DOM** (P2) — Test the variation preview in a live admin screen and fix selectors if they do not match. *(Not implemented; requires runtime testing.)*

6. **Add internationalization (i18n)** (P2) — Wrap all user-facing strings in `__()`, `_e()`, etc. *(Not implemented; out of scope.)*

7. **Add automated tests** (P2) — Set up PHPUnit with the WordPress test framework and write integration tests for the recalculation workflow. *(Not implemented; requires test infrastructure.)*

8. **Scope JavaScript loading to relevant admin screens** (P2) — Load the admin JS only on product edit screens where the currency fields are present. *(Not implemented; out of scope.)*

9. **Invalidate WooCommerce caches after recalculation** (P2) — After updating product meta, call `wc_delete_product_transients()` or equivalent to clear stale price caches. *(Resolved as a side effect of the CRUD API fix in Task 02 and `WC_Product_Variable::sync()` in Task 03.)*

---

## 4. Issues Outside the Scope of This Task

The following issues were identified during the audit but are **not** addressed in the P1 recalculation fixes (Tasks 01–03):

- Duplicate `woocommerce_admin_process_product_object` handler (P2, code quality).
- Variation JavaScript selector inconsistencies (P2, requires runtime verification).
- JavaScript global `$` / noConflict concern (P2, requires runtime verification).
- Missing internationalization (P2).
- Missing automated tests and project documentation (P2).
- JavaScript loaded on broader admin screens than necessary (P2, performance).
- Security hardening opportunities (see Section 5).

**Previously listed but now resolved:**
- ~~WooCommerce cache/lookup table invalidation after recalculation~~ — **Resolved** in Tasks 02–03 (CRUD API + `WC_Product_Variable::sync()`).
- ~~Stale `_price` after recalculation~~ — **Resolved** in Tasks 02–03 (CRUD API + `WC_Product_Variable::sync()`).
- ~~Variable product parent price range staleness~~ — **Resolved** in Task 03 (`WC_Product_Variable::sync()`).

---

## 5. Security Hardening Opportunities

The following are **hardening opportunities** identified during the audit. **None of these have been demonstrated to be exploitable vulnerabilities.** They are listed for awareness and future consideration.

### 5.1 Nonce verification in AJAX endpoints

The AJAX endpoint `mnswmc_get_exchange_rate` verifies a nonce (`_ajax_nonce`). This is correct. No action needed.

### 5.2 Capability checks in save handlers

The `save_post_mnswmc` handler checks `current_user_can('edit_post', $post_id)`. This is correct. No action needed.

The `woocommerce_admin_process_product_object` and `woocommerce_save_product_variation` handlers do not perform explicit capability checks, but they are hooked into WooCommerce's product save flow, which already requires the `edit_post` capability. This is acceptable.

### 5.3 Input sanitization

All user input is sanitized with `wc_clean()` or `sanitize_text_field()` before being stored. This is correct. No action needed.

### 5.4 SQL injection

The recalculation query uses `$wpdb->prepare()` with a `%d` placeholder. This is correct and prevents SQL injection. No action needed.

### 5.5 Output escaping

Admin-screen output uses `esc_attr()` and `esc_html()` where appropriate. No action needed.

**Conclusion:** No exploitable security vulnerabilities were identified. The security posture of the plugin is adequate for its current scope.

---

## 6. Test Environment Status

**A live local WordPress + WooCommerce environment was used for runtime testing in Tasks 01–03.**

| Item | Value |
|---|---|
| Test site | `http://anishop-package-test.local` (Local by Flywheel) |
| WordPress | 7.1.4 |
| WooCommerce | 11.2.2 |
| PHP | 8.3.30 |
| Plugin under test | `ani-navasan-lite` 1.0.0 (installed from local source, branch `fix/recalculation-p1`) |
| Original Navasan plugin | **Not installed** (no conflict) |
| Access method | **WP-CLI 2.12.0** (Novamira MCP tools were not loaded into the AI session) |
| Node.js | v22.23.4 (for JS rounding equivalence) |

**Consequence:** All P1 findings in this audit have been runtime-verified in a live environment. The stale `_price` issue, the variable product parent price range staleness, and the cache/lookup table staleness were all confirmed and resolved. See `2026-11-10-recalculation-p1.md` for the full test results.

---

## 7. Files Inspected

| File | Purpose |
|---|---|
| `includes/functions.php` | Recalculation handler, helper functions |
| `includes/currencies-metabox.php` | Currency CPT metabox, rate save trigger |
| `includes/hooks.php` | Plugin hooks and asset loading |
| `admin/metabox.php` | Product/variation metabox fields, save handlers |
| `assets/js/admin.js` | Client-side price calculation and preview |
| `woocommerce/includes/abstracts/abstract-wc-product.php` | WC `get_price()` implementation |
| `woocommerce/includes/class-wc-product-variable.php` | WC variable product price range, `sync()` method |
| `woocommerce/includes/data-stores/class-wc-product-variable-data-store-cpt.php` | WC `read_price_data()` implementation |
| `woocommerce/includes/data-stores/class-wc-product-data-store-cpt.php` | WC data store `_price` sync logic, `handle_updated_props()` |
| `woocommerce/includes/data-stores/class-wc-data-store-wp.php` | WC lookup table update logic |
| `woocommerce/includes/wc-product-functions.php` | WC `wc_update_product` function, `_price` sync workaround |
| `woocommerce/includes/legacy/abstract-wc-legacy-product.php` | WC legacy product `sync_attributes()` implementation |


