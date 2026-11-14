# Task 10b — Integration Test Safety & Restore Integrity

**Date:** 2026-11-14
**Branch:** `fix/task-10b-integration-restore-safety`
**Base:** `test/task-10-automated-regression-suite` (commit `f5c549c`)
**Scope:** Test infrastructure + documentation only — **no plugin source changes**

---

## 1. Task Objective

Harden the Task 10 integration test infrastructure so that running the tests
cannot leave the authorized local WordPress/WooCommerce test site in a
partially modified or inconsistent state. The specific limitation identified
during independent review: the Task 10 backup/restore mechanism captured only
selected `wp_posts` fields and `wp_postmeta`, but WooCommerce CRUD operations
also modify the product lookup table and other persistent data — so the I6
verification did not prove complete database restoration.

This task corrects that limitation and improves failure safety. It is
**test-infrastructure-only**: no plugin business logic or production plugin
source files are modified.

## 2. Original Issue and Root Cause

The Task 10 `t10_backup_state()` / `t10_restore_state()` in
`tests/integration/integration-test.php` had two concrete gaps:

1. **Incomplete `wp_posts` capture.** Only five columns were backed up
   (`post_title`, `post_content`, `post_status`, `post_parent`, `menu_order`).
   The tested operations also update `post_modified` / `post_modified_gmt`
   (and, in general, any column a save touches), which were **not** restored.
2. **`wp_wc_product_meta_lookup` not captured at all.** The recalculation
   handler and the product/variation CRUD `save()` update the WooCommerce
   product lookup table (`min_price`, `max_price`, `onsale`, …). The Task 10
   restore never touched this table, so after a run the lookup rows for the
   authorized products could differ from the pre-test state. The I6
   verification only compared `wp_posts` fields + `wp_postmeta`, so it could
   not detect (or correct) lookup drift.

**Root cause:** the backup scope was defined around "the post and its meta"
rather than "every persistent record the tested operations can write".

## 3. Initial Findings — Empirical Data-Scope Investigation

Before changing anything, the exact write scope was measured on the authorized
site (`anishop-package-test.local`, PHP 8.4.10 / WP 7.1.4 / WC 11.3.0) by
snapshotting the relevant tables, running a controlled recalculation and a
simple-product save, and diffing before/after. Findings:

- **Table engines:** `wp_posts`, `wp_postmeta`, `wp_wc_product_meta_lookup`,
  and `wp_options` are all **InnoDB** on the target site → a single DB
  transaction can atomically cover the restore. (The engine is re-checked at
  runtime by the suite; a non-InnoDB table is reported as a warning.)
- **`wp_posts`:** `post_modified` / `post_modified_gmt` change on every save
  (confirmed for 17613, 17616, 17654 during a recalculation).
- **`wp_postmeta`:** all currency/price meta change (e.g. `_regular_price`,
  `_price`, `_sale_price`, `_mnswmc_*`, `_torob_scraper_min_price`).
- **`wp_wc_product_meta_lookup`:** `min_price` / `max_price` (and `onsale`)
  change on product/variation saves and recalculation. Confirmed rows exist for
  all four product IDs (17613, 17615, 17616, 17617).
- **`wp_options` (transients):** **no** transient options reference the
  authorized product IDs, and the tested operations did not create or delete
  any. A targeted transient backup is still included as a safety net (normally
  an empty no-op).
- **Other tables** (`wp_terms`, `wp_term_relationships`, `wp_term_taxonomy`,
  `wp_termmeta`, `wp_comments`, `wp_users`, `wp_usermeta`): not touched by the
  tested save/recalculation paths (the handlers do not modify terms, comments,
  or users).

The diagnostic run was fully restored and re-verified against the Task 10
baseline before any implementation began.

## 4. Files Inspected

- `tests/integration/integration-test.php` — the Task 10 suite (backup/restore,
  scenarios I0–I6).
- `tests/README.md`, `tests/run-unit-tests.sh`, `tests/unit/`, `tests/compat/`
  — the surrounding test infrastructure.
- `docs/reports/2026-11-14-task-10-automated-regression-suite.md` — Task 10 report.
- `docs/نقشه راه توسعه Ani Navasan Lite.md` — roadmap.
- `.clinerules/memory-bank.md` — active context.
- Live database (read-only inspection + a fully-restored diagnostic run) on the
  authorized site.

