# Task 10 — Automated Regression / Test Suite

**Date:** 2026-11-14
**Branch:** `test/task-10-automated-regression-suite`
**Base:** latest `origin/main` (post-1.1.0)
**Scope:** Test infrastructure only — **no plugin source changes**

---

## 1. Task Objective

Build a repeatable automated regression/test infrastructure for Ani Navasan Lite
**without changing existing plugin behavior**. The suite must:

- Target the **real plugin code** (not reimplementations).
- Cover the core business logic: rounding, rate helper, migrations, the full
  save flow, and currency-rate recalculation.
- Be safe: live-site tests must back up and restore the full state of every
  record they can modify (scoped to the authorized test products/currencies)
  and verify the restoration.
- Be repeatable: a single command runs the local unit suite; a documented
  command runs the live integration suite.

## 2. Initial Findings

- The plugin's core logic lives in `includes/functions.php` (rounding, rate
  helper, both one-time migrations, the recalculation handler) and
  `admin/metabox.php` (simple + variation save handlers). The decimal-rounding
  hook is in `includes/hooks.php`.
- A pre-existing Docker compatibility matrix exists in `tests/compat/`
  (Task 08) — this task does **not** modify it; it is referenced for coverage.
- The authorized local site `anishop-package-test.local` (PHP 8.4.10 / WP 7.1.4
  / WC 11.3.0) is reachable via WP-CLI, with the plugin active and the
  authorized test data present:
  - Products: `17613` (simple), `17615` (variable parent), `17616` + `17617`
    (variations).
  - Currencies: `17654` (A, rate 2000), `17655` (B, rate 2000).
- Live baseline state (verified before testing):
  - `17613`: `_mnswmc_active=yes`, currency A, `_mnswmc_regular_price=100`,
    `_mnswmc_sale_price=''`, `_regular_price=200000`, `_sale_price=120000`.
  - `17616`: currency A, `_mnswmc_regular_price=100`, `_mnswmc_sale_price=''`,
    `_regular_price=200000`, no WC sale price.
  - `17617`: currency B, `_mnswmc_regular_price=200`, `_mnswmc_sale_price=180`,
    `_regular_price=400000`, `_sale_price=360000`.
  - `17615` (parent): `_price=200000`.

## 3. Files Inspected

- `includes/functions.php` — rounding, rate helper, migrations, recalc handler.
- `includes/hooks.php` — `save_post_product` decimal-rounding handler.
- `admin/metabox.php` — simple + variation save handlers.
- `includes/currencies-metabox.php` — currency save handler (nonce + capability).
- `assets/js/admin.js` — `mnswmcRoundToThousand()` (JS rounding).
- `tests/compat/smoke-test.php` — pre-existing harness (reference for the
  currency-save workflow and admin context).
- `docs/نقشه راه توسعه Ani Navasan Lite.md` — roadmap (Task 10 row).

## 4. Files Created

| File | Purpose |
|------|---------|
| `tests/unit/bootstrap.php` | Minimal WP stubs + in-memory meta/options + fake `$wpdb`; loads the real `includes/functions.php` |
| `tests/unit/rounding-test.php` | Unit tests for `ani_navasan_round_to_thousand()` |
| `tests/unit/rate-helper-test.php` | Unit tests for `ani_navasan_get_currency_rate()` |
| `tests/unit/migration-test.php` | Unit tests for both one-time migrations |
| `tests/unit/rounding-js-test.js` | Unit tests for `mnswmcRoundToThousand()` (extracted from the real `admin.js`) |
| `tests/unit/consistency-vectors.json` | Shared PHP↔JS rounding vectors |
| `tests/unit/consistency-php.php` | PHP side of the consistency check |
| `tests/unit/consistency-js.js` | JS side of the consistency check |
| `tests/integration/integration-test.php` | Live-site integration + regression suite (I0–I6) |
| `tests/run-unit-tests.sh` | One-command local unit-test runner |
| `tests/README.md` | Test-infrastructure documentation |

**No plugin source files were modified.**

## 5. Detailed Description of Changes

### 5.1 Unit-test bootstrap (`tests/unit/bootstrap.php`)

A minimal, self-contained WordPress stub layer that lets the **real**
`includes/functions.php` run outside WordPress:

- In-memory `get_post_meta` / `update_post_meta` / `delete_post_meta` /
  `get_option` / `update_option` / `delete_option` stores.
- A fake `$wpdb` with `prepare`, `get_var`, `get_col`, `get_results`, `query`,
  `insert`, `update`, `delete` — backed by the in-memory meta store so the
  migration functions (which query `wp_postmeta` directly) work.
