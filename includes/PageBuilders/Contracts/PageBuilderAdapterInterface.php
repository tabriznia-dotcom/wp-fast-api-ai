<?php
/**
 * Contract for page builder adapters.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\PageBuilders\Contracts;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Adapters convert a trusted Page Schema into a builder's native format.
 * They are registered on the `aipd_register_page_builders` action.
 */
interface PageBuilderAdapterInterface {

	/**
	 * Unique adapter id.
	 *
	 * @return string
	 */
	public function get_id();

	/**
	 * Translated display name.
	 *
	 * @return string
	 */
	public function get_name();

	/**
	 * Whether the builder is installed and active. Must never trigger a fatal error.
	 *
	 * @return bool
	 */
	public function is_available();

	/**
	 * Component types this adapter renders natively. Others are rendered with fallbacks.
	 *
	 * @return string[]
	 */
	public function get_supported_components();

	/**
	 * Builder-specific validation of a trusted schema.
	 *
	 * @param array $schema Page Schema.
	 * @return true|WP_Error
	 */
	public function validate_schema( array $schema );

	/**
	 * Renders the schema to the builder's storage format.
	 *
	 * @param array $schema Page Schema.
	 * @return mixed Block markup string, Elementor element array or HTML.
	 */
	public function render_page( array $schema );

	/**
	 * Creates a draft page. Never publishes.
	 *
	 * @param array $schema Page Schema.
	 * @param array $args   Optional: post_type, parent, title.
	 * @return int|WP_Error Post id.
	 */
	public function create_draft( array $schema, array $args = array() );

	/**
	 * Replaces a single section in an existing post created by this adapter.
	 *
	 * @param int    $post_id Post id.
	 * @param array  $schema  Full updated Page Schema containing the section.
	 * @param string $section_id Section id to replace.
	 * @return true|WP_Error
	 */
	public function update_section( $post_id, array $schema, $section_id );

	/**
	 * URL to edit the post with this builder.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public function get_edit_url( $post_id );
}
