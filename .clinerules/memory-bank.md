# Ani Navasan Lite — Memory Bank (Active Context)

> I am Cline. My memory resets completely between sessions; this Memory Bank is my only link to previous work. **Read it fully at the start of every task.** It must reflect verified project state only — never record assumptions or unconfirmed root causes as facts.
>
> **Last updated:** 2026-11-11 (Task 07 — E2E currency-recalculation verification report; CLI/automated verification complete; standalone backup/restore/verify safety cycle built and round-trip proven; browser test pending user execution, now gated on a successful backup + passing verify).

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
| `ani-navasan-lite.php` | Main plugin file. Header (version `1.0.0`). Mutual-exclusivity guard (deactivates if the main Navasan plugin is active). One-time activation notice. Loads `includes/init.php` on `plugins_loaded` only when the main plugin is inactive. |
| `includes/init.php` | Bootstrap. Requires all internal files (`functions.php`, `hooks.php`, `admin/admin.php`, `admin/metabox.php`, `currencies-cpt.php`, `currencies-metabox.php`, `ajax.php`). Enqueues admin CSS on product edit screens. |
| `includes/functions.php` | Shared helpers. `ani_navasan_round_to_thousand()` (nearest 1,000 Toman). The `ani_navasan_lite_recalc_currency` handler (recalculates all dependent products/variations and syncs variable parents). |
| `includes/hooks.php` | `save_post_product` handler that stores decimal currency fields (`_mnswmc_regular_price`, `_torob_scraper_min_price_currency`) rounded to 2 decimals. |
| `includes/ajax.php` | `wp_ajax_mnswmc_get_exchange_rate` — returns a currency's rate/symbol/title. Verifies nonce (`mnswmc_nonce`) **and** the `edit_products` capability. |
| `includes/currencies-cpt.php` | Registers the `mnswmc` post type (currencies) on `init`. |
| `includes/currencies-metabox.php` | Currency metabox (symbol + rate). On `save_post_mnswmc`, saves `_mnswmc_symbol`/`_mnswmc_rate` and fires `ani_navasan_lite_recalc_currency` when the rate is saved. |
| `admin/admin.php` | Enqueues `assets/js/admin.js` on product edit screens; localizes `mnswmc_ajax` (admin-ajax URL + nonce). |
| `admin/metabox.php` | Product/variation metabox fields (currency dependency, currency select, currency price, floor price). `ani_navasan_get_currency_options()`. Simple-product save handler (`woocommerce_admin_process_product_object`). Variation save handler (`woocommerce_save_product_variation`). |
| `assets/js/admin.js` | Admin JS. `mnswmcRoundToThousand()` (JS rounding). Live Toman preview for simple products and variations. AJAX rate fetch with `data-rate` fallback. |
| `assets/css/admin.css` | Admin styles for the live-preview boxes. |

### Metadata keys

**Currency (on `mnswmc` posts) — owned by this plugin:**
- `_mnswmc_symbol` — ISO currency symbol (e.g., `USD`, `AED`).
- `_mnswmc_rate` — rate of one unit of the currency in Toman.

**Product & variation — owned by this plugin:**
- `_mnswmc_active` — `yes`/`no`, currency dependency enabled.
- `_mnswmc_currency_id` — ID of the selected `mnswmc` currency post.
- `_mnswmc_regular_price` — the currency-denominated price.
- `_mnswmc_dependence_type` — `simple` or `variable` (set on save).

**Floor-price metadata — maintained for compatibility with another plugin (Torob scraper):**
- `_torob_scraper_min_price` — floor price in Toman (this plugin writes the recalculated Toman value).
- `_torob_scraper_min_price_currency` — floor price in the currency (this plugin reads it).

> The `_torob_scraper_*` keys are **not** owned by this plugin; they are preserved for compatibility with a separate price-scraping plugin. This plugin reads the currency floor price and writes the recalculated Toman floor price.

### Currency recalculation hook

