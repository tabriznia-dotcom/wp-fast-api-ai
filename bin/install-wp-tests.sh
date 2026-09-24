#!/usr/bin/env bash
# Installs WordPress core, the WordPress PHPUnit test library and (optionally)
# the SQLite database integration, so the test suite runs without MySQL.
#
# Usage: bin/install-wp-tests.sh [wp-version] [target-dir] [db: sqlite|mysql]
#   WP version defaults to 7.1.2, target dir to /tmp/aipd-wp, db to sqlite.
# For MySQL set DB_NAME, DB_USER, DB_PASSWORD and DB_HOST in the environment.
#
# Afterwards:
#   export WP_TESTS_DIR=<target>/wordpress-develop/tests/phpunit
#   export WP_TESTS_CONFIG_FILE_PATH=<target>/wp-tests-config.php
#   vendor/bin/phpunit
set -euo pipefail

WP_VERSION="${1:-7.1.2}"
TARGET="${2:-/tmp/aipd-wp}"
DB="${3:-sqlite}"
mkdir -p "$TARGET"
cd "$TARGET"

if [ ! -d wordpress ]; then
	git clone --quiet --depth 1 --branch "$WP_VERSION" https://github.com/WordPress/WordPress.git wordpress
fi
if [ ! -d wordpress-develop ]; then
	git clone --quiet --depth 1 --branch "$WP_VERSION" --filter=blob:none --sparse https://github.com/WordPress/wordpress-develop.git wordpress-develop
	(cd wordpress-develop && git sparse-checkout set tests/phpunit/includes tests/phpunit/data/themes)
fi

DB_LINES=""
if [ "$DB" = "sqlite" ]; then
	if [ ! -d sqlite-database-integration ]; then
		git clone --quiet --depth 1 https://github.com/WordPress/sqlite-database-integration.git
	fi
	PLUGIN_DIR="$TARGET/sqlite-database-integration/packages/plugin-sqlite-database-integration"
	[ -d "$PLUGIN_DIR" ] || PLUGIN_DIR="$TARGET/sqlite-database-integration"
	sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$PLUGIN_DIR#" -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
		"$PLUGIN_DIR/db.copy" > wordpress/wp-content/db.php
	mkdir -p "$TARGET/db"
	DB_LINES="define( 'DB_DIR', '$TARGET/db/' ); define( 'DB_FILE', 'tests.sqlite' );"
fi

cat > wp-tests-config.php <<PHP
<?php
define( 'ABSPATH', '$TARGET/wordpress/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );
define( 'DB_NAME', '${DB_NAME:-wordpress_tests}' );
define( 'DB_USER', '${DB_USER:-root}' );
define( 'DB_PASSWORD', '${DB_PASSWORD:-}' );
define( 'DB_HOST', '${DB_HOST:-localhost}' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
$DB_LINES
define( 'AUTH_KEY', 'aipd-tests-1' ); define( 'SECURE_AUTH_KEY', 'aipd-tests-2' ); define( 'LOGGED_IN_KEY', 'aipd-tests-3' ); define( 'NONCE_KEY', 'aipd-tests-4' );
define( 'AUTH_SALT', 'aipd-tests-5' ); define( 'SECURE_AUTH_SALT', 'aipd-tests-6' ); define( 'LOGGED_IN_SALT', 'aipd-tests-7' ); define( 'NONCE_SALT', 'aipd-tests-8' );
\$table_prefix = 'wptests_';
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
PHP

echo "WordPress $WP_VERSION test environment ready in $TARGET"
echo "export WP_TESTS_DIR=$TARGET/wordpress-develop/tests/phpunit"
echo "export WP_TESTS_CONFIG_FILE_PATH=$TARGET/wp-tests-config.php"
