# Task 08 (follow-up) — Metadata Compatibility with the Main Navasan Plugin

**Date:** 2026-11-13
**Branch:** `test/task-08-metadata-compatibility`
**Status:** Phase 1 COMPLETE (rate-key alignment: live 17/17, matrix 8/8 envs, 296/296 checks). Phase 2 (final compatibility fixes) **PARTIALLY COMPLETE** — symbol & sale-price keys analyzed as **not equivalent** (no change, evidence in §14); ionCube 15.5 installed & verified; main plugin 7.12.0 installed but **activation BLOCKED** (unauthorized ionCube encoder); guard (simulated) PASS, bidirectional contract verified, regression 21/21 PASS. See §14.

---

## 1. Task Objective

Align the currency-rate metadata of Ani Navasan Lite with the **data contract of the main Navasan plugin** (`mns-woocommerce-rate-based-products`), as specified by the project owner:

- The main plugin stores the rate of one currency unit (in Toman) under the post meta key **`_mnswmc_currency_value`** on `mnswmc` posts.
- The Lite plugin previously stored the same value under **`_mnswmc_rate`**.

Requirements:

1. The Lite plugin must **write** the rate to `_mnswmc_currency_value` (the standard key).
2. The Lite plugin must **read** the rate from `_mnswmc_currency_value`, with a **fallback** to the legacy `_mnswmc_rate` key for pre-existing data.
3. A **one-time migration** on plugin activation must copy legacy values to the standard key without data loss.
4. No change to any other behavior (recalculation, rounding, sale-price preservation, parent sync, security checks).

> **Source of the contract:** the key name `_mnswmc_currency_value` was provided by the project owner as the main plugin's data contract. The main plugin is **not installed** on the target site and its source is not available, so the contract could not be verified against the main plugin's code (see §9).

## 2. Initial Findings

- All rate reads/writes in the Lite plugin used `_mnswmc_rate` (5 read sites, 1 write site).
- The target site (`anishop-package-test.local`) contained two `mnswmc` currencies:
  - `17654` (TST) — created with the legacy key before the code change.
  - `17655` — legacy key only (`_mnswmc_rate=2000`).
- Authorized test products (Task 07/08): `17613` (simple), `17615` (variable parent), `17616`/`17617` (variations).
- The Task 08 Docker smoke-test harness (`tests/compat/`) asserted the legacy key in S2.2 and had no coverage for the fallback or the migration.

## 3. Files Inspected

- `ani-navasan-lite.php` (activation hook, mutual-exclusivity guard)
- `includes/functions.php` (rounding, recalculation handler)
- `includes/currencies-metabox.php` (currency metabox + save handler)
- `includes/ajax.php` (rate AJAX endpoint)
- `admin/metabox.php` (currency options, simple/variation save handlers)
- `tests/compat/smoke-test.php`, `tests/compat/run-env.sh`, `tests/compat/run-matrix.sh`
- `docs/reports/2026-11-11-task-07-e2e-currency-recalculation.md` (original product state)
- Live site postmeta for `17613`/`17615`/`17616`/`17617`/`17654`/`17655`

## 4. Files Created, Modified, or Deleted

| File | Change |
|---|---|
| `includes/functions.php` | **Modified** — added `ani_navasan_get_currency_rate()` (standard key + legacy fallback) and `ani_navasan_lite_migrate_rate_key()` (one-time activation migration); recalculation handler now reads the rate through the helper. |
| `ani-navasan-lite.php` | **Modified** — activation hook now requires `includes/functions.php` and runs the one-time migration. |
| `includes/currencies-metabox.php` | **Modified** — metabox reads the rate via the helper; save handler writes `_mnswmc_currency_value` (was `_mnswmc_rate`). |
| `includes/ajax.php` | **Modified** — `wp_ajax_mnswmc_get_exchange_rate` reads the rate via the helper. |
| `admin/metabox.php` | **Modified** — `ani_navasan_get_currency_options()`, the simple-product save handler, and the variation save handler read the rate via the helper. |
| `tests/compat/smoke-test.php` | **Modified** — S2.2 now asserts `_mnswmc_currency_value`; new **S9 section (5 checks)**: standard-key read, legacy-key fallback, standard-key precedence, migration copy (legacy→standard, legacy kept), migration idempotency/no-overwrite; cleanup extended for the temporary post. |
| `docs/reports/2026-11-13-task-08-metadata-compatibility.md` | **Created** — this report. |

