<?php
/**
 * Elementor mapping tests (no Elementor required).
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Tests\Unit;

use AIPageDesigner\PageBuilders\Elementor\ElementorAdapter;
use AIPageDesigner\PageBuilders\Elementor\ElementorMapper;
use AIPageDesigner\Schema\SchemaService;
use AIPageDesigner\Tests\Integration\GutenbergFixturesTest;
use AIPageDesigner\Tests\TestCase;

require_once dirname( __DIR__ ) . '/Integration/GutenbergFixturesTest.php';

/**
 * @covers \AIPageDesigner\PageBuilders\Elementor\ElementorMapper
 */
class ElementorMapperTest extends TestCase {

	/**
	 * Collects all elements recursively.
	 *
	 * @param array $elements Elements.
	 * @return array
	 */
	private function flatten( array $elements ) {
		$out = array();
		foreach ( $elements as $element ) {
			$out[] = $element;
			$out   = array_merge( $out, $this->flatten( $element['elements'] ) );
		}
		return $out;
	}

	/**
	 * Widget types in a flat element list.
	 *
	 * @param array $all Elements.
	 * @return string[]
	 */
	private function widget_types( array $all ) {
		$types = array();
		foreach ( $all as $element ) {
			if ( isset( $element['widgetType'] ) ) {
				$types[] = $element['widgetType'];
			}
		}
		return $types;
	}

	public function test_container_structure_and_ids() {
		$schema   = SchemaService::process( $this->template( 'landing-page-en' ) )['schema'];
		$elements = ( new ElementorMapper( $schema, true ) )->elements();
		$this->assertCount( count( $schema['sections'] ), $elements );
		$all = $this->flatten( $elements );
		$ids = wp_list_pluck( $all, 'id' );
		$this->assertSame( count( $ids ), count( array_unique( $ids ) ), 'Element ids are unique' );
		foreach ( $all as $element ) {
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{7}$/', $element['id'] );
			$this->assertContains( $element['elType'], array( 'container', 'widget' ) );
		}
		$this->assertSame( 'hero', $elements[0]['settings']['_element_id'] );
		$this->assertFalse( $elements[0]['isInner'] );
		$this->assertSame( 'section', $elements[0]['settings']['html_tag'] );
		$types = $this->widget_types( $all );
		$this->assertContains( 'heading', $types );
		$this->assertContains( 'button', $types );
		$this->assertNotContains( 'price-table', $types, 'Pro widgets are not used without Elementor Pro' );
		$this->assertNotContains( 'form', $types );
	}

	public function test_legacy_sections_and_columns() {
		$schema   = SchemaService::process( $this->template( 'landing-page-en' ) )['schema'];
		$elements = ( new ElementorMapper( $schema, false ) )->elements();
		$this->assertSame( 'section', $elements[0]['elType'] );
		$this->assertSame( 'column', $elements[0]['elements'][0]['elType'] );
		// Features has an intro: section > column > [intro widgets..., inner section > 3 columns].
		$wrapper = $elements[1]['elements'][0];
		$this->assertSame( 'column', $wrapper['elType'] );
		$inner = end( $wrapper['elements'] );
		$this->assertSame( 'section', $inner['elType'] );
		$this->assertTrue( $inner['isInner'] );
		$this->assertCount( 3, $inner['elements'] );
		$this->assertSame( 'heading', $wrapper['elements'][0]['widgetType'] );
	}

	public function test_rtl_alignment_is_mirrored() {
		$schema   = SchemaService::process( $this->template( 'landing-page-fa' ) )['schema'];
		$elements = $this->flatten( ( new ElementorMapper( $schema, true ) )->elements() );
		$aligns   = array();
		foreach ( $elements as $element ) {
			if ( isset( $element['widgetType'] ) && 'heading' === $element['widgetType'] && isset( $element['settings']['align'] ) ) {
				$aligns[] = $element['settings']['align'];
			}
		}
		$this->assertContains( 'right', $aligns );
		$this->assertNotContains( 'left', $aligns );
	}

	public function test_pro_widgets_used_only_when_available() {
		$schema = SchemaService::process( GutenbergFixturesTest::kitchen_sink( 'en' ) )['schema'];
		$types  = $this->widget_types( $this->flatten( ( new ElementorMapper( $schema, true, array( 'form', 'price-table' ) ) )->elements() ) );
		$this->assertContains( 'form', $types );
		$this->assertContains( 'price-table', $types );
	}

	public function test_output_is_json_serializable_and_has_no_shortcodes() {
		$schema = SchemaService::process( GutenbergFixturesTest::kitchen_sink( 'en' ) )['schema'];
		$json   = wp_json_encode( ( new ElementorMapper( $schema, true ) )->elements() );
		$this->assertIsString( $json );
		$this->assertStringNotContainsString( '[gallery]', $json );
	}

	public function test_adapter_is_unavailable_without_elementor_and_does_not_fatal() {
		if ( did_action( 'elementor/loaded' ) ) {
			$this->markTestSkipped( 'Elementor is loaded in this run.' );
		}
		$adapter = new ElementorAdapter();
		$this->assertFalse( $adapter->is_available() );
		$this->login_admin();
		$result = $adapter->create_draft( SchemaService::process( $this->template( 'landing-page-en' ) )['schema'] );
		$this->assertWPError( $result );
		$this->assertSame( 'aipd_builder_unavailable', $result->get_error_code() );
	}
}
