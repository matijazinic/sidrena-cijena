#!/usr/bin/env bash
# Lokalna WordPress + WooCommerce instalacija za testiranje plugina (bez Dockera).
# Treba: brew install php mariadb wp-cli
#
#   dev/setup-local.sh          instalira (ako već nije) i pokreće server na http://localhost:8890
#   dev/setup-local.sh stop     zaustavlja PHP server
set -euo pipefail
# wp-cli 2.12 baca deprecated na PHP 8.5, a raspakiravanje WP-a treba više memorije.
WP_BIN="$(command -v wp)"
wp() { php -d memory_limit=1024M -d error_reporting=24575 "$WP_BIN" "$@"; }

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
SITE_DIR="${SCJ_SITE_DIR:-$HOME/Sites/sidrena-test}"
PORT="${SCJ_PORT:-8890}"
URL="http://localhost:$PORT"
DB="scj_test"

if [[ "${1:-}" == "stop" ]]; then
	pkill -f "php -S localhost:$PORT" || true
	exit 0
fi

brew services list | grep -q '^mariadb.*started' || brew services start mariadb
for i in {1..30}; do mariadb -e 'select 1' >/dev/null 2>&1 && break; sleep 1; done
mariadb -e "CREATE DATABASE IF NOT EXISTS $DB CHARACTER SET utf8mb4; CREATE USER IF NOT EXISTS 'scj'@'localhost' IDENTIFIED BY 'scj'; GRANT ALL ON $DB.* TO 'scj'@'localhost';"

mkdir -p "$SITE_DIR"
cd "$SITE_DIR"
if [[ ! -f wp-load.php ]]; then
	wp core download --locale=hr
fi
if [[ ! -f wp-config.php ]]; then
	wp config create --dbname=$DB --dbuser=scj --dbpass=scj --dbhost=127.0.0.1 --locale=hr
	wp config set WP_DEBUG true --raw
	wp config set WP_DEBUG_LOG true --raw
	wp config set WP_DEBUG_DISPLAY false --raw
	wp config set WP_ENVIRONMENT_TYPE local
fi
if ! wp core is-installed 2>/dev/null; then
	wp core install --url="$URL" --title="Sidrena test" --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email
	wp option update timezone_string Europe/Zagreb
	wp rewrite structure '/%postname%/' --hard
	wp plugin install woocommerce --activate
	wp option update woocommerce_currency EUR
	wp option update woocommerce_default_country HR
	wp option update woocommerce_price_decimal_sep ','
	wp option update woocommerce_price_thousand_sep '.'
	wp option update woocommerce_currency_pos right_space
	wp option update woocommerce_onboarding_profile '{"skipped":true}' --format=json
fi
# Plugin je symlink na projekt: NIKAD "wp plugin delete/uninstall" – obrisalo bi izvorni kod.
ln -sfn "$PLUGIN_DIR" wp-content/plugins/sidrena-cijena

# Router: php -S ne zna za .htaccess.
cat > router.php <<'PHP'
<?php
$path = rawurldecode( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) ); // nazivi cjenika imaju razmake i dvotočku
if ( $path !== '/' && file_exists( __DIR__ . $path ) && ! is_dir( __DIR__ . $path ) ) {
	return false;
}
if ( is_dir( __DIR__ . $path ) && file_exists( __DIR__ . rtrim( $path, '/' ) . '/index.php' ) ) {
	return false;
}
require __DIR__ . '/index.php';
PHP

if ! pgrep -f "php -S localhost:$PORT" >/dev/null; then
	# Više workera: WP-Cron i loopback zahtjevi (bot-check) inače blokiraju jednodretveni server.
	PHP_CLI_SERVER_WORKERS=4 nohup php -S "localhost:$PORT" -t "$SITE_DIR" "$SITE_DIR/router.php" >"$SITE_DIR/server.log" 2>&1 &
	sleep 1
fi
echo "WordPress: $URL/wp-admin  (admin / admin)"
echo "Mapa: $SITE_DIR"
