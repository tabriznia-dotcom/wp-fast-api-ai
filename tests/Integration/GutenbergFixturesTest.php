<?php
/**
 * Writes block markup fixtures consumed by the JavaScript block validation test.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Tests\Integration;

use AIPageDesigner\PageBuilders\Gutenberg\GutenbergAdapter;
use AIPageDesigner\Schema\SchemaService;
use AIPageDesigner\Tests\Fixtures;
use AIPageDesigner\Tests\TestCase;

/**
 * Generates tests/fixtures/*.html for tests/js/validate-blocks.test.js.
 */
class GutenbergFixturesTest extends TestCase {

	/**
	 * Writes fixtures.
	 */
	public function test_write_fixtures() {
		$adapter  = new GutenbergAdapter();
		$fixtures = array(
			'landing-en'      => $this->template( 'landing-page-en' ),
			'landing-fa'      => $this->template( 'landing-page-fa' ),
			'kitchen-sink-en' => Fixtures::kitchen_sink( 'en' ),
			'kitchen-sink-ar' => Fixtures::kitchen_sink( 'ar' ),
		);
		foreach ( $fixtures as $name => $data ) {
			$result = SchemaService::process( $data );
			$this->assertIsArray( $result, $name . ': ' . ( is_wp_error( $result ) ? wp_json_encode( $result->get_error_data() ) : '' ) );
			$markup = $adapter->render_page( $result['schema'] );
			$this->assertNotEmpty( $markup );
			file_put_contents( AIPD_DIR . 'tests/fixtures/' . $name . '.html', $markup ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}
}