`ani_navasan_lite_recalc_currency` (action; args: `$currency_id`, `$rate`)
- Fired from `save_post_mnswmc` after a currency rate is saved.
- The handler in `includes/functions.php` finds all products/variations with `_mnswmc_currency_id = $currency_id` and `_mnswmc_active = 'yes'`, recalculates their Toman prices (currency price × rate, rounded to the nearest 1,000), updates them via the WooCommerce CRUD API, and syncs variable parents.

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
- **Task 07** — end-to-end currency create/edit → recalculation verification (**test-only; no plugin source changes**). The real `mnswmc` admin save workflow (nonce + `wp_insert_post()`/`wp_update_post()` → `save_post_mnswmc` → `_mnswmc_rate` persistence → `ani_navasan_lite_recalc_currency`) was exercised via WP-CLI. **70 scenario checks executed: 68 passed, 2 failed** — both failures are `wp_wc_product_meta_lookup` row assertions (exact cause **not confirmed**; consistent with a `wp eval-file` CLI-context effect but not definitively established). Simple-product, variation, variable-parent-sync, rounding (PHP vs. JS), sale-price-preservation, disabled-dependency-exclusion, and repeatability scenarios all passed. Store API (over HTTP) and frontend product pages verified for products `17613`/`17615`/`17616`/`17617`. Site fully restored (49/49 verification checks); temporary currencies `17624`/`17625`/`17626` deleted. **The browser admin UI was NOT tested** — a precise manual procedure is provided in the report (§8) and is **pending user execution**. **Standalone backup/restore/verify safety cycle** (scripts in `/tmp/task07-manual/`, outside the repo) was built and **round-trip proven** (backup → controlled mutation → verify detects → restore → verify passes; no double serialization; all 4 products load correctly via the WooCommerce API; post-restore state confirmed identical to the pre-test backup). The manual browser test is now **ready to execute**, gated on a successful backup (§8.2) and a passing verify (§8.11). Report: `docs/reports/2026-11-11-task-07-e2e-currency-recalculation.md`. Branch: `test/task-07-e2e-currency-recalculation` (pushed, **not merged**).

**Release:** `1.0.0` — https://github.com/ramin40686/ani-navasan-lite/releases/tag/1.0.0

Detailed reports: `docs/reports/2026-11-10-technical-audit.md`, `docs/reports/2026-11-10-recalculation-p1.md`, and `docs/reports/2026-11-11-final-merge-release.md`.

---

## F. Remaining Work and Known Limitations

Genuinely unresolved items (prioritized). Do not mark any of these as completed unless actual implementation and verification exist.

**P1 — important correctness/compatibility:**
- **Browser-based admin UI verification (Task 07) — pending user execution.** The real WordPress admin UI was not tested by Cline; a precise manual procedure is provided in the Task 07 report (§8). The standalone backup/restore/verify safety cycle is now **proven** (round-trip tested), so the manual test is **ready to execute** — it is gated on a successful backup (§8.2) and a passing verify (§8.11). Task 07 is not fully closed until this manual test is completed and its results recorded.
- Compatibility should be tested against explicitly selected supported WooCommerce, WordPress, and PHP versions (only WC 11.2.2 + PHP 8.3.30 were tested).

**P2 — hardening and maintainability:**
- Variation admin-screen JavaScript selectors still need live admin DOM verification.
- Automated regression tests should be introduced/expanded (none exist; live testing was manual via WP-CLI).
- Plugin-specific nonce verification for product saving may need a separate security-hardening task.
- Internationalization may need improvement (admin strings are currently hardcoded Persian).

**P3 — optional:**
- Instrument the `_product_attributes` write path to identify the original double-serialization trigger (the user decided not to spend more time on this; keep as a low-priority follow-up).

**Known limitations (accepted):**
- `WC_Product_Variable::sync($parent, false)` does not persist stock status — acceptable for the price-only recalculation flow, but a future stock-changing flow would need an explicit save or `sync_stock_status($parent, true)`.
- The original double-serialization trigger remains unconfirmed.
- **`wp_wc_product_meta_lookup` assertions were inconsistent in the `wp eval-file` CLI context** (2 Task 07 Scenario 1 checks failed; exact cause **not confirmed**). The lookup table was not used as a primary verification source; `_price`, `_regular_price`, the Store API, and frontend output were used instead.