- Stubs for `absint`, `sanitize_key`, `wp_parse_args`, `apply_filters`,
  `do_action`, `__`, `_e`, `esc_html__`, `number_format_i18n`, etc.
- Loads the real `includes/functions.php` via `require`.

This keeps the unit tests fast, isolated, and free of any database or network
dependency, while still exercising the actual production code paths.

### 5.2 PHP unit tests

- **`rounding-test.php`** (12 checks): `ani_navasan_round_to_thousand()` —
  exact multiples, round-up/round-down at the 500 boundary, zero, negative,
  float, and non-numeric inputs.
- **`rate-helper-test.php`** (8 checks): `ani_navasan_get_currency_rate()` —
  standard key read, legacy fallback, standard-key precedence, empty/missing
  keys, non-numeric, and a missing post.
- **`migration-test.php`** (11 checks):
  - `ani_navasan_lite_migrate_rate_key()` — legacy→standard copy, never
    overwrites an existing standard value, keeps the legacy key, idempotent
    (option flag), no-op when nothing to migrate.
  - `ani_navasan_lite_migrate_dependence_type()` — legacy `variable`→`advanced`,
    leaves `simple`/`advanced` untouched, idempotent, no-op when nothing to
    migrate.

### 5.3 JS unit test (`rounding-js-test.js`)

Extracts the **real** `mnswmcRoundToThousand()` function body from
`assets/js/admin.js` (regex on the shipped file) and evaluates it, then runs 24
checks mirroring the PHP rounding cases (including the 500-boundary behavior
and non-numeric/empty/null inputs). This guarantees the test always tracks the
shipped JS.

### 5.4 PHP↔JS consistency check

`consistency-vectors.json` holds 12 shared inputs. `consistency-php.php` and
`consistency-js.js` each run the real rounding function over the vectors and
print one `input => output` line each. The runner diffs the two outputs; any
divergence fails the suite. This is the guard against the PHP and JS rounding
implementations drifting apart.

### 5.5 Integration suite (`tests/integration/integration-test.php`)

Runs via `wp eval-file` against the authorized site. Key design points:

- **Guards:** refuses to run outside WP-CLI or with the plugin inactive.
- **Admin context:** sets an admin user so the save handlers' capability checks
  pass (mirrors the compat harness).
- **Backup/restore:** `t10_backup_state()` captures post + all meta rows for the
  six authorized posts; `t10_restore_state()` restores them in a single DB
  transaction (direct `$wpdb`, no hooks fired) and clears caches.
- **Real save paths:**
  - Simple product: populates `$_POST` and calls
    `do_action('woocommerce_admin_process_product_object', $product)` +
    `$product->save()`.
  - Variations: populates `$_POST[field][i]` and calls
    `do_action('woocommerce_save_product_variation', $id, $i)`.
  - Rounding hook: `do_action('save_post_product', $id, $post, true)`.
  - Currency: `t10_save_currency()` sets the nonce + `$_POST` and calls
    `wp_update_post()`, firing the genuine `save_post_mnswmc` handler (nonce +
    capability verified) which persists the rate and fires
    `ani_navasan_lite_recalc_currency`.
- **No partial POSTs:** the tests never submit a partial product form, so
  attributes and unrelated meta are never dropped.
- **Scenarios I0–I6** (see README §2): environment, simple save, variation save
  + isolation, rounding hook, recalculation for both currencies (with parent
  sync, sale-price preservation, and cross-currency isolation), attribute
  serialization regression, and a final restore round-trip verification.
  (Task 10b hardened the backup/restore to cover all modified tables — see
  `docs/reports/2026-11-14-task-10b-integration-restore-safety.md`.)

### 5.6 Runner + docs

- `tests/run-unit-tests.sh` runs all five unit suites and prints a summary;
  exits non-zero on any failure.
- `tests/README.md` documents the layout, how to run each suite, the safety
  model, the scenario table, and coverage notes.

## 6. Security Implications

- **No plugin source changed** — no new attack surface.
- The integration test sets an admin user and creates a valid nonce to exercise
  the real capability/nonce-verified save handlers; it does not weaken or
  bypass any check (the handlers still verify the nonce and `edit_products`).
- The test uses only the authorized local site and authorized test data.
- No credentials, keys, or tokens are stored in any test file or this report.
- Backup/restore uses a DB transaction with rollback on error, minimizing the
  window of partial state.

## 7. Compatibility Implications

- **None on the plugin** — no behavior, metadata, or API changes.
- The unit tests depend only on PHP (7.4+) and Node.js; the integration test
  depends on the authorized site's stack (PHP 8.4.10 / WP 7.1.4 / WC 11.3.0).
