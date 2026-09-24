<?php
/**
 * Elementor integration (runs only when Elementor is loaded, see AIPD_TEST_ELEMENTOR_DIR).
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Tests\Integration;

use AIPageDesigner\PageBuilders\Elementor\ElementorAdapter;
use AIPageDesigner\Schema\SchemaService;
use AIPageDesigner\Tests\TestCase;

/**
 * @group elementor
 * @covers \AIPageDesigner\PageBuilders\Elementor\ElementorAdapter
 */
class ElementorIntegrationTest extends TestCase {

	public function set_up() {
		parent::set_up();
		if ( ! did_action( 'elementor/loaded' ) ) {
			$this->markTestSkipped( 'Elementor is not loaded. Set AIPD_TEST_ELEMENTOR_DIR to run these tests.' );
		}
		// Offline test run: seed Elementor's remote info cache (see also tests/bootstrap.php).
		set_transient( \Elementor\Api::TRANSIENT_KEY_PREFIX . ELEMENTOR_VERSION, array( 'pro_widgets' => array() ), HOUR_IN_SECONDS );
		// WP_UnitTestCase deletes all posts after each test class, including Elementor's Kit.
		$kit = get_option( 'elementor_active_kit' );
		if ( ! $kit || ! get_post( $kit ) ) {
			delete_option( 'elementor_active_kit' );
			\Elementor\Core\Kits\Manager::create_default_kit();
		}
	}

	public function test_draft_is_editable_with_elementor() {
		$this->login_admin();
		$adapter = new ElementorAdapter();
		$this->assertTrue( $adapter->is_available() );

		$schema  = SchemaService::process( $this->template( 'landing-page-fa' ) )['schema'];
		$post_id = $adapter->create_draft( $schema );
		$this->assertIsInt( $post_id, is_wp_error( $post_id ) ? $post_id->get_error_message() : '' );
		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$this->assertSame( 'builder', get_post_meta( $post_id, '_elementor_edit_mode', true ) );

		$data = json_decode( get_post_meta( $post_id, '_elementor_data', true ), true );
		$this->assertIsArray( $data );
		$this->assertCount( count( $schema['sections'] ), $data );

		$document = \Elementor\Plugin::$instance->documents->get( $post_id, false );
		$this->assertTrue( $document->is_built_with_elementor() );
		$this->assertTrue( $document->is_editable_by_current_user() );
		$this->assertStringContainsString( 'action=elementor', $adapter->get_edit_url( $post_id ) );

		// Section replacement keeps the rest.
		$schema['sections'][0]['columns'][0]['components'][0]['text'] = 'عنوان جدید';
		$this->assertTrue( $adapter->update_section( $post_id, $schema, 'hero' ) );
		$this->assertStringContainsString( 'عنوان جدید', wp_json_encode( json_decode( get_post_meta( $post_id, '_elementor_data', true ), true ), JSON_UNESCAPED_UNICODE ) );
	}

	public function test_elements_render_without_errors() {
		$this->login_admin();
		$adapter = new ElementorAdapter();
		$post_id = $adapter->create_draft( SchemaService::process( $this->template( 'landing-page-en' ) )['schema'] );
		$html    = \Elementor\Plugin::$instance->frontend->get_builder_content( $post_id, false );
		$this->assertStringContainsString( 'Launch faster', $html );
		$this->assertStringContainsString( 'id="hero"', $html );
	}
}
