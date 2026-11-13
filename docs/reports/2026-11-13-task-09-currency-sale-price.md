# Task 09 — Dependence-Type Alignment + Currency Sale Price Support

**Date:** 2026-11-13
**Branch:** `feat/task-09-currency-sale-price` (from `test/task-08-metadata-compatibility`)
**Status:** COMPLETE — live smoke test 45/45 PASS (PHP 8.4.10 / WP 7.1.4 / WC 11.3.0); live sale-price + migration verification on authorized test data PASS; state fully restored.

---

## 1. Task Objective

Align Ani Navasan Lite with the main Navasan plugin's data contract for two metadata keys:

1. **`_mnswmc_dependence_type`** — the main plugin uses the values `simple` and `advanced`. Lite previously used `simple` (simple products) and `variable` (variations). This task:
   - Adds a `simple`/`advanced` select to the simple-product and variation admin sections.
   - Persists only `simple` or `advanced` on save (whitelist validation).
   - Adds a one-time activation migration `ani_navasan_lite_migrate_dependence_type()` that normalizes legacy `variable` → `advanced` (idempotent, never alters `simple`/`advanced`).
   - Adds an admin-only notice (shown when `advanced` is selected) explaining that Lite does not implement advanced formulas — both types use the same calculation (currency price × rate, rounded to the nearest 1,000 Toman).

2. **`_mnswmc_sale_price`** — the main plugin stores the **currency-denominated** sale price under this key. Lite previously had no equivalent (it preserved the WC standard `_sale_price` in Toman). This task:
   - Adds a currency sale price field for simple products and variations.
   - Calculates the Toman sale price (currency sale price × rate, rounded to the nearest 1,000) and saves it through the WooCommerce CRUD API (`set_sale_price()` → `save()`).
   - **Empty `_mnswmc_sale_price` must not create or force a WooCommerce sale price** — the existing WC sale price (including scheduled sales) is preserved.
   - Extends the rate-change recalculation to update the Toman sale price when `_mnswmc_sale_price` is present, without creating a sale price when empty.

> **Scope note:** Lite does **not** implement advanced pricing formulas. Both `simple` and `advanced` dependence types use the same calculation in Lite. The `advanced` value is stored for metadata compatibility with the main plugin; the admin notice makes this limitation explicit.

## 2. Initial Findings

- The main plugin's readable source (from Task 08) confirms `_mnswmc_dependence_type` uses `simple`/`advanced` and `_mnswmc_sale_price` is the currency-denominated sale price.
- Lite's simple-product save handler hardcoded `_mnswmc_dependence_type = 'simple'`; the variation save handler hardcoded `variable`.
- Lite's recalculation handler updated only the regular price and floor price; it did not touch the sale price.
- The `save_post_product` hook in `includes/hooks.php` rounded scalar currency fields to 2 decimals; `_mnswmc_sale_price` was not in the list.
- The admin JS had no sale-price handling for simple products or variations.
- Authorized test data on `anishop-package-test.local`:
  - Simple product `17613` (active, currency `17654`, regular 100, native WC sale 120000).
  - Variable parent `17615`; variations `17616` (active, currency `17654`, regular 100, legacy `_mnswmc_dependence_type=variable`) and `17617` (inactive).
  - Currencies `17654` (TST, rate 2000) and `17655`.

## 3. Files Inspected

- `ani-navasan-lite.php` (activation hook)
- `includes/functions.php` (rounding, recalculation handler, migrations)
- `includes/hooks.php` (scalar decimal rounding on `save_post_product`)
- `admin/metabox.php` (simple/variation metabox fields + save handlers)
- `assets/js/admin.js` (admin live-preview JS)
- `assets/css/admin.css` (admin styles)
- `tests/compat/smoke-test.php` (CLI smoke-test harness)
- `docs/reports/2026-11-13-task-08-metadata-compatibility.md` (main-plugin contract evidence)
- Live site postmeta for `17613`/`17615`/`17616`/`17617`/`17654`/`17655`

## 4. Files Created, Modified, or Deleted

