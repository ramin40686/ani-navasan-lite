# Task 16 — `1.2.1` Release-Candidate Preparation

**Date:** 2026-11-14
**Branch:** `release/stage-5-final-preparation`
**Base:** `origin/main` @ `cc77122` (post-1.2.0)
**Scope:** Documentation, packaging, and version-bump only — **no plugin business-logic, feature, or runtime-behavior changes**

---

## 1. Task Objective

Prepare Ani Navasan Lite `1.2.1` as the final Stage 5 release candidate after Task 15 install-lifecycle validation, without changing plugin business logic.

`1.2.1` is a **documentation/packaging/version-bump** release. It records the completed Stage 5 work (Tasks 13–15: final readiness review, release package audit, installation-lifecycle tests) and prepares the release artifacts (version sync, changelog, README, final ZIP, and release notes).

## 2. Initial Findings

- Stage 5 Tasks 13–15 are complete (see their reports).
- The plugin was at version `1.2.0` (released).
- The owner approved the next version as `1.2.1`.
- No plugin business logic, features, or runtime behavior changes were requested or made.

## 3. Files Inspected

- `ani-navasan-lite.php` (plugin header)
- `admin/admin.php` (admin JS version)
- `includes/init.php` (admin CSS version)
- `CHANGELOG.md`
- `README.md`
- `docs/نقشه راه توسعه Ani Navasan Lite.md` (roadmap)
- `.clinerules/memory-bank.md`
- Task 13/14/15 reports

## 4. Files Created, Modified, or Deleted

**Modified:**
- `ani-navasan-lite.php` — plugin header `Version: 1.2.0` → `1.2.1`
- `admin/admin.php` — admin JS version `1.2.0` → `1.2.1`
- `includes/init.php` — admin CSS version `1.2.0` → `1.2.1`
- `CHANGELOG.md` — added `[1.2.1]` entry
- `README.md` — current version `1.2.0` → `1.2.1`
- `docs/نقشه راه توسعه Ani Navasan Lite.md` — Stage 5 section updated (Tasks 13–15 complete, Task 16 in progress)
- `.clinerules/memory-bank.md` — header + Section E (Tasks 13–16) + releases + detailed reports updated

**Created:**
- `docs/reports/2026-11-14-task-16-release-candidate-preparation.md` (this report)

**Deleted:** none

## 5. Detailed Description of Changes

### 5.1 Version synchronization (1.2.0 → 1.2.1)

All version locations were synced to `1.2.1`:
- Plugin header (`ani-navasan-lite.php`): `Version: 1.2.1`
- Admin JS version (`admin/admin.php`): `1.2.1`
- Admin CSS version (`includes/init.php`): `1.2.1`
- `README.md` current version: `1.2.1`
- `CHANGELOG.md`: new `[1.2.1]` entry

No `1.2.0` version references remain in runtime files.

### 5.2 Changelog

A `[1.2.1]` entry was added to `CHANGELOG.md` documenting the documentation/packaging/version-bump nature of the release and the completed Stage 5 work.

### 5.3 Roadmap

The Stage 5 section of the roadmap was updated to mark Tasks 13–15 as complete and Task 16 as in progress (release-candidate prepared; merge/tag/Release pending owner approval).

### 5.4 Memory Bank

The memory bank was updated to reflect the current state: header (Task 16 in progress), Section E (Tasks 13–16), releases (`1.2.1` pending), and detailed reports.

### 5.5 Final release ZIP

The final `1.2.1` release ZIP was built and verified (see §8).

## 6. Security Implications

- No security-relevant code changes. The release contains only documentation, packaging, and version-bump changes.
- The final ZIP was scanned for sensitive data (passwords, API keys, secrets, local paths, credentials) — **no sensitive data found** (only the public author email in the plugin header).
- No new attack surface introduced.

## 7. Compatibility Implications

- No compatibility changes. The supported range is unchanged (see plugin header: `Requires PHP: 7.4`, `Requires at least: 7.0.8`, `WC requires at least: 9.6.2`, `WC tested up to: 11.2.2`).
- The version bump is a patch release; no breaking changes.
- Metadata compatibility with the main Navasan plugin is preserved (rate `_mnswmc_currency_value`, currency price `_mnswmc_regular_price`, currency sale price `_mnswmc_sale_price`, dependence type `simple`/`advanced`).

