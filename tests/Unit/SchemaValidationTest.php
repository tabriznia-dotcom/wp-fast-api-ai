<?php
/**
 * Schema validation, sanitization and normalization tests.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Tests\Unit;

use AIPageDesigner\Schema\DesignTokens;
use AIPageDesigner\Schema\PageSchema;
use AIPageDesigner\Schema\SchemaService;
use AIPageDesigner\Schema\Validator;
use AIPageDesigner\Tests\TestCase;

/**
 * @covers \AIPageDesigner\Schema\SchemaService
 */
class SchemaValidationTest extends TestCase {

	/**
	 * Minimal valid input.
	 *
	 * @param array $components Components for the first section.
	 * @return array
	 */
	private function page( array $components ) {
		return array(
			'meta'     => array(
				'title'     => 'T',
				'language'  => 'en',
				'direction' => 'ltr',
				'page_type' => 'landing',
				'style'     => 'minimal',
			),
			'sections' => array(
				array(
					'id'      => 'hero',
					'type'    => 'hero',
					'columns' => array( array( 'components' => $components ) ),
				),
			),
		);
	}

	public function test_bundled_templates_are_valid() {
		foreach ( array( 'landing-page-en', 'landing-page-fa' ) as $name ) {
			$result = SchemaService::process( $this->template( $name ) );
			$this->assertIsArray( $result, $name );
			$this->assertEmpty( ( new Validator() )->validate( $result['schema'], PageSchema::definition() ), $name );
		}
	}

	public function test_persian_template_is_rtl_and_english_is_ltr() {
		$fa = SchemaService::process( $this->template( 'landing-page-fa' ) );
		$en = SchemaService::process( $this->template( 'landing-page-en' ) );
		$this->assertSame( 'rtl', $fa['schema']['meta']['direction'] );
		$this->assertSame( 'fa-IR', $fa['schema']['meta']['language'] );
		$this->assertSame( 'ltr', $en['schema']['meta']['direction'] );
	}

	public function test_direction_follows_language() {
		$data                      = $this->page( array( array( 'type' => 'paragraph', 'text' => 'x' ) ) );
		$data['meta']['language']  = 'ar';
		$data['meta']['direction'] = 'ltr';
		$result                    = SchemaService::process( $data );
		$this->assertSame( 'rtl', $result['schema']['meta']['direction'] );
	}

	public function test_invalid_json_text_is_rejected() {
		$this->assertWPError( SchemaService::from_text( 'not json at all' ) );
		$this->assertWPError( SchemaService::from_text( '{"meta": {"title": "cut off' ) );
		$this->assertWPError( SchemaService::from_text( '' ) );
	}

	public function test_json_inside_code_fence_is_extracted() {
		$json   = wp_json_encode( $this->page( array( array( 'type' => 'heading', 'level' => 1, 'text' => 'Hi' ) ) ) );
		$result = SchemaService::from_text( "Here you go:\n```json\n" . $json . "\n```" );
		$this->assertIsArray( $result );
	}

	public function test_incomplete_schema_without_sections_is_rejected() {
		$result = SchemaService::process( array( 'meta' => array( 'title' => 'x' ) ) );
		$this->assertWPError( $result );
		$this->assertSame( 'aipd_schema_invalid', $result->get_error_code() );
	}

	public function test_unknown_components_and_properties_are_removed() {
		$result     = SchemaService::process(
			$this->page(
				array(
					array( 'type' => 'php', 'code' => '<?php echo 1; ?>' ),
					array( 'type' => 'html', 'html' => '<script>alert(1)</script>' ),
					array( 'type' => 'paragraph', 'text' => 'ok', 'onclick' => 'x' ),
				)
			)
		);
		$components = $result['schema']['sections'][0]['columns'][0]['components'];
		$this->assertCount( 1, $components );
		$this->assertArrayNotHasKey( 'onclick', $components[0] );
		$this->assertNotEmpty( $result['warnings'] );
	}

	public function test_scripts_and_event_handlers_are_stripped_from_text() {
		$result = SchemaService::process(
			$this->page(
				array(
					array( 'type' => 'paragraph', 'text' => 'Hi <script>alert(1)</script><img src=x onerror=alert(1)><a href="javascript:alert(1)" onclick="x">l</a> <strong>ok</strong>' ),
				)
			)
		);
		$text   = $result['schema']['sections'][0]['columns'][0]['components'][0]['text'];
		$this->assertStringNotContainsString( '<script', $text );
		$this->assertStringNotContainsString( 'onerror', $text );
		$this->assertStringNotContainsString( 'onclick', $text );
		$this->assertStringNotContainsString( 'javascript:', $text );
		$this->assertStringNotContainsString( '<img', $text );
		$this->assertStringContainsString( '<strong>ok</strong>', $text );
	}

	public function test_dangerous_links_become_safe() {
		$result = SchemaService::process(
			$this->page(
				array(
					array(
						'type'  => 'buttons',
						'items' => array(
							array( 'text' => 'a', 'url' => 'javascript:alert(1)' ),
							array( 'text' => 'b', 'url' => 'data:text/html,<script>' ),
							array( 'text' => 'c', 'url' => '//evil.example/x' ),
							array( 'text' => 'd', 'url' => '/../../wp-config.php' ),
							array( 'text' => 'e', 'url' => 'https://example.com/ok' ),
						),
					),
				)
			)
		);
		$items  = $result['schema']['sections'][0]['columns'][0]['components'][0]['items'];
		$this->assertCount( 3, $items, 'maxItems 3 truncates the list' );
		$this->assertSame( '#', $items[0]['url'] );
		$this->assertSame( '#', $items[1]['url'] );
		$this->assertSame( '#', $items[2]['url'] );
	}

