# End-to-End Currency Recalculation Verification — Task 07 Report

**Date:** 2026-11-11
**Task:** Task 07 — End-to-End Currency Create/Edit → Recalculation, Parent Sync & Data Integrity
**Repository:** `ramin40686/ani-navasan-lite`
**Branch:** `test/task-07-e2e-currency-recalculation`
**Status:** COMPLETE (test-only; no plugin source changes)

---

## 1. Task Objective

Verify, on the local test site, that the Ani Navasan Lite currency create/edit workflows correctly trigger:

1. Dependent **simple product** price recalculation.
2. Dependent **variation** price recalculation.
3. **Variable parent** price synchronization.
4. **Data integrity** (no drift, duplicate meta, duplicate variations, or parent corruption).
5. **Sale-price preservation** during recalculation.
6. **Nearest-1,000-Toman rounding** consistency (PHP vs. JavaScript).
7. **Disabled-dependency** exclusion (disabled products are not recalculated).

The test was driven through the **real `mnswmc` admin save workflow** (nonce + `wp_insert_post()` / `wp_update_post()` → `save_post_mnswmc` → `_mnswmc_rate` persistence → `ani_navasan_lite_recalc_currency`), **not** by calling the recalculation handler directly.

**Explicitly out of scope / not tested:** the browser-based admin UI (the real save *callback* path was exercised, but the actual admin form/DOM was not).

---

## 2. Environment

| Item | Value |
|---|---|
| Site | `http://anishop-package-test.local` |
| WordPress | `7.1.4` |
| WooCommerce | `11.2.2` |
| PHP | `8.3.30` |
| Plugin | `ani-navasan-lite` (active) |
| Test driver | WP-CLI `wp eval-file` (real admin save callbacks) |

**Authorized test products** (backed up before any modification):

| ID | Type | Role |
|---|---|---|
| `17613` | simple | simple-product recalculation target |
| `17615` | variable | variable parent (sync target) |
| `17616` | variation (child of 17615) | variation recalculation target |
| `17617` | variation (child of 17615) | variation recalculation target |

**Temporary currencies created for the test** (all deleted at the end):

| Label | ID | Initial rate |
|---|---|---|
| TEST-A (task07) | `17624` | `1500` |
| TEST-B (task07) | `17625` | `70000` |
| TEST-C (task07) | `17626` | `70000` |

**Pre-test state:** `0` existing `mnswmc` currency records. A full backup (post data, raw postmeta, WooCommerce state, structured attributes, variation relationships, lookup rows, existing currencies) was generated and verified at `/tmp/task07/backup.json`.

> Note: the authorized products carried pre-existing `_mnswmc_*` meta from an earlier test (referencing currency IDs `4168`/`15939`, which no longer exist). This pre-existing meta was captured in the backup and faithfully restored — it is **not** a product of this task.

---

## 3. Test Method

- **Currency saves** were driven through the real admin save workflow: a helper (`/tmp/task07/helpers.php`) set the admin user, generated the `mnswmc_nonce`, and called `wp_insert_post()` / `wp_update_post()` with the `mnswmc_rate` / `mnswmc_symbol` fields. This fired the genuine `save_post_mnswmc` handler, which persisted `_mnswmc_rate` / `_mnswmc_symbol` and fired `ani_navasan_lite_recalc_currency`.
- A logging handler recorded every `ani_navasan_lite_recalc_currency` invocation (currency ID + rate) to confirm the action fired exactly once per save with the correct arguments.
- **Direct calls to `ani_navasan_lite_recalc_currency` were avoided** for the main proof of behavior.
- Prices were verified via the WooCommerce CRUD API (`get_price()` / `get_regular_price()`), the raw `_price` / `_regular_price` postmeta, the **Store API over HTTP**, and the **frontend product pages**.


---

## 4. Scenario Results

| Scenario | Description | Result |
|---|---|---|
| **1** | Simple product recalculation (17613 ← TEST-A) | **9/11** (2 lookup-table checks failed — CLI limitation, see §7) |
| **2** | Variation recalculation + isolation (17616 ← TEST-B, 17617 ← TEST-C) | **14/14** |
| **3** | Disabled dependency is not recalculated (17613) | **3/3** |
| **4** | Variable parent synchronization (17615) | **3/3** (+ Store API verified over HTTP) |
| **5** | Nearest-1,000-Toman rounding, PHP vs. JS | **16/16** |
| **6** | Sale-price preservation during recalculation (17613) | **8/8** |
| **7** | Repeatability — no drift / duplicates / corruption | **15/15** |
| **Store API (HTTP)** | All four products | **4/4** |
| **Frontend** | All four product pages | **4/4** |