| File | Change |
|---|---|
| `admin/metabox.php` | **Modified** — added `_mnswmc_dependence_type` select (simple/advanced) + advanced notice for simple products and variations; added `_mnswmc_sale_price` field for both; simple-product save handler now persists the dependence type (whitelist) and currency sale price, calculates Toman regular/sale prices, and saves via CRUD; variation save handler does the same per-loop; preview boxes include the sale Toman price line. |
| `ani-navasan-lite.php` | **Modified** — activation hook now also runs `ani_navasan_lite_migrate_dependence_type()`; fixed a duplicate `/**` comment. |
| `includes/functions.php` | **Modified** — added `ani_navasan_lite_migrate_dependence_type()` (one-time, `variable`→`advanced`, idempotent); recalculation handler now reads `_mnswmc_sale_price` and, when > 0, recalculates and sets the Toman sale price via CRUD (empty → no change, preserving WC sale scheduling). |
| `includes/hooks.php` | **Modified** — added `_mnswmc_sale_price` to the scalar decimal rounding list; added an `!is_array()` guard so variation (array) values are not processed in the simple-product save path. |
| `assets/js/admin.js` | **Modified** — simple product: currency sale price visibility, inline Toman sale calculation, WC sale price readonly when active, preview output, advanced notice toggle; variation: `_mnswmc_sale_price_{loop}` in the visible field group, Toman sale calculation, WC variation sale price readonly, preview sale output, per-variation advanced notice toggle. |
| `assets/css/admin.css` | **Modified** — styles for `.ani_navasan_advanced_notice` and `.mnswmc-preview .sale-final` / `.ani_navasan_preview .ani_final_sale_price`. |
| `tests/compat/smoke-test.php` | **Modified** — new **S10 section (8 checks)**: legacy `variable`→`advanced` migration, migration idempotence, simple-product currency sale → Toman sale, empty currency sale → no WC sale price, variation currency sale → Toman sale; cleanup extended for the temporary products. |
| `docs/reports/2026-11-13-task-09-currency-sale-price.md` | **Created** — this report. |
| `docs/نقشه راه توسعه Ani Navasan Lite.md` | **Modified** — Task 09 row updated to the actual scope; the former Task 09 (Live DOM verification) renumbered to Task 09b. |

No files were deleted. No other plugin files were touched.

## 5. Detailed Description of Changes

### 5.1 Dependence-type select + advanced notice (admin)

- **Simple product** (`woocommerce_product_options_pricing`): a `woocommerce_wp_select` for `_mnswmc_dependence_type` with options `simple`/`advanced`. The current value is read from meta; a legacy `variable` is displayed as `advanced`; any other/empty value defaults to `simple`.
- **Variation** (`woocommerce_product_after_variable_attributes`): the same select per loop index (`_mnswmc_dependence_type_{loop}` / `_mnswmc_dependence_type[{$loop}]`), defaulting to `advanced` for variations.
- **Advanced notice**: a hidden `.ani_navasan_advanced_notice` div is rendered in both sections; the admin JS toggles its visibility when the selected value is `advanced`. The notice states that Lite does not implement advanced formulas and that both types use the same calculation.

### 5.2 Dependence-type persistence (save handlers)

- **Simple product** (`woocommerce_admin_process_product_object`): reads `$_POST['_mnswmc_dependence_type']`, sanitizes with `wc_clean()`, and whitelists to `simple`/`advanced` (default `simple`). Persists via `$product->update_meta_data()`.
- **Variation** (`woocommerce_save_product_variation`): reads `$_POST['_mnswmc_dependence_type'][$i]` (correct loop-index mapping), sanitizes with `wc_clean()`, and whitelists to `simple`/`advanced` (default `advanced`). Persists via `update_post_meta()`.
- The previous hardcoded values (`simple` for simple products, `variable` for variations) are removed.

### 5.3 One-time migration — `ani_navasan_lite_migrate_dependence_type()`

- Runs from the **activation hook** (admin-only context), after the rate-key migration.
- Queries all `postmeta` rows with `meta_key = '_mnswmc_dependence_type'` and `meta_value = 'variable'`, and updates each to `advanced`.
- **Never alters** `simple`/`advanced` values → idempotent, no data loss.
- Records completion in the option `ani_navasan_lite_migrated_dependence_type` (informational; the function is safe to re-run regardless).

### 5.4 Currency sale price field (admin)

- **Simple product**: a `woocommerce_wp_text_input` for `_mnswmc_sale_price` (number, step 0.01, min 0), shown only when currency dependence is active (part of the currency field group).
- **Variation**: the same input per loop index (`_mnswmc_sale_price_{loop}` / `_mnswmc_sale_price[{$loop}]`), shown only when the variation's currency dependence is active.
- The field stores the **currency-denominated** sale price (not Toman).

### 5.5 Toman sale price calculation (save handlers)

- **Simple product**: when currency dependence is active and a rate exists, the handler reads `_mnswmc_regular_price` and `_mnswmc_sale_price`. If the regular currency price > 0, it sets the Toman regular price via `set_regular_price()`. If the sale currency price > 0, it sets the Toman sale price via `set_sale_price()`; **otherwise it clears the sale price** (`set_sale_price('')`) so a stale sale price is not left behind when the user removes the currency sale price. The product is saved once via CRUD.
- **Variation**: the same logic per loop index, using `wc_get_product($variation_id)` and `set_regular_price()`/`set_sale_price()`/`save()`.
- The floor price (`_torob_scraper_min_price`) is still recalculated and stored as meta (unchanged behavior).