	public function test_invalid_enum_values_fall_back_to_defaults() {
		$data                              = $this->page( array( array( 'type' => 'paragraph', 'text' => 'x', 'size' => 'gigantic' ) ) );
		$data['sections'][0]['background'] = 'url(javascript:1)';
		$result                            = SchemaService::process( $data );
		$this->assertSame( 'normal', $result['schema']['sections'][0]['columns'][0]['components'][0]['size'] );
		$this->assertSame( 'default', $result['schema']['sections'][0]['background'] );
	}

	public function test_invalid_colors_use_preset_values() {
		$data                  = $this->page( array( array( 'type' => 'paragraph', 'text' => 'x' ) ) );
		$data['design_tokens'] = array( 'colors' => array( 'primary' => 'red; background:url(x)', 'text' => '#abc' ) );
		$result                = SchemaService::process( $data );
		$colors                = $result['schema']['design_tokens']['colors'];
		$this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', $colors['primary'] );
		foreach ( $colors as $color ) {
			$this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', $color );
		}
	}

	public function test_low_contrast_colors_are_fixed() {
		$data                  = $this->page( array( array( 'type' => 'paragraph', 'text' => 'x' ) ) );
		$data['design_tokens'] = array(
			'colors' => array(
				'text'       => '#eeeeee',
				'background' => '#ffffff',
				'primary'    => '#ffff00',
				'on_primary' => '#ffffff',
			),
		);
		$colors                = SchemaService::process( $data )['schema']['design_tokens']['colors'];
		$this->assertGreaterThanOrEqual( 4.5, DesignTokens::contrast( $colors['text'], $colors['background'] ) );
		$this->assertGreaterThanOrEqual( 4.5, DesignTokens::contrast( $colors['on_primary'], $colors['primary'] ) );
	}

	public function test_heading_hierarchy_is_repaired() {
		$result = SchemaService::process(
			$this->page(
				array(
					array( 'type' => 'heading', 'level' => 3, 'text' => 'First' ),
					array( 'type' => 'heading', 'level' => 1, 'text' => 'Second H1' ),
					array( 'type' => 'heading', 'level' => 5, 'text' => 'Skip' ),
				)
			)
		);
		$levels = wp_list_pluck( $result['schema']['sections'][0]['columns'][0]['components'], 'level' );
		$this->assertSame( array( 1, 2, 3 ), $levels );
	}

	public function test_duplicate_section_ids_are_made_unique() {
		$data               = $this->page( array( array( 'type' => 'paragraph', 'text' => 'x' ) ) );
		$data['sections'][] = $data['sections'][0];
		$result             = SchemaService::process( $data );
		$this->assertNotSame( $result['schema']['sections'][0]['id'], $result['schema']['sections'][1]['id'] );
	}

	public function test_missing_image_alt_is_flagged() {
		$result = SchemaService::process( $this->page( array( array( 'type' => 'image', 'alt' => '' ) ) ) );
		$image  = $result['schema']['sections'][0]['columns'][0]['components'][0];
		$this->assertTrue( $image['decorative'] );
		$this->assertNotEmpty( preg_grep( '/alt text/', $result['warnings'] ) );
	}

	public function test_long_strings_are_truncated() {
		$result = SchemaService::process( $this->page( array( array( 'type' => 'heading', 'level' => 1, 'text' => str_repeat( 'a', 5000 ) ) ) ) );
		$this->assertLessThanOrEqual( 200, mb_strlen( $result['schema']['sections'][0]['columns'][0]['components'][0]['text'] ) );
	}

	public function test_too_many_sections_are_truncated() {
		$data             = $this->page( array( array( 'type' => 'paragraph', 'text' => 'x' ) ) );
		$data['sections'] = array_fill( 0, 30, $data['sections'][0] );
		$result           = SchemaService::process( $data );
		$this->assertCount( 12, $result['schema']['sections'] );
	}

	public function test_flat_component_list_is_wrapped_in_a_column() {
		$data = $this->page( array() );
		unset( $data['sections'][0]['columns'] );
		$data['sections'][0]['components'] = array( array( 'type' => 'paragraph', 'text' => 'flat' ) );
		$result                            = SchemaService::process( $data );
		$this->assertSame( 'flat', $result['schema']['sections'][0]['columns'][0]['components'][0]['text'] );
	}

	public function test_oversized_client_schema_is_rejected() {
		$data                        = $this->page( array() );
		$data['meta']['description'] = str_repeat( 'x', SchemaService::MAX_BYTES + 10 );
		$this->assertWPError( SchemaService::from_client( $data ) );
	}

	public function test_validator_detects_violations() {
		$errors = ( new Validator() )->validate(
			array(
				'schema_version' => '1.0',
				'meta'           => array( 'title' => 1 ),
				'design_tokens'  => array(),
				'sections'       => array(),
				'extra'          => true,
			),
			PageSchema::definition()
		);
		$this->assertNotEmpty( $errors );
		$this->assertNotEmpty( preg_grep( '/unknown property "extra"/', $errors ) );
	}

	public function test_validate_filter_can_reject() {
		add_filter(
			'aipd_validate_schema',
			static function ( $errors ) {
				$errors[] = 'custom rule';
				return $errors;
			}
		);
		$this->assertWPError( SchemaService::process( $this->page( array( array( 'type' => 'paragraph', 'text' => 'x' ) ) ) ) );
	}

	public function test_schema_json_export_is_valid_json() {
		$this->assertIsArray( json_decode( PageSchema::to_json(), true ) );
	}
}
