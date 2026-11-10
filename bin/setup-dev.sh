#!/usr/bin/env bash
# Поднимает локальный WordPress на SQLite в .dev/wp и подключает плагин симлинком.
# Нужны: php, wp-cli, curl, unzip. Docker/MySQL не нужны.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WP_DIR="$ROOT/.dev/wp"
PORT="${WPFF_PORT:-8889}"
wpc() { wp --path="$WP_DIR" "$@"; }
export WP_CLI_PHP_ARGS="-d error_reporting=E_ALL&~E_DEPRECATED"

mkdir -p "$WP_DIR"

if [ ! -f "$WP_DIR/wp-load.php" ]; then
	wpc core download --skip-content --locale=en_US
	mkdir -p "$WP_DIR/wp-content/plugins" "$WP_DIR/wp-content/themes"
fi

SQLITE="$WP_DIR/wp-content/plugins/sqlite-database-integration"
if [ ! -d "$SQLITE" ]; then
	curl -sSL -o /tmp/wpff-sqlite.zip https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip
	unzip -q /tmp/wpff-sqlite.zip -d "$WP_DIR/wp-content/plugins"
	rm /tmp/wpff-sqlite.zip
fi
cp "$SQLITE/db.copy" "$WP_DIR/wp-content/db.php"
sed -i.bak \
	-e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$SQLITE#" \
	-e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
	"$WP_DIR/wp-content/db.php" && rm -f "$WP_DIR/wp-content/db.php.bak"

if [ ! -f "$WP_DIR/wp-config.php" ]; then
	wpc config create --dbname=wp --dbuser=wp --dbpass=wp --skip-check \
		--extra-php <<'PHP'
define( 'DB_DIR', __DIR__ . '/wp-content/database' );
// Tests point this at a throwaway database (see tests/bootstrap.php).
if ( getenv( 'WPFF_DB_FILE' ) ) { define( 'DB_FILE', getenv( 'WPFF_DB_FILE' ) ); }
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
PHP
fi

ln -sfn "$ROOT" "$WP_DIR/wp-content/plugins/freeform-fields"

# Demo field group on the dev site (not in tests) and a ?classic=1 switch to the classic editor.
mkdir -p "$WP_DIR/wp-content/mu-plugins"
cat > "$WP_DIR/wp-content/mu-plugins/wpff-demo.php" <<'PHP'
<?php
// Dev-site only: demo fields and ?classic=1 switch. Skipped while tests run.
if ( getenv( 'WPFF_DB_FILE' ) ) {
	return;
}
require WP_CONTENT_DIR . '/plugins/freeform-fields/examples/demo-fields.php';
add_filter( 'use_block_editor_for_post', static fn( $use ) => isset( $_GET['classic'] ) ? false : $use );
PHP

if ! wpc core is-installed 2>/dev/null; then
	PASS="$(php -r 'echo bin2hex(random_bytes(8));')"
	wpc core install --url="http://localhost:$PORT" --title="WP Freeform Fields Dev" \
		--admin_user=admin --admin_password="$PASS" --admin_email=admin@example.test --skip-email
	printf 'WPFF_DEV_USER=admin\nWPFF_DEV_PASS=%s\nWPFF_DEV_URL=http://localhost:%s\n' "$PASS" "$PORT" > "$ROOT/.dev/credentials"
	echo "Логин/пароль сохранены в .dev/credentials"
fi

if [ ! -d "$WP_DIR/wp-content/themes/twentytwentyfive" ]; then
	wpc theme install twentytwentyfive --activate
fi

wpc plugin activate freeform-fields || true
echo "Готово. Запуск: wp --path=\"$WP_DIR\" server --port=$PORT"