## 8. Tests Executed and Their Results

### 8.1 Final `1.2.1` ZIP verification

The final `1.2.1` release ZIP was built at `/tmp/stage5/ani-navasan-lite-1.2.1.zip` and verified:

| Check | Result |
|---|---|
| ZIP structure (`unzip -t`) | PASS — no errors detected |
| File list | PASS — exactly 11 runtime files |
| Plugin header version | PASS — `1.2.1` |
| Admin JS version | PASS — `1.2.1` |
| Admin CSS version | PASS — `1.2.1` |
| No `1.2.0` references in runtime files | PASS |
| PHP syntax (`php -l`, 9 files) | PASS — 9/9 |
| JS syntax (`node --check`) | PASS |
| Sensitive-data scan | PASS — no sensitive data found |
| ZIP files identical to working tree | PASS — all 11 files identical |

**Final ZIP:**
- Path: `/tmp/stage5/ani-navasan-lite-1.2.1.zip`
- File count: 11
- Size: 20139 bytes
- SHA256: `971f98bc701cba2580d97253bfc371cfbe232f0d7ffbfa70e872cd753cadcfb5`

### 8.2 The 11 runtime files

1. `ani-navasan-lite.php`
2. `includes/init.php`
3. `includes/functions.php`
4. `includes/hooks.php`
5. `includes/ajax.php`
6. `includes/currencies-cpt.php`
7. `includes/currencies-metabox.php`
8. `admin/admin.php`
9. `admin/metabox.php`
10. `assets/js/admin.js`
11. `assets/css/admin.css`

### 8.3 Prior Stage 5 test results (Tasks 13–15)

- Task 13 (final readiness review): documentation only.
- Task 14 (release package audit): trial `1.2.0` ZIP verified.
- Task 15 (installation-lifecycle tests): **all scenarios PASS** on `anishop-package-test.local` (install from ZIP, activation, frontend/admin smoke, deactivation, simulated WP update flow, baseline restore; zero new `14-Nov` `debug.log` entries).

## 9. Known Limitations

- The final ZIP is a local artifact (`/tmp/stage5/ani-navasan-lite-1.2.1.zip`); release ZIPs are not committed to the repository.
- The GitHub Release has not been published (pending owner approval).

## 10. Remaining Issues

- **BLOCKED pending explicit owner approval:**
  - Merge `release/stage-5-final-preparation` to `main`
  - Create and push the official `1.2.1` tag
  - Publish the GitHub Release

## 11. Git Branch Name

`release/stage-5-final-preparation`

## 12. Commit Hash

(Recorded after commit)

## 13. Push Status

(Recorded after push)

---

## 14. GitHub Release Notes for `1.2.1`

> **Ani Navasan Lite 1.2.1**
>
> This is a **documentation, packaging, and version-bump** release. **No plugin business logic, features, or runtime behavior were changed.**
>
> ### What's in this release
> - **Documentation:** Added `README.md` (installation, usage, metadata, compatibility) and `CHANGELOG.md` (version history).
> - **Packaging:** Audited the release package; the release ZIP contains only the 11 runtime files (no development, test, local, or sensitive files).
> - **Install-lifecycle testing:** Validated the full install lifecycle (install from ZIP, activation, frontend/admin smoke tests, deactivation, simulated WordPress update flow, and baseline restore) on a test site — all scenarios passed.
> - **Version sync:** All version locations (plugin header, admin JS, admin CSS, README, changelog) are consistent at `1.2.1`.
>
> ### Notes
> - This release records and packages the completed Stage 5 (final release preparation) work.
> - The supported range is unchanged (see plugin header: PHP 7.4+, WordPress 7.0.8+, WooCommerce 9.6.2+).
> - Metadata compatibility with the main Navasan plugin is preserved (rate `_mnswmc_currency_value`, currency price `_mnswmc_regular_price`, currency sale price `_mnswmc_sale_price`, dependence type `simple`/`advanced`).
>
> ### SHA256
> `971f98bc701cba2580d97253bfc371cfbe232f0d7ffbfa70e872cd753cadcfb5`

