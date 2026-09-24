<?php
/**
 * Plugin bootstrap and service wiring.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Core;

use AIPageDesigner\Admin\Admin;
use AIPageDesigner\AI\AIService;
use AIPageDesigner\AI\ProviderRegistry;
use AIPageDesigner\API\RestController;
use AIPageDesigner\Integrations\Multilingual;
use AIPageDesigner\Jobs\JobRepository;
use AIPageDesigner\Jobs\JobRunner;
use AIPageDesigner\Logging\Logger;
use AIPageDesigner\PageBuilders\AbstractAdapter;
use AIPageDesigner\PageBuilders\AdapterRegistry;
use AIPageDesigner\Privacy\Privacy;
use AIPageDesigner\Templates\TemplateRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton holding shared services.
 */
final class Plugin {

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Providers.
	 *
	 * @var ProviderRegistry
	 */
	public $providers;

	/**
	 * Page builder adapters.
	 *
	 * @var AdapterRegistry
	 */
	public $builders;

	/**
	 * AI service.
	 *
	 * @var AIService
	 */
	public $ai;

	/**
	 * Job runner.
	 *
	 * @var JobRunner
	 */
	public $runner;

	/**
	 * Returns the instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->providers = new ProviderRegistry();
		$this->builders  = new AdapterRegistry();
		$this->ai        = new AIService( $this->providers );
		$this->runner    = new JobRunner( $this->ai, $this->providers );
	}

	/**
	 * Hooked on plugins_loaded.
	 *
	 * @return void
	 */
	public static function boot() {
		$plugin = self::instance();

		Capabilities::init();
		Privacy::init();
		Multilingual::init();
		Frontend::init();

		add_action( 'init', array( $plugin, 'on_init' ) );
		add_action( 'rest_api_init', array( $plugin, 'register_rest' ) );
		add_action( 'admin_init', array( Installer::class, 'maybe_upgrade' ) );
		add_action( 'wp_initialize_site', array( $plugin, 'on_new_site' ), 20 );
		add_action( Installer::CRON_CLEANUP, array( $plugin, 'cleanup' ) );
		add_action( Installer::CRON_RUN_JOB, array( $plugin->runner, 'cron' ) );

		if ( is_admin() ) {
			( new Admin( $plugin ) )->init();
		}

		/**
		 * Fires when AI Page Designer has loaded. Use it to register integrations.
		 *
		 * @since 1.0.0
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'aipd_loaded', $plugin );
	}

	/**
	 * Registers post types and meta.
	 *
	 * @return void
	 */
	public function on_init() {
		// Bundled translations (fa_IR). Language packs from translate.wordpress.org in
		// wp-content/languages take precedence when installed.
		load_plugin_textdomain( 'ai-page-designer', false, dirname( AIPD_BASENAME ) . '/languages' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Ships translations that are not yet on translate.wordpress.org.

		TemplateRepository::register_post_type();

		foreach ( AbstractAdapter::post_types() as $post_type ) {
			register_post_meta(
				$post_type,
				AbstractAdapter::META_SCHEMA,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => false,
					'revisions_enabled' => post_type_supports( $post_type, 'revisions' ),
					'auth_callback'     => static function ( $allowed, $meta_key, $object_id ) {
						return current_user_can( 'edit_post', $object_id );
					},
				)
			);
		}
	}

	/**
	 * REST routes.
	 *
	 * @return void
	 */
	public function register_rest() {
		( new RestController( $this ) )->register_routes();
	}

	/**
	 * Installs tables on a newly created multisite site when network active.
	 *
	 * @param \WP_Site $site Site.
	 * @return void
	 */
	public function on_new_site( $site ) {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active_for_network( AIPD_BASENAME ) ) {
			switch_to_blog( $site->blog_id );
			Installer::install();
			restore_current_blog();
		}
	}

	/**
	 * Daily retention cleanup.
	 *
	 * @return void
	 */
	public function cleanup() {
		JobRepository::fail_stale();
		JobRepository::purge();
		Logger::purge( (int) Options::get( 'retention_days' ) );
	}
}
