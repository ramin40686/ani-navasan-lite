# Ani Navasan Lite — Test Infrastructure

This directory contains the automated regression/test infrastructure for the
**Ani Navasan Lite** plugin. It is **test-only**: it does not modify any plugin
source. The tests target the **real plugin code** (not reimplementations).

## Layout

```
tests/
├── run-unit-tests.sh          # Local unit-test runner (no site required)
├── unit/                      # Unit tests (fast, isolated, no WordPress)
│   ├── bootstrap.php          # Minimal WP stubs + in-memory meta/options + fake $wpdb
│   ├── rounding-test.php      # ani_navasan_round_to_thousand()
│   ├── rate-helper-test.php   # ani_navasan_get_currency_rate()
│   ├── migration-test.php     # rate-key + dependence-type migrations
│   ├── dependence-type-test.php # ani_navasan_resolve_dependence_type() (Task 11)
│   ├── rounding-js-test.js    # mnswmcRoundToThousand() (extracted from admin.js)
│   ├── consistency-vectors.json  # Shared PHP<->JS rounding vectors
│   ├── consistency-php.php    # PHP side of the consistency check
│   └── consistency-js.js      # JS side of the consistency check
├── integration/
│   ├── integration-test.php   # Live-site integration + regression suite
│   ├── backup-restore.php     # Hardened backup/restore/verify + fault-injection proxy (Task 10c)
│   └── fault-injection-test.php  # Controlled fault-injection tests (Task 10c)
└── compat/                    # (pre-existing) Docker compatibility matrix
```

## 1. Unit tests (local, no site required)

Run the whole unit suite:

```bash
bash tests/run-unit-tests.sh
```

This runs:

| Suite | What it tests | Target |
|-------|---------------|--------|
| PHP: rounding | `ani_navasan_round_to_thousand()` (nearest 1,000 Toman) | real `includes/functions.php` |
| PHP: rate helper | `ani_navasan_get_currency_rate()` (standard key + legacy fallback) | real `includes/functions.php` |
| PHP: migrations | `ani_navasan_lite_migrate_rate_key()` + `ani_navasan_lite_migrate_dependence_type()` | real `includes/functions.php` |
| PHP: dependence type | `ani_navasan_resolve_dependence_type()` (whitelist + fallback + default) | real `includes/functions.php` |
| JS: rounding | `mnswmcRoundToThousand()` | extracted from real `assets/js/admin.js` |
| PHP<->JS consistency | identical rounding output for shared vectors | both, diffed |

The PHP unit tests load the **real** `includes/functions.php` using a minimal
WordPress stub layer (`tests/unit/bootstrap.php`) with in-memory meta/options
stores and a fake `$wpdb`. No database, no network, no WordPress install.

The JS test extracts the **real** `mnswmcRoundToThousand()` function body from
`assets/js/admin.js` and evaluates it, so it always tests the shipped code.

The consistency check runs the same vector file through both the PHP and JS
rounding functions and diffs the outputs — any divergence fails the suite.

## 2. Integration tests (live site required)

The integration suite runs against the **authorized local site**
(`anishop-package-test.local`) and uses **only** the authorized test products
and currencies:

| ID | Role |
|----|------|
| 17613 | Simple product (currency A) |
| 17615 | Variable product (parent) |
| 17616 | Variation A (currency A) |
| 17617 | Variation B (currency B) |
| 17654 | Currency A |
| 17655 | Currency B |

Run it:

```bash
cd ~/Local\ Sites/anishop-package-test/app/public
wp eval-file /path/to/ani-navasan-lite/tests/integration/integration-test.php
```

### Safety model

- **Backup first:** the FULL state of every record the suite can modify is
  captured before any mutation, scoped to the six authorized posts and the four
  product IDs:
  - `wp_posts` — ALL columns (including `post_modified`/`post_modified_gmt`).
  - `wp_postmeta` — ALL rows, including duplicate keys and their `meta_id`.
  - `wp_wc_product_meta_lookup` — ALL columns for the four product IDs.
  - `wp_options` (transients) — a targeted safety net (options referencing a
    product ID).
  - **Task 10c:** every backup SELECT is checked for a database error and a
    null return. A failed query is **never** converted into an empty result;
    an incomplete snapshot (a missing post row, a missing postmeta entry, or a
    product ID that was not queried) throws `T10c_BackupException` and the run
    aborts **before any mutation**.
- **Real save paths:** the tests exercise the genuine plugin handlers
  (`woocommerce_admin_process_product_object`, `woocommerce_save_product_variation`,
  `save_post_product`, and the real `save_post_mnswmc` currency-save workflow
  via `wp_update_post()` with a valid nonce + admin user).
- **Transaction support gate (Task 10c):** before any mutation the suite
  confirms every required table is **InnoDB** (transactional). If any table is
  not confirmed InnoDB (or the engine query fails), the run aborts before any
  mutation — atomic restoration can only be claimed when it is actually
  available.
- **Restore after:** state is restored via a single direct-DB **transaction**
  (no hooks fired). **Task 10c:** the result of `START TRANSACTION` and
  `COMMIT` is checked explicitly; every write is checked; a failed start
  performs **no writes**, and a failed commit rolls back and is reported as a
  failure. The restore is then **verified** across all four tables in I6.
