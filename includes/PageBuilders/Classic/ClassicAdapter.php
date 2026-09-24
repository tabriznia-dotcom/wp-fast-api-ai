<?php
/**
 * Classic editor / HTML fallback adapter.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\PageBuilders\Classic;

use AIPageDesigner\PageBuilders\AbstractAdapter;
use AIPageDesigner\Schema\PageSchema;
use AIPageDesigner\Security\Kses;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Stores sanitized semantic HTML. Works with any editor or theme.
 */
class ClassicAdapter extends AbstractAdapter {

	const ID = 'classic';

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
		return __( 'Classic editor / HTML', 'ai-page-designer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return true;
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
		return self::clean( ( new HtmlRenderer( $schema ) )->page( true ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $schema Page Schema.
	 */
	protected function post_content( array $schema ) {
		// Raw HTML opens as a single "Classic" block in the block editor.
		return $this->render_page( $schema );
	}

	/**
	 * Sanitizes HTML and keeps section markers.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function clean( $html ) {
		return Kses::page( $html );
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
		$section = null;
		foreach ( $schema['sections'] as $candidate ) {
			if ( $candidate['id'] === $section_id ) {
				$section = $candidate;
			}
		}
		if ( null === $section ) {
			return new WP_Error( 'aipd_section_not_found', __( 'The section could not be found.', 'ai-page-designer' ), array( 'status' => 404 ) );
		}

		$post    = get_post( $post_id );
		$id      = preg_quote( $section_id, '/' );
		$pattern = '/\s*<!-- aipd:section ' . $id . ' -->.*?<!-- \/aipd:section ' . $id . ' -->\s*/s';
		if ( ! preg_match( $pattern, $post->post_content ) ) {
			return new WP_Error( 'aipd_section_missing_in_content', __( 'The section was removed or renamed in the editor, so it cannot be replaced automatically.', 'ai-page-designer' ), array( 'status' => 409 ) );
		}

		$html    = self::clean( ( new HtmlRenderer( $schema ) )->section_html( $section, true ) );
		$content = preg_replace_callback(
			$pattern,
			function () use ( $html ) {
				return $html;
			},
			$post->post_content,
			1
		);

		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post_id,
					'post_content' => $content,
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
}