### 5.6 Rate-change recalculation (sale price)

- The `ani_navasan_lite_recalc_currency` handler now reads `_mnswmc_sale_price`. When the value > 0, it recalculates the Toman sale price (currency sale price × rate, rounded to the nearest 1,000) and sets it via `set_sale_price()`. **When the value is empty, the handler does not touch the sale price** — this preserves the existing WC sale price (including scheduled sales) during a rate change.
- The regular price and floor price recalculation are unchanged.

### 5.7 Scalar decimal rounding (`includes/hooks.php`)

- `_mnswmc_sale_price` was added to the list of currency fields rounded to 2 decimals on `save_post_product`.
- An `!is_array()` guard was added so that variation (array) values posted from a variable-product form are not processed in the simple-product save path (they are handled by the variation save handler).

### 5.8 Admin JS

- **Simple product**:
  - `findSimpleSalePrice()` helper locates the WC sale price input (robust selector fallback).
  - The currency sale price field is added to the visible currency field group.
  - `applySimpleRate()` now takes a `salePrice` argument and calculates the Toman sale price (`mnswmcRoundToThousand(salePrice * rate)` when > 0, else empty).
  - The WC sale price input is set to the calculated Toman value and made readonly when currency dependence is active.
  - The preview box shows the sale Toman price (`.ani_final_sale_price`).
  - The advanced notice is toggled based on the `_mnswmc_dependence_type` value.
- **Variation**:
  - `_mnswmc_sale_price_{loop}` is added to the visible currency field group.
  - The Toman sale price is calculated from `_mnswmc_sale_price_{loop}` and written to the corresponding WC variation sale price field.
  - The WC variation regular and sale price fields are made readonly when the variation's currency dependence is active.
  - The variation preview box shows the sale Toman price (`.sale-final`).
  - The advanced notice is toggled per variation based on `_mnswmc_dependence_type_{loop}`.

### 5.9 Admin CSS

- `.ani_navasan_advanced_notice` — yellow notice box styling.
- `.mnswmc-preview .sale-final` and `.ani_navasan_preview .ani_final_sale_price` — bold blue styling for the sale Toman price in the preview boxes.

## 6. Security Implications

- **Input validation**: the dependence type is whitelisted to `simple`/`advanced` in both save handlers (any other value is rejected and the default is used). The currency sale price is a numeric field (step 0.01, min 0) and is cast to float before calculation.
- **Sanitization**: `wc_clean()` is applied to the dependence type input. The sale price is rounded to 2 decimals by the existing `save_post_product` hook.
- **No new AJAX endpoints**: the task reuses the existing `mnswmc_get_exchange_rate` endpoint (unchanged). No new nonce or capability surface was introduced.
- **No direct DB writes for prices**: all Toman price updates go through the WooCommerce CRUD API (`set_regular_price()`/`set_sale_price()` → `save()`), so `_price`, the `wc_product_meta_lookup` table, and caches are updated correctly.
- **Migration safety**: the dependence-type migration only updates rows with the exact value `variable` to `advanced`; it never deletes data and is idempotent.
- **No credentials or secrets** were added, logged, or committed.

## 7. Compatibility Implications

- **Metadata contract**: `_mnswmc_dependence_type` now uses the main plugin's `simple`/`advanced` values. Legacy `variable` values are migrated to `advanced` on activation. This improves interoperability with the main plugin (a product saved by Lite can be read by the main plugin without a `variable` value).
- **Sale price contract**: `_mnswmc_sale_price` now stores the currency-denominated sale price, matching the main plugin's contract. The Toman sale price is stored in the WC standard `_sale_price` (unchanged).
- **Backward compatibility**: existing products with `_mnswmc_dependence_type = variable` are migrated to `advanced` on activation. Existing products with `simple`/`advanced` are unchanged. Existing WC sale prices are preserved when the currency sale price is empty.
- **No breaking changes** to the public API, hooks, or existing metadata keys.
- **Rounding policy** (nearest 1,000 Toman) is unchanged and consistent between PHP and JS.

## 8. Tests Executed and Results

### 8.1 Static checks

- `php -l` on all 6 edited PHP files: **PASS** (no syntax errors).
- JS syntax check on `assets/js/admin.js`: **PASS**.

### 8.2 Live smoke test (authorized site `anishop-package-test.local`)