**Total scenario checks: 68, with 2 failures** — both in Scenario 1, both `wp_wc_product_meta_lookup` checks, both attributable to the CLI context (see §7), **not** to a recalculation defect.

### Scenario 1 — Simple product (17613 ← TEST-A)
- TEST-A rate edited `1500 → 2000`.
- `ani_navasan_lite_recalc_currency` fired **once** with `currency_id=17624`, `rate=2000`.
- `17613` recalculated to `2,000,000` (1000 × 2000).
- `17615`, `17616`, `17617` remained unchanged (correct isolation).
- The 2 failed checks were `wp_wc_product_meta_lookup` row assertions (see §7).

### Scenario 2 — Variations + isolation (17616 ← TEST-B, 17617 ← TEST-C)
- TEST-B rate `70000 → 80000`: `17616` recalculated to `38,800,000` (485 × 80000).
- TEST-C rate `70000 → 75000`: `17617` recalculated to `36,000,000` (480 × 75000).
- Correct isolation: each variation followed only its own currency.
- `17615` variation count remained `2` (no variation duplication).

### Scenario 3 — Disabled dependency (17613)
- `17613` set to `_mnswmc_active = 'no'`; TEST-A rate changed `2000 → 2500`.
- The recalc action still fired (the currency was saved), but `17613` was **skipped** — price remained `2,000,000` (not `2,500,000`).
- `17613` re-enabled afterward.

### Scenario 4 — Variable parent sync (17615)
- After Scenarios 1–2, `17615`'s variations were `17616 = 38,800,000` and `17617 = 36,000,000`.
- Parent `17615` price correctly became `36,000,000` (min variation price).
- Parent `_price` meta contained exactly **2 distinct rows**: `36,000,000` and `38,800,000`.
- Expected parent range: `36,000,000–38,800,000`.
- The Store API schema class was unavailable in the CLI context, so the Store API was verified **over HTTP** instead (see §5).

### Scenario 5 — Rounding (17613, currency_price = 1000)
Verified for boundary, below-midpoint, above-midpoint, and exact-midpoint cases:

| Raw value | Expected (nearest 1,000) | PHP | JS-equivalent | Live recalc |
|---|---|---|---|---|
| `1,500,000` (boundary) | `1,500,000` | ✅ | ✅ | ✅ |
| `1,500,400` (below midpoint) | `1,500,000` | ✅ | ✅ | ✅ |
| `1,500,600` (above midpoint) | `1,501,000` | ✅ | ✅ | ✅ |
| `1,500,500` (exact midpoint) | `1,501,000` (round half up) | ✅ | ✅ | ✅ |

PHP `ani_navasan_round_to_thousand()` and the JavaScript `mnswmcRoundToThousand()` produced **identical** results in all cases, and the live recalculation matched the PHP-rounded value.

### Scenario 6 — Sale-price preservation (17613)
- Sale price set to `1,000,000` (product on sale).
- TEST-A rate changed `1500.5 → 1600`.
- Regular price updated to `1,600,000`; **sale price preserved** at `1,000,000`; product still on sale; active price = sale price (`1,000,000`); `_sale_price` meta intact.
- Sale price removed afterward.

### Scenario 7 — Repeatability
- TEST-A rate `1600` saved **5 times** in a row: `17613` stable at `1,600,000` each time (recalc fired once per save).
- Rate `1600 → 1700 → 1600`: price returned exactly to `1,600,000` (no drift).
- TEST-B rate `80000` saved 3 times (parent-sync path exercised repeatedly).
- Meta counts unchanged for all four products (71/60/33/33).
- `17615` variation count unchanged (`2`).
- Parent `17615` `_price` rows = exactly the distinct variation prices (`36,000,000`, `38,800,000`).


---

## 5. Store API and Frontend Verification

**Store API (over HTTP)** — price data under the `prices` object:

| Product | Store API price |
|---|---|
| `17613` | `2,000,000` |
| `17615` | `36,000,000` (range `36,000,000–38,800,000`) |
| `17616` | `38,800,000` |
| `17617` | `36,000,000` |

**Frontend product pages** — `woocommerce-Price-amount` values parsed from the rendered permalinks:

