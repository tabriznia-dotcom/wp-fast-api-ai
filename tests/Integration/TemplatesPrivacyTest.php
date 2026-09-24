<?php
/**
 * Templates import/export, privacy tools and capability mapping.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Tests\Integration;

use AIPageDesigner\Core\Capabilities;
use AIPageDesigner\Core\Options;
use AIPageDesigner\Jobs\JobRepository;
use AIPageDesigner\Logging\Logger;
use AIPageDesigner\Privacy\Privacy;
use AIPageDesigner\Templates\TemplateRepository;
use AIPageDesigner\Tests\TestCase;

/**
 * @covers \AIPageDesigner\Templates\TemplateRepository
 * @covers \AIPageDesigner\Privacy\Privacy
 */
class TemplatesPrivacyTest extends TestCase {

	public function test_export_import_round_trip() {
		$this->login_admin();
		$export = TemplateRepository::export( 'builtin:landing-page-fa' );
		$this->assertSame( 'ai-page-designer-template', $export['format'] );
		$id = TemplateRepository::import_json( wp_json_encode( $export ) );
		$this->assertIsInt( $id );
		$schema = TemplateRepository::get( 'user:' . $id );
		$this->assertSame( 'rtl', $schema['meta']['direction'] );
	}

	public function test_import_rejects_invalid_content() {
		$this->login_admin();
		$this->assertWPError( TemplateRepository::import_json( 'not json' ) );
		$this->assertWPError( TemplateRepository::import_json( wp_json_encode( array( 'hello' => 'world' ) ) ) );
		$this->assertWPError( TemplateRepository::import_json( str_repeat( ' ', TemplateRepository::MAX_BYTES + 1 ) ) );
	}

	public function test_import_upload_validates_file() {
		$tmp = wp_tempnam( 'aipd' );
		file_put_contents( $tmp, '{"a":1}' ); // phpcs:ignore
		$base = array(
			'tmp_name' => $tmp,
			'size'     => 7,
			'error'    => UPLOAD_ERR_OK,
		);
		// Not a real HTTP upload.
		$this->assertSame( 'aipd_upload_failed', TemplateRepository::import_upload( $base + array( 'name' => 'a.json' ) )->get_error_code() );
		$this->assertSame( 'aipd_upload_failed', TemplateRepository::import_upload( array( 'error' => UPLOAD_ERR_INI_SIZE ) )->get_error_code() );
		unlink( $tmp ); // phpcs:ignore
	}

	public function test_imported_template_values_are_sanitized() {
		$this->login_admin();
		$data = $this->template( 'landing-page-en' );
		$data['sections'][0]['columns'][0]['components'][0]['text'] = '<script>x()</script>Title';
		$data['sections'][0]['label']                               = '"><img src=x onerror=alert(1)>';
		$id     = TemplateRepository::import_json( wp_json_encode( $data ) );
		$schema = TemplateRepository::get( 'user:' . $id );
		$json   = wp_json_encode( $schema );
		$this->assertStringNotContainsString( '<script', $json );
		$this->assertStringNotContainsString( 'onerror', $json );
	}

	public function test_template_path_traversal_is_not_possible() {
		$this->assertWPError( TemplateRepository::get( 'builtin:../../wp-config' ) );
		$this->assertWPError( TemplateRepository::get( '/etc/passwd' ) );
		$this->assertWPError( TemplateRepository::get( 'user:999999' ) );
	}

	public function test_privacy_exporter_and_eraser() {
		$user_id = self::factory()->user->create( array( 'user_email' => 'person@example.com' ) );
		wp_set_current_user( $user_id );
		JobRepository::create( wp_generate_uuid4(), $user_id, 'outline', array( 'brief' => array( 'business' => 'Bakery' ) ), array( 'title' => 'My page' ) );
		Options::update_settings( array( 'logs_enabled' => true, 'log_level' => 'info' ) );
		Logger::info( 'test', 'hello' );

		$export = Privacy::export( 'person@example.com' );
		$this->assertCount( 1, $export['data'] );
		$this->assertStringContainsString( 'Bakery', wp_json_encode( $export['data'] ) );

		$erase = Privacy::erase( 'person@example.com' );
		$this->assertTrue( $erase['items_removed'] );
		$this->assertCount( 0, Privacy::export( 'person@example.com' )['data'] );
		$this->assertSame( array(), Logger::for_user( $user_id ) );
	}

	public function test_privacy_hooks_registered() {
		$this->assertArrayHasKey( 'ai-page-designer', apply_filters( 'wp_privacy_personal_data_exporters', array() ) );
		$this->assertArrayHasKey( 'ai-page-designer', apply_filters( 'wp_privacy_personal_data_erasers', array() ) );
		$this->assertStringContainsString( 'does not collect data from site visitors', Privacy::policy_text() );
	}

	public function test_logging_can_be_disabled() {
		Options::update_settings( array( 'logs_enabled' => false ) );
		Logger::error( 'x', 'should not be stored' );
		$this->assertSame( 0, Logger::query()['total'] );
	}

	public function test_retention_purges_old_history() {
		$uuid = wp_generate_uuid4();
		JobRepository::create( $uuid, 1, 'outline', array() );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'aipd_jobs', array( 'created_at' => '2000-01-01 00:00:00' ), array( 'uuid' => $uuid ) ); // phpcs:ignore
		$this->assertSame( 1, JobRepository::purge() );
	}

	public function test_multisite_settings_capability_mapping() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->assertTrue( current_user_can( Capabilities::MANAGE ) );
		if ( ! is_multisite() ) {
			$this->assertSame( array( 'x' ), Capabilities::map_meta_cap( array( 'x' ), Capabilities::MANAGE ) );
			return;
		}
		add_filter( 'aipd_site_admins_can_manage_settings', '__return_false' );
		$this->assertFalse( current_user_can( Capabilities::MANAGE ), 'Site admins lose settings access when the network restricts it' );
		grant_super_admin( $admin );
		$this->assertTrue( current_user_can( Capabilities::MANAGE ) );
	}

	public function test_admin_role_gets_caps_and_editor_does_not() {
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::GENERATE ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( Capabilities::GENERATE ) );
	}
}
