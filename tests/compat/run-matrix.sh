#!/usr/bin/env bash
#
# Ani Navasan Lite — Task 08 compatibility matrix: full matrix runner.
#
# Runs every environment in the matrix sequentially (each environment is a
# throwaway Docker stack; sequential execution keeps resource usage and
# result attribution deterministic).
#
# Matrix (PHP x WordPress x WooCommerce):
#   1. 8.3 / 7.1.4 / 11.2.2   baseline (production, Task 07)
#   2. 7.4 / 7.1.4 / 11.2.2   minimum PHP
#   3. 8.0 / 7.1.4 / 11.2.2
#   4. 8.1 / 7.1.4 / 11.2.2
#   5. 8.2 / 7.1.4 / 11.2.2
#   6. 8.3 / 7.0.8 / 11.2.2   minimum WordPress (WC 11.2 lower bound)
#   7. 8.3 / 7.1.4 / 9.6.2    minimum WooCommerce
#   8. 7.4 / 7.1.4 / 9.6.2    corner: minimum PHP + minimum WooCommerce
#
# Usage: run-matrix.sh [env_index ...]   (no args = all environments)
#
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RESULTS_DIR="$SCRIPT_DIR/results"
mkdir -p "$RESULTS_DIR"

# php_tag|wp_version|wc_version
MATRIX=(
	"php8.3|7.1.4|11.2.2"
	"php7.4|7.1.4|11.2.2"
	"php8.0|7.1.4|11.2.2"
	"php8.1|7.1.4|11.2.2"
	"php8.2|7.1.4|11.2.2"
	"php8.3|7.0.8|11.2.2"
	"php8.3|7.1.4|9.6.2"
	"php7.4|7.1.4|9.6.2"
)

if [ "$#" -gt 0 ]; then
	INDICES=("$@")
else
	INDICES=()
	for i in "${!MATRIX[@]}"; do
		INDICES+=( "$i" )
	done
fi

SUMMARY="$RESULTS_DIR/summary.txt"
: > "$SUMMARY"
OVERALL=0

for idx in "${INDICES[@]}"; do
	if [ "$idx" -lt 0 ] || [ "$idx" -ge "${#MATRIX[@]}" ]; then
		echo "invalid env index: $idx (valid: 0..$(( ${#MATRIX[@]} - 1 )))"
		exit 2
	fi
	IFS='|' read -r PHP_TAG WP_VERSION WC_VERSION <<< "${MATRIX[$idx]}"
	LABEL="${PHP_TAG#php}-wp${WP_VERSION}-wc${WC_VERSION}"
	echo "=============================================================="
	echo "[$idx] $LABEL"
	echo "=============================================================="
	if bash "$SCRIPT_DIR/run-env.sh" "$PHP_TAG" "$WP_VERSION" "$WC_VERSION" "$LABEL"; then
		echo "[$idx] $LABEL PASS" >> "$SUMMARY"
	else
		echo "[$idx] $LABEL FAIL" >> "$SUMMARY"
		OVERALL=1
	fi
done

echo "=============================================================="
echo "Matrix summary:"
cat "$SUMMARY"
echo "=============================================================="
exit "$OVERALL"