- The pre-existing `tests/compat/` Docker matrix is untouched and remains the
  source of the supported-range claim (PHP 7.4–8.3, WP 7.0.8–7.1.4,
  WC 9.6.2–11.2.2).


## 8. Tests Executed and Results

### 8.1 Unit tests (local) — via `bash tests/run-unit-tests.sh`

| Suite | Result |
|-------|--------|
| PHP: rounding (`ani_navasan_round_to_thousand`) | **12/12 PASS** |
| PHP: rate helper (`ani_navasan_get_currency_rate`) | **8/8 PASS** |
| PHP: migrations (rate-key + dependence-type) | **11/11 PASS** |
| JS: rounding (`mnswmcRoundToThousand`) | **24/24 PASS** |
| PHP↔JS consistency (12 shared vectors, diffed) | **PASS** (identical output) |
| **Runner summary** | **5/5 suites PASS** |

### 8.2 Integration tests (live site) — via `wp eval-file`

Run against `anishop-package-test.local` (PHP 8.4.10 / WP 7.1.4 / WC 11.3.0):

| Section | Checks | Result |
|---------|--------|--------|
| I0 Environment + plugin load | 8 | PASS |
| I1 Simple product save handler | 9 | PASS |
| I2 Variation save handler + isolation | 17 | PASS |
| I3 Decimal rounding hook | 3 | PASS |
| I4a Recalculation — currency A | 12 | PASS |
| I4b Recalculation — currency B | 8 | PASS |
| I5 Attribute serialization regression | 2 | PASS |
| I6 Restore round-trip verification | 242 | PASS |
| **Total** | **301** | **301/301 PASS, 0 failures** |

The I6 section verifies that, after the final restore, the authorized records
match the pre-test backup — confirming the suite leaves the site in its
original state.

> **Task 10b follow-up:** the original Task 10 backup/restore captured only
> selected `wp_posts` fields and `wp_postmeta`, and did **not** capture the
> WooCommerce product lookup table (`wp_wc_product_meta_lookup`) or the
> `post_modified` timestamps — so its "byte-for-byte" claim was not fully
> demonstrated. Task 10b hardened the backup/restore to capture and restore
> **all** modified tables (posts, postmeta, lookup, targeted transients) within
> a transaction, with per-query error checking, `try/finally` + shutdown-handler
> failure safety, and a full multi-table restore verification. See
> `docs/reports/2026-11-14-task-10b-integration-restore-safety.md`.

### 8.3 Static checks

- `php -l` on all new PHP files: **PASS** (no syntax errors).
- `node --check` on all new JS files: **PASS** (no syntax errors).

## 9. Known Limitations

- The **unit** tests exercise `includes/functions.php` in isolation with stubs;
  they do not run inside a full WordPress/WooCommerce runtime. The **integration**
  suite covers the full runtime, so the two together give complementary coverage.
- The integration suite is bound to the **authorized local site** and its
  specific test product/currency IDs; it is not portable to an arbitrary
  install without adjusting the `T10_*` constants.
- The integration suite does **not** cover the admin AJAX endpoint
  (`wp_ajax_mnswmc_get_exchange_rate`) or admin metabox rendering — those are
  covered by the pre-existing `tests/compat/` smoke test.
- The mutual-exclusivity guard against the main Navasan plugin remains
  **BLOCKED** (the main plugin cannot be activated — unauthorized ionCube
  encoder; see the Task 08 report). This is unchanged by this task.

## 10. Remaining Issues

- None introduced by this task.
- The pre-existing open items (from the Memory Bank) are unchanged:
  - `wp_wc_product_meta_lookup` CLI-context assertions (2 Task 07 checks) —
    cause not confirmed; not addressed here.
  - Main-Navasan-plugin coexistence — BLOCKED (owner closed the item in Task 09d).
- A future enhancement could wrap the integration suite in a CI job that
  provisions a throwaway site (as `tests/compat/` does with Docker) so it does
  not depend on the specific local site.

## 11. Git

- **Branch:** `test/task-10-automated-regression-suite`
- **Base:** latest `origin/main`
- **Push status:** pushed to `origin`
- **Merged:** No (per project workflow — awaiting owner review).

### 11.1 Commits

| # | Full SHA | Subject |
|---|----------|---------|
| 1 | `0527e406d0715df04ed6173ed1cc35b6d1b73976` | test: add automated regression/test suite (Task 10) |
| 2 | (this docs commit) | docs(task-10): record commit hash and push status in report |

The main implementation commit is `0527e40` (short). Commit 2 only updates this
report's git section and does not touch any test or plugin file.