## 5. Files Created, Modified, or Deleted

| File | Change |
|------|--------|
| `tests/integration/integration-test.php` | **Modified** — hardened multi-table backup/restore, transactional restore with per-query error checking, `try/finally` + single shutdown-handler + restoration state flag, improved I5, full multi-table I6 verification, fail-safe summary. |
| `tests/README.md` | **Modified** — documented the new backup scope, tables covered, restore guarantees, transaction behavior, and failure handling. |
| `docs/reports/2026-11-14-task-10-automated-regression-suite.md` | **Modified** — corrected the "byte-for-byte" claim to the precise, demonstrated scope; cross-referenced Task 10b. |
| `docs/نقشه راه توسعه Ani Navasan Lite.md` | **Modified** — added the Task 10b row (Persian, structure preserved). |
| `.clinerules/memory-bank.md` | **Modified** — recorded Task 10b completion. |
| `docs/reports/2026-11-14-task-10b-integration-restore-safety.md` | **Created** — this report. |

**No plugin source files were modified.** The change is confined to `tests/`
and documentation.

## 6. Exact Backup/Restore Architecture

### 6.1 Backup — `t10b_backup_state($post_ids, $product_ids)`

Captures the full state of every record the suite can modify, limited to the
authorized scope (6 posts + 4 product IDs). Values are stored **exactly as
returned by the database** (no serialization/normalization), so the restore
writes them back verbatim:

- `posts` — `SELECT *` (ALL columns) for each of the 6 authorized posts.
- `postmeta` — `SELECT meta_id, post_id, meta_key, meta_value … ORDER BY
  meta_id` for each post, preserving `meta_id`, **duplicate keys**, and row
  order.
- `lookup` — `SELECT *` (ALL columns) for each of the 4 product IDs (only rows
  that exist are captured; absence is meaningful).
- `options` — targeted transient safety net: `SELECT option_name,
  option_value` for options whose name references a product ID (normally empty).

### 6.2 Restore — `t10b_restore_state($backup)`

Runs inside a **single DB transaction** (`START TRANSACTION` … `COMMIT`).
Every SQL operation is checked; any hard failure triggers `ROLLBACK` and a
failure return. A non-InnoDB table is reported as a **warning** (rollback may
not cover it) but does not by itself abort the restore.

- `wp_posts` — `UPDATE` ALL columns for each backed-up post.
- `wp_postmeta` — `DELETE` all rows for the post, then `INSERT` the backed-up
  rows verbatim (preserving `meta_id`, duplicate keys, and values).
- `wp_wc_product_meta_lookup` — **upsert** each authorized product row
  (`UPDATE` if present, `INSERT` if absent); if a product ID has no snapshot
  row, any row the tests created is `DELETE`d.
- `wp_options` (transients) — remove targeted options absent from the snapshot;
  insert/restore the snapshot options.

Returns `[ 'ok' => bool, 'errors' => [string, …] ]`. A failed query is **never**
treated as a successful restore.

### 6.3 Restore verification — `t10b_verify_restore($backup)`

After the restore, re-snapshots the current state and compares it with the
backup across **all four tables**:

- `wp_posts` — every column of every authorized post matches.
- `wp_postmeta` — exact `(meta_key → [values])` multiset per post, including
  **row counts**, **duplicate keys**, and exact stored values.
- `wp_wc_product_meta_lookup` — every column of every authorized product row
  matches; no extra rows exist for the authorized product IDs.
- `wp_options` (transients) — the targeted option set matches.

Returns `[ 'ok' => bool, 'mismatches' => [string, …] ]`.

## 7. Tables and Records Covered

| Table | Records covered | Restored? | Verified? |
|-------|-----------------|-----------|-----------|
| `wp_posts` | ALL columns, 6 authorized posts | Yes | Yes |
| `wp_postmeta` | ALL rows (incl. duplicate keys), 6 posts | Yes | Yes |
| `wp_wc_product_meta_lookup` | ALL columns, 4 product IDs | Yes (upsert) | Yes |
| `wp_options` (transients) | Targeted: options referencing a product ID | Yes | Yes |

