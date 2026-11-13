# Compatibility Matrix Validation — Task 08 Report

**Date:** 2026-11-12
**Task:** Task 08 — Docker-based PHP/WordPress/WooCommerce compatibility matrix (CLI smoke test)
**Repository:** `ramin40686/ani-navasan-lite`
**Branch:** `test/task-08-compatibility-matrix`
**Status:** COMPLETE (finalized) — full matrix green (8/8 environments, 32/32 checks each); no plugin behavior changes; header support-range fields added (`Requires at least` corrected to `7.0.8`); compatibility with the main Navasan plugin **BLOCKED/UNTESTED** (main plugin not installed on the target site)

---

## 1. Task Objective

Validate `ani-navasan-lite` against a Docker-based PHP × WordPress × WooCommerce compatibility matrix using a repeatable CLI smoke test, and — if the results justify it — declare the supported range in the plugin header.

The smoke test exercises the plugin's core runtime paths **without a browser**:

1. Plugin bootstrap (all `includes/*` files load; no fatal errors).
2. Currency CPT (`mnswmc`) registration.
3. Currency create + rate save → `ani_navasan_lite_recalc_currency` fires.
4. **Simple product** recalculation (currency price × rate, rounded to nearest 1,000 Toman).
5. **Variation** recalculation.
6. **Variable parent** price synchronization (`WC_Product_Variable::sync`).
7. **Sale-price preservation** (recalculation updates `_regular_price`; `_sale_price` untouched; `is_on_sale()` and active price correct).
8. **Disabled-dependency exclusion** (products with `_mnswmc_active = no` are not recalculated).
9. **Rounding consistency** (PHP `ani_navasan_round_to_thousand()` vs. JS `mnswmcRoundToThousand()`).
10. **AJAX endpoint** (`wp_ajax_mnswmc_get_exchange_rate`) — nonce + `edit_products` capability + correct rate/symbol/title.
11. **Admin metabox rendering** (product + variation fields render without fatal errors).

**Explicitly out of scope:** the browser-based admin UI/DOM (the real save *callback* and render *functions* are exercised, but not the live DOM). This remains a P2 item (see §9).

---

## 2. Initial Findings

- The compatibility harness (`tests/compat/`) had been scaffolded in prior work; environment 0 (`8.3 / 7.1.4 / 11.2.2`) ran to completion and produced valid JSON, but **1 of 32 checks failed**.
- The failing check was **`S5.1 sale_regular_150000`** (got `180000`).
- Root cause: a **test-expectation bug, not a plugin bug**. The sale product is `60 TST × 3000 = 180000` Toman; the plugin computed `180000` correctly. The test wrongly expected `150000` — which is actually the *variation's* value (`50 TST × 3000`, correctly asserted by `S5.5`).
- Prior to this, three **CLI test-loading** issues (not plugin issues) had already been fixed in `smoke-test.php` (see §5.2).

**Conclusion:** the plugin itself is compatible across the matrix as-is; the only plugin-file change required was adding header metadata fields.

---

## 3. Files Inspected

| File | Purpose of inspection |
|---|---|
| `ani-navasan-lite.php` | Plugin header (missing support-range fields); mutual-exclusivity guard. |
| `includes/init.php`, `includes/functions.php`, `includes/hooks.php`, `includes/ajax.php`, `includes/currencies-cpt.php`, `includes/currencies-metabox.php`, `admin/admin.php`, `admin/metabox.php` | Confirmed the runtime paths the smoke test must exercise. |
| `assets/js/admin.js` | Confirmed `mnswmcRoundToThousand()` for the PHP/JS rounding-consistency check. |
| `tests/compat/smoke-test.php` | The failing check and the CLI-loading fixes. |
| `tests/compat/run-env.sh`, `tests/compat/run-matrix.sh`, `tests/compat/README.md` | Harness behavior (container lifecycle, JSON parsing, matrix definition). |
| `woocommerce/includes/admin/class-wc-admin.php`, `woocommerce/includes/admin/wc-meta-box-functions.php` | Confirmed the admin form helpers are not auto-loaded in CLI and must be required explicitly. |

---

## 4. Files Created, Modified, or Deleted

**Created:**
- `tests/compat/smoke-test.php` — the CLI smoke test (32 checks).
- `tests/compat/run-env.sh` — single-environment Docker runner (builds a throwaway WP+WC+PHP stack, installs the plugin, runs the smoke test, emits JSON + artifacts).
- `tests/compat/run-matrix.sh` — full-matrix runner (8 environments, sequential).
- `tests/compat/README.md` — harness documentation.
- `tests/compat/results/.gitignore` — excludes generated artifacts from version control.

