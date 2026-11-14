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
│   ├── rounding-js-test.js    # mnswmcRoundToThousand() (extracted from admin.js)
│   ├── consistency-vectors.json  # Shared PHP<->JS rounding vectors
│   ├── consistency-php.php    # PHP side of the consistency check
│   └── consistency-js.js      # JS side of the consistency check
├── integration/
│   └── integration-test.php   # Live-site integration + regression suite
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

- **Backup first:** the full post + meta state of all six authorized posts is
  captured before any mutation.
- **Real save paths:** the tests exercise the genuine plugin handlers
  (`woocommerce_admin_process_product_object`, `woocommerce_save_product_variation`,
  `save_post_product`, and the real `save_post_mnswmc` currency-save workflow
  via `wp_update_post()` with a valid nonce + admin user).
- **Restore after:** state is restored **byte-for-byte** via a direct-DB
  transaction (no hooks fired), then verified row-by-row (I6).
- **No partial POSTs:** the tests never send a partial product form that could
  drop attributes or unrelated meta.

### Scenarios

| Section | Coverage |
|---------|----------|
| I0 | Environment + plugin load (functions, CPT, products exist) |
| I1 | Simple-product save handler: meta persistence + Toman price calc (regular, sale, floor) |
| I2 | Variation save handler: per-variation meta + prices + cross-variation isolation |
| I3 | Decimal rounding hook (`save_post_product`): 2-decimal rounding of currency fields |
| I4a | Recalculation — currency A rate change 2000→2500→2000 (simple + variation A, parent sync, sale preservation, currency-B isolation) |
| I4b | Recalculation — currency B rate change 2000→2500→2000 (variation B, parent sync, currency-A isolation) |
| I5 | Attribute serialization regression (`_product_attributes` is a real array, not double-serialized) |
| I6 | Final restore round-trip verification (every post + meta row matches the backup) |

## 3. Compatibility matrix (pre-existing)

`tests/compat/` contains the Docker-based PHP/WP/WC compatibility matrix
(Task 08). See `tests/compat/README.md`.

## Coverage notes

- **Covered by unit tests:** rounding (PHP + JS + cross-language), rate helper
  (standard/legacy/fallback), both one-time migrations (idempotency,
  never-overwrite, legacy→standard, `variable`→`advanced`).
- **Covered by integration tests:** the full save flow (simple + variation),
  decimal rounding hook, currency-rate recalculation (both currencies, parent
  sync, sale-price preservation, cross-currency isolation), attribute
  serialization regression, and restore integrity.
- **Not covered (out of scope for this task):** the admin AJAX endpoint
  (`wp_ajax_mnswmc_get_exchange_rate`) and the admin metabox rendering are
  covered by the pre-existing `tests/compat/` smoke test, not by this suite.
  The mutual-exclusivity guard against the main Navasan plugin remains
  BLOCKED (main plugin not installable — see the Task 08 report).
