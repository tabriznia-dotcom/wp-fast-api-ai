<?php
/**
 * Shared draft creation logic for adapters.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\PageBuilders;

use AIPageDesigner\PageBuilders\Contracts\PageBuilderAdapterInterface;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Handles capability checks, draft insertion, schema meta and hooks.
 */
abstract class AbstractAdapter implements PageBuilderAdapterInterface {

	const META_SCHEMA    = '_aipd_schema';
	const META_BUILDER   = '_aipd_builder';
	const META_GENERATED = '_aipd_generated';
	const META_CSS       = '_aipd_css';

	/**
	 * Supported post types for generated drafts.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		/**
		 * Filters the post types a generated draft can be created as.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $types Post types. Default page and post.
		 */
		return array_values( array_filter( (array) apply_filters( 'aipd_post_types', array( 'page', 'post' ) ), 'post_type_exists' ) );
	}

	/**
	 * Content stored in post_content.
	 *
	 * @param array $schema Schema.
	 * @return string
	 */
	abstract protected function post_content( array $schema );

	/**
	 * Called after the post exists (for builder-specific meta).
	 *
	 * @param int   $post_id Post id.
	 * @param array $schema  Schema.
	 * @return true|WP_Error
	 */
	protected function after_insert( $post_id, array $schema ) {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $schema Page Schema.
	 */
	public function validate_schema( array $schema ) {
		if ( empty( $schema['sections'] ) ) {
			return new WP_Error( 'aipd_schema_empty', __( 'The page has no sections.', 'ai-page-designer' ) );
		}
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $schema Page Schema.
	 * @param array $args   Optional arguments.
	 */
	public function create_draft( array $schema, array $args = array() ) {
		if ( ! $this->is_available() ) {
			return new WP_Error( 'aipd_builder_unavailable', __( 'The selected page builder is not active.', 'ai-page-designer' ), array( 'status' => 400 ) );
		}

		$post_type = isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : 'page';
		if ( ! in_array( $post_type, self::post_types(), true ) ) {
			return new WP_Error( 'aipd_invalid_post_type', __( 'This content type is not supported.', 'ai-page-designer' ), array( 'status' => 400 ) );
		}
		$type_object = get_post_type_object( $post_type );
		if ( ! $type_object || ! current_user_can( $type_object->cap->create_posts ) ) {
			return new WP_Error( 'aipd_forbidden', __( 'You are not allowed to create this type of content.', 'ai-page-designer' ), array( 'status' => 403 ) );
		}

		$valid = $this->validate_schema( $schema );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		/**
		 * Filters draft arguments before a generated draft is created.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $args       Arguments: post_type, title, parent.
		 * @param array  $schema     Page Schema.
		 * @param string $builder_id Adapter id.
		 */
		$args = (array) apply_filters( 'aipd_before_create_draft', array_merge( array( 'post_type' => $post_type ), $args ), $schema, $this->get_id() );

		$title  = isset( $args['title'] ) && '' !== $args['title'] ? sanitize_text_field( $args['title'] ) : $schema['meta']['title'];
		$parent = isset( $args['parent'] ) ? absint( $args['parent'] ) : 0;
		if ( $parent && ! current_user_can( 'edit_post', $parent ) ) {
			$parent = 0;
		}

		$postarr = array(
			'post_type'    => $post_type,
			'post_status'  => 'draft', // Never published automatically.
			'post_title'   => $title,
			'post_content' => $this->post_content( $schema ),
			'post_excerpt' => $schema['meta']['description'],
			'post_author'  => get_current_user_id(),
			'post_parent'  => 'page' === $post_type ? $parent : 0,
		);

		$post_id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $post_id ) ) {
			return new WP_Error( 'aipd_insert_failed', __( 'The draft could not be created.', 'ai-page-designer' ), array( 'status' => 500 ) );
		}

		$this->store_meta( $post_id, $schema );
		$this->apply_page_template( $post_id, $post_type );

		$result = $this->after_insert( $post_id, $schema );
		if ( is_wp_error( $result ) ) {
			wp_delete_post( $post_id, true );
			return $result;
		}

		/**
		 * Fires after a generated draft is created.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $post_id    Post id.
		 * @param array  $schema     Page Schema.
		 * @param string $builder_id Adapter id.
		 */
		do_action( 'aipd_after_create_draft', $post_id, $schema, $this->get_id() );

		return (int) $post_id;
	}

	/**
	 * Uses a theme page template without a title when available, because the
	 * generated hero already contains the page's only H1.
	 *
	 * @param int    $post_id   Post id.
	 * @param string $post_type Post type.
	 * @return void
	 */
	protected function apply_page_template( $post_id, $post_type ) {
		$templates = wp_get_theme()->get_page_templates( null, $post_type );
		$chosen    = '';
		foreach ( array_keys( $templates ) as $slug ) {
			if ( false !== strpos( (string) $slug, 'no-title' ) ) {
				$chosen = (string) $slug;
				break;
			}
		}

		/**
		 * Filters the page template assigned to generated drafts.
		 *
		 * @since 1.0.0
		 *
		 * @param string $chosen    Template slug, or '' for the theme default.
		 * @param array  $templates Available templates (slug => name).
		 * @param string $builder   Adapter id.
		 */
		$chosen = (string) apply_filters( 'aipd_page_template', $chosen, $templates, $this->get_id() );
		if ( '' !== $chosen && isset( $templates[ $chosen ] ) ) {
			update_post_meta( $post_id, '_wp_page_template', $chosen );
		}
	}

	/**
	 * Stores schema, builder and scoped CSS in post meta.
	 *
	 * @param int   $post_id Post id.
	 * @param array $schema  Schema.
	 * @return void
	 */
	protected function store_meta( $post_id, array $schema ) {
		update_post_meta( $post_id, self::META_SCHEMA, wp_slash( (string) wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );
		update_post_meta( $post_id, self::META_BUILDER, $this->get_id() );
		update_post_meta( $post_id, self::META_GENERATED, '1' );
		update_post_meta( $post_id, self::META_CSS, wp_slash( StyleSheet::for_schema( $schema ) ) );
	}

	/**
	 * Reads the stored schema of a generated post.
	 *
	 * @param int $post_id Post id.
	 * @return array|null
	 */
	public static function stored_schema( $post_id ) {
		$raw = get_post_meta( $post_id, self::META_SCHEMA, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Common checks before updating a post.
	 *
	 * @param int $post_id Post id.
	 * @return true|WP_Error
	 */
	protected function can_update( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'aipd_forbidden', __( 'You are not allowed to edit this page.', 'ai-page-designer' ), array( 'status' => 403 ) );
		}
		if ( get_post_meta( $post_id, self::META_BUILDER, true ) !== $this->get_id() ) {
			return new WP_Error( 'aipd_builder_mismatch', __( 'This page was not created with the selected page builder.', 'ai-page-designer' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post id.
	 */
	public function get_edit_url( $post_id ) {
		return (string) get_edit_post_link( $post_id, 'raw' );
	}
}
