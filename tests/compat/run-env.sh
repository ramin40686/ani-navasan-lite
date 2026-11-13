#!/usr/bin/env bash
#
# Ani Navasan Lite — Task 08 compatibility matrix: single environment runner.
#
# Spins up a throwaway Docker environment (MySQL 8.0 + official WordPress
# image for the given PHP version), installs the target WooCommerce version,
# installs the plugin from this repository, and runs the smoke test suite.
#
# Usage: run-env.sh <php_tag> <wp_version> <wc_version> <label>
#   php_tag:    php7.4 | php8.0 | php8.1 | php8.2 | php8.3
#   wp_version: e.g. 7.1.4
#   wc_version: e.g. 11.2.2
#   label:      result label, e.g. 8.3-wp7.1.4-wc11.2.2
#
# Results are written to tests/compat/results/<label>.*
#
set -uo pipefail

PHP_TAG="${1:?php_tag required (e.g. php8.3)}"
WP_VERSION="${2:?wp_version required (e.g. 7.1.4)}"
WC_VERSION="${3:?wc_version required (e.g. 11.2.2)}"
LABEL="${4:?label required (e.g. 8.3-wp7.1.4-wc11.2.2)}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
WORK_DIR="/tmp/compat-matrix/${LABEL}"
RESULTS_DIR="$SCRIPT_DIR/results"
mkdir -p "$WORK_DIR" "$RESULTS_DIR"
cd "$WORK_DIR"

export PHP_TAG WP_VERSION

log() { echo "[$LABEL] $*"; }

# ---------------------------------------------------------------------------
# 1. Compose file (generated so one template serves the whole matrix)
# ---------------------------------------------------------------------------
cat > docker-compose.yml <<EOF
services:
  db:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: wp
      MYSQL_USER: wp
      MYSQL_PASSWORD: wp
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "127.0.0.1", "-uroot", "-proot"]
      interval: 3s
      timeout: 5s
      retries: 60

  wp:
    image: "wordpress:\${PHP_TAG}-apache"
    environment:
      WORDPRESS_VERSION: "\${WP_VERSION}"
      WORDPRESS_DB_HOST: db
      WORDPRESS_DB_NAME: wp
      WORDPRESS_DB_USER: wp
      WORDPRESS_DB_PASSWORD: wp
      WORDPRESS_TABLE_PREFIX: wp_
      WORDPRESS_DEBUG: 1
      WORDPRESS_CONFIG_EXTRA: "define('WP_DEBUG_LOG', true); define('WP_DEBUG_DISPLAY', false);"
    depends_on:
      db:
        condition: service_healthy
    volumes:
      - wpdata:/var/www/html

volumes:
  wpdata:
EOF

# ---------------------------------------------------------------------------
# 2. Start containers
# ---------------------------------------------------------------------------
log "starting containers (php=$PHP_TAG wp=$WP_VERSION wc=$WC_VERSION)..."
if ! docker compose up -d --wait > "$WORK_DIR/compose-up.log" 2>&1; then
	log "FATAL: docker compose up failed (see $WORK_DIR/compose-up.log)"
	exit 1
fi

# ---------------------------------------------------------------------------
# 3. Install wp-cli inside the WP container
# ---------------------------------------------------------------------------
log "installing wp-cli..."
if ! docker compose exec -T wp sh -c \
	"php -r \"copy('https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar', '/tmp/wp');\" && chmod +x /tmp/wp" \
	> "$WORK_DIR/wpcli-install.log" 2>&1; then
	log "FATAL: wp-cli install failed (see $WORK_DIR/wpcli-install.log)"
	docker compose down -v --remove-orphans > /dev/null 2>&1
	exit 1
fi

# ---------------------------------------------------------------------------
# 4. Download the target WordPress version and install it.
#    NOTE: the official image only bundles a fixed core version and does NOT
#    auto-install; the entrypoint just copies files + wp-config.php.
# ---------------------------------------------------------------------------
log "downloading WordPress $WP_VERSION..."
if ! docker compose exec -T wp php -d memory_limit=1G /tmp/wp core download --version="$WP_VERSION" --force --allow-root \
	> "$WORK_DIR/wp-download.log" 2>&1; then
	log "FATAL: WordPress $WP_VERSION download failed (see $WORK_DIR/wp-download.log)"
	docker compose down -v --remove-orphans > /dev/null 2>&1
	exit 1
fi
log "installing WordPress..."
if ! docker compose exec -T wp php -d memory_limit=1G /tmp/wp core install \
	--url="http://localhost" --title="Compat" --admin_user="admin" \
	--admin_password="admin" --admin_email="admin@example.com" --skip-email --allow-root \
	> "$WORK_DIR/wp-install.log" 2>&1; then
	log "FATAL: WordPress install failed (see $WORK_DIR/wp-install.log)"
	docker compose down -v --remove-orphans > /dev/null 2>&1
	exit 1