No files were deleted. No other plugin files were touched.

## 5. Detailed Description of Changes

### 5.1 Rate read helper — `ani_navasan_get_currency_rate( $currency_id )`

```php
$rate = get_post_meta( $currency_id, '_mnswmc_currency_value', true );
if ( $rate === '' || $rate === null ) {
    $rate = get_post_meta( $currency_id, '_mnswmc_rate', true );
}
return $rate !== '' ? floatval( $rate ) : 0;
```

- Standard key first; legacy key only as fallback when the standard key is empty.
- Returns `float` (0 when no rate is stored) — same contract as the previous inline `floatval()` reads.
- All five former read sites now call this helper, so the read contract is defined in exactly one place.

### 5.2 One-time migration — `ani_navasan_lite_migrate_rate_key()`

- Runs from the **activation hook** (admin-only context).
- For every `mnswmc` post: if `_mnswmc_currency_value` is empty and `_mnswmc_rate` is not, copies the legacy value to the standard key.
- **Never deletes** the legacy key (it remains as the runtime fallback) and **never overwrites** an existing standard value → idempotent, no data loss.
- Records completion in the option `ani_navasan_lite_migrated_rate_key` (informational; the function is safe to re-run regardless).

### 5.3 Write path

- `save_post_mnswmc` now persists the rate to `_mnswmc_currency_value`. Nonce (`mnswmc_meta_save`) and capability (`edit_post`) checks are unchanged. The recalculation action still fires with the saved rate.

### 5.4 Unchanged behavior (by design)

- Rounding policy (nearest 1,000 Toman), recalculation query, WooCommerce CRUD usage, sale-price preservation, `WC_Product_Variable::sync($parent, false)` + explicit cache clearing, mutual-exclusivity guard, AJAX nonce + `edit_products` capability check.

## 6. Security Implications

- **No new attack surface.** The helper only reads post meta; the migration only runs on activation (triggering it requires the `activate_plugins` capability).
- The currency save handler's nonce + capability verification is unchanged.
- The migration uses a static `SELECT ID FROM {$wpdb->posts} WHERE post_type = 'mnswmc'` (no user input) — no injection risk.
- No credentials, tokens, or sensitive data are introduced, logged, or committed.

## 7. Compatibility Implications

- **Metadata contract:** the Lite plugin now writes the rate exactly where the main Navasan plugin expects it (`_mnswmc_currency_value`), so a store that switches between the two plugins (one active at a time, per the mutual-exclusivity guard) keeps its currency rates.
- **Backward compatibility:** pre-existing `_mnswmc_rate` values remain readable (fallback) and are copied to the standard key on the next activation. No data is lost or deleted.
- **Other metadata keys** (`_mnswmc_symbol`, `_mnswmc_active`, `_mnswmc_currency_id`, `_mnswmc_regular_price`, `_torob_scraper_*`) are unchanged.
- **Still BLOCKED:** direct verification against the main plugin (not installed; source unavailable) and a real coexistence test.

## 8. Tests Executed and Results

### 8.1 PHP syntax validation — EXECUTED, PASS

`php -l` on all 6 modified PHP files: **6/6 no syntax errors**.

### 8.2 Live verification on `anishop-package-test.local` — EXECUTED, PASS

Scripts in `/tmp/task08-meta-compat/` (outside the repo), run via `wp eval-file`. Only the authorized test products were touched; the rate of test currency `17654` was changed `2000 → 2500 → 2000` through the **real admin save workflow** (nonce + `wp_update_post()` → `save_post_mnswmc` → `_mnswmc_currency_value` persistence → `ani_navasan_lite_recalc_currency`) and restored to `2000`.

