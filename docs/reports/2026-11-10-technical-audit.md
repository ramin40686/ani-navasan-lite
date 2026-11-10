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

4. **Remove the duplicate `woocommerce_admin_process_product_object` handler** (P2) — Consolidate the two identical handlers into one. *(Implemented in Task 05. Resolved.)*

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

---

## 8. Task 05 — Product Attribute Recovery & Code Cleanup (2026-11-10)

**Branch:** `fix/restore-attributes-and-deduplicate`

This section documents the corrective actions implemented in Task 05, based on the Task 04 investigation findings.

### 8.1 Confirmed Attribute Corruption (P0)

During the Task 04 investigation, it was confirmed that parent product `17615` had **double-serialized** `_product_attributes` and `_default_attributes` meta values:

| Meta key | Corrupted state (raw DB value) |
|---|---|
| `_product_attributes` | `s:5522:"a:31:{...}"` (a serialized *string* containing a serialized array) |
| `_default_attributes` | `s:57:"a:1:{...}"` (a serialized *string* containing a serialized array) |

**Effect:** `WC_Product::get_attributes()` unserializes the value to a *string* (not an array), so WooCommerce returned **zero attributes** for the product. The variation records (`17616`, `17617`) and their price/currency meta were **not** affected.

**Root cause:** **NOT CONFIRMED.** The exact mechanism that produced the double serialization was not established by the investigation. It is *likely* related to a restore/test sequence followed by a WooCommerce product save, but this is not proven. No code in the plugin was found that directly writes `_product_attributes`.

**Recovery:** The corrupted values were restored from the pre-corruption snapshot (`/tmp/task03_snapshot.json`, captured before the Task 03 corruption). See Section 8.2.

### 8.2 Restoration Procedure and Serialization Prevention

The restoration followed a strict procedure to prevent re-corruption:

1. Read the serialized string from the snapshot.
2. Convert it to a PHP value using `maybe_unserialize()`.
3. Verify the result is an array (and has the expected 31 attributes / default color).
4. Store the **PHP array** using `update_post_meta()` (not `add_post_meta()`).

**Critical serialization rule:** Passing an already-serialized string directly to `update_post_meta()` would cause double serialization *again*, because WordPress automatically serializes arrays/objects on write. The correct approach is to pass the **unserialized PHP array**, letting WordPress serialize it exactly once.

**Verification after restoration:**
- `get_post_meta(17615, '_product_attributes', true)` returns an array with 31 entries.
- `get_post_meta(17615, '_default_attributes', true)` returns an array with the default color.
- Raw DB values start with `a:` (single-serialized), not `s:` (double-serialized).
- `wc_get_product(17615)->get_attributes()` returns 31 attributes.
- `wc_get_product(17615)->get_default_attributes()` returns the default color.
- Variations `17616`/`17617` remain unchanged (parent, type, status intact).
- No duplicate meta rows (row count = 1 for each key).

A pre-restore backup of the corrupted state was saved to `/tmp/task05_backup_corrupted.json` (outside the Git repository) to allow exact recovery if the restoration had failed.

### 8.3 Duplicate Handler Removal (Section 1.1 → Resolved)

The duplicate `woocommerce_admin_process_product_object` handler was removed. The two handlers were verified to be **byte-for-byte identical** in their function bodies (confirmed by `diff`). Exactly one registration and its duplicate callback were removed; the remaining handler (now at line 121 of `admin/metabox.php`) is unchanged. No other file depended on the second callback.

### 8.4 Dead JavaScript Hidden-Input Removal (Section 1.4 → Partially Resolved)

The `woocommerce_variations_save_variations` handler in `assets/js/admin.js` generated hidden inputs with names such as `variable_mnswmc_active[variationId]`. A full-plugin search confirmed **no PHP or JavaScript consumer** reads these names. The actual variation form fields use `_mnswmc_active[{$loop}]` (loop index), which is what the PHP `woocommerce_save_product_variation` handler reads (`$_POST['_mnswmc_active'][$i]`). WooCommerce's save loop (`class-wc-meta-box-product-data.php`) iterates `$i` from `0` to `$max_loop` and calls the hook with `($variation_id, $i)`, confirming the loop-index mapping. The dead hidden-input generation block was removed.

**Note:** The broader variation JavaScript selector concerns in Section 1.4 (e.g., `#variable_regular_price_{index}`) remain a P2 item requiring live admin-screen verification and are out of scope for Task 05.

