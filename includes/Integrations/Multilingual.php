<?php
/**
 * Optional integrations with multilingual plugins.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Assigns the content language of generated drafts in WPML or Polylang
 * through their public APIs. TranslatePress translates rendered output and
 * needs no per-post language. None of these plugins is required.
 */
final class Multilingual {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'aipd_after_create_draft', array( __CLASS__, 'assign_language' ), 10, 2 );
	}

	/**
	 * Active multilingual plugin, if any.
	 *
	 * @return string wpml|polylang|translatepress|''.
	 */
	public static function active_plugin() {
		if ( function_exists( 'pll_set_post_language' ) ) {
			return 'polylang';
		}
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return 'wpml';
		}
		if ( class_exists( 'TRP_Translate_Press' ) ) {
			return 'translatepress';
		}
		return '';
	}

	/**
	 * Sets the post language when the language exists in the multilingual plugin.
	 *
	 * @param int   $post_id Post id.
	 * @param array $schema  Page Schema.
	 * @return void
	 */
	public static function assign_language( $post_id, array $schema ) {
		$language = strtolower( (string) strtok( $schema['meta']['language'], '-' ) );
		$plugin   = self::active_plugin();

		if ( 'polylang' === $plugin && function_exists( 'pll_languages_list' ) ) {
			$languages = (array) pll_languages_list( array( 'fields' => 'slug' ) );
			if ( in_array( $language, $languages, true ) ) {
				pll_set_post_language( $post_id, $language );
			}
			return;
		}

		if ( 'wpml' === $plugin ) {
			$active = apply_filters(
				'wpml_active_languages', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML public API.
				null,
				array( 'skip_missing' => 0 )
			);
			if ( is_array( $active ) && isset( $active[ $language ] ) ) {
				do_action(
					'wpml_set_element_language_details', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML public API.
					array(
						'element_id'    => $post_id,
						'element_type'  => apply_filters( 'wpml_element_type', get_post_type( $post_id ) ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML public API.
						'trid'          => false,
						'language_code' => $language,
					)
				);
			}
		}
	}
}