The backup is **scoped** to the authorized test products, their variations, and
the two authorized test currencies. Unrelated site-wide data is not touched.

## 8. Failure-Handling Behavior

- **`try/finally`:** scenarios I1–I5 run inside a `try` block; the `finally`
  block always attempts the final restore, even if a scenario throws an
  ordinary PHP error.
- **Single shutdown handler:** a `register_shutdown_function()` safety net
  attempts the restore only if the `try/finally` path did not already run it
  (e.g. a fatal error aborted the script). Only **one** handler is registered.
- **Restoration state flag:** `$GLOBALS['t10b_restored']` guards
  `t10b_do_restore()` so the restore is performed **at most once** (never
  twice).
- **No masking:** a scenario exception and a restore failure are reported
  **independently**. The summary never prints a success line when the restore
  or verification failed.
- **Fail-safe exit:** if the restore or verification fails, or a scenario
  exception occurred, the suite prints a `FAILED` summary and `exit(1)`.
- **Documented limitation:** a PHP shutdown handler **cannot** guarantee
  restoration after process termination, a server crash, power loss, or a
  database outage. Those scenarios are explicitly **not** claimed to be
  protected.


## 9. I5 Regression Test Changes

The Task 10 I5 only inspected the **existing** state of `_product_attributes`
(it did not perform an operation). The Task 10b I5 now tests an **actual
operation**:

1. Captures the original `_product_attributes` of the variable parent (17615).
2. Performs a **controlled, relevant synchronization operation**: a
   currency-rate recalculation (2000 → 2500 → 2000) that syncs the variable
   parent via `WC_Product_Variable::sync($parent, false)` — the exact code path
   the plugin uses to avoid re-persisting (and double-serializing)
   `_product_attributes`.
3. Reads `_product_attributes` after the operation.
4. Verifies it is still a well-formed **array** (not a serialized string).
5. Verifies the **original attribute data is preserved** (no loss/alteration).
6. Reads the **raw stored value** directly from `wp_postmeta` and verifies it
   unserializes to an array in **one** step (a double-serialized value would
   unserialize to a string, not an array).
7. The original state is restored by the hardened restore mechanism.

No unrelated product changes are introduced (the rate returns to 2000, so all
prices return to baseline). No plugin source was modified to make the test
pass.

## 10. Security Implications

- No new attack surface: the change is test-only and runs via WP-CLI on the
  authorized site with an admin user.
- The restore uses `$wpdb->prepare()` for all dynamic queries (no SQL
  injection).
- No credentials, tokens, or secrets are introduced or logged.

## 11. Compatibility Implications

- **PHP 7.4 compatible:** the failure-safety design uses `try/finally`,
  `register_shutdown_function()`, and a state flag — all available in PHP 7.4.
  (`Throwable` is used in the `catch`, which is PHP 7.0+.)
- **No plugin behavior change:** the plugin source is untouched; the supported
  range (PHP 7.4–8.3, WP 7.0.8–7.1.4, WC 9.6.2–11.2.2) is unchanged.
- The suite remains bound to the authorized local site and its fixed test
  product/currency IDs (unchanged from Task 10).


## 12. Tests Executed and Results

### 12.1 Unit tests — `bash tests/run-unit-tests.sh`

| Suite | Result |
|-------|--------|
| PHP: rounding (`ani_navasan_round_to_thousand`) | **12/12 PASS** |
| PHP: rate helper (`ani_navasan_get_currency_rate`) | **8/8 PASS** |
| PHP: migrations (rate-key + dependence-type) | **11/11 PASS** |
| JS: rounding (`mnswmcRoundToThousand`) | **24/24 PASS** |
| PHP↔JS consistency (12 shared vectors, diffed) | **PASS** |
| **Runner summary** | **5/5 suites PASS** |

No regressions.

### 12.2 Integration tests — `wp eval-file` on the authorized site

Run against `anishop-package-test.local` (PHP 8.4.10 / WP 7.1.4 / WC 11.3.0).
The suite was run **twice** to confirm repeatability; both runs started from
the same baseline and ended fully restored.

