<?php
/**
 * PSR-4 autoloader for the plugin namespace.
 *
 * The plugin ships its own autoloader so that it never needs Composer on the
 * production server. Composer is used for development tooling only.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner;

defined( 'ABSPATH' ) || exit;

/**
 * Maps AIPageDesigner\Foo\Bar to includes/Foo/Bar.php.
 */
final class Autoloader {

	/**
	 * Namespace prefix handled by this loader.
	 */
	const PREFIX = 'AIPageDesigner\\';

	/**
	 * Registers the autoloader once.
	 *
	 * @return void
	 */
	public static function register() {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		spl_autoload_register( array( __CLASS__, 'load' ) );
		$registered = true;
	}

	/**
	 * Loads a class file if it belongs to the plugin namespace.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function load( $class_name ) {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );

		// Only allow plain namespace segments; never build paths from anything else.
		if ( ! preg_match( '/^[A-Za-z0-9_\\\\]+$/', $relative ) ) {
			return;
		}

		$file = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