fi

# ---------------------------------------------------------------------------
# 5. Install the target WooCommerce version
# ---------------------------------------------------------------------------
log "installing WooCommerce $WC_VERSION..."
if ! docker compose exec -T wp sh -c \
	"curl -fsSL -o /tmp/wc.zip 'https://downloads.wordpress.org/plugin/woocommerce.${WC_VERSION}.zip'" \
	> "$WORK_DIR/wc-download.log" 2>&1; then
	log "FATAL: WooCommerce $WC_VERSION download failed"
	docker compose down -v --remove-orphans > /dev/null 2>&1
	exit 1
fi
if ! docker compose exec -T wp php -d memory_limit=1G /tmp/wp plugin install /tmp/wc.zip --activate --allow-root \
	> "$WORK_DIR/wc-install.log" 2>&1; then
	log "FATAL: WooCommerce install/activate failed (see $WORK_DIR/wc-install.log)"
	docker compose down -v --remove-orphans > /dev/null 2>&1
	exit 1
fi

# ---------------------------------------------------------------------------
# 6. Install the plugin under test (repo contents, without VCS/docs/tests)
# ---------------------------------------------------------------------------
log "installing ani-navasan-lite from $REPO_DIR..."
tar czf "$WORK_DIR/plugin.tar.gz" -C "$REPO_DIR" \
	--exclude='.git' --exclude='docs' --exclude='tests' --exclude='.clinerules' --exclude='.vscode' .
docker compose cp "$WORK_DIR/plugin.tar.gz" wp:/tmp/plugin.tar.gz
docker compose exec -T wp sh -c \
	"mkdir -p /var/www/html/wp-content/plugins/ani-navasan-lite && tar xzf /tmp/plugin.tar.gz -C /var/www/html/wp-content/plugins/ani-navasan-lite"
if ! docker compose exec -T wp php -d memory_limit=1G /tmp/wp plugin activate ani-navasan-lite --allow-root \
	> "$WORK_DIR/plugin-activate.log" 2>&1; then
	log "FATAL: plugin activation failed (see $WORK_DIR/plugin-activate.log)"
	docker compose down -v --remove-orphans > /dev/null 2>&1
	exit 1
fi

# ---------------------------------------------------------------------------
# 7. Run the smoke test suite
# ---------------------------------------------------------------------------
log "running smoke test..."
docker compose cp "$SCRIPT_DIR/smoke-test.php" wp:/tmp/smoke-test.php
docker compose exec -T -e COMPAT_LABEL="$LABEL" wp php -d memory_limit=1G /tmp/wp eval-file /tmp/smoke-test.php --allow-root \
	> "$RESULTS_DIR/${LABEL}.json" 2> "$RESULTS_DIR/${LABEL}.stderr"
SMOKE_EXIT=$?
log "smoke test exit=$SMOKE_EXIT"

# ---------------------------------------------------------------------------
# 8. Collect artifacts (debug log + exact versions)
# ---------------------------------------------------------------------------
docker compose cp wp:/var/www/html/wp-content/debug.log "$RESULTS_DIR/${LABEL}.debug.log" 2>/dev/null || true
{
	docker compose exec -T wp php -r 'echo "php=" . PHP_VERSION . "\n";'
	docker compose exec -T wp php -d memory_limit=1G /tmp/wp core version --allow-root 2>/dev/null | sed 's/^/wp=/'
	docker compose exec -T wp php -d memory_limit=1G /tmp/wp eval 'echo "wc=" . (defined("WC_VERSION") ? WC_VERSION : "missing") . "\n";' 2>/dev/null
} > "$RESULTS_DIR/${LABEL}.versions" 2>&1

# ---------------------------------------------------------------------------
# 9. Tear down
# ---------------------------------------------------------------------------
docker compose down -v --remove-orphans > /dev/null 2>&1

# ---------------------------------------------------------------------------
# 10. Verdict
# ---------------------------------------------------------------------------
FAILED_COUNT=$(php -r '$d = json_decode(file_get_contents($argv[1]), true); echo is_array($d) ? (int) ($d["failed"] ?? -1) : -1;' "$RESULTS_DIR/${LABEL}.json" 2>/dev/null)
if [ "$FAILED_COUNT" = "0" ]; then
	log "PASS (all checks passed)"
	exit 0
else
	log "FAIL (failed=$FAILED_COUNT, smoke_exit=$SMOKE_EXIT)"
	exit 1
fi