- **Verification by identity (Task 10c):** postmeta is compared by
  **`meta_id` identity** (not just key/value pairs) — a changed `meta_id`, a
  missing row, an unexpected row, a duplicate row, or a row-count difference is
  all detected. Every verification SELECT is checked for a query error; a
  failed verification query is a **failure, never a pass**.
- **Failure safety:** scenarios run inside `try/finally` with a single
  shutdown-handler safety net and a restoration state flag, so the restore is
  attempted even if a scenario throws an ordinary PHP error, and is performed
  at most once. If the restore or verification fails, the suite exits non-zero
  and never prints a success summary.
- **No partial POSTs:** the tests never send a partial product form that could
  drop attributes or unrelated meta.
- **Transient handling (Task 10c):** the targeted product transients are part
  of the restored state. The restore re-inserts them and the post-restore cache
  clearing does **not** call `wc_delete_product_transients()` (which would
  delete the very rows the verification then checks).
- **Documented limitation:** a shutdown handler cannot guarantee restoration
  after process termination, a server crash, power loss, or a database outage.

### Scenarios

| Section | Coverage |
|---------|----------|
| I0 | Environment + plugin load (functions, CPT, products exist) |
| I1 | Simple-product save handler: meta persistence + Toman price calc (regular, sale, floor) |
| I2 | Variation save handler: per-variation meta + prices + cross-variation isolation |
| I3 | Decimal rounding hook (`save_post_product`): 2-decimal rounding of currency fields |
| I4a | Recalculation — currency A rate change 2000→2500→2000 (simple + variation A, parent sync, sale preservation, currency-B isolation) |
| I4b | Recalculation — currency B rate change 2000→2500→2000 (variation B, parent sync, currency-A isolation) |
| I5 | Attribute serialization regression — a controlled recalculation syncs the variable parent; `_product_attributes` stays a real array (not double-serialized) with its data intact |
| I7 | Security regression (Task 11) — the `save_post_mnswmc` handler rejects an invalid nonce, a missing nonce, and an insufficient-capability request (rate unchanged in each case) |
| J1 | JavaScript loading scope (Task 11) — `mnswmc-admin-js` is enqueued on product edit / new-product screens and NOT on the dashboard or non-product post screens |
| I6 | Final restore round-trip verification (all four tables — posts, postmeta, lookup, targeted transients — match the backup) |

## 2b. Fault-injection tests (live site required, no mutation)

`tests/integration/fault-injection-test.php` exercises the **exact same**
backup/restore/verify code paths as the main suite (it includes
`backup-restore.php`), but injects controlled database faults via
`T10c_WpdbProxy` — a wrapper around the real `$wpdb` that matches query
patterns, returns a controlled failure, and sets `$wpdb->last_error` **without
executing the query**. No live database mutation ever occurs.

Run it:

```bash
cd ~/Local\ Sites/anishop-package-test/app/public
wp eval-file /path/to/ani-navasan-lite/tests/integration/fault-injection-test.php
```

| Section | What it proves |
|---------|----------------|
| FI-1 | A failed backup SELECT aborts the backup with `T10c_BackupException` (never a silent empty snapshot). |
| FI-2 | A failed `START TRANSACTION` aborts the restore **before any write** and is reported as a failure. |
| FI-3 | A failed verification SELECT is a **failure, never a pass** (the baseline verifies cleanly without a fault). |
| FI-4 | A postmeta `meta_id` difference is detected (identity comparison, not key/value-only). |
| FI-5 | Duplicate postmeta rows (same key, different `meta_id`) are compared correctly and a change is scoped to the right `meta_id`. |
| SAFETY | A final re-verification proves the database matches the pre-test baseline (the fault injection mutated nothing). |

## 3. Compatibility matrix (pre-existing)

`tests/compat/` contains the Docker-based PHP/WP/WC compatibility matrix
(Task 08). See `tests/compat/README.md`.

## Coverage notes

- **Covered by unit tests:** rounding (PHP + JS + cross-language), rate helper
  (standard/legacy/fallback), both one-time migrations (idempotency,
  never-overwrite, legacy→standard, `variable`→`advanced`), and the
  dependence-type resolver `ani_navasan_resolve_dependence_type()` (Task 11:
  whitelist, fallback to a valid existing value, `simple` default).
- **Covered by integration tests:** the full save flow (simple + variation),
  decimal rounding hook, currency-rate recalculation (both currencies, parent
  sync, sale-price preservation, cross-currency isolation), attribute
  serialization regression, security regression (Task 11 I7: currency-handler
  nonce/capability rejection), JavaScript loading scope (Task 11 J1), and
  restore integrity.
- **Covered by fault-injection tests (Task 10c):** the failure paths of the
  backup/restore/verify harness itself — a failed backup SELECT, a failed
  `START TRANSACTION`, a failed verification SELECT, postmeta `meta_id`
  identity differences, and duplicate postmeta row comparison. These prove the
  harness cannot mistake a failure for a success.
- **Not covered (out of scope for this task):** the admin AJAX endpoint
  (`wp_ajax_mnswmc_get_exchange_rate`) and the admin metabox rendering are
  covered by the pre-existing `tests/compat/` smoke test, not by this suite.
  The mutual-exclusivity guard against the main Navasan plugin remains
  BLOCKED (main plugin not installable — see the Task 08 report).
