# Task 10c — Backup / Restore / Verification Hardening

**Date:** 2026-11-14
**Branch:** `fix/task-10c-backup-validation`
**Base:** `fix/task-10b-integration-restore-safety`
**Scope:** Test infrastructure + documentation only — **no plugin source changes**

---

## 1. Task Objective

Harden the Ani Navasan Lite integration-test backup, restore, and verification
so that the following failure modes **cannot be mistaken for success**:

1. A failed backup database query (a SELECT that errors or returns `null`)
   being silently converted into an empty snapshot.
2. An incomplete snapshot (a missing post row, a missing postmeta entry, or a
   product ID that was never queried) being treated as a valid baseline.
3. A restore being claimed as atomic when the underlying table does not support
   transactions (or when `START TRANSACTION` / `COMMIT` actually fails).
4. A postmeta identity change (a changed `meta_id`, a missing row, an
   unexpected row, or a duplicate row) being missed by a key/value-only
   comparison.
5. A failed verification query being treated as a pass.

This task is **test-infrastructure-only**: no plugin business logic or
production plugin source files are modified.

## 2. Initial Findings

The Task 10b harness already captured all four tables (posts, postmeta,
lookup, targeted transients) and restored them inside a single transaction.
However, a review of the failure paths identified five concrete gaps:

1. **Backup SELECTs were not checked for errors.** `t10b_backup_state()` used
   `$wpdb->get_results()` / `get_row()` / `get_var()` without checking
   `$wpdb->last_error` or a `null` return. A failed query would yield an empty
   result, which the code would treat as "no rows to back up" — a silent,
   incomplete snapshot.
2. **No transaction-support gate.** The suite assumed the tables were
   transactional. If a table were MyISAM (or the engine query failed), the
   "atomic restore" claim would be false, but the suite would not stop.
3. **`START TRANSACTION` / `COMMIT` results were not checked.** A failed
   `START TRANSACTION` would leave the connection outside a transaction, so the
   subsequent writes would autocommit one-by-one (no atomicity), yet the code
   would continue as if the transaction had begun. A failed `COMMIT` was not
   detected.
4. **Postmeta was compared by key/value, not by `meta_id` identity.** Two
   datasets with the same `(post_id, meta_key, meta_value)` triples but
   different `meta_id`s (e.g. a row deleted and re-inserted, or a duplicate
   row) would compare as "equal" even though the row identity had changed.
5. **Verification SELECTs were not checked for errors.** A failed verification
   query would yield an empty result, which the comparison logic could
   interpret as "no differences" — a false pass.

## 3. Files Inspected

- `tests/integration/integration-test.php` (Task 10b harness)
- `tests/integration/backup-restore.php` (new — extracted hardened helpers)
- `tests/integration/fault-injection-test.php` (new — fault-injection suite)
- `tests/README.md`
- `docs/نقشه راه توسعه Ani Navasan Lite.md` (roadmap)

## 4. Files Created, Modified, or Deleted

**Created:**
- `tests/integration/backup-restore.php` — the hardened backup/restore/verify
  helpers, shared by both the main integration suite and the fault-injection
  suite.
- `tests/integration/fault-injection-test.php` — the controlled
  fault-injection test suite.
- `docs/reports/2026-11-14-task-10c-backup-validation.md` — this report.

**Modified:**
- `tests/integration/integration-test.php` — now `require`s
  `backup-restore.php`, removes the old local backup/restore/verify helper
  definitions, calls the hardened backup inside a `try/catch`, and aborts
  before any mutation if the backup fails or transaction support is not
  established. Also removes the post-restore `wc_delete_product_transients()`
  call (see §5.6).
- `tests/README.md` — documents the new hardening and the fault-injection
  suite.
- `docs/نقشه راه توسعه Ani Navasan Lite.md` — adds the Task 10c row and
  updates the stage status.

**Deleted:** none.

## 5. Detailed Description of Changes

### 5.1 Backup fails loudly (`T10c_BackupException`)

