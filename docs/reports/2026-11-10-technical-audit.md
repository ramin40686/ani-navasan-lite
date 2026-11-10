# Technical Audit — Ani Navasan Lite

**Date:** 2026-11-10
**Scope:** Static code review of the currency-dependent product price recalculation workflow
**Method:** Source-code inspection (no runtime testing performed for this audit)

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

The `ani_navasan_lite_recalc_currency` handler updates `_regular_price` via `update_post_meta()` but does not update `_price`.

**WooCommerce behavior (verified against WC 11.2.1 source):**
- `WC_Product::get_price()` returns `get_prop('price')`, which is backed by the `_price` meta field.
- For simple products, the storefront active price is driven by `_price`.
- For variable products, `WC_Product_Variable::get_variation_price()` → `read_price_data()` → `$variation->get_price('edit')` → reads `_price` of each variation.
- When a product is saved via the CRUD API (`$product->save()`), WooCommerce's data store automatically syncs `_price` with `_regular_price` (or `_sale_price` if on sale). This sync does **not** occur when `update_post_meta()` is used directly.

**Impact (suspected, requires runtime verification):** After a currency-rate change and recalculation, the storefront may display the stale `_price` value while `_regular_price` has been updated. This is a suspected P1 defect. The fix in this task adds an explicit `_price` update (guarded by an on-sale check) to the recalculation handler.

**Classification:** Confirmed static observation. Runtime impact suspected but not yet verified in a live WordPress + WooCommerce environment (see Section 4).

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

These issues are identified from static code review but cannot be confirmed or ruled out without a live WordPress + WooCommerce environment.

### 2.1 Stale storefront price after recalculation

As described in Section 1.3, the recalculation handler updates `_regular_price` but not `_price`. Since the storefront reads `_price`, the displayed price may be stale after recalculation. **This requires runtime testing to confirm.**

### 2.2 Variation price range staleness

For variable products, the parent's price range is computed from the variations' `_price` values. If the recalculation does not update the variations' `_price`, the parent's price range will be stale. **This requires runtime testing to confirm.**

### 2.3 WooCommerce cache and lookup table staleness

The recalculation handler uses `update_post_meta()` directly, which does not trigger WooCommerce's product save hooks. This means:
- The `wc_product_meta_lookup` table is not updated.
- Product transients (e.g., `wc_var_prices_{product_id}`) are not invalidated.

**Impact:** Price-based filtering and cached price ranges may show stale values until the cache expires or is manually cleared. **This requires runtime testing to confirm the practical impact.**

### 2.4 JavaScript variation selector matching

As described in Section 1.4, the variation JavaScript selectors may not match the actual DOM in all WooCommerce versions. **This requires runtime verification in a live admin screen.**

---

## 3. Recommendations

1. **Fix the stale `_price` bug in recalculation** (P1) — Add an explicit `_price` update to the recalculation handler, guarded by an on-sale check. *(Implemented in this task.)*

2. **Unify rounding to 1,000 Toman** (P1) — Use a shared rounding function across all calculation paths (PHP save, PHP recalculation, JS preview). *(Implemented in this task.)*

3. **Remove the duplicate `woocommerce_admin_process_product_object` handler** (P2) — Consolidate the two identical handlers into one. *(Not implemented in this task; out of scope.)*

4. **Verify variation JavaScript selectors against live WooCommerce DOM** (P2) — Test the variation preview in a live admin screen and fix selectors if they do not match. *(Not implemented in this task; requires runtime testing.)*

5. **Add internationalization (i18n)** (P2) — Wrap all user-facing strings in `__()`, `_e()`, etc. *(Not implemented in this task; out of scope.)*

6. **Add automated tests** (P2) — Set up PHPUnit with the WordPress test framework and write integration tests for the recalculation workflow. *(Not implemented in this task; requires test infrastructure.)*

7. **Scope JavaScript loading to relevant admin screens** (P2) — Load the admin JS only on product edit screens where the currency fields are present. *(Not implemented in this task; out of scope.)*

8. **Invalidate WooCommerce caches after recalculation** (P2) — After updating product meta, call `wc_delete_product_transients()` or equivalent to clear stale price caches. *(Not implemented in this task; requires runtime verification of impact.)*

---

## 4. Issues Outside the Scope of This Task

The following issues were identified during the audit but are **not** addressed in this task (P1 recalculation fixes):

- Duplicate `woocommerce_admin_process_product_object` handler (P2, code quality).
- Variation JavaScript selector inconsistencies (P2, requires runtime verification).
- JavaScript global `$` / noConflict concern (P2, requires runtime verification).
- Missing internationalization (P2).
- Missing automated tests and project documentation (P2).
- JavaScript loaded on broader admin screens than necessary (P2, performance).
- Security hardening opportunities (see Section 5).
- WooCommerce cache/lookup table invalidation after recalculation (P2, requires runtime verification).

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

**No disposable WordPress + WooCommerce test environment was available for this audit.**

- Docker is available (v29.9.3) but no WordPress containers are running.
- WP-CLI is available (v2.12.0) but no WordPress installation is configured for it.
- Three Studio sites exist (`kafshkohestan`, `my-wordpress-website`, `newaniblocksy`) with WooCommerce installed, but none are confirmed to be disposable test environments.
- PHPUnit is not installed.

**Consequence:** All findings in this audit are based on static code analysis. Runtime behavior (especially the stale `_price` issue) has not been verified in a live environment.

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
| `woocommerce/includes/class-wc-product-variable.php` | WC variable product price range |
| `woocommerce/includes/data-stores/class-wc-product-variable-data-store-cpt.php` | WC `read_price_data()` implementation |
| `woocommerce/includes/data-stores/class-wc-product-data-store-cpt.php` | WC data store `_price` sync logic |