| Section | Checks | Run #1 | Run #2 |
|---------|--------|--------|--------|
| I0 Environment + plugin load | 8 | PASS | PASS |
| I1 Simple product save handler | 9 | PASS | PASS |
| I2 Variation save handler + isolation | 15 | PASS | PASS |
| I3 Decimal rounding hook + mid-run restore | 4 | PASS | PASS |
| I4a Recalculation — currency A | 12 | PASS | PASS |
| I4b Recalculation — currency B | 8 | PASS | PASS |
| I5 Attribute serialization regression (controlled op) | 4 | PASS | PASS |
| I6 Full multi-table restore round-trip | 3 | PASS | PASS |
| **Total** | **63** | **63/63 PASS** | **63/63 PASS** |

Note: the Task 10 I6 used ~242 per-meta-row checks; the Task 10b I6 uses a
single comprehensive `t10b_verify_restore()` check (I6.2) that compares **all
four tables** (posts, postmeta, lookup, targeted transients) — row counts,
duplicate keys, exact values, and orphan-row removal. The lower check count
reflects the consolidated, multi-table verification, not reduced coverage.

### 12.3 Controlled failure-path test

A dedicated script (reusing the exact hardened backup/restore/verify
functions) performed a known mutation (17613 regular price → 1998000), then
triggered a **deliberate assertion failure**, and confirmed:

- **Restoration was attempted** (via the `try/finally` path): `true`.
- **The original state was fully restored:** 17613 regular returned to 200000;
  `t10b_verify_restore()` reported `ok: true` (all four tables match).
- **The process exited non-zero:** exit code `1` (the suite fails safely rather
  than reporting success).

The database was **not** crashed or corrupted. After the controlled test, the
authorized test data was re-verified against the baseline (all products,
currencies, and lookup rows match).

### 12.4 Static checks

- `php -l` on `tests/integration/integration-test.php`: **PASS** (no syntax
  errors).
- No JS files were changed (no `node --check` required).

## 13. Known Limitations

- The suite is bound to the **authorized local site** and its fixed test
  product/currency IDs; it is not portable without adjusting the `T10_*`
  constants (unchanged from Task 10).
- A PHP shutdown handler **cannot** guarantee restoration after process
  termination, a server crash, power loss, or a database outage. The
  `try/finally` path covers ordinary PHP errors and assertion failures; the
  shutdown handler is a best-effort safety net for fatal errors only.
- The restore relies on the involved tables being InnoDB (true on the target
  site). If a table were MyISAM, the transaction could not atomically roll it
  back; the suite reports this as a warning rather than silently assuming
  atomicity.
- The transient backup is a **targeted** safety net (options referencing a
  product ID). It does not capture unrelated site-wide transients, by design.
- The admin AJAX endpoint and metabox rendering remain covered by the
  pre-existing `tests/compat/` smoke test, not this suite (unchanged).

## 14. Remaining Issues

- None introduced by this task.
- The pre-existing open items are unchanged:
  - `wp_wc_product_meta_lookup` CLI-context assertions (2 Task 07 checks) —
    cause not confirmed; not addressed here. (Task 10b now backs up and
    restores the lookup table, but does not re-litigate the Task 07 CLI
    assertion behavior.)
  - Main-Navasan-plugin coexistence — BLOCKED (owner closed the item in
    Task 09d).
- A future enhancement could provision a throwaway site (as `tests/compat/`
  does with Docker) so the integration suite does not depend on the specific
  local site.

## 15. Git

- **Branch:** `fix/task-10b-integration-restore-safety`
- **Base:** `test/task-10-automated-regression-suite` (`f5c549c`)
- **Push status:** pushed to `origin`
- **Merged:** No (per project workflow — awaiting owner review).

### 15.1 Commits

| # | Full SHA | Subject |
|---|----------|---------|
| 1 | `4cb541f84d934e20282b5d3b7c5dad17a4c2778e` | test(task-10b): harden integration backup/restore + failure safety |
| 2 | `24d61ce7cbddecd5b142363562f635b40e616958` | docs(task-10b): record commit hash and push status in report |
| 3 | `59d42c66c6788e5cb0a343f6f46336d680d3919a` | docs(task-10b): remove remaining unsupported byte-for-byte claims |

Commit 1 is the implementation (test + docs). Commits 2–3 are documentation-only
follow-ups to this report (recording hashes and removing unsupported
"byte-for-byte" phrasing); they do not touch any test or plugin file.