**Run 1 (`live-verify.php`, 14 checks): 12 passed, 2 "failed".**
The 2 failures were **incorrect test expectations, not plugin defects**: product `17613` is **on sale** (`_regular_price=200000`, `_sale_price=120000`). The recalculation correctly updated the *regular* price and preserved `_sale_price` (established design); the active price (`get_price()`) therefore stayed at the sale price `120000`. Diagnosis: full meta-row dump of `17613` + step-by-step reproduction of the handler (query finds `17613`; `set_regular_price()` + `save()` succeed; `_regular_price` updated; `_price` correctly remains the sale price).

**Run 2 (`live-verify-v2.php`, corrected expectations, 17 checks): 17/17 PASS.**

| Check | Result |
|---|---|
| Helper reads standard key (`17654` → 2000) | PASS |
| Fallback: temp currency with legacy key only → 1500 | PASS |
| Migration idempotency (re-run leaves `17654`/`17655` unchanged) | PASS |
| Rate saved to `_mnswmc_currency_value` via real workflow (2500, then 2000) | PASS |
| Simple `17613` regular price recalculated (250000 / 200000) | PASS |
| Simple `17613` sale price preserved (120000) and active price = sale price | PASS |
| Variation `17616` recalculated (250000 / 200000) | PASS |
| Independent variation `17617` unchanged (33600000) | PASS |
| Variable parent `17615` `_price` rows synced (250000,33600000 / 200000,33600000) | PASS |
| Temp fallback currency deleted | PASS |

**Migration on the real site (verified):** the activation-time migration ran on the target site; currency `17655` (legacy-only, `2000`) now has both keys (`_mnswmc_rate=2000`, `_mnswmc_currency_value=2000`); option `ani_navasan_lite_migrated_rate_key=1`.

**Final live state (consistent):** `17654` rate `2000` (standard key); `17613` regular `200000` / sale `120000`; `17616` `200000`; `17617` `33600000`; `17615` parent rows `200000,33600000`.

### 8.3 Docker compatibility matrix (8 environments × 37 checks)

The full Task 08 matrix (PHP 7.4.33/8.0.30/8.1.34/8.2.35/8.3.36 × WP 7.0.8/7.1.4 × WC 9.6.2/11.2.2) was re-run with the modified plugin + extended harness (37 checks, incl. the new S9 section).

