<?php
/**
 * Elementor adapter.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\PageBuilders\Elementor;

use AIPageDesigner\PageBuilders\AbstractAdapter;
use AIPageDesigner\PageBuilders\Classic\HtmlRenderer;
use AIPageDesigner\Schema\PageSchema;
use AIPageDesigner\Security\Kses;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Creates Elementor drafts through Elementor's public Documents API.
 *
 * All Elementor classes are referenced only after is_available() confirms
 * Elementor is loaded, so the plugin never fatals without Elementor.
 */
class ElementorAdapter extends AbstractAdapter {

	const ID = 'elementor';

	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return self::ID;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Elementor', 'ai-page-designer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return did_action( 'elementor/loaded' ) && class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance ) && is_object( \Elementor\Plugin::$instance );
	}

	/**
	 * Whether Elementor Pro is active.
	 *
	 * @return bool
	 */
	public static function has_pro() {
		return defined( 'ELEMENTOR_PRO_VERSION' ) || did_action( 'elementor_pro/init' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_supported_components() {
		return PageSchema::COMPONENT_TYPES;
	}

	/**
	 * Whether flexbox containers are enabled.
	 *
	 * @return bool
	 */
	public function uses_containers() {
		if ( ! $this->is_available() ) {
			return true;
		}
		$plugin = \Elementor\Plugin::$instance;
		if ( isset( $plugin->experiments ) && is_object( $plugin->experiments ) && method_exists( $plugin->experiments, 'is_feature_active' ) ) {
			return (bool) $plugin->experiments->is_feature_active( 'container' );
		}
		return false;
	}

	/**
	 * Registered widget types that may be used conditionally (Pro widgets).
	 *
	 * @return string[]
	 */
	public function available_optional_widgets() {
		if ( ! $this->is_available() || ! self::has_pro() ) {
			return array();
		}
		$out     = array();
		$manager = isset( \Elementor\Plugin::$instance->widgets_manager ) ? \Elementor\Plugin::$instance->widgets_manager : null;
		foreach ( array( 'form', 'price-table' ) as $type ) {
			if ( $manager && method_exists( $manager, 'get_widget_types' ) && $manager->get_widget_types( $type ) ) {
				$out[] = $type;
			}
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $schema Page Schema.
	 */
	public function render_page( array $schema ) {
		return ( new ElementorMapper( $schema, $this->uses_containers(), $this->available_optional_widgets() ) )->elements();
	}

	/**
	 * Plain HTML copy in post_content (shown if Elementor is later deactivated).
	 *
	 * @param array $schema Schema.
	 * @return string
	 */
	protected function post_content( array $schema ) {
		return Kses::page( ( new HtmlRenderer( $schema ) )->page( false ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int   $post_id Post id.
	 * @param array $schema  Page Schema.
	 */
	protected function after_insert( $post_id, array $schema ) {
		return $this->save_elements( $post_id, $this->render_page( $schema ) );
	}

	/**
	 * Saves elements via the Documents API, with a documented-meta fallback.
	 *
	 * @param int   $post_id  Post id.
	 * @param array $elements Elements.
	 * @return true|WP_Error
	 */
	private function save_elements( $post_id, array $elements ) {
		$plugin   = \Elementor\Plugin::$instance;
		$document = ( isset( $plugin->documents ) && method_exists( $plugin->documents, 'get' ) ) ? $plugin->documents->get( $post_id, false ) : null;

		if ( $document && method_exists( $document, 'save' ) ) {
			if ( method_exists( $document, 'set_is_built_with_elementor' ) ) {
				$document->set_is_built_with_elementor( true );
			}
			$saved = $document->save(
				array(
					'elements' => $elements,
					'settings' => array( 'post_status' => 'draft' ),
				)
			);
			if ( false === $saved ) {
				return new WP_Error( 'aipd_elementor_save_failed', __( 'Elementor could not save the page. Check that you can edit it with Elementor.', 'ai-page-designer' ), array( 'status' => 500 ) );
			}
		} else {
			update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
			update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );
			update_post_meta( $post_id, '_elementor_data', wp_slash( (string) wp_json_encode( $elements ) ) );
			if ( defined( 'ELEMENTOR_VERSION' ) ) {
				update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION );
			}
		}

		// Elementor's plain-text save may publish nothing, but make sure status stays draft.
		if ( 'draft' !== get_post_status( $post_id ) ) {
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'draft',
				)
			);
		}
		return true;
	}

	/**
	 * Current Elementor elements for a post.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	private function current_elements( $post_id ) {
		$raw  = get_post_meta( $post_id, '_elementor_data', true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		return is_array( $data ) ? $data : array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $post_id    Post id.
	 * @param array  $schema     Page Schema.
	 * @param string $section_id Section id.
	 */
	public function update_section( $post_id, array $schema, $section_id ) {
		$allowed = $this->can_update( $post_id );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( ! $this->is_available() ) {
			return new WP_Error( 'aipd_builder_unavailable', __( 'Elementor is not active.', 'ai-page-designer' ), array( 'status' => 400 ) );
		}

		$section = null;
		foreach ( $schema['sections'] as $candidate ) {
			if ( $candidate['id'] === $section_id ) {
				$section = $candidate;
			}
		}
		if ( null === $section ) {
			return new WP_Error( 'aipd_section_not_found', __( 'The section could not be found.', 'ai-page-designer' ), array( 'status' => 404 ) );
		}

		$elements = $this->current_elements( $post_id );
		$found    = false;
		foreach ( $elements as $index => $element ) {
			if ( isset( $element['settings']['_element_id'] ) && $element['settings']['_element_id'] === $section_id ) {
				$elements[ $index ] = ( new ElementorMapper( $schema, $this->uses_containers(), $this->available_optional_widgets() ) )->section( $section );
				$found              = true;
				break;
			}
		}
		if ( ! $found ) {
			return new WP_Error( 'aipd_section_missing_in_content', __( 'The section was removed or renamed in Elementor, so it cannot be replaced automatically.', 'ai-page-designer' ), array( 'status' => 409 ) );
		}

		$result = $this->save_elements( $post_id, $elements );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$this->store_meta( $post_id, $schema );
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post id.
	 */
	public function get_edit_url( $post_id ) {
		if ( $this->is_available() ) {
			$document = \Elementor\Plugin::$instance->documents->get( $post_id );
			if ( $document && method_exists( $document, 'get_edit_url' ) ) {
				return (string) $document->get_edit_url();
			}
		}
		return (string) add_query_arg(
			array(
				'post'   => (int) $post_id,
				'action' => 'elementor',
			),
			admin_url( 'post.php' )
		);
	}
}
