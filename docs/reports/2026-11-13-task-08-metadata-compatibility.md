# Task 08 (follow-up) — Metadata Compatibility with the Main Navasan Plugin

**Date:** 2026-11-13
**Branch:** `test/task-08-metadata-compatibility`
**Status:** COMPLETE — implementation, live verification (17/17), and full compatibility matrix (8/8 environments, 296/296 checks) all passed

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