- **First re-run (before the S9.4 harness fix):** environments 0–3 completed at **36/37** — the single failure in each was `S9.4`, caused by a **test-harness bug** (stale `$_POST` from the S5 save leaked into the temporary post's `save_post_mnswmc` handler, which pre-populated `_mnswmc_currency_value=3000`; the migration then correctly skipped it). This cannot occur in a real admin request (`$_POST` is per-request). The run was stopped after environment 3 because the remaining environments would fail identically.
- **Harness fix:** `unset( $_POST['mnswmc_meta_nonce'], $_POST['mnswmc_symbol'], $_POST['mnswmc_rate'] )` before creating the temporary post in S9.4 (documented in the harness).
- **Second re-run:** started after the fix; results in §8.4.

### 8.4 Matrix results (final) — EXECUTED, PASS

**8/8 environments PASS, 37/37 checks each (296 total, 0 failures):**

| Environment | PHP | WP | WC | Result |
|---|---|---|---|---|
| 8.3-wp7.1.4-wc11.2.2 | 8.3.36 | 7.1.4 | 11.2.2 | 37/37 PASS |
| 7.4-wp7.1.4-wc11.2.2 | 7.4.33 | 7.1.4 | 11.2.2 | 37/37 PASS |
| 8.0-wp7.1.4-wc11.2.2 | 8.0.30 | 7.1.4 | 11.2.2 | 37/37 PASS |
| 8.1-wp7.1.4-wc11.2.2 | 8.1.34 | 7.1.4 | 11.2.2 | 37/37 PASS |
| 8.2-wp7.1.4-wc11.2.2 | 8.2.35 | 7.1.4 | 11.2.2 | 37/37 PASS |
| 8.3-wp7.0.8-wc11.2.2 | 8.3.36 | 7.0.8 | 11.2.2 | 37/37 PASS |
| 8.3-wp7.1.4-wc9.6.2 | 8.3.36 | 7.1.4 | 9.6.2 | 37/37 PASS |
| 7.4-wp7.1.4-wc9.6.2 | 7.4.33 | 7.1.4 | 9.6.2 | 37/37 PASS |

The new S9 checks (standard-key read, legacy fallback, standard-key precedence, migration copy, migration no-overwrite) passed in every environment. The confirmed support range is unchanged: **PHP 7.4–8.3, WP 7.0.8–7.1.4, WC 9.6.2–11.2.2**.

## 9. Known Limitations

- The main plugin's data contract (`_mnswmc_currency_value`) is based on the **project owner's specification**; it was **not verified against the main plugin's source** (not installed on the target site, file unavailable). If the main plugin's actual key differs, the helper + migration would need a one-line adjustment.
- Coexistence/compatibility testing with the main plugin remains **BLOCKED** (unchanged from Task 08).
- The legacy `_mnswmc_rate` key is intentionally **kept** (not deleted) after migration, so both keys coexist on migrated currencies.

## 10. Remaining Issues

- Record the final matrix results (§8.4) and finalize this report.
- Main-plugin coexistence test (needs a ZIP/version of `mns-woocommerce-rate-based-products`) — still BLOCKED.
- Task 07's two `wp_wc_product_meta_lookup` CLI-context failures remain unresolved (unchanged; the lookup table is not used as a verification source).

## 11. Git Branch

`test/task-08-metadata-compatibility` (created from `origin/main` at `1a03779`)

## 12. Commit Hash

The commit that adds the metadata-compatibility changes, the extended smoke test, and this report on the task branch (see `git log --oneline test/task-08-metadata-compatibility`).

## 13. Push Status

Branch `test/task-08-metadata-compatibility` pushed to `origin`. **Not merged** (per project workflow: the project owner reviews via ChatGPT and makes the final decision).

---

## 14. Task 08 — Final Compatibility Fixes (Phase 2, 2026-11-13)

**Objective:** complete the three remaining items of Task 08:
1. Align the currency **symbol** metadata.
2. Review and decide the **sale price** metadata.
3. Install the main Navasan plugin + ionCube in the approved environment and run real bidirectional + Guard tests.

**Approved environment:** `http://anishop-package-test.local` only (WP path `/home/ramin/Local Sites/anishop-package-test/app/public`). Authorized test products `17613`/`17615`/`17616`/`17617` and test currencies. No production changes.

### 14.1 Environment & runtime identification

- **Actual PHP runtime: 8.4.10** (both web php-fpm and CLI) — **not** the previously reported 8.3.36. The site is a **Local** site (lightning-services), not a Studio site.
- WordPress **7.1.4**, WooCommerce **11.3.0**.
- The site's php.ini is site-specific (`/home/ramin/.config/Local/run/vTKHuMGVX/conf/php/php.ini`), shared by the site's web (php-fpm) and CLI (via the `wp` wrapper, which resolves the same php.ini). Each Local site has its own php.ini, so modifying it affects **only this site**.

### 14.2 ionCube Loader installation — DONE, VERIFIED

- The main plugin requires ionCube Loader ≥ 12.0.0 (`includes/Requirements.php`: `IC_MIN = '12.0.0'`; also PHP ≥ 7.4, WP ≥ 6.0, WC ≥ 7.0 — all satisfied by the site).
- Installed ionCube Loader **15.5** (`ioncube_loader_lin_8.4.so`) in a **site-specific** directory: `/home/ramin/Local Sites/anishop-package-test/ioncube/` (outside the web root; not shared with other sites).
- Added `zend_extension = /home/ramin/Local Sites/anishop-package-test/ioncube/ioncube_loader_lin_8.4.so` as the **first line** of the site's php.ini (ionCube requires it to be the first entry). Backup: `php.ini.bak-task08b`.
- **Verified in both runtimes:**
  - Web (php-fpm): `{"php":"8.4.10","ioncube":"15.5"}` (via a temporary check file, removed immediately after).
  - CLI: `PHP 8.4.10 | ionCube 15.5`.
- php-fpm was restarted cleanly (the Local app supervisor restarted it; the site returned HTTP 200).
- **No changes to other sites' PHP runtimes** (each site has its own php.ini; the `.so` is in a site-specific directory and is only referenced by this site's php.ini).