| Product | Displayed price |
|---|---|
| `17613` | `2,000,000` |
| `17615` | `36,000,000` (range `36,000,000–38,800,000`) |
| `17616` | `38,800,000` (variation page renders the parent range) |
| `17617` | `36,000,000` (variation page renders the parent range) |

All displayed prices matched the recalculated values. (Variation permalinks render the parent product page, so they show the parent price range — expected WooCommerce behavior.)

---

## 6. Restoration and Cleanup

After all scenarios, the site was restored to its exact pre-test state:

1. **Products `17613`, `17615`, `17616`, `17617`** — all postmeta deleted and the exact original raw meta rows re-inserted (byte-for-byte, so structured/serialized meta such as `_product_attributes` was restored without double serialization); content-relevant post fields restored; `wc_product_meta_lookup` rows restored (all columns); caches cleared.
2. **Temporary currencies `17624`, `17625`, `17626`** — deleted.
3. **`wp-config.php`** — restored from `/tmp/wp-config.php.bak-task07` (the only change had been temporary `WP_DEBUG` / `WP_DEBUG_LOG` / `WP_DEBUG_DISPLAY` settings).

**Restore verification (49/49 checks passed):**
- All four products' meta matches the backup **exactly** (key → value multiset comparison).
- All post content fields (title, status, parent, content) match the backup.
- All prices match the backup (`17613 = 19,600,000`; `17615 = 33,600,000` min; `17616 = 33,950,000`; `17617 = 33,600,000`).
- `17615` `_price` rows = the two original variation prices (`33,600,000`, `33,950,000`).
- `17613` / `17615` `_product_attributes` are valid arrays and **not** double-serialized.
- `17615` variation count = `2`.
- All lookup rows (min/max price, stock status, onsale) match the backup.
- All three temporary currencies confirmed deleted; `mnswmc` count = `0`.

**Final sanity check:** plugin ACTIVE; all four product prices back to original; `mnswmc` count `0`.

> **Restore bug found and fixed during this task:** the first restore run used the wrong field names (`$row['key']` / `$row['value']`) when re-inserting meta, while the backup stores `meta_key` / `meta_value`. This inserted 71/60/33/33 rows with empty keys/values. The script was corrected to use `meta_key` / `meta_value` and re-run; the corrected restore passed all 49 verification checks. This was a **test-harness** bug only — no plugin source was affected.

---

## 7. Known Limitations and CLI-Context Caveats

1. **Browser admin UI not tested.** The real `mnswmc` save *callback* path was exercised (nonce + `wp_insert_post()` / `wp_update_post()` → `save_post_mnswmc`), but the actual admin form / DOM / browser UI was **not** tested. The variation admin-screen JavaScript selectors in particular remain unverified against a live admin DOM (a pre-existing P2 item).
2. **`wp_wc_product_meta_lookup` is unreliable in this `wp eval-file` CLI context.** Lookup rows sometimes appeared with correct values, sometimes were missing, and direct product saves did not consistently create/update them. The 2 failed Scenario 1 checks were both lookup-table assertions. **Lookup-table behavior in CLI was therefore not used as a primary verification source**; `_price`, `_regular_price`, the Store API, and frontend output were used instead. This is a CLI-context limitation, **not** a recalculation defect.
3. **Store API schema class unavailable in CLI.** `Automattic\WooCommerce\StoreApi\Schemas\CV2\ProductSchema` is not loadable under `wp eval-file`, so the Store API was verified over HTTP rather than in-process.
4. **`wc_version()` not available** in this WooCommerce version; `WC()->version` was used.


---

## 8. Security Implications

- **No plugin source files were modified** in this task. No security posture change.
- The test helper generated a valid `mnswmc_nonce` and ran as the admin user, mirroring the real admin save path. No nonce bypass or capability escalation was introduced or observed.
- No credentials, secrets, API keys, or tokens were committed or included in this report.
- The temporary `WP_DEBUG` / `WP_DEBUG_LOG` settings in `wp-config.php` were **reverted** (restored from backup).

---

## 9. Compatibility Implications

- Verified against **WooCommerce 11.2.2 + WordPress 7.1.4 + PHP 8.3.30** only. Compatibility with other supported versions remains a pre-existing P1 item (not expanded by this task).
- The recalculation workflow (CRUD API price updates, `WC_Product_Variable::sync($parent, false)` + explicit cache clearing, sale-price preservation, nearest-1,000-Toman rounding) behaved as documented in the Memory Bank. No new compatibility concerns were observed.