- Command: `cd ~/Local\ Sites/anishop-package-test/app/public && COMPAT_LABEL="task09-live" wp eval-file /tmp/smoke-test-task09.php`
- Environment: **PHP 8.4.10 / WP 7.1.4 / WC 11.3.0**
- Result: **45/45 checks PASS, 0 failures** (including the new S10 section: 8 checks).
- S10 checks:
  - `S10.1 dep_type_variable_to_advanced` — PASS
  - `S10.2 dep_type_simple_advanced_unchanged` — PASS
  - `S10.3 sale_regular_300000` — PASS
  - `S10.4 sale_price_240000` — PASS
  - `S10.5 empty_sale_no_wc_sale_price` — PASS
  - `S10.6 empty_sale_regular_300000` — PASS
  - `S10.7 variation_sale_regular_150000` — PASS
  - `S10.8 variation_sale_price_120000` — PASS

### 8.3 Live migration verification (authorized test data)

- Variation `17616` had the legacy `_mnswmc_dependence_type = variable`. After the smoke test ran the migration (a global operation), it became `advanced`.
- Re-running the migration manually: **idempotent** (no change).
- Option `ani_navasan_lite_migrated_dependence_type` = `1`.
- Product `17613` (`simple`) and variation `17617` (`advanced`) were **not altered**.

### 8.4 Live sale-price verification (authorized test data)

- **Simple product `17613`** (currency `17654`, regular 100):
  - Set `_mnswmc_sale_price = 60`, rate 2000→2500 (real admin save workflow): regular = **250000** (100×2500), sale = **150000** (60×2500), on_sale = yes. **PASS**
  - Cleared `_mnswmc_sale_price`, rate 2500→2000: regular = **200000** (100×2000), sale = **150000** (NOT cleared by recalc, as designed). **PASS**
  - Restored to original state (regular 200000, sale 120000, `_mnswmc_sale_price` empty). **Verified against backup: ALL MATCH.**
- **Variation `17616`** (currency `17654`, regular 100):
  - Set `_mnswmc_sale_price = 60`, rate 2000→2500: var regular = **250000**, var sale = **150000**, parent price = **150000** (derived from variation). **PASS**
  - Cleared `_mnswmc_sale_price`, rate 2500→2000: var regular = **200000**, var sale = **150000** (NOT cleared by recalc). **PASS**
  - Restored to original state (var regular 200000, var sale empty, parent price 200000). **Verified against backup: ALL MATCH.**

### 8.5 Frontend verification (Store API over HTTP)

- `17613`: regular=200000, sale=120000, price=120000. **PASS**
- `17615` (parent): price=200000, regular=200000, sale=200000. **PASS**

### 8.6 State restoration

- A full backup of the 4 test products' relevant meta was taken before live testing (`/tmp/task09-backup.json`).
- After all live tests, the state was restored and verified: **ALL MATCH** (no residual changes to the authorized test data).

## 9. Known Limitations

- **Lite does not implement advanced formulas**: both `simple` and `advanced` use the same calculation (currency price × rate, rounded to the nearest 1,000 Toman). The `advanced` value is stored for metadata compatibility only; the admin notice makes this explicit.
- **Recalc does not clear the sale price**: when `_mnswmc_sale_price` is empty, the rate-change recalculation does not clear the existing WC sale price (by design, to preserve WC sale scheduling). The sale price is only cleared by the save handler when the user explicitly removes the currency sale price and saves the product.
- **Live DOM verification of the admin JS** (selectors, readonly behavior, preview rendering) was **not** performed in a real browser in this session — it remains as Task 09b (see roadmap). The JS logic was verified by code review and the CLI smoke test covers the server-side calculation, but the browser-side behavior needs manual confirmation.
- **Coexistence with the main plugin** remains BLOCKED (main plugin activation is blocked by the unauthorized ionCube encoder, per Task 08). The metadata contract is verified against the main plugin's readable source, but a real coexistence test requires a legitimately licensed copy.

## 10. Remaining Issues

- **Task 09b (Live DOM verification)**: verify the admin JS selectors, readonly behavior, and preview rendering in a real browser on the authorized site (products `17613`/`17615`, variations `17616`/`17617`).
- **`wp_wc_product_meta_lookup` assertions** (2 Task 07 Scenario 1 checks) remain unresolved (cause not confirmed; consistent with a `wp eval-file` CLI-context effect).
- **Coexistence with the main plugin** remains BLOCKED (unauthorized ionCube encoder).

## 11. Git Branch Name

`feat/task-09-currency-sale-price`

## 12. Commit Hash

(See git log after commit — to be filled in after the commit is created.)

## 13. Push Status

(To be filled in after the push is completed.)