`t10b_backup_state()` (in `backup-restore.php`) now checks **every** SELECT:

- After each `get_results()` / `get_row()` / `get_var()`, it checks
  `$wpdb->last_error` and a `null`/`false` return.
- A failed query throws `T10c_BackupException` with a message naming the
  failed query.
- An **incomplete snapshot** is also detected and throws:
  - a post ID that returned no row,
  - a postmeta query that returned fewer rows than expected for a post that is
    known to have meta,
  - a product ID that was not queried in the lookup snapshot.

The main suite wraps the backup call in a `try/catch`. If the backup throws,
the run **aborts before any mutation** and exits non-zero.

### 5.2 Transaction-support gate

`t10c_transaction_support_established()` confirms, **before any mutation**,
that every required table (`wp_posts`, `wp_postmeta`,
`wp_wc_product_meta_lookup`, `wp_options`) is **InnoDB** (transactional). It
uses `t10b_table_engines()`, which runs `SHOW TABLE STATUS` and checks each
table's `Engine`. If any table is not confirmed InnoDB — or the engine query
itself fails — the run aborts before any mutation. This ensures the "atomic
restore" claim is only made when it is actually available.

### 5.3 Explicit `START TRANSACTION` / `COMMIT` checks

`t10b_restore_state()` now:

- Checks the return value of `START TRANSACTION`. A failed start performs
  **no writes** and is reported as a failure.
- Checks the return value of `COMMIT`. A failed commit triggers a `ROLLBACK`
  and is reported as a failure.
- Checks every individual write (as in Task 10b); a failed write triggers a
  `ROLLBACK` and is reported.

### 5.4 Postmeta verification by `meta_id` identity

`t10c_compare_postmeta()` compares postmeta by **`meta_id` identity**, not just
`(post_id, meta_key, meta_value)` triples. It detects:

- a changed `meta_id` (a row deleted and re-inserted),
- a missing row (present in the backup, absent now),
- an unexpected row (present now, absent from the backup),
- a duplicate row (same key, different `meta_id`),
- a row-count difference.

The mismatch message names the specific `meta_id` and the nature of the
difference.

### 5.5 Verification queries are checked

`t10b_verify_restore()` now checks every verification SELECT for
`$wpdb->last_error` and a `null` return. A failed verification query is
reported as a **failure, never a pass**.

### 5.6 Transient handling fix (bug found during verification)

During the first integration run, I6.2 failed with a transient mismatch: the
backup had captured 8 WooCommerce product transients, the restore re-inserted
them, but the post-restore cache clearing called
`wc_delete_product_transients()` — which **deleted the very transients the
restore had just re-inserted**, leaving `current=[]` vs `backup=[8]`.

This was a latent bug: in Task 10b no transients existed for these products,
so the call was a no-op. Once transients existed, it caused a false failure.

**Fix:** the post-restore cache clearing in `t10b_do_restore()` no longer calls
`wc_delete_product_transients()`. It still calls `clean_post_cache()` (which
clears only the post object cache, not transients). The targeted product
transients are now correctly treated as part of the restored state.

## 6. Fault-Injection Test Suite

`tests/integration/fault-injection-test.php` exercises the **exact same**
backup/restore/verify code paths as the main suite (it includes
`backup-restore.php`), but injects controlled database faults via
`T10c_WpdbProxy` — a wrapper around the real `$wpdb` that:

- matches query patterns,
- returns a controlled failure for the matched query,
- sets `$wpdb->last_error` to a controlled message,
- **does not execute the matched query** (so no live database mutation occurs).

| Section | What it proves |
|---------|----------------|
| FI-1 | A failed backup SELECT aborts the backup with `T10c_BackupException` (never a silent empty snapshot). |
| FI-2 | A failed `START TRANSACTION` aborts the restore **before any write** and is reported as a failure. |
| FI-3 | A failed verification SELECT is a **failure, never a pass** (the baseline verifies cleanly without a fault). |
| FI-4 | A postmeta `meta_id` difference is detected (identity comparison, not key/value-only). |
| FI-5 | Duplicate postmeta rows (same key, different `meta_id`) are compared correctly and a change is scoped to the right `meta_id`. |
| SAFETY | A final re-verification proves the database matches the pre-test baseline (the fault injection mutated nothing). |