### 14.3 Main plugin installation & activation — INSTALLED, ACTIVATION BLOCKED

- Installed `mns-woocommerce-rate-based-products` **7.12.0** from `docs/sources/mns-woocommerce-rate-based-products.zip` (via `wp plugin install`).
- **Activation fails with a fatal error (exit 255).** The PHP error log shows:
  > `PHP Fatal error: The encoded file .../wp-content/plugins/mns-woocommerce-rate-based-products/includes/Core.php was created with an unauthorised copy of the ionCube PHP Encoder. Please contact legal@ioncube.com with details of the provider of this PHP script.`
- **Root cause (evidence-based):** the ionCube-encrypted files in the ZIP (`includes/Core.php`, `includes/Helpers.php`, `includes/Admin/WooCommerce.php`, `includes/Admin/Settings.php`, `includes/RestApi.php`) were encoded with an **unauthorized (pirated) copy of the ionCube Encoder**. The legitimate ionCube Loader (15.5) **refuses to execute** such files.
- The plugin's bootstrap (`mns_navasan_run()`) always loads the encrypted `Core.php` (`Core::instance()`), so **every load of the active plugin fatals**. The plugin cannot be activated or run in this environment.
- **This is a licensing/provenance issue, not a PHP/WP/WC version incompatibility.** All of the plugin's stated requirements are satisfied by the site (PHP 8.4.10 ≥ 7.4, WP 7.1.4 ≥ 6.0, WC 11.3.0 ≥ 7.0, ionCube 15.5 ≥ 12.0).
- **Consequence:** the main plugin cannot be activated, so the real bidirectional compatibility test and the real Guard test (with the main plugin actually active) are **BLOCKED**.

### 14.4 Source analysis of the main plugin (readable files)

The ZIP contains a mix of readable and ionCube-encrypted PHP files. The readable files (`mns-woocommerce-rate-based-products.php`, `includes/Requirements.php`, `includes/DB.php`, `includes/Admin/MetaBoxes.php`, `includes/Admin/PostTypes.php`, `templates/metaboxes/*.php`) provide the metadata contract:

**Currency meta keys (on `mnswmc` posts):**
- The DB layer (`includes/DB.php`) builds keys as `_` + `mnswmc_` + name. The Currency class (encrypted) maps short names to full field names.
- **Confirmed from readable source** (`includes/Admin/PostTypes.php`, direct `meta_key` queries used for column sorting):
  - `_mnswmc_currency_value` — base rate (the "value" field). **This independently confirms the rate key aligned in Phase 1 (§1–§13).**
  - `_mnswmc_currency_lowest_rate`, `_mnswmc_currency_profit`, `_mnswmc_currency_fee`, `_mnswmc_currency_rate` (final rate).
- The currency save handler (`includes/Admin/MetaBoxes.php::currency_save`) writes (via `$currency->update_meta(...)`): `rate_symbol` ← `$_POST['_mnswmc_currency_rate_symbol']`, `value` ← `$_POST['_mnswmc_currency_value']`, plus `calculation_type`, `formula_text`, `profit`, `fee`, `ratio`, `fixed`, `update_type`, `relation`, `connection`, `operation`, `calculation_order`, `lowest_rate`, `rounding_value`, `rounding_type`, `rounding_side`.

**Product meta keys (on products/variations):**
- `_mnswmc_active` ("Rate Based" checkbox) — same as Lite.
- `_mnswmc_dependence_type` (`simple`/`advanced`) — Lite uses `simple`/`variable`.
- `_mnswmc_currency_id` — same as Lite.
- `_mnswmc_regular_price` ("Rate Price" — the currency-denominated regular price) — same as Lite.
- `_mnswmc_sale_price` ("Sale Rate Price" — the currency-denominated sale price) — **Lite has no equivalent**.
- `_mnswmc_profit_margin`, `_mnswmc_profit_type`, `_mnswmc_price_rounding`, `_mnswmc_rounding_type`, `_mnswmc_rounding_side`, `_mnswmc_ceil_price`, `_mnswmc_floor_price`, `_mnswmc_price_alert`.

