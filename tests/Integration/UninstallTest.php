<?php
/**
 * Uninstall behavior.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Tests\Integration;

use AIPageDesigner\Core\Installer;
use AIPageDesigner\Core\Options;
use AIPageDesigner\PageBuilders\Gutenberg\GutenbergAdapter;
use AIPageDesigner\Schema\SchemaService;
use AIPageDesigner\Templates\TemplateRepository;
use AIPageDesigner\Tests\TestCase;

/**
 * @coversNothing
 */
class UninstallTest extends TestCase {

	public function set_up() {
		parent::set_up();
		// Let DROP TABLE reach the database instead of the test suite's temporary-table rewrite.
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
	}

	public function tear_down() {
		Installer::install();
		parent::tear_down();
	}

	private function table_exists( $table ) {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore
	}

	public function test_uninstall_respects_user_choice_and_only_touches_own_data() {
		global $wpdb;
		$this->login_admin();
		$this->configure_provider();
		update_option( 'unrelated_option', 'keep' );
		$page_id     = ( new GutenbergAdapter() )->create_draft( SchemaService::process( $this->template( 'landing-page-en' ) )['schema'] );
		$template_id = TemplateRepository::save( 'Mine', $this->template( 'landing-page-en' ) );
		set_transient( 'aipd_rl_1_test', 3, HOUR_IN_SECONDS );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', AIPD_BASENAME );
		}

		// 1. Default: keep data, remove capabilities.
		Options::update_settings( array( 'delete_data_on_uninstall' => false ) );
		include AIPD_DIR . 'uninstall.php';
		$this->assertNotFalse( get_option( Options::SETTINGS ) );
		$this->assertTrue( $this->table_exists( $wpdb->prefix . 'aipd_jobs' ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( 'aipd_manage_settings' ) );

		// 2. Opted in: remove plugin data only.
		Options::update_settings( array( 'delete_data_on_uninstall' => true ) );
		aipd_uninstall_site();
		$this->assertFalse( get_option( Options::SETTINGS ) );
		$this->assertFalse( get_option( Options::PROVIDERS ) );
		$this->assertFalse( $this->table_exists( $wpdb->prefix . 'aipd_jobs' ) );
		$this->assertFalse( $this->table_exists( $wpdb->prefix . 'aipd_logs' ) );
		$this->assertNull( get_post( $template_id ) );
		wp_cache_flush(); // Uninstall runs in a fresh request; drop this request's cache.
		$this->assertFalse( get_transient( 'aipd_rl_1_test' ) );

		// Generated pages and unrelated data are preserved.
		$this->assertSame( 'draft', get_post_status( $page_id ) );
		$this->assertNotEmpty( get_post( $page_id )->post_content );
		$this->assertSame( '', get_post_meta( $page_id, '_aipd_schema', true ) );
		$this->assertSame( 'keep', get_option( 'unrelated_option' ) );
		$this->assertNotFalse( get_option( 'siteurl' ) );
	}

	public function test_deactivation_clears_cron_but_keeps_data() {
		Installer::install();
		$this->assertNotFalse( wp_next_scheduled( Installer::CRON_CLEANUP ) );
		Installer::deactivate();
		$this->assertFalse( wp_next_scheduled( Installer::CRON_CLEANUP ) );
		$this->assertNotFalse( get_option( Options::DB_VER ) );
	}
}