**Modified:**
- `ani-navasan-lite.php` — added 5 header metadata fields (support range). No behavior change.
- `tests/compat/smoke-test.php` — fixed the `S5.1` expected value (`150000` → `180000`) and its check name.

**Deleted:** none.

> The generated artifacts under `tests/compat/results/` (per-environment `.json`, `.stderr`, `.debug.log`, `.versions`, and `summary.txt`) are **not committed**; they are regenerated on every run and are documented in §8.

---

## 5. Detailed Description of Changes

### 5.1 Plugin header (`ani-navasan-lite.php`)

Added the standard WordPress + WooCommerce support-range header fields, justified by the green matrix (§8):

```
 * Requires at least: 7.0.8
 * Requires PHP: 7.4
 * Tested up to: 7.1
 * WC requires at least: 9.6.2
 * WC tested up to: 11.2.2
```

This is metadata only — it does not alter runtime behavior.

> **Note (finalization):** `Requires at least` was set to `7.0.8` (not `7.0`) because the lowest WordPress version actually tested in the matrix is `7.0.8`; there is no evidence of testing `7.0.0`. The value was corrected from an initial `7.0` to `7.0.8` to match the real test evidence.

### 5.2 Smoke-test fixes (`tests/compat/smoke-test.php`)

All fixes are to the **test harness**, not the plugin. They were required because WP-CLI does not replicate the HTTP/AJAX/admin-request environment:

1. **AJAX JSON capture.** In WP 7.1, `wp_send_json_*()` echoes JSON and then calls `wp_die()` **only when `wp_doing_ajax()` is true**; otherwise it performs a bare `die`, which terminates the CLI script. The test now:
   - defines `DOING_AJAX` so `wp_send_json_*()` takes the `wp_die()` path;
   - hooks the context-specific `wp_die` filters (notably `wp_die_ajax_handler`, since AJAX mode uses it rather than only the generic `wp_die_handler`);
   - captures the echoed AJAX JSON via **output buffering** instead of relying solely on the `wp_die` exception message.
2. **Admin form helpers.** WooCommerce admin helpers such as `woocommerce_wp_text_input()` are not loaded in CLI. The test explicitly requires `includes/admin/wc-meta-box-functions.php`.
3. **`plugin_path()` trailing slash.** `WC()->plugin_path()` returns a path **without** a trailing slash in CLI, so direct concatenation produced an invalid file path. The test uses `trailingslashit( WC()->plugin_path() )`.
4. **`S5.1` expectation.** Corrected the sale-product regular-price expectation from `150000` to `180000` (`60 TST × 3000`), matching the plugin's correct output.

---

## 6. Security Implications

- **No security-relevant behavior changed.** The only plugin-file edit is header metadata.
- The smoke test **asserts** the existing security controls rather than altering them:
  - The AJAX endpoint check confirms the nonce (`mnswmc_nonce`) and the `edit_products` capability gate are enforced (a request without a valid nonce/capability is rejected).
  - A nonce is still treated as **not** a substitute for authorization; the capability check is verified independently.
- No credentials, keys, or tokens are introduced, logged, or committed.

---

## 7. Compatibility Implications

- **Confirmed supported range** (all combinations green):
  - **PHP:** 7.4, 8.0, 8.1, 8.2, 8.3
  - **WordPress:** 7.0.8, 7.1.4
  - **WooCommerce:** 9.6.2, 11.2.2
- The plugin uses only stable, documented WooCommerce CRUD APIs (`wc_get_product()`, `set_regular_price()`, `save()`, `WC_Product_Variable::sync()`) and WordPress core APIs; no version-conditional code paths were needed.
- The only cross-version output observed was a **benign WooCommerce core notice** (`_load_textdomain_just_in_time` — translation loading too early), present identically in every environment and originating from WooCommerce, not this plugin. It does not affect functionality.
- **Compatibility with the main Navasan plugin (`mns-woocommerce-rate-based-products`): BLOCKED / UNTESTED.** The main plugin is not installed on the target site (`anishop-package-test.local`) and its file is not available there, so a real coexistence/compatibility test could not be run. The source-level mutual-exclusivity guard (auto-deactivation when the main plugin is active) is **not** equivalent to a real compatibility test. A future test requires a ZIP or specific version of the main plugin.