### 14.5 Symbol metadata — NOT EQUIVALENT (no change made)

**Finding (evidence-based):** the two "symbol" keys are **not equivalent** — they store different concepts:

| Plugin | Key | Concept | Example value |
|---|---|---|---|
| Lite | `_mnswmc_symbol` | The currency's **ISO symbol** (identifies the currency) | `USD`, `AED` |
| Main | `_mnswmc_currency_rate_symbol` | The **display unit of the rate** (identifies the rate's unit) | `Toman` |

**Evidence:**
- Main plugin template (`templates/metaboxes/currency.php`): the field is labeled "Rate Symbol" with the help text: *"Set the unit used to display the rate of this currency. For example, if you enter Tomans and the currency rate is 50000, the rate of this currency will be displayed as 50,000 Tomans."* The placeholder is `mns_navasan()->get_currency_symbol()` (the store's base currency = Toman).
- Lite metabox (`includes/currencies-metabox.php`): the field is labeled "نماد اختصاری (ISO)" (ISO abbreviation symbol) with examples "USD, AED, EUR".
- In the main plugin, the currency's own identity (name/symbol) is the **post title**, not a meta key. `_mnswmc_currency_rate_symbol` is only the unit for displaying the rate.

**Decision:** per the task instruction ("if the source review shows the two keys are not equivalent, do not replace them without reason and report the evidence"), **no replacement/migration was implemented**. Writing Lite's ISO symbol (`USD`) into `_mnswmc_currency_rate_symbol` would be semantically wrong (the main plugin would display the rate as "15,000 USD" instead of "15,000 Toman").

**Compatibility impact:** display-only. The price-calculation contract (rate via `_mnswmc_currency_value`, currency price via `_mnswmc_regular_price`) is fully compatible. The symbol/rate-symbol difference only affects how the rate is displayed in the main plugin's admin (a Lite-created currency would show the rate without a unit, or with a default).

**Optional future enhancement (not implemented, needs owner approval):** Lite could additionally write the rate unit (`تومان`) to `_mnswmc_currency_rate_symbol` so the main plugin displays the rate correctly. This is a display-only enhancement.

### 14.6 Sale price metadata — NOT EQUIVALENT (no change made)

**Finding (evidence-based):** the two "sale price" keys are **not equivalent**:

| Plugin | Key | Concept |
|---|---|---|
| Main | `_mnswmc_sale_price` | The **currency-denominated** sale price (input, e.g. `8` USD) |
| Lite | `_sale_price` (WooCommerce standard) | The **Toman-denominated** sale price (output, set directly by the admin) |

**Evidence:**
- Main plugin product metabox (`templates/metaboxes/product.php`): `_mnswmc_sale_price` is labeled "Sale Rate Price" (the sale price in the currency), parallel to `_mnswmc_regular_price` ("Rate Price"). The main plugin computes the final Toman sale price from `_mnswmc_sale_price` × rate (+ profit/rounding) and stores it in the WC standard `_sale_price`.
- Lite has **no currency-denominated sale price field**. Lite's admin sets the WC standard `_sale_price` (Toman) directly, and Lite **preserves** `_sale_price` during recalculation (it never overwrites it).

**Decision:** per the task instruction, **no change was made**. Lite intentionally does not support currency-denominated sale prices (lightweight design). Adding `_mnswmc_sale_price` would require implementing the full currency sale-price calculation logic, which is out of scope for the lightweight plugin.

**Compatibility impact:**
- A Lite-created product (has `_mnswmc_regular_price`, no `_mnswmc_sale_price`) works in the main plugin (no sale price).
- A main-created product with a currency sale price (`_mnswmc_sale_price`) will have its Toman `_sale_price` **stale** in Lite (Lite does not recalculate the sale price when the rate changes, because it does not store the currency sale price). The regular price is still recalculated correctly.

### 14.7 Guard test

**Real Guard test (main plugin actually active): BLOCKED.** The main plugin cannot be activated (fatal: unauthorized ionCube encoder, §14.3). The guard's `admin_init` check runs after plugin loading, but the main plugin fatals during loading (before `admin_init`), so the real scenario cannot be executed.

**Simulated Guard test (main plugin "active" via the `option_active_plugins` filter, code not loaded): EXECUTED, PASS.**
- Baseline: `is_plugin_active(main)` = `false`, Lite active.
- Simulated: `is_plugin_active(main)` = `true` (filter).
- After `do_action('admin_init')`: **Lite was deactivated by the guard** (Lite active = `false`).
- Restored: Lite re-activated (final state: Lite active, main plugin inactive).
- **Result:** the guard's deactivation logic works — when the main plugin is active, Lite deactivates itself.

**Load-prevention guard (`plugins_loaded`): verified by code review + the "load" path.** The `plugins_loaded` handler loads Lite's code only if the main plugin is inactive. The "load" path is confirmed (Lite's functions work when the main plugin is inactive). The "don't load" path is a simple `if (!is_plugin_active(...))` check (code review).

**Final plugin state:** Lite **active**, main plugin **inactive** (installed but not activatable).

### 14.8 Bidirectional compatibility test

**Full bidirectional test (main plugin actually running): BLOCKED** (the main plugin cannot run, §14.3).

**Metadata contract verification (source analysis + Lite regression): PARTIALLY VERIFIED.**
- **Lite → Original (contract):** Lite writes the rate to `_mnswmc_currency_value` (the main plugin's standard key, confirmed in §14.4) and the currency price to `_mnswmc_regular_price` (the main plugin's key). The main plugin reads these keys (confirmed in readable source). **Contract compatible.** (Not verified by actually running the main plugin.)
- **Original → Lite (contract):** The main plugin writes the rate to `_mnswmc_currency_value` and the currency price to `_mnswmc_regular_price`. Lite reads the rate via `ani_navasan_get_currency_rate()` (reads `_mnswmc_currency_value`) and the currency price via `_mnswmc_regular_price`. **Contract compatible.** (Not verified by actually running the main plugin.)
- **Sale price:** not compatible (Lite has no currency sale price, §14.6).
- **Symbol:** not compatible (different concepts, §14.5).

### 14.9 Regression tests — EXECUTED, PASS

Live regression on `anishop-package-test.local` (authorized test products `17613`/`17615`/`17616`/`17617`, test currencies `17654`/`17655` + temporary currencies, deleted after), via `wp eval-file`:

| Check | Result |
|---|---|
| R1 standard rate read (`17654` → 2000) | PASS |
| R2 legacy rate fallback (temp currency, legacy only → 3000) | PASS |
| R3a migration copies legacy→standard (→ 4000) | PASS |
| R3b migration keeps legacy key (4000) | PASS |
| R3c migration does not overwrite standard (9999 kept) | PASS |
| R4 standard key priority (both keys → 5000) | PASS |
| R5 simple regular price (`17613` → 200000) | PASS |
| R6 simple sale price (`17613` → 120000) | PASS |
| R7 variation price (`17616` → 200000) | PASS |
| R8 variable parent price (`17615` → 200000) | PASS |
| R9a recalc simple regular (rate 2500 → 250000) | PASS |
| R9b recalc variation regular (→ 250000) | PASS |
| R9c recalc non-dependent unchanged (`17617` → 33600000) | PASS |
| R9d recalc variable parent sync (`17615` → 250000) | PASS |
| R10 sale price preserved after recalc (120000) | PASS |
| R11a–d rounding (1234→1000, 1500→2000, 1999→2000, 2500→3000) | PASS (4) |
| R12 frontend behavior (Store API: Toman price) | PASS |
| R13 no currency price shown to customer (Store API: IRT/تومان) | PASS |

**Total: 21 checks PASS, 0 FAIL.** (The symbol-migration checks from the task's regression list do not apply — no symbol migration was implemented, §14.5.)

**Frontend verification (Store API, product `17613`):** `price=120000`, `regular_price=200000`, `sale_price=120000`, `currency_code=IRT`, `currency_symbol=تومان`. The customer sees the Toman price; the currency price (100 TST) is not shown.

**Final live state (consistent):** `17654` rate `2000` (standard key); `17613` regular `200000` / sale `120000`; `17616` `200000`; `17617` `33600000`; `17615` parent `200000`.

### 14.10 Raw DB metadata (recorded)

**Currencies (`mnswmc` posts):**
- `17654` (TST-REGRESSION): `_mnswmc_symbol=TST`, `_mnswmc_currency_value=2000`, `_mnswmc_rate=` (empty).
- `17655` (TST-LEGACY): `_mnswmc_symbol=` (empty), `_mnswmc_currency_value=2000`, `_mnswmc_rate=2000` (both keys, from the Phase 1 migration).

**Products:**
- `17613` (simple, on sale): `_regular_price=200000`, `_price=120000`, `_sale_price=120000`, `_mnswmc_active=yes`, `_mnswmc_currency_id=17654`, `_mnswmc_regular_price=100`.
- `17615` (variable parent): `_price=200000`, `_mnswmc_active=no`.
- `17616` (variation): `_regular_price=200000`, `_price=200000`, `_mnswmc_active=yes`, `_mnswmc_currency_id=17654`, `_mnswmc_regular_price=100`.
- `17617` (variation): `_regular_price=33600000`, `_price=33600000`, `_mnswmc_active=no`.

### 14.11 Files changed in this phase

**No Lite plugin source files were modified** (the symbol and sale-price keys are not equivalent, so no migration/alignment was implemented; see §14.5, §14.6).

Environment changes (outside the repo):
- ionCube Loader 15.5 installed in `/home/ramin/Local Sites/anishop-package-test/ioncube/` (site-specific).
- The site's php.ini (`/home/ramin/.config/Local/run/vTKHuMGVX/conf/php/php.ini`) — added the ionCube `zend_extension` as the first line (backup: `php.ini.bak-task08b`).
- Main plugin `mns-woocommerce-rate-based-products` 7.12.0 installed on the site (inactive).

Documentation changes (in the repo):
- `docs/reports/2026-11-13-task-08-metadata-compatibility.md` — this section (§14) + status line.
- `docs/نقشه راه توسعه Ani Navasan Lite.md` — Task 08 status updated.
- `.clinerules/memory-bank.md` — updated.

### 14.12 Final result

**PARTIALLY COMPLETE.**
- **Symbol metadata:** analyzed — **not equivalent** (evidence in §14.5). No change made (per task instruction).
- **Sale price metadata:** analyzed — **not equivalent** (evidence in §14.6). No change made (per task instruction).
- **ionCube:** installed and verified (15.5, web + CLI).
- **Main plugin:** installed (7.12.0) but **activation BLOCKED** (unauthorized ionCube encoder — a licensing/provenance issue, not a version incompatibility).
- **Guard test:** simulated test PASS; real test BLOCKED.
- **Bidirectional compatibility:** contract verified (source + regression); full test BLOCKED.
- **Regression:** 21/21 PASS.

**Blocked items (require owner action):**
- A **legitimately licensed** copy of the main plugin (or its source) is needed to run the real bidirectional + Guard tests. The current ZIP's encrypted files were encoded with an unauthorized ionCube encoder and cannot be executed by the legitimate loader.
- A decision on the optional symbol display-compatibility enhancement (§14.5) and the sale-price limitation (§14.6).

### 14.13 Git (this phase)

- **Branch:** `test/task-08-metadata-compatibility` (same branch, continued from `27b2726`).
- **Commit:** the commit adding §14 + roadmap + memory bank updates (see `git log --oneline test/task-08-metadata-compatibility`).
- **Push:** pushed to `origin`. **Not merged.** No tag/release created.
- The main plugin ZIP (`docs/sources/mns-woocommerce-rate-based-products.zip`) and no secrets are committed.