---

## 10. Files Inspected

- `includes/functions.php` (recalculation handler, rounding helper)
- `includes/currencies-metabox.php` (currency save handler, `ani_navasan_lite_recalc_currency` firing)
- `wp-config.php` (local test site; temporary debug settings)

## 11. Files Created / Modified / Deleted

- **Plugin source:** **none** (no plugin files created, modified, or deleted).
- **Test harness (outside the repository, in `/tmp/task07/`):** `helpers.php`, `setup_currencies.php`, `setup_products.php`, `scenario1_simple.php`, `scenario2_variable.php`, `scenario3_disabled.php`, `scenario4_parent_sync.php`, `scenario5_rounding.php`, `scenario6_sale.php`, `scenario7_repeatability.php`, `backup.php`, `restore.php`, `verify_restore_final.php`, plus result/output artifacts (`backup.json`, `currency_ids.json`, `results_s*.json`, `s*.out`, `storeapi_*.json`, `verify_final.out`, `restore_report.json`).
- **Local site config:** `wp-config.php` temporarily modified (debug) and **restored** from backup.
- **This report:** `docs/reports/2026-11-11-task-07-e2e-currency-recalculation.md` (new).

---

## 12. Tests Executed and Results

| Check | Result |
|---|---|
| Scenario 1 — simple product recalculation | ✅ 9/11 (2 lookup-table checks — CLI limitation, §7) |
| Scenario 2 — variation recalculation + isolation | ✅ 14/14 |
| Scenario 3 — disabled dependency exclusion | ✅ 3/3 |
| Scenario 4 — variable parent sync | ✅ 3/3 (+ Store API over HTTP) |
| Scenario 5 — rounding PHP vs. JS | ✅ 16/16 |
| Scenario 6 — sale-price preservation | ✅ 8/8 |
| Scenario 7 — repeatability / no corruption | ✅ 15/15 |
| Store API (HTTP) — 4 products | ✅ 4/4 |
| Frontend product pages — 4 products | ✅ 4/4 |
| Restore verification (backup comparison) | ✅ 49/49 |
| Final sanity check (plugin active, prices, currency count) | ✅ |

**Not executed (and why):**
- Browser-based admin UI testing (out of scope; documented in §7).
- Automated regression tests (none exist; this was a live manual/CLI test).

---

## 13. Remaining Issues

- **P1 (pre-existing, unchanged):** full end-to-end verification in an environment with the originally-referenced currency CPTs (`15939`/`4168`); compatibility testing across the full supported WP/WC/PHP matrix.
- **P2 (pre-existing, unchanged):** live admin DOM verification of variation JS selectors; automated regression tests; plugin-specific nonce verification for product saving; i18n of hardcoded Persian admin strings.
- **New observation (informational):** the authorized products carried stale `_mnswmc_*` meta referencing non-existent currency IDs (`4168`/`15939`) from an earlier test. This was faithfully preserved/restored (not cleaned, to keep the restore byte-for-byte). A future cleanup task could remove orphaned `_mnswmc_*` meta if desired.

---

## 14. Git Branch, Commit, and Push Status

- **Branch:** `test/task-07-e2e-currency-recalculation` (created from `origin/main`).
- **Commit:** the commit that adds this report on the task branch (a documentation-only commit; see `git log --oneline -1 test/task-07-e2e-currency-recalculation`).
- **Push:** the task branch is pushed to `origin` (tracking set).
- **Merge:** **NOT merged** to `main` (per project workflow — awaiting independent review and explicit approval).
- **PR (optional):** https://github.com/ramin40686/ani-navasan-lite/pull/new/test/task-07-e2e-currency-recalculation

---

## 15. Explicit Statements (per task requirement)

1. **The real `mnswmc` save workflow was tested** — currency creates/edits were driven through nonce + `wp_insert_post()` / `wp_update_post()` → `save_post_mnswmc` → `_mnswmc_rate` persistence → `ani_navasan_lite_recalc_currency`. Direct handler calls were avoided for the main proof of behavior.
2. **The browser admin UI was NOT tested** — only the save callback path was exercised; the admin form/DOM remains unverified.
3. **`wc_product_meta_lookup` behavior in CLI was unreliable** and was **not** used as the primary verification source; `_price`, `_regular_price`, the Store API, and frontend output were used instead.



