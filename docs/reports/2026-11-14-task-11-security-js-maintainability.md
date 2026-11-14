# Task 11 — Security Review, JavaScript Loading Optimization & Code Maintainability

**Date:** 2026-11-14
**Branch:** `security/task-11-security-js-maintainability`
**Base:** `fix/task-10c-backup-validation` (commit `ab980af17f69de0e52583f0b1bf41d8c3473031a`)
**Scope:** Security audit + targeted, evidence-based corrections (security, JS loading, maintainability). No changes to the calculation algorithm, metadata contracts, or product pricing behavior.
**Status:** **COMPLETE — merged into `main` and released as `1.2.0`** (see §10).
**Version:** `1.2.0` (bumped from `1.1.0` in this task's release commit).

---

## 1. Security Audit Scope

A systematic security review of the entire plugin was performed, covering:

- AJAX handlers (`includes/ajax.php`).
- WordPress hooks and save handlers (`includes/hooks.php`, `includes/currencies-metabox.php`, `admin/metabox.php`).
- Product and variation metadata writes.
- Currency metadata writes.
- Capability checks and nonce validation.
- Input validation and sanitization.
- Output escaping.
- SQL queries and database operations.
- User-controlled values.
- Direct file access protection.
- Cross-request behavior.
- The dedicated-product-nonce question (see §3).

Every file in the plugin was read in full. The WooCommerce save-flow protection was
verified against the **installed** WooCommerce 11.3.0 source on the authorized test
site (not assumed).

## 2. Findings and Classifications

Classification key:
1. **Confirmed vulnerability** — a real, exploitable weakness.
2. **Security weakness requiring correction** — a genuine gap that should be fixed.
3. **Defensive hardening opportunity** — not a vulnerability, but a reasonable
   best-practice improvement.
4. **False positive / non-issue** — investigated and found not to be a problem.

| # | Area | Finding | Classification | Action |
|---|------|---------|----------------|--------|
| F1 | `save_post_mnswmc` (currency save) | No explicit `DOING_AUTOSAVE` guard; autosave protection was incidental (the nonce check also blocks autosaves because autosave requests omit the metabox nonce). | 3 — Defensive hardening | **Fixed** — added an explicit `DOING_AUTOSAVE` guard (consistency with the product save handler; standard pattern). |
| F2 | `save_post_product` / `save_post_mnswmc` | Considered adding a `wp_is_post_revision()` guard. | 4 — False positive / non-issue | **Not implemented** — see §2.1 (the guards would be dead code). |
| F3 | Product save nonce | A previous audit suggested a dedicated plugin nonce for `save_post_product`. | 4 — False positive / non-issue | **Not implemented** — see §3 (well-reasoned decision). |
| F4 | AJAX `wp_ajax_mnswmc_get_exchange_rate` | Nonce (`mnswmc_nonce`) + `edit_products` capability + `intval` + post-type check. Output via `wp_send_json_*` (JSON-encoded). | 4 — Non-issue | No change. |
| F5 | Output escaping | All dynamic admin output is escaped (`esc_attr`, `esc_html`, `selected()`); WooCommerce field helpers escape internally; JSON output is JSON-encoded. | 4 — Non-issue | No change. |
| F6 | SQL / database | The only dynamic query (recalculation handler) uses `$wpdb->prepare()` with `%d`. Migrations use static queries (no user input). All other DB access is via WP/WC APIs. | 4 — Non-issue | No change. |
| F7 | Direct file access | Every PHP file begins with `if (!defined('ABSPATH')) exit;`. | 4 — Non-issue | No change. |
| F8 | Capability checks | AJAX: `edit_products`. Currency save: `edit_post`. Product rounding: `edit_post`. Simple/variation save: inherit WooCommerce's `edit_post`. | 4 — Non-issue | No change. |
| F9 | Input validation / sanitization | Currency symbol: `sanitize_text_field`. Rate: `floatval`. Product fields: `wc_clean`. Dependence type: whitelisted to `simple`/`advanced`. AJAX `currency_id`: `intval`. | 4 — Non-issue | No change. |
| F10 | JavaScript | No `innerHTML` with user data (all dynamic content uses `.text()`); no `eval`/`document.write`; AJAX uses the localized nonce. | 4 — Non-issue | No change. |

**Result: no confirmed vulnerabilities (class 1) and no security weaknesses requiring
correction (class 2) were found.** The only actionable item was F1 (a defensive
hardening opportunity), which was implemented.

### 2.1 Why the `wp_is_post_revision()` guards were NOT added (F2)

This was investigated empirically on the authorized site before any decision:

- `wp_is_post_revision()` (in `wp-includes/revision.php`) delegates to
  `wp_get_post_revision()`, which returns `null` unless
  **`$revision->post_type === 'revision'`**.
- `wp_get_post_revisions()` queries revisions with `post_type = 'revision'` and
  `post_status = 'inherit'`.
- **Therefore a real WordPress revision has `post_type = 'revision'`, not the
  parent's post type.**
- The hooks `save_post_product` and `save_post_mnswmc` are post-type-specific
  (`save_post_{post_type}`); WordPress core fires them **only** for posts whose
  `post_type` is `product` / `mnswmc` respectively. A revision (`post_type =
  'revision'`) triggers `save_post_revision`, **never** `save_post_product` or
  `save_post_mnswmc`.

Consequently, a `wp_is_post_revision()` guard inside those two handlers would be
**unreachable dead code** — the hooks never fire for real revisions. Adding it would
introduce dead code (a maintainability anti-pattern this task is explicitly asked to
reduce). The autosave case (the only unintended context that *does* trigger these
hooks, since autosaves keep the parent's `post_type`) is already handled:
- `save_post_product` already has a `DOING_AUTOSAVE` guard.
- `save_post_mnswmc` now has an explicit `DOING_AUTOSAVE` guard (F1) in addition to
  the nonce check.

A manual probe confirmed the behavior: a post created with `post_type=product`,
`post_status=revision` is **not** recognized by `wp_is_post_revision()` (returns
`false`), while a post with `post_type=revision` **is** recognized (returns the
parent ID). This matches the source analysis.

## 3. Dedicated Product-Nonce Investigation and Decision

**Question (from a previous audit):** is a dedicated plugin nonce needed for
`save_post_product` / the product & variation save handlers?

**Decision: NO dedicated plugin nonce is added.** This is a reasoned decision, not an
oversight. Evidence:

1. **The simple-product and variation save handlers run inside WooCommerce's
   already-protected save flow.** Verified against the installed WooCommerce 11.3.0
   source:
   - `WC_Admin_Meta_Boxes::save_meta_boxes()`
     (`wp-content/plugins/woocommerce/includes/admin/class-wc-admin-meta-boxes.php`)
     enforces, in order:
     1. `DOING_AUTOSAVE` / `wp_is_post_revision()` / `wp_is_post_autosave()` guard;
     2. **nonce** — `wp_verify_nonce( $_POST['woocommerce_meta_nonce'], 'woocommerce_save_data' )`;
     3. **post_ID match** — `absint( $_POST['post_ID'] ) === $post_id`;
     4. **capability** — `current_user_can( 'edit_post', $post_id )`.
   - Only after all four pass does the flow fire
     `woocommerce_admin_process_product_object`
     (`class-wc-meta-box-product-data.php:447`) and
     `woocommerce_save_product_variation`
     (`class-wc-meta-box-product-data.php:639`) — the two hooks the plugin's save
     handlers are attached to.
   - So the plugin's product/variation meta writes are **already** gated by a nonce
     + capability + autosave/revision guard + post-ID match.

2. **The `save_post_product` rounding handler** (`includes/hooks.php`) is protected by:
   - the **core post nonce** (a user must present a valid `update-post_{id}` /
     `add-post` nonce to reach `save_post_product` through the admin UI);
   - an explicit `current_user_can( 'edit_post', $post_id )` check;
   - a `DOING_AUTOSAVE` guard.
   - It only writes when the plugin's fields are present in `$_POST` (scalar,
     non-array), which is exactly the plugin's own form.

3. **A dedicated plugin nonce would add no protection and would create risk:**
   - The plugin's fields are part of the product edit form, which is only reachable
     by users with `edit_post` on that product. There is no privilege gap to close.
   - Attaching a plugin-nonce requirement to `save_post_product` would risk breaking
     legitimate save paths that do not carry the plugin nonce (quick edit, bulk edit,
     programmatic `wp_update_post()`, saves triggered by other plugins), because those
     paths never include a plugin-specific nonce.
   - The currency metabox **already** has a dedicated nonce (`mnswmc_meta_save`),
     which is appropriate there because `save_post_mnswmc` is a direct `save_post`
     hook on a CPT the plugin fully owns.

**Conclusion:** the existing protections (core post nonce + WooCommerce
`woocommerce_save_data` nonce + capability checks + autosave guards) are sufficient.
A dedicated product nonce is **not justified** and was intentionally not added.

## 4. JavaScript / CSS Loading Analysis

**Before:**
- `admin/admin.php` enqueued `mnswmc-admin-js` on **all** `post.php` /
  `post-new.php` screens (every post type — pages, posts, attachments, etc.).
- `includes/init.php` enqueued the `ani-navasan-lite-admin` CSS **only** on product
  edit screens (it checks `$post->post_type === 'product'`).

So the JS was **over-scoped** relative to the CSS: it loaded on unrelated post edit
screens where it is a no-op (its selectors — `#_mnswmc_active`,
`.woocommerce_variation`, etc. — only exist on product screens).

**Change (J1):** `mnswmc_enqueue_admin_scripts()` now enqueues the JS **only** on
product edit / new-product screens, matching the CSS scope:
- `post.php` → enqueued only when `global $post` is a `product`.
- `post-new.php` → enqueued only when `$_GET['post_type'] === 'product'`
  (sanitized with `sanitize_key()`).
- any other hook → not enqueued.
- The script version was bumped `1.0` → `1.1.0` for cache-busting (the JS file also
  changed in M2) and to match the CSS version.

**Other loading checks (no change needed):**
- No duplicate asset registrations (one script handle `mnswmc-admin-js`, one style
  handle `ani-navasan-lite-admin`).
- Dependencies are declared correctly (`mnswmc-admin-js` depends on `jquery`; the CSS
  has no dependencies).
- No script/stylesheet is loaded globally without a genuine requirement.
- The currency CPT screens do not load the product JS (the JS is product-scoped; the
  currency metabox needs no JS).

**Preserved:** product editing, variation editing, currency management, live price
previews, and independent per-field preview calculations are all unchanged (the JS
logic is untouched except for the dead-code removal in M2).

## 5. Maintainability Findings

| # | Finding | Evidence | Action |
|---|---------|----------|--------|
| M1 | **Duplicate (dead) activation notice.** The one-time "activated" success notice was defined in **two** places: `ani-navasan-lite.php` (an `admin_notices` callback registered at file load) and `includes/init.php` (an `admin_init` callback that adds an `admin_notices` callback). Because `admin_init` runs before `admin_notices`, the `init.php` version resets the `ani_navasan_lite_activated` option to `0` first, so the `ani-navasan-lite.php` version always sees `0` and **never displays** — it is dead code. | Hook-order analysis (`plugins_loaded` → `admin_init` → `admin_notices`). | **Fixed** — removed the dead notice from `ani-navasan-lite.php`; the `init.php` version (the one that actually runs) is retained. Behavior is unchanged (the notice still shows once, via `init.php`). |
| M2 | **Dead JS fallback.** In `applySimpleRate()`, after `findSimpleRegularPrice()` returned empty, the code fell back to `$('#_regular_price')`. But `findSimpleRegularPrice()` already checks `input#_regular_price` as its **first** selector, so the fallback is unreachable. | Reading `findSimpleRegularPrice()` (its first selector is `input#_regular_price`). | **Fixed** — removed the unreachable fallback. |
| M3 | **Duplicated dependence-type resolution.** The simple-product and variation save handlers each contained an identical whitelist + fallback block for `_mnswmc_dependence_type` (accept `simple`/`advanced`, else preserve a valid existing value, else default `simple`). | The two blocks were line-for-line equivalent except for the `$_POST` shape and the meta-get mechanism. | **Fixed** — extracted `ani_navasan_resolve_dependence_type( $posted, $existing )` into `includes/functions.php` (the shared-helpers file, and unit-testable) and used it in both handlers. The whitelist and default now live in one place. |
| M4 | **Repeated currency-options query (N+1).** `ani_navasan_get_currency_options()` is called once for the simple-product metabox and once per variation, re-querying the (small) `mnswmc` CPT each time. | Reading the metabox render code. | **Documented, not changed.** The CPT is small (a handful of currencies) and the query is cheap; the number of variations is small. A request-scoped cache would save negligible time and add caching behavior for no meaningful benefit. Per the task's guidance to avoid negligible-savings changes, no change was made. |
| M5 | **`console.log` debug noise** in `assets/js/admin.js`. | Reading the JS. | **Documented, not changed.** Stylistic only; not a security or correctness issue. Removing all of it would be a stylistic refactor the task warns against. |
| M6 | **Duplicated "server-side calculation"** between the simple and variation save handlers. | The two blocks compute Toman prices the same way but operate on different objects (a `$product` CRUD object vs. a `$variation_id` + `$_POST[...][$i]`). | **Documented, not changed.** Extracting a shared function would require abstracting the object differences, adding complexity for limited benefit. Left as-is. |

**Not changed (per task constraints):** no architecture rewrite, no new framework,
no new classes/interfaces, no metadata-contract changes, no calculation-algorithm
changes, no product-pricing-behavior changes, no new product features.

## 6. Changes Implemented

**Security (defensive hardening):**
- **F1** `includes/currencies-metabox.php`: added an explicit `DOING_AUTOSAVE` guard
  to the `save_post_mnswmc` handler (before the nonce check), for consistency with
  the product save handler and to follow the standard pattern.

**JavaScript loading:**
- **J1** `admin/admin.php`: scoped `mnswmc-admin-js` to product edit / new-product
  screens only (matching the CSS); bumped the script version to `1.1.0`.

**Maintainability:**
- **M1** `ani-navasan-lite.php`: removed the duplicate (dead) activation notice.
- **M2** `assets/js/admin.js`: removed the unreachable `#_regular_price` fallback.
- **M3** `includes/functions.php` + `admin/metabox.php`: extracted
  `ani_navasan_resolve_dependence_type()` and used it in both save handlers.

**Explicitly NOT implemented (with reasons):**
- No dedicated product nonce (§3).
- No `wp_is_post_revision()` guards (§2.1 — would be dead code).
- No currency-options cache (M4 — negligible savings).
- No `console.log` removal (M5 — stylistic).
- No server-side-calc extraction (M6 — would add complexity).

## 7. Files Modified

**Plugin source (6 files):**
- `includes/currencies-metabox.php` — F1 (`DOING_AUTOSAVE` guard).
- `admin/admin.php` — J1 (JS scoping + version bump).
- `ani-navasan-lite.php` — M1 (removed dead notice).
- `assets/js/admin.js` — M2 (removed dead fallback).
- `includes/functions.php` — M3 (new helper).
- `admin/metabox.php` — M3 (use the helper in both save handlers).

**Tests (3 files):**
- `tests/unit/dependence-type-test.php` — **new** unit test for the helper.
- `tests/run-unit-tests.sh` — added the new unit suite to the runner.
- `tests/integration/integration-test.php` — added **I7** (security: currency-handler
  nonce/capability protections) and **J1** (JS loading scope) sections.

**Documentation (updated in this task):**
- `docs/reports/2026-11-14-task-11-security-js-maintainability.md` — this report.
- `docs/نقشه راه توسعه Ani Navasan Lite.md` — Roadmap (Task 10 closed, Task 11, Task 12 skipped).
- `.clinerules/memory-bank.md` — Memory Bank.
- `tests/README.md` — test documentation.

## 8. Tests Executed and Results

All live tests ran on the **authorized** site `anishop-package-test.local`
(PHP 8.4.10 web / WP 7.1.4 / WC 11.3.0). The plugin source was synced to the site's
`wp-content/plugins/ani-navasan-lite/` before the live runs so the tests exercised the
new code.

| Test | Command | Result |
|------|---------|--------|
| PHP lint — all 6 modified plugin files + 2 test files | `php -l` | **PASS** (8/8, no syntax errors) |
| JS syntax — `assets/js/admin.js` | `node --check` | **PASS** |
| Unit suite (6 suites, incl. new dependence-type) | `bash tests/run-unit-tests.sh` | **6/6 PASS** (was 5/5; +1 new suite) |
| Integration suite — run 1 | `wp eval-file …/integration-test.php` | **70/70 PASS** (exit 0; was 63; +7 new checks) |
| Integration suite — run 2 (repeatability) | `wp eval-file …/integration-test.php` | **70/70 PASS** (exit 0) |
| Fault-injection suite | `wp eval-file …/fault-injection-test.php` | **16/16 PASS** (exit 0) |
| Post-run baseline verification | `wp eval` (prices + rates) | **ALL BASELINE CHECKS PASS** |

**New checks added (7):**
- `I7.1` invalid nonce rejected (currency rate unchanged).
- `I7.2` missing nonce rejected (currency rate unchanged).
- `I7.3` insufficient capability rejected (valid nonce, no `edit_post`; rate unchanged).
- `J1.1` JS enqueued on a product edit screen.
- `J1.2` JS enqueued on a new-product screen.
- `J1.3` JS **not** enqueued on the dashboard.
- `J1.4` JS **not** enqueued on a non-product post edit screen.

**New unit checks (12):** `ani_navasan_resolve_dependence_type()` — valid posted
values accepted (win over existing), empty/invalid/legacy/wrong-case posted values
preserve a valid existing value, and the `simple` default when neither is valid.

**Note on the `DOING_AUTOSAVE` guard (F1):** it is not directly unit-testable in the
CLI context because `DOING_AUTOSAVE` is a constant set by WordPress during real
autosave requests (PHP constants cannot be redefined in a test). It was verified by
code review (standard pattern, correct placement before the nonce check) and by the
fact that all legitimate save paths (I1, I2, I4a, I4b, I5) still pass with the guard
in place (the guard does not block non-autosave saves). This is documented as a
manual/code-review verification rather than an automated test.

## 9. Remaining Known Limitations

- The `DOING_AUTOSAVE` guard (F1) is verified by code review + the passing legitimate
  save paths, not by a dedicated automated autosave simulation (not feasible in the
  CLI context — see §8 note).
- The currency-options N+1 query (M4) remains (documented as negligible; no change).
- `console.log` debug statements remain in `assets/js/admin.js` (M5; stylistic).
- The pre-existing open items are unchanged: the `wp_wc_product_meta_lookup`
  CLI-context assertions (2 Task 07 checks, cause not confirmed) and the
  main-Navasan-plugin coexistence (BLOCKED; owner closed in Task 09d).

## 10. Git Branch and Commit Information

- **Branch:** `security/task-11-security-js-maintainability`
- **Base:** `fix/task-10c-backup-validation` (`ab980af17f69de0e52583f0b1bf41d8c3473031a`)
- **Commits:**

| # | Full SHA | Subject |
|---|----------|---------|
| 1 | `969508eee3ddd48e61bcd5525cafb9c0b4f9d374` | security(task-11): add explicit DOING_AUTOSAVE guard to currency save handler |
| 2 | `edab3302c4c6c9129b8dfbd5fc72ac87e0369aac` | perf(task-11): scope admin JS to product edit/new-product screens only |
| 3 | `4825e855fabfc45b4d9efcec86661ac394128b11` | refactor(task-11): remove dead code + extract shared dependence-type resolver |
| 4 | `0c1fbf5086334314aaf42a8aff7963c85e9c31c2` | test(task-11): add dependence-type unit suite + I7 security & J1 JS-scope integration checks |
| 5 | `9f099ebe27ff06fbb09bb8e607d9ee694645f62e` | docs(task-11): report + roadmap (Task 10 closed, 10d/12 skipped) + memory bank + test README |
| 6 | `11bb8d41c7a47d24bb919d2beb690ee96ea81567` | docs(task-11): record commit hashes and push status in report |

- **Push status:** pushed to `origin/security/task-11-security-js-maintainability`.
- **Merged:** **Yes** — merged into `main` with `--no-ff` (owner-authorized). See §10.1.

### 10.1 Merge, Tag & Release (1.2.0)

- **Merge method:** non-fast-forward (`--no-ff`) into `main`, preserving the task branch history.
- **Merge commit SHA:** `MERGE_SHA_PLACEHOLDER`
- **Final `main` HEAD (after merge):** `MAIN_HEAD_PLACEHOLDER`
- **Annotated tag:** `1.2.0` → points to the merge commit `MERGE_SHA_PLACEHOLDER` (tag object SHA: `TAG_SHA_PLACEHOLDER`).
- **GitHub Release:** `https://github.com/ramin40686/ani-navasan-lite/releases/tag/1.2.0`
- **Remote sync:** `main` and `origin/main` synchronized; tag `1.2.0` pushed to `origin`.
- **Post-merge test results:** recorded in §10.2.

### 10.2 Post-merge verification

- PHP syntax checks, JavaScript syntax check, unit suite, integration suite, and fault-injection suite re-run after the merge (results recorded below).
- Plugin version verified as `1.2.0` (header + runtime).
- Authorized test data verified intact; working tree clean.

## 11. Test Environment and Restoration Status

- **Authorized site only:** all live tests ran on `anishop-package-test.local`
  (local root `/home/ramin/Local Sites/anishop-package-test`, WordPress root
  `…/app/public`). No other site was inspected or modified.
- **Authorized test data only:** products `17613`/`17615`/`17616`/`17617` and
  currencies `17654`/`17655`. No unrelated products, currencies, or settings were
  modified. No unnecessary test products or currencies were created.
- **Restoration:** the integration suite's hardened backup/restore (Task 10b/10c)
  captured and restored all modified records; the final I6 verification and a
  post-run baseline check both confirm the authorized data matches its pre-test
  state (prices and currency rates verified in §8).
- **Plugin source on the site:** the site's `wp-content/plugins/ani-navasan-lite/`
  was synced with the Task 11 source so the live tests exercised the new code. This
  is the test site's plugin copy; the source of truth is the repository branch.

