<?php
/**
 * Admin menu, assets and screen routing.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Admin;

use AIPageDesigner\Core\Capabilities;
use AIPageDesigner\Core\Options;
use AIPageDesigner\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the admin UI. Assets load only on the plugin's own screens.
 */
final class Admin {

	const SLUG = 'aipd';

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Screen hook suffixes created by this plugin.
	 *
	 * @var string[]
	 */
	private $hooks = array();

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'plugin_action_links_' . AIPD_BASENAME, array( $this, 'action_links' ) );
		( new Actions( $this->plugin ) )->init();
	}

	/**
	 * Admin page definitions: slug => array( title, capability, renderer ).
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function pages() {
		return array(
			self::SLUG                => array( __( 'Dashboard', 'ai-page-designer' ), Capabilities::GENERATE, 'dashboard' ),
			self::SLUG . '-wizard'    => array( __( 'New Page', 'ai-page-designer' ), Capabilities::GENERATE, 'wizard' ),
			self::SLUG . '-templates' => array( __( 'Templates', 'ai-page-designer' ), Capabilities::GENERATE, 'templates' ),
			self::SLUG . '-brand'     => array( __( 'Brand Kit', 'ai-page-designer' ), Capabilities::MANAGE, 'brand_kit' ),
			self::SLUG . '-history'   => array( __( 'Generation History', 'ai-page-designer' ), Capabilities::GENERATE, 'history' ),
			self::SLUG . '-providers' => array( __( 'AI Providers', 'ai-page-designer' ), Capabilities::MANAGE, 'providers' ),
			self::SLUG . '-settings'  => array( __( 'API Settings', 'ai-page-designer' ), Capabilities::MANAGE, 'settings' ),
			self::SLUG . '-logs'      => array( __( 'Logs', 'ai-page-designer' ), Capabilities::MANAGE, 'logs' ),
			self::SLUG . '-privacy'   => array( __( 'Privacy', 'ai-page-designer' ), Capabilities::MANAGE, 'privacy' ),
			self::SLUG . '-tools'     => array( __( 'Import/Export', 'ai-page-designer' ), Capabilities::MANAGE, 'tools' ),
			self::SLUG . '-help'      => array( __( 'Help', 'ai-page-designer' ), Capabilities::GENERATE, 'help' ),
		);
	}

	/**
	 * Registers menu pages.
	 *
	 * @return void
	 */
	public function menu() {
		$screens = new Pages( $this->plugin );
		$pages   = $this->pages();

		$this->hooks[] = add_menu_page(
			__( 'AI Page Designer', 'ai-page-designer' ),
			__( 'AI Page Designer', 'ai-page-designer' ),
			Capabilities::GENERATE,
			self::SLUG,
			array( $screens, 'dashboard' ),
			'dashicons-layout',
			58
		);

		foreach ( $pages as $slug => $page ) {
			$this->hooks[] = add_submenu_page(
				self::SLUG,
				$page[0] . ' ‹ ' . __( 'AI Page Designer', 'ai-page-designer' ),
				$page[0],
				$page[1],
				$slug,
				array( $screens, $page[2] )
			);
		}
	}

	/**
	 * Enqueues assets on plugin screens only.
	 *
	 * @param string $hook Hook suffix.
	 * @return void
	 */
	public function assets( $hook ) {
		if ( ! in_array( $hook, $this->hooks, true ) ) {
			return;
		}

		wp_enqueue_style( 'aipd-admin', AIPD_URL . 'assets/css/admin.css', array( 'wp-components' ), AIPD_VERSION );

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing only.

		if ( self::SLUG . '-wizard' === $page ) {
			$this->enqueue_build( 'wizard' );
			wp_add_inline_script(
				'aipd-wizard',
				'window.aipdWizard = ' . wp_json_encode(
					array(
						'adminUrl'    => admin_url(),
						'providerUrl' => admin_url( 'admin.php?page=' . self::SLUG . '-providers' ),
						'privacyUrl'  => admin_url( 'admin.php?page=' . self::SLUG . '-privacy' ),
						'templateId'  => isset( $_GET['template'] ) ? sanitize_text_field( wp_unslash( $_GET['template'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preselection.
						'postId'      => isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preselection.
						'isRtl'       => is_rtl(),
					)
				) . ';',
				'before'
			);
		} else {
			$this->enqueue_build( 'admin' );
		}
	}

	/**
	 * Enqueues a built script with its generated dependency file.
	 *
	 * @param string $name Entry name.
	 * @return void
	 */
	private function enqueue_build( $name ) {
		$asset_file = AIPD_DIR . 'build/' . $name . '.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array( 'wp-api-fetch', 'wp-element', 'wp-components', 'wp-i18n' ),
			'version'      => AIPD_VERSION,
		);
		wp_enqueue_script( 'aipd-' . $name, AIPD_URL . 'build/' . $name . '.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( 'aipd-' . $name, 'ai-page-designer', AIPD_DIR . 'languages' );
		if ( file_exists( AIPD_DIR . 'build/' . $name . '.css' ) ) {
			wp_enqueue_style( 'aipd-' . $name, AIPD_URL . 'build/' . $name . '.css', array( 'wp-components' ), $asset['version'] );
		}
	}

	/**
	 * Plugin list action links.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		if ( Capabilities::can_manage() ) {
			array_unshift(
				$links,
				sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-providers' ) ), esc_html__( 'Settings', 'ai-page-designer' ) )
			);
		}
		return $links;
	}

	/**
	 * URL of a plugin screen.
	 *
	 * @param string $suffix Page suffix ('' for dashboard).
	 * @param array  $args   Query args.
	 * @return string
	 */
	public static function url( $suffix = '', array $args = array() ) {
		$slug = '' === $suffix ? self::SLUG : self::SLUG . '-' . $suffix;
		return add_query_arg( array_merge( array( 'page' => $slug ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Whether setup is complete.
	 *
	 * @param Plugin $plugin Plugin.
	 * @return array{privacy:bool,provider:bool}
	 */
	public static function setup_state( Plugin $plugin ) {
		$provider = $plugin->providers->active();
		return array(
			'privacy'  => (bool) Options::get( 'privacy_acknowledged' ),
			'provider' => $provider && $provider->is_configured(),
		);
	}
}
