#!/usr/bin/env bash
# Prepares a local WordPress site for the Playwright E2E suite and starts it on
# http://localhost:8889 (PHP built-in server + SQLite). Requires WP-CLI (`wp`).
#
# Usage: bin/e2e-setup.sh [target-dir]   (default /tmp/aipd-wp, see install-wp-tests.sh)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TARGET="${1:-/tmp/aipd-wp}"
bash "$ROOT/bin/install-wp-tests.sh" "${WP_VERSION:-7.1.2}" "$TARGET" sqlite > /dev/null
W="$TARGET/wordpress"

cat > "$W/wp-config.php" <<PHP
<?php
define( 'DB_NAME', 'e2e' ); define( 'DB_USER', 'root' ); define( 'DB_PASSWORD', '' ); define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' ); define( 'DB_COLLATE', '' );
define( 'DB_DIR', '$TARGET/db/' ); define( 'DB_FILE', 'e2e.sqlite' );
define( 'AUTH_KEY', 'e2e-1' ); define( 'SECURE_AUTH_KEY', 'e2e-2' ); define( 'LOGGED_IN_KEY', 'e2e-3' ); define( 'NONCE_KEY', 'e2e-4' );
define( 'AUTH_SALT', 'e2e-5' ); define( 'SECURE_AUTH_SALT', 'e2e-6' ); define( 'LOGGED_IN_SALT', 'e2e-7' ); define( 'NONCE_SALT', 'e2e-8' );
\$table_prefix = 'wp_';
define( 'WP_DEBUG', true ); define( 'WP_DEBUG_DISPLAY', false ); define( 'DISABLE_WP_CRON', true );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
PHP

ln -sfn "$ROOT" "$W/wp-content/plugins/ai-page-designer"
mkdir -p "$W/wp-content/mu-plugins"
ln -sfn "$ROOT/tests/E2E/mu-plugins/aipd-e2e.php" "$W/wp-content/mu-plugins/aipd-e2e.php"

rm -f "$TARGET/db/e2e.sqlite"
wp --path="$W" core install --url=http://localhost:8889 --title="AIPD E2E" --admin_user=admin --admin_password=password --admin_email=admin@example.com --skip-email
wp --path="$W" plugin activate ai-page-designer

echo "Starting http://localhost:8889 (Ctrl+C to stop). Run: npm run test:e2e"
cd "$W" && php -S localhost:8889
