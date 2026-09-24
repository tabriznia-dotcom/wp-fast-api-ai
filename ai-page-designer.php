<?php
/**
 * Plugin Name:       AI Page Designer
 * Plugin URI:        https://github.com/tabriznia-dotcom/wp-fast-api-ai
 * Description:       Turn a written brief into a validated page schema and a draft page for the block editor, Elementor or classic HTML, using an AI provider you choose.
 * Version:           1.1.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            AI Page Designer contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-page-designer
 * Domain Path:       /languages
 *
 * @package AIPageDesigner
 */

defined( 'ABSPATH' ) || exit;

define( 'AIPD_VERSION', '1.1.0' );
define( 'AIPD_DB_VERSION', '1' );
define( 'AIPD_FILE', __FILE__ );
define( 'AIPD_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIPD_URL', plugin_dir_url( __FILE__ ) );
define( 'AIPD_BASENAME', plugin_basename( __FILE__ ) );
define( 'AIPD_MIN_PHP', '7.4' );
define( 'AIPD_MIN_WP', '6.6' );

if ( version_compare( PHP_VERSION, AIPD_MIN_PHP, '<' ) || version_compare( get_bloginfo( 'version' ), AIPD_MIN_WP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: Minimum PHP version, 2: Minimum WordPress version. */
						__( 'AI Page Designer requires PHP %1$s and WordPress %2$s or newer. The plugin is inactive until the requirements are met.', 'ai-page-designer' ),
						AIPD_MIN_PHP,
						AIPD_MIN_WP
					)
				)
			);
		}
	);
	return;
}

require_once AIPD_DIR . 'includes/Autoloader.php';
\AIPageDesigner\Autoloader::register();

register_activation_hook( __FILE__, array( \AIPageDesigner\Core\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \AIPageDesigner\Core\Installer::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \AIPageDesigner\Core\Plugin::class, 'boot' ) );
