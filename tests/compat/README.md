# Task 08 — Compatibility Matrix Test Harness

Docker-based compatibility matrix for **Ani Navasan Lite** across selected
PHP, WordPress, and WooCommerce versions.

## Matrix

| # | PHP | WordPress | WooCommerce | Rationale |
|---|-----|-----------|-------------|-----------|
| 0 | 8.3 | 7.1.4 | 11.2.2 | Baseline (production, Task 07) |
| 1 | 7.4 | 7.1.4 | 11.2.2 | Minimum PHP |
| 2 | 8.0 | 7.1.4 | 11.2.2 | PHP 8.0 |
| 3 | 8.1 | 7.1.4 | 11.2.2 | PHP 8.1 |
| 4 | 8.2 | 7.1.4 | 11.2.2 | PHP 8.2 |
| 5 | 8.3 | 7.0.8 | 11.2.2 | Minimum WordPress (WC 11.2 lower bound) |
| 6 | 8.3 | 7.1.4 | 9.6.2 | Minimum WooCommerce |
| 7 | 7.4 | 7.1.4 | 9.6.2 | Corner: minimum PHP + minimum WooCommerce |

Declared support: **PHP 7.4–8.3, WordPress 7.0–7.1, WooCommerce 9.6–11.2.**

## Files

- `smoke-test.php` — smoke test suite, run via `wp eval-file` inside the
  container. Prints a JSON result object. Must stay PHP 7.4 compatible.
- `run-env.sh` — single environment runner (Docker compose: `mysql:8.0` +
  `wordpress:phpX.Y-apache`, WP auto-install, target WC install, plugin
  install from the repo, smoke test, artifact collection, teardown).
- `run-matrix.sh` — runs the full matrix (or selected env indices) sequentially.
- `results/` — per-environment artifacts: `<label>.json` (check results),
  `<label>.stderr`, `<label>.debug.log`, `<label>.versions`, `summary.txt`.

## Usage

```bash
# Full matrix (sequential; ~30–50 min depending on network)
bash tests/compat/run-matrix.sh

# Single environment (index into the matrix above)
bash tests/compat/run-matrix.sh 0

# Single environment directly
bash tests/compat/run-env.sh php8.3 7.1.4 11.2.2 8.3-wp7.1.4-wc11.2.2
```

## Smoke test scenarios

- **S0** environment + plugin load (versions, plugin active, rounding policy)
- **S1** currency CPT (`mnswmc`) registration
- **S2** currency creation through the real admin save workflow
  (nonce + `wp_insert_post` → `save_post_mnswmc` → recalc action)
- **S3** simple product recalculation on rate change
- **S4** variable product: dependent variation recalculated, non-dependent
  variation untouched, parent price synced
- **S5** sale price preservation during regular-price recalculation
- **S6** AJAX endpoint: valid nonce succeeds, invalid nonce rejected
- **S7** admin metabox callbacks render without PHP errors
- **S8** no PHP deprecations/warnings/notices from the plugin in `debug.log`

## Methodology notes

- Currency create/edit steps use the **real admin save workflow** (same as
  Task 07), not direct meta writes.
- Products are created via the **WooCommerce CRUD API**
  (`WC_Product_Simple` / `WC_Product_Variable` / `WC_Product_Variation`);
  `wc_create_product()` is intentionally not used (removed in newer WC).
- `wp_wc_product_meta_lookup` is **not** used as a verification source
  (known CLI-context inconsistency, see Task 07 report).
- Each environment is a fresh throwaway stack; all test data is deleted at
  the end of the smoke test and the stack is torn down afterwards.