## 7. Security Implications

- **No plugin source changes.** The plugin's attack surface is unchanged.
- The fault-injection proxy only **intercepts** queries in the test process;
  it never executes a matched (faulted) query against the live database, so it
  cannot cause a mutation.
- No credentials, tokens, or secrets are introduced or logged.
- The test suite continues to require an authenticated WP-CLI context and the
  authorized site; it does not weaken any authorization.

## 8. Compatibility Implications

- **No plugin behavior changes.** The plugin is unaffected.
- The hardened helpers are backward-compatible with the Task 10b data model
  (same four tables, same scope). The only behavioral change is that failure
  modes that were previously silent are now loud.
- The transient-handling fix (§5.6) only affects the test harness's
  post-restore cache clearing, not the plugin.

## 9. Tests Executed and Results

All tests were executed on the authorized site
(`anishop-package-test.local`, PHP 8.4.10 / WP 7.1.4 / WC 11.3.0).

| Test | Command | Result |
|------|---------|--------|
| PHP lint — `backup-restore.php` | `php -l` | PASS |
| PHP lint — `integration-test.php` | `php -l` | PASS |
| PHP lint — `fault-injection-test.php` | `php -l` | PASS |
| Unit suite (5 suites) | `bash tests/run-unit-tests.sh` | **5/5 PASS** |
| Integration suite — run 1 | `wp eval-file …/integration-test.php` | **63/63 PASS** (exit 0) |
| Integration suite — run 2 (repeatability) | `wp eval-file …/integration-test.php` | **63/63 PASS** (exit 0) |
| Fault-injection suite — run 1 | `wp eval-file …/fault-injection-test.php` | **16/16 PASS** (exit 0) |
| Fault-injection suite — run 2 (repeatability) | `wp eval-file …/fault-injection-test.php` | **16/16 PASS** (exit 0) |
| Post-run site-state sanity | `wp eval` (product prices) | Clean (matches baseline) |

**Note on the first integration run:** the very first run (before the §5.6
fix) reported **62/63** with a single I6.2 transient mismatch. This was the
latent `wc_delete_product_transients()` bug described in §5.6. After the fix,
both subsequent runs reported 63/63.

**Repeatability note:** the integration suite is robust to the transient state
at the start of a run. Run 1 had 8 product transients present at backup time
(restored to their backup values); run 2 had 0 (transients created during the
run were cleaned up by the restore). Both cases pass 63/63, confirming the
harness handles both the "transients present" and "transients absent" cases
correctly.

## 10. Known Limitations

- A shutdown handler still cannot guarantee restoration after process
  termination, a server crash, power loss, or a database outage (unchanged
  from Task 10b).
- The fault-injection proxy simulates query failures by intercepting and not
  executing the matched query. It does not induce real network/DB outages; it
  proves the harness's *reaction* to a failed query, which is the property
  under test.
- The transient safety net remains a best-effort capture of options referencing
  the authorized product IDs. Transients are ephemeral caches; their presence
  at backup time is not deterministic between runs (as observed in §9). The
  harness is correct in both cases, but the exact transient count is not a
  stable assertion target.

## 11. Remaining Issues

- None introduced by this task.
- The pre-existing P1 item (`wp_wc_product_meta_lookup` assertions in the Task
  07 CLI context) remains open and is out of scope here.

## 12. Git Branch

`fix/task-10c-backup-validation`

## 13. Commit Hash

`1080637fc427dc33c8332015bee4d00966fd40aa`

## 14. Push Status

Pushed to `origin/fix/task-10c-backup-validation`. **Not merged** (per project
workflow, merging is the project owner's decision).

