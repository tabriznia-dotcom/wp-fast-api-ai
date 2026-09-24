<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite and the plugin.
 *
 * Environment variables:
 * - WP_TESTS_DIR:              path to wordpress-develop/tests/phpunit.
 * - WP_TESTS_CONFIG_FILE_PATH: path to wp-tests-config.php (optional).
 * - AIPD_TEST_ELEMENTOR_DIR:   path to an Elementor checkout to run Elementor integration tests (optional).
 * - WP_TESTS_MULTISITE=1:      run the suite in multisite mode.
 *
 * @package AIPageDesigner
 */

$aipd_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $aipd_tests_dir ) {
	$aipd_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( getenv( 'WP_TESTS_CONFIG_FILE_PATH' ) && ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	define( 'WP_TESTS_CONFIG_FILE_PATH', getenv( 'WP_TESTS_CONFIG_FILE_PATH' ) );
}

if ( ! file_exists( $aipd_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find {$aipd_tests_dir}/includes/functions.php. Run bin/install-wp-tests.sh first." . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

require_once $aipd_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		$elementor = getenv( 'AIPD_TEST_ELEMENTOR_DIR' );
		if ( $elementor && file_exists( $elementor . '/elementor.php' ) ) {
			require $elementor . '/elementor.php';
		}
		require dirname( __DIR__ ) . '/ai-page-designer.php';
	}
);

// Install the plugin for each test run.
tests_add_filter(
	'setup_theme',
	static function () {
		\AIPageDesigner\Core\Installer::install();
	}
);

require $aipd_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/TestCase.php';

if ( did_action( 'elementor/loaded' ) ) {
	// Elementor normally creates its default Kit on activation, and caches element
	// types that reference it; create it once before any test runs.
	set_transient( \Elementor\Api::TRANSIENT_KEY_PREFIX . ELEMENTOR_VERSION, array( 'pro_widgets' => array() ), DAY_IN_SECONDS );
	\Elementor\Core\Kits\Manager::create_default_kit();
}
