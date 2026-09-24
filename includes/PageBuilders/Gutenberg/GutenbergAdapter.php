<?php
/**
 * Block editor adapter.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\PageBuilders\Gutenberg;

use AIPageDesigner\PageBuilders\AbstractAdapter;
use AIPageDesigner\Schema\PageSchema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the page as core block markup in post_content.
 */
class GutenbergAdapter extends AbstractAdapter {

	const ID = 'gutenberg';

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
		return __( 'Block editor (Gutenberg)', 'ai-page-designer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return function_exists( 'serialize_blocks' ) && function_exists( 'use_block_editor_for_post_type' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_supported_components() {
		return PageSchema::COMPONENT_TYPES;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $schema Page Schema.
	 */
	public function render_page( array $schema ) {
		return ( new BlockSerializer( $schema ) )->page();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $schema Page Schema.
	 */
	protected function post_content( array $schema ) {
		return $this->render_page( $schema );
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

		$replacement = ( new BlockSerializer( $schema ) )->section( $section_id );
		if ( null === $replacement ) {
			return new WP_Error( 'aipd_section_not_found', __( 'The section could not be found.', 'ai-page-designer' ), array( 'status' => 404 ) );
		}

		$post     = get_post( $post_id );
		$blocks   = parse_blocks( $post->post_content );
		$replaced = self::replace_by_anchor( $blocks, $section_id, $replacement, 0 );
		if ( ! $replaced ) {
			return new WP_Error( 'aipd_section_missing_in_content', __( 'The section was removed or renamed in the editor, so it cannot be replaced automatically.', 'ai-page-designer' ), array( 'status' => 409 ) );
		}

		// wp_update_post() creates a revision, so the previous version can be restored.
		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post_id,
					'post_content' => serialize_blocks( $blocks ),
				)
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'aipd_update_failed', __( 'The page could not be updated.', 'ai-page-designer' ), array( 'status' => 500 ) );
		}
		$this->store_meta( $post_id, $schema );
		return true;
	}

	/**
	 * Replaces the first group block whose anchor matches, searching two levels deep.
	 *
	 * @param array  $blocks      Parsed blocks (by reference).
	 * @param string $anchor      Anchor.
	 * @param array  $replacement Replacement block.
	 * @param int    $depth       Current depth.
	 * @return bool
	 */
	private static function replace_by_anchor( array &$blocks, $anchor, array $replacement, $depth ) {
		foreach ( $blocks as $index => $block ) {
			if ( 'core/group' === $block['blockName'] && isset( $block['attrs']['anchor'] ) && $block['attrs']['anchor'] === $anchor ) {
				$blocks[ $index ] = $replacement;
				return true;
			}
			if ( $depth < 2 && ! empty( $block['innerBlocks'] ) ) {
				$inner = $block['innerBlocks'];
				if ( self::replace_by_anchor( $inner, $anchor, $replacement, $depth + 1 ) ) {
					$blocks[ $index ]['innerBlocks'] = $inner;
					return true;
				}
			}
		}
		return false;
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