### 8.5 Variable Product Synchronization Optimization (P2 → Resolved)

The recalculation handler previously called `WC_Product_Variable::sync($parent)` with the default `$save = true`, which triggers a **full product save**. This was changed to `WC_Product_Variable::sync($parent, false)` plus explicit cache clearing.

**WooCommerce source evidence (WC 11.2.2, `class-wc-product-variable.php::sync()`):**

| Step in `sync()` | Writes to DB directly? | Requires `$save`? |
|---|---|---|
| `sync_price()` | **Yes** — deletes/re-adds parent `_price` meta and updates `wc_product_meta_lookup` | No |
| `sync_stock_status()` | **No** — only sets in-memory value ("Change does not persist unless saved by caller") | Yes (to persist) |
| `sync_attributes()` | No-op for modern products (only for `_product_version` < 2.4.0) | N/A |
| `$product->save()` (when `$save = true`) | Yes — full save, fires all product save hooks, calls `clear_caches()` | — |

**Decision rationale:**
- `sync_price()` already persists the parent `_price` meta and the lookup table directly, so the price is correct without a save.
- In the price-recalculation use case, stock status is not being changed, so not persisting `sync_stock_status()` is acceptable.
- `sync_price()` does **not** clear caches, so explicit cache clearing is added: `wc_delete_product_transients()`, `WC_Cache_Helper::invalidate_cache_group('product_' . $id)`, and `clean_post_cache()`.
- Avoiding the full product save prevents re-persisting `_product_attributes` (which carried a double-serialization risk) and avoids firing unrelated product save hooks.

**Constraint honored:** No manual SQL or custom price synchronization was introduced; the parent price continues to be derived from its variations via the standard WooCommerce API. No separate regular/sale price is set on the variable parent.

### 8.6 Live Test Results (Task 05)

Live integration testing was performed on `http://anishop-package-test.local` (WC 11.2.2, PHP 8.3.30) using the authorized test products (parent `17615`, variations `17616`/`17617`). The recalculation was exercised by firing `ani_navasan_lite_recalc_currency` with an explicit rate (the referenced currency CPTs do not exist in the test environment, so the rate was passed directly).

| Check | Result |
|---|---|
| Variation `17616` `_regular_price`/`_price` updated (485 × 71000 = 34435000) | **PASS** |
| Variation `17617` unchanged (33600000) | **PASS** |
| Parent min/max price (33600000 / 34435000) | **PASS** |
| Parent `_price` meta rows (multi-row, one per distinct child price) | **PASS** |
| `wc_product_meta_lookup` updated (parent + both variations) | **PASS** |
| Store API price range reflects new max | **PASS** |
| Parent attributes intact (31, valid array) after sync | **PASS** |
| `_product_attributes` still single-serialized (`a:`) after sync | **PASS** |
| Parent stock status unchanged (`instock`) | **PASS** |
| Original state restored after re-running at original rate (70000) | **PASS** |
| Stability under repeated recalculation (3 alternating runs) | **PASS** (no cumulative error) |

**Simple product handler (non-destructive verification):** The remaining `woocommerce_admin_process_product_object` handler was verified against an existing simple product (`4378`): currency activation, currency selection, currency price storage, Toman calculation (240 × 70000 = 16800000), rounding to 1,000 Toman, and dependence type all correct. The handler does not modify `_sale_price` (preserved) and only updates `_torob_scraper_min_price` when a currency min price is present.

### 8.7 Remaining Risks

- **Original corruption cause unconfirmed.** The double-serialization mechanism is not proven. If the underlying trigger (e.g., a specific restore/save sequence) still exists, the corruption could recur. A future task should instrument the `_product_attributes` write path to capture the exact trigger.
- **Stock status not persisted by `sync($parent, false)`.** In the price-recalculation flow this is safe (stock is unchanged), but if a future change also modifies variation stock during recalculation, the parent stock status would need an explicit save or a separate `sync_stock_status($parent, true)` call.
- **Referenced currency CPTs missing in test environment.** The variations reference currency IDs (`15939`, `4168`) whose CPT posts do not exist in the local test site. The recalculation was tested by passing the rate directly; a full end-to-end test (rate saved via the currency metabox) could not be performed without creating currency posts.
- **Section 1.4 variation JS selectors** remain unverified against a live admin DOM (P2).


