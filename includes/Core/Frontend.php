<?php
/**
 * Front-end styles for generated pages.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Core;

use AIPageDesigner\PageBuilders\AbstractAdapter;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the small scoped stylesheet only on generated pages. No scripts,
 * fonts or third-party resources are loaded on the front end.
 */
final class Frontend {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		// Also style the block editor canvas, so direction and cards match the front end.
		add_action( 'enqueue_block_assets', array( __CLASS__, 'enqueue_editor' ) );
	}

	/**
	 * Adds the page CSS inside the block editor when editing a generated post.
	 *
	 * @return void
	 */
	public static function enqueue_editor() {
		if ( ! is_admin() ) {
			return;
		}
		$post = get_post();
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		self::add_css( $post->ID );
	}

	/**
	 * Enqueues inline CSS for the current singular generated page.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! is_singular() ) {
			return;
		}
		self::add_css( get_queried_object_id() );
	}

	/**
	 * Registers the inline stylesheet for a generated post.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	private static function add_css( $post_id ) {
		if ( ! $post_id || '1' !== get_post_meta( $post_id, AbstractAdapter::META_GENERATED, true ) ) {
			return;
		}
		$css = wp_strip_all_tags( (string) get_post_meta( $post_id, AbstractAdapter::META_CSS, true ) );
		$css = str_ireplace( '</style', '', $css );
		if ( '' === $css ) {
			return;
		}
		wp_register_style( 'aipd-page', false, array(), AIPD_VERSION );
		wp_enqueue_style( 'aipd-page' );
		wp_add_inline_style( 'aipd-page', $css );
	}
}
