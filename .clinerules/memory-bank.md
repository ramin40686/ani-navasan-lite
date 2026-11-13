# Ani Navasan Lite — Memory Bank (Active Context)

> I am Cline. My memory resets completely between sessions; this Memory Bank is my only link to previous work. **Read it fully at the start of every task.** It must reflect verified project state only — never record assumptions or unconfirmed root causes as facts.
>
> **Last updated:** 2026-11-13 (Task 09 **COMPLETE**: (1) `_mnswmc_dependence_type` aligned to the main plugin's `simple`/`advanced` contract — select added for simple products + variations, whitelisted on save, one-time activation migration `ani_navasan_lite_migrate_dependence_type()` normalizes legacy `variable`→`advanced` (idempotent); Lite does NOT implement advanced formulas (both types use the same calculation; admin notice explains this). (2) Currency sale price support — `_mnswmc_sale_price` (currency-denominated) field for simple products + variations; Toman sale price calculated via WC CRUD; empty value does NOT create/force a WC sale price and recalc preserves the existing WC sale price. Live smoke test **45/45 PASS** (PHP 8.4.10 / WP 7.1.4 / WC 11.3.0); live sale-price + migration verification on authorized test data PASS; state fully restored (ALL MATCH). Report: `docs/reports/2026-11-13-task-09-currency-sale-price.md`. Branch `feat/task-09-currency-sale-price` pushed (commits `867f65b` + `0a7b403`), **not merged**. Former Task 09 (Live DOM verification) renumbered to **Task 09b** in the roadmap.)

---

## A. Project Brief

- **Project name:** Ani Navasan Lite (نوسان آنی لایت)
- **Repository:** `ramin40686/ani-navasan-lite`
- **Type:** Lightweight WooCommerce currency-dependent pricing plugin (WordPress).

**Purpose:** A lightweight WooCommerce currency-dependent pricing plugin. The store administrator can:

- Define currencies manually (a dedicated `mnswmc` post type).
- Set and edit each currency's rate against **Toman**.
- Enable or disable currency dependency for individual **simple products** and **variations**.
- Select the relevant currency.
- Enter the currency-denominated price.
- See the calculated Toman price in the product editor.

The customer sees the final **Toman** price on the frontend. When an administrator changes a currency rate, dependent product and variation prices are recalculated.

**Mutual exclusivity:** The plugin deactivates itself if the main Navasan plugin (`mns-woocommerce-rate-based-products`) is active, to avoid conflicts.

---

## B. Product Scope and Design Philosophy

- This plugin is **intentionally lightweight**.
- It is **not** intended to reproduce the full professional Navasan plugin.
- Avoid unnecessary features and architectural complexity.
- **Do not** add automatic external exchange-rate APIs.
- **Do not** introduce complex pricing formulas or unrelated pricing rules.
- **Preserve metadata compatibility** with the original Navasan plugin where practical.
- **Do not** change established metadata names without a strong, documented reason.

---

## C. Technical Architecture

### File responsibilities

| File | Responsibility |
|---|---|
| `ani-navasan-lite.php` | Main plugin file. Header (version `1.0.0`). Mutual-exclusivity guard (deactivates if the main Navasan plugin is active). One-time activation notice. Activation hook runs the one-time rate-key migration (`ani_navasan_lite_migrate_rate_key()`) **and** the one-time dependence-type migration (`ani_navasan_lite_migrate_dependence_type()`). Loads `includes/init.php` on `plugins_loaded` only when the main plugin is inactive. |
| `includes/init.php` | Bootstrap. Requires all internal files (`functions.php`, `hooks.php`, `admin/admin.php`, `admin/metabox.php`, `currencies-cpt.php`, `currencies-metabox.php`, `ajax.php`). Enqueues admin CSS on product edit screens. |
| `includes/functions.php` | Shared helpers. `ani_navasan_round_to_thousand()` (nearest 1,000 Toman). `ani_navasan_get_currency_rate()` (reads `_mnswmc_currency_value`, falls back to legacy `_mnswmc_rate`). `ani_navasan_lite_migrate_rate_key()` (one-time activation migration, legacy→standard, never overwrites). `ani_navasan_lite_migrate_dependence_type()` (one-time activation migration, legacy `variable`→`advanced`, idempotent). The `ani_navasan_lite_recalc_currency` handler (recalculates all dependent products/variations regular **and** sale prices and syncs variable parents). |
| `includes/hooks.php` | `save_post_product` handler that stores decimal currency fields (`_mnswmc_regular_price`, `_mnswmc_sale_price`, `_torob_scraper_min_price_currency`) rounded to 2 decimals (with an `!is_array()` guard for variation array values). |
| `includes/ajax.php` | `wp_ajax_mnswmc_get_exchange_rate` — returns a currency's rate/symbol/title. Verifies nonce (`mnswmc_nonce`) **and** the `edit_products` capability. |
| `includes/currencies-cpt.php` | Registers the `mnswmc` post type (currencies) on `init`. |
| `includes/currencies-metabox.php` | Currency metabox (symbol + rate). On `save_post_mnswmc`, saves `_mnswmc_symbol`/`_mnswmc_currency_value` and fires `ani_navasan_lite_recalc_currency` when the rate is saved. |
| `admin/admin.php` | Enqueues `assets/js/admin.js` on product edit screens; localizes `mnswmc_ajax` (admin-ajax URL + nonce). |
| `admin/metabox.php` | Product/variation metabox fields (currency dependency, currency select, currency price, **currency sale price**, **dependence type select** `simple`/`advanced` + advanced notice, floor price). `ani_navasan_get_currency_options()`. Simple-product save handler (`woocommerce_admin_process_product_object`) persists dependence type (whitelisted) + currency sale price and calculates Toman regular/sale prices via CRUD. Variation save handler (`woocommerce_save_product_variation`) does the same per-loop. |
| `assets/js/admin.js` | Admin JS. `mnswmcRoundToThousand()` (JS rounding). Live Toman preview for simple products and variations (regular + sale). AJAX rate fetch with `data-rate` fallback. Currency sale price visibility + inline Toman sale calculation + WC sale price readonly when active + advanced notice toggle (simple + variation). |
| `assets/css/admin.css` | Admin styles for the live-preview boxes, `.ani_navasan_advanced_notice`, and the sale-final preview lines. |

### Metadata keys

**Currency (on `mnswmc` posts) — owned by this plugin:**
- `_mnswmc_symbol` — ISO currency symbol (e.g., `USD`, `AED`).
- `_mnswmc_currency_value` — **standard** rate of one unit of the currency in Toman (matches the main Navasan plugin's data contract, per owner spec). Written by the save handler; read first by `ani_navasan_get_currency_rate()`.
- `_mnswmc_rate` — **legacy** rate key (pre-2026-11-13). Read only as a fallback when `_mnswmc_currency_value` is empty. A one-time activation migration (`ani_navasan_lite_migrate_rate_key()`) copies legacy values to the standard key without deleting the legacy key or overwriting an existing standard value.

**Product & variation — owned by this plugin:**
- `_mnswmc_active` — `yes`/`no`, currency dependency enabled.
- `_mnswmc_currency_id` — ID of the selected `mnswmc` currency post.
- `_mnswmc_regular_price` — the currency-denominated price.
- `_mnswmc_sale_price` — the currency-denominated **sale** price (matches the main plugin's contract). Empty = no currency sale price; the WC standard `_sale_price` (Toman) is the calculated result.
- `_mnswmc_dependence_type` — `simple` or `advanced` (matches the main plugin's contract; set on save, whitelisted). Legacy Lite value `variable` is migrated to `advanced` by the one-time activation migration `ani_navasan_lite_migrate_dependence_type()`. **Lite does not implement advanced formulas** — both `simple` and `advanced` use the same calculation (currency price × rate, rounded to the nearest 1,000 Toman).

**Floor-price metadata — maintained for compatibility with another plugin (Torob scraper):**
- `_torob_scraper_min_price` — floor price in Toman (this plugin writes the recalculated Toman value).
- `_torob_scraper_min_price_currency` — floor price in the currency (this plugin reads it).

> The `_torob_scraper_*` keys are **not** owned by this plugin; they are preserved for compatibility with a separate price-scraping plugin. This plugin reads the currency floor price and writes the recalculated Toman floor price.

### Currency recalculation hook

`ani_navasan_lite_recalc_currency` (action; args: `$currency_id`, `$rate`)
- Fired from `save_post_mnswmc` after a currency rate is saved.
- The handler in `includes/functions.php` finds all products/variations with `_mnswmc_currency_id = $currency_id` and `_mnswmc_active = 'yes'`, recalculates their Toman **regular** prices (currency price × rate, rounded to the nearest 1,000) and, when `_mnswmc_sale_price` > 0, their Toman **sale** prices (currency sale price × rate, rounded to the nearest 1,000), updates them via the WooCommerce CRUD API, and syncs variable parents.
- **When `_mnswmc_sale_price` is empty, the handler does NOT touch the WC sale price** (preserves existing WC sale scheduling). The sale price is only cleared by the save handler when the user explicitly removes the currency sale price and saves the product.

### Rounding policy

**All calculated Toman prices are rounded to the nearest 1,000 Toman.**
- PHP: `ani_navasan_round_to_thousand($value)` → `(int) round((float) $value / 1000) * 1000`.
- JavaScript: `mnswmcRoundToThousand(value)` → `Math.round((parseFloat(value) || 0) / 1000) * 1000`.
- PHP and JavaScript must remain consistent.

### WooCommerce integration decisions

- Use **WooCommerce CRUD APIs** (`wc_get_product()` → `set_regular_price()` → `save()`) for product and variation price updates, so `_price` is synced correctly and the `wc_product_meta_lookup` table + caches are updated.
- **Preserve native sale-price behavior** — recalculation updates the regular price; WooCommerce derives the active price from `is_on_sale()`. `_sale_price` is never overwritten.
- Use **`WC_Product_Variable::sync()`** for variable parent price synchronization.
- Current use: **`sync($parent, false)`** + explicit cache invalidation:
  - `sync_price()` persists the parent `_price` meta (multi-row) and the lookup table directly (no save needed).
  - `sync_stock_status()` only sets in-memory state (not persisted with `$save = false`) — acceptable for the price-only flow.
  - `sync_attributes()` is a no-op for modern products.
  - Because `sync_price()` does not clear caches, the handler explicitly calls `wc_delete_product_transients()`, `WC_Cache_Helper::invalidate_cache_group('product_' . $parent_id)`, and `clean_post_cache($parent_id)`.
  - Avoiding the full product save prevents re-persisting `_product_attributes` (double-serialization risk) and avoids firing unrelated product save hooks.
- **Parent variable prices are derived from variations**; the plugin must not introduce an independent parent price.

---

## D. Important Technical Decisions and Lessons

Decisions that future development must preserve:

1. Use **WooCommerce CRUD APIs** rather than direct price metadata manipulation where appropriate.
2. **Do not overwrite `_sale_price`** during regular-price recalculation.
3. Use the **shared rounding function** in PHP and the corresponding JavaScript implementation (nearest 1,000 Toman).
4. Preserve the correct **WooCommerce variation loop-index mapping** (`$_POST[field][$i]` ↔ `woocommerce_save_product_variation($variation_id, $i)`).
5. **Avoid duplicate save handlers.**
6. **Avoid unnecessary full parent-product saves** during variable price synchronization (use `sync($parent, false)` + explicit cache clearing).
7. **Do not modify product attributes or default attributes** as part of currency recalculation.
8. **Preserve existing metadata names** and compatibility (`_mnswmc_*`, `_torob_scraper_*`).
9. **Test only explicitly authorized products** when live database modifications are required.
10. **Never run destructive tests on production data.**

**Serialization lesson (verified):** When restoring serialized meta (e.g., `_product_attributes`), pass the **unserialized PHP array** to `update_post_meta()` — WordPress serializes it exactly once. Passing an already-serialized string causes double serialization again.

**Do not document unconfirmed root causes as facts.** The original double-serialization trigger is **not confirmed** (see F).

## E. Completed Work

Development stages (all merged to `main` and released as **`1.0.0`**):

- **Initial architecture & technical audit** — static code review of the recalculation workflow; findings documented in `docs/reports/2026-11-10-technical-audit.md`.
- **Task 01** — rounding consistency and recalculation corrections. Commit `9085956`.
- **Task 02** — WooCommerce CRUD integration and sale-price handling. Commit `800f8e6` (+ docs `704ea2c`, `0e43394`).
- **Task 03** — variable parent synchronization correction (`WC_Product_Variable::sync`). Commit `16d6896` (+ docs `592c56e`).
- **Task 04** — read-only integrity investigation and metadata audit (no code changes; documented in the technical audit report).
- **Task 05** — attribute restoration, duplicate-handler cleanup, obsolete JavaScript removal, and synchronization optimization (`sync($parent, false)` + explicit cache clearing). Commits `1199380` (code) and `c82e951` (docs).
- **Task 06** — final merge, release `1.0.0`, and Memory Bank initialization. Merge commit `0f15660`; tag `1.0.0`.
- **Task 07** — end-to-end currency create/edit → recalculation verification (**test-only; no plugin source changes**). The real `mnswmc` admin save workflow (nonce + `wp_insert_post()`/`wp_update_post()` → `save_post_mnswmc` → `_mnswmc_rate` persistence → `ani_navasan_lite_recalc_currency`) was exercised via WP-CLI. **70 scenario checks executed: 68 passed, 2 failed** — both failures are `wp_wc_product_meta_lookup` row assertions (exact cause **not confirmed**; consistent with a `wp eval-file` CLI-context effect but not definitively established). Simple-product, variation, variable-parent-sync, rounding (PHP vs. JS), sale-price-preservation, disabled-dependency-exclusion, and repeatability scenarios all passed. Store API (over HTTP) and frontend product pages verified for products `17613`/`17615`/`17616`/`17617`. Site fully restored (49/49 verification checks); temporary currencies `17624`/`17625`/`17626` deleted. **The browser admin UI was executed and confirmed by the user** — the user ran the full manual procedure in the report (§8.3–§8.11) in a real browser and confirmed the success of all steps (real currency admin UI + rate save → recalculation; simple-product and variation prices; rate change → recalculation; isolation of the non-dependent variation; variable parent sync; frontend display; test-currency deletion; final Restore + Verify = `ALL SECTIONS PASS`). **Standalone backup/restore/verify safety cycle** (scripts in `/tmp/task07-manual/`, outside the repo) was built and **round-trip proven** (backup → controlled mutation → verify detects → restore → verify passes; no double serialization; all 4 products load correctly via the WooCommerce API; post-restore state confirmed identical to the pre-test backup). The two `wp_wc_product_meta_lookup` failures above remain **unresolved** (cause not confirmed). Report: `docs/reports/2026-11-11-task-07-e2e-currency-recalculation.md`. Branch: `test/task-07-e2e-currency-recalculation` (pushed, **not merged**).

- **Task 08** — Docker-based PHP/WordPress/WooCommerce compatibility matrix (**test-only; no plugin behavior changes**). Built a repeatable CLI smoke-test harness (`tests/compat/`: `smoke-test.php` 32 checks, `run-env.sh` single-env Docker runner, `run-matrix.sh` 8-env runner, `README.md`). Each environment is a throwaway Docker stack (WP + WC + requested PHP) with the plugin installed from the working tree; the smoke test runs via WP-CLI and covers bootstrap, CPT registration, currency create + recalc trigger, simple-product/variation recalc, variable-parent sync, sale-price preservation, disabled-dependency exclusion, PHP↔JS rounding consistency, the AJAX endpoint (nonce + `edit_products` capability), and admin metabox rendering. **Full matrix: 8/8 environments PASS, 32/32 checks each (256 total, 0 failures)** across PHP 7.4.33/8.0.30/8.1.34/8.2.35/8.3.36 × WP 7.0.8/7.1.4 × WC 9.6.2/11.2.2. **Confirmed support range: PHP 7.4–8.3, WP 7.0.8–7.1.4, WC 9.6.2–11.2.2.** Added support-range header fields to `ani-navasan-lite.php` (`Requires at least: 7.0.8`, `Requires PHP: 7.4`, `Tested up to: 7.1`, `WC requires at least: 9.6.2`, `WC tested up to: 11.2.2`); `Requires at least` was later corrected from `7.0` to `7.0.8` to match the lowest tested WP version. All smoke-test fixes were to the **test harness** (CLI does not replicate HTTP/AJAX/admin context): `DOING_AJAX` + `wp_die_ajax_handler` + output buffering for AJAX JSON; explicit require of WC `wc-meta-box-functions.php`; `trailingslashit( WC()->plugin_path() )`; and one wrong test expectation (`S5.1` `150000`→`180000`, a test bug — the plugin was correct). Generated `tests/compat/results/` artifacts are gitignored. **Compatibility with the main Navasan plugin (`mns-woocommerce-rate-based-products`) is BLOCKED/UNTESTED** — the main plugin is not installed on the target site (`anishop-package-test.local`) and its file is not available there; the source-level mutual-exclusivity guard is not a real coexistence test. Report: `docs/reports/2026-11-12-task-08-compatibility-matrix.md`. Branch: `test/task-08-compatibility-matrix` (pushed, **not merged**).

- **Task 08 follow-up — metadata compatibility with the main Navasan plugin** (branch `test/task-08-metadata-compatibility`, created from `origin/main` at `1a03779`). Aligned the currency-rate metadata with the main plugin's data contract (per owner spec): the rate is now **written** to the standard key `_mnswmc_currency_value` and **read** via the new helper `ani_navasan_get_currency_rate()` (standard key first, legacy `_mnswmc_rate` fallback). Added the one-time activation migration `ani_navasan_lite_migrate_rate_key()` (copies legacy→standard only when the standard key is empty; keeps the legacy key; never overwrites; option `ani_navasan_lite_migrated_rate_key`). All 5 former read sites (recalc handler, currency metabox, AJAX endpoint, currency options, simple/variation save handlers) now use the helper. Smoke test extended with an **S9 section (5 checks)**; S2.2 now asserts the standard key. **Verified:** `php -l` 6/6; live verification on `anishop-package-test.local` **17/17** (real admin save workflow, rate 2000→2500→2000 restored; simple/variation recalc, sale-price preservation on on-sale `17613`, independent-variation isolation, parent sync; migration idempotency; fallback); full Docker matrix re-run **8/8 environments PASS, 37/37 checks each (296 total, 0 failures)** — support range unchanged (PHP 7.4–8.3, WP 7.0.8–7.1.4, WC 9.6.2–11.2.2). One harness bug found and fixed during the re-run (stale `$_POST` in the single WP-CLI process leaked into S9.4's temporary post save — a CLI-only artifact, impossible in real per-request admin saves). The "17613 anomaly" during live verification was a **test-expectation error** (product is on sale; `get_price()` returns the sale price), not a plugin defect. **Coexistence with the main plugin remains BLOCKED** (main plugin not installed; contract not verified against its source). Report: `docs/reports/2026-11-13-task-08-metadata-compatibility.md`. Branch pushed, **not merged**.

- **Task 08 final phase — symbol/sale-price metadata decision + main-plugin install & tests** (same branch `test/task-08-metadata-compatibility`, continued from `27b2726`; **no Lite source changes**). (1) **Symbol:** the main plugin's readable source (`templates/metaboxes/currency.php`, `includes/Admin/MetaBoxes.php`, `includes/Admin/PostTypes.php`) shows `_mnswmc_currency_rate_symbol` is the **rate display unit** (e.g. "Toman"), while Lite's `_mnswmc_symbol` is the **currency ISO symbol** (e.g. "USD") — **NOT equivalent**, so no migration/alignment was implemented (per task instruction; evidence in report §14.5). The rate key `_mnswmc_currency_value` was **independently confirmed** from the main plugin's readable source (direct `meta_key` sort queries in `PostTypes.php`). (2) **Sale price:** the main plugin's `_mnswmc_sale_price` is the **currency-denominated** sale price; Lite has no equivalent and preserves the WC standard `_sale_price` (Toman) — **NOT equivalent**, no change (report §14.6). (3) **ionCube + main plugin:** the target site's actual PHP runtime is **8.4.10** (not the previously reported 8.3.36); it is a **Local** site with a site-specific php.ini. ionCube Loader **15.5** was installed in a site-specific dir (`/home/ramin/Local Sites/anishop-package-test/ioncube/`) and added as the first line of the site php.ini; **verified in web (php-fpm) and CLI**. Main plugin **7.12.0** installed from `docs/sources/mns-woocommerce-rate-based-products.zip`, but **activation is BLOCKED**: its ionCube-encrypted files (`Core.php`, `Helpers.php`, `Admin/WooCommerce.php`, `Admin/Settings.php`, `RestApi.php`) were encoded with an **unauthorized ionCube encoder** — the legitimate loader fatals with "created with an unauthorised copy of the ionCube PHP Encoder". This is a **licensing/provenance issue, not a version incompatibility** (all stated requirements are satisfied). (4) **Guard:** real test BLOCKED (main plugin fatals on load, before `admin_init`); **simulated test PASS** (via `option_active_plugins` filter, Lite's guard deactivates itself when the main plugin is "active"). (5) **Bidirectional compatibility:** full test BLOCKED; the metadata **contract** (rate `_mnswmc_currency_value`, currency price `_mnswmc_regular_price`) is verified compatible via readable source + Lite regression. (6) **Regression: 21/21 PASS** (rate read/fallback/migration/precedence, simple/variation/parent prices, recalc, sale-price preservation, rounding, frontend Toman price via Store API). Report §14: `docs/reports/2026-11-13-task-08-metadata-compatibility.md`. Branch pushed, **not merged**.

**Release:** `1.0.0` — https://github.com/ramin40686/ani-navasan-lite/releases/tag/1.0.0

- **Task 09 — dependence-type alignment + currency sale price support** (branch `feat/task-09-currency-sale-price`, created from `test/task-08-metadata-compatibility`; **code changes**). (1) **Dependence type:** `_mnswmc_dependence_type` now uses the main plugin's `simple`/`advanced` contract. A select was added for simple products (default `simple`) and variations (default `advanced`); values are whitelisted to `simple`/`advanced` on save (any other value rejected). A one-time activation migration `ani_navasan_lite_migrate_dependence_type()` normalizes legacy `variable`→`advanced` (idempotent, never alters `simple`/`advanced`, option `ani_navasan_lite_migrated_dependence_type`). **Lite does NOT implement advanced formulas** — both types use the same calculation; an admin notice (`.ani_navasan_advanced_notice`) makes this explicit. (2) **Currency sale price:** `_mnswmc_sale_price` (currency-denominated) field added for simple products + variations (shown only when currency dependence is active). The save handlers calculate the Toman sale price (currency sale price × rate, rounded to the nearest 1,000) and set it via WC CRUD (`set_sale_price()`); **empty value does NOT create/force a WC sale price** (the save handler clears a stale sale price only when the user explicitly removes the currency sale price). The rate-change recalculation handler now also recalculates the Toman sale price when `_mnswmc_sale_price` > 0, and **preserves the existing WC sale price when it is empty**. `_mnswmc_sale_price` was added to the scalar decimal rounding list in `includes/hooks.php` (with an `!is_array()` guard for variation array values). Admin JS: currency sale price visibility, inline Toman sale calculation, WC sale price readonly when active, preview output, advanced notice toggle (simple + variation). (3) **Verification:** `php -l` 4/4 + JS syntax PASS; live smoke test **45/45 PASS** (PHP 8.4.10 / WP 7.1.4 / WC 11.3.0) including the new **S10 section (8 checks)**; live migration verification (legacy `variable`→`advanced`, idempotent, `simple`/`advanced` unchanged); live sale-price verification on authorized test data (simple `17613`: 60×2500→150000, empty→no WC sale price; variation `17616`: 60×2500→150000, parent sync 150000, empty→preserved); Store API/frontend verified for `17613`/`17615`; state fully restored (verified against backup: ALL MATCH). (4) **Roadmap:** Task 09 row updated to the actual scope; the former Task 09 (Live DOM verification) renumbered to **Task 09b**. Report: `docs/reports/2026-11-13-task-09-currency-sale-price.md`. Branch pushed (commits `867f65b` + `0a7b403`), **not merged**.

Detailed reports: `docs/reports/2026-11-10-technical-audit.md`, `docs/reports/2026-11-10-recalculation-p1.md`, `docs/reports/2026-11-11-final-merge-release.md`, `docs/reports/2026-11-11-task-07-e2e-currency-recalculation.md`, `docs/reports/2026-11-12-task-08-compatibility-matrix.md`, `docs/reports/2026-11-13-task-08-metadata-compatibility.md`, and `docs/reports/2026-11-13-task-09-currency-sale-price.md`.

---

## F. Remaining Work and Known Limitations

Genuinely unresolved items (prioritized). Do not mark any of these as completed unless actual implementation and verification exist.

**P1 — important correctness/compatibility:**
- **`wp_wc_product_meta_lookup` assertions (2 Task 07 Scenario 1 checks) remain unresolved** — exact cause **not confirmed** (consistent with a `wp eval-file` CLI-context effect but not definitively established). The browser-test confirmation does not resolve these; the lookup table was not used as a primary verification source.
- **Compatibility with the main Navasan plugin (`mns-woocommerce-rate-based-products`) is BLOCKED** (Task 08 final). The main plugin 7.12.0 is now **installed** on the target site and ionCube Loader 15.5 is installed/verified, but **activation is BLOCKED**: the ZIP's ionCube-encrypted files were encoded with an **unauthorized ionCube encoder**, so the legitimate loader fatals on every load ("created with an unauthorised copy of the ionCube PHP Encoder"). This is a **licensing/provenance issue, not a version incompatibility** (all stated requirements — PHP ≥7.4, WP ≥6.0, WC ≥7.0, ionCube ≥12.0 — are satisfied by the site's PHP 8.4.10 / WP 7.1.4 / WC 11.3.0 / ionCube 15.5). A real coexistence/bidirectional/Guard test requires a **legitimately licensed** copy of the main plugin (or its source). **Metadata contract status:** the rate key (`_mnswmc_currency_value`) and currency-price key (`_mnswmc_regular_price`) are **verified compatible** against the main plugin's readable source; the symbol (`_mnswmc_symbol` vs `_mnswmc_currency_rate_symbol`) and sale-price (`_sale_price` vs `_mnswmc_sale_price`) keys are **NOT equivalent** (different concepts) and were intentionally left unchanged (evidence in report §14.5/§14.6). The guard's deactivation logic was verified via a simulated test (PASS); the real guard test is BLOCKED.

**P2 — hardening and maintainability:**
- **Task 09b (Live DOM verification)**: **COMPLETE (owner manual test)**. The project owner personally tested the admin panel and verified the final prices on the frontend. The overall result of the manual test was positive. This was the former Task 09, renumbered after Task 09's scope changed to dependence-type alignment + currency sale price. The owner's manual test is recorded as the project owner's test, not Cline's automated test.
- A CLI smoke-test harness now exists (`tests/compat/`, Task 08; extended with S10 in Task 09) covering the core runtime paths across the compatibility matrix; a full unit/integration regression suite (e.g., PHPUnit + WP test framework) still does not exist.
- Plugin-specific nonce verification for product saving may need a separate security-hardening task.
- Internationalization may need improvement (admin strings are currently hardcoded Persian).

**P3 — optional:**
- Instrument the `_product_attributes` write path to identify the original double-serialization trigger (the user decided not to spend more time on this; keep as a low-priority follow-up).

**Known limitations (accepted):**
- `WC_Product_Variable::sync($parent, false)` does not persist stock status — acceptable for the price-only recalculation flow, but a future stock-changing flow would need an explicit save or `sync_stock_status($parent, true)`.
- The original double-serialization trigger remains unconfirmed.
- **`wp_wc_product_meta_lookup` assertions were inconsistent in the `wp eval-file` CLI context** (2 Task 07 Scenario 1 checks failed; exact cause **not confirmed**). The lookup table was not used as a primary verification source; `_price`, `_regular_price`, the Store API, and frontend output were used instead.