---

## 8. Tests Executed and Their Results

**Command (full matrix):** `bash tests/compat/run-matrix.sh`
**Command (single env):** `bash tests/compat/run-env.sh <php> <wp> <wc> <label>`

Each environment is a throwaway Docker stack (WordPress + WooCommerce + the requested PHP), the plugin is installed from the working tree, and the 32-check smoke test runs via WP-CLI.

**Result: 8/8 environments PASS, 32/32 checks each (256 total checks, 0 failures).**

| # | PHP | WordPress | WooCommerce | Result |
|---|-----|-----------|-------------|--------|
| 0 | 8.3.36 | 7.1.4 | 11.2.2 | PASS (32/32) |
| 1 | 7.4.33 | 7.1.4 | 11.2.2 | PASS (32/32) |
| 2 | 8.0.30 | 7.1.4 | 11.2.2 | PASS (32/32) |
| 3 | 8.1.34 | 7.1.4 | 11.2.2 | PASS (32/32) |
| 4 | 8.2.35 | 7.1.4 | 11.2.2 | PASS (32/32) |
| 5 | 8.3.36 | 7.0.8 | 11.2.2 | PASS (32/32) |
| 6 | 8.3.36 | 7.1.4 | 9.6.2 | PASS (32/32) |
| 7 | 7.4.33 | 7.1.4 | 9.6.2 | PASS (32/32) |

**Checks per environment (32):** plugin bootstrap (no fatals), CPT registration, currency create + recalc trigger, simple-product recalc, variation recalc, variable-parent sync, sale-price preservation (regular recalced / sale preserved / `is_on_sale` / active price), disabled-dependency exclusion, PHP↔JS rounding consistency, AJAX endpoint (nonce + capability + payload), and admin metabox rendering (product + variation).

**Artifacts (regenerated, not committed):** per-environment `.json` (machine-readable results), `.stderr` (all empty — no PHP errors/warnings), `.debug.log` (only the benign WC notice), `.versions` (resolved PHP/WP versions), and `summary.txt` (PASS/FAIL per environment).

**Static checks executed:**
- `php -l` on `ani-navasan-lite.php` and `tests/compat/smoke-test.php` — **no syntax errors**.


---

## 9. Known Limitations

- **Browser admin DOM not exercised.** The smoke test calls the real save callbacks and render functions, but does not render/inspect the live admin DOM. Variation admin-screen JS selectors still need live DOM verification (carried-over P2).
- **Matrix is a representative subset**, not the full Cartesian product (5 PHP × 2 WP × 2 WC = 20). The 8 chosen environments cover every PHP version, both WP versions, both WC versions, and the minimum/maximum corners. Intermediate combinations (e.g., PHP 8.0 × WC 9.6.2) were not individually tested.
- **`wp_wc_product_meta_lookup`** is not used as a primary verification source in the smoke test (consistent with the unresolved Task 07 CLI-context observation); `_price`, `_regular_price`, and the CRUD object state are used instead.
- The `.versions` artifact records resolved PHP and WP versions but not the resolved WC version (the WC version is captured in the label and the `.json`).

---

## 10. Remaining Issues

- **P1 (BLOCKED):** compatibility with the main Navasan plugin (`mns-woocommerce-rate-based-products`) is UNTESTED — the main plugin is not installed on the target site (`anishop-package-test.local`) and its file is not available there. A future test requires a ZIP or specific version of the main plugin.
- **P2 (carried over → Task 09):** live admin DOM verification of variation/variable JS selectors.
- **P2 (carried over → Task 10):** automated regression test suite (this smoke test is a CLI harness, not a unit/integration suite).
- **P1 (carried over):** the two `wp_wc_product_meta_lookup` Task 07 assertions remain unresolved (cause not confirmed); out of scope for this task.
- **Observation (out of scope, not changed):** `ani-navasan-lite.php` lines 36–37 contain a doubled `/**` (a malformed docblock opener). It is cosmetic, pre-existing, and does not affect behavior; left unchanged to keep this task's scope tight. Flagged for a future cleanup task.

---

## 11. Git Branch Name

`test/task-08-compatibility-matrix`

## 12. Commit Hash

The commit(s) that add the compatibility harness, the header fields, and this report on the task branch (see `git log --oneline test/task-08-compatibility-matrix`).

## 13. Push Status

Pushed to `origin/test/task-08-compatibility-matrix`. **Not merged** (per project workflow — pending independent review and explicit approval).

