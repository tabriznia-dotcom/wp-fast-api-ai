<?php
/**
 * HTML allowlists used for generated content.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Wrappers around wp_kses() with strict allowlists.
 */
final class Kses {

	/**
	 * Inline markup allowed inside text fields produced by the model.
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function inline_tags() {
		$tags = array(
			'strong' => array(),
			'em'     => array(),
			'b'      => array(),
			'i'      => array(),
			'br'     => array(),
			'code'   => array(),
			'mark'   => array(),
			's'      => array(),
			'sub'    => array(),
			'sup'    => array(),
			'a'      => array(
				'href'  => true,
				'title' => true,
				'rel'   => true,
			),
		);

		/**
		 * Filters inline HTML allowed in generated text. Script, style and event
		 * attributes are always stripped regardless of this filter.
		 *
		 * @since 1.0.0
		 *
		 * @param array $tags wp_kses allowlist.
		 */
		return self::strip_dangerous( (array) apply_filters( 'aipd_inline_allowed_html', $tags ) );
	}

	/**
	 * Cleans inline text: allowlisted tags only, safe link protocols, no shortcodes.
	 *
	 * @param string $text Raw text.
	 * @param int    $max  Maximum length in characters.
	 * @return string
	 */
	public static function inline( $text, $max = 2000 ) {
		$text = self::strip_code_blocks( (string) $text );
		$text = wp_kses( $text, self::inline_tags(), array( 'http', 'https', 'mailto', 'tel' ) );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
		if ( function_exists( 'mb_substr' ) && mb_strlen( $text ) > $max ) {
			$text = force_balance_tags( mb_substr( $text, 0, $max ) );
		}
		return $text;
	}

	/**
	 * Plain text only.
	 *
	 * @param string $text Raw text.
	 * @param int    $max  Maximum length.
	 * @return string
	 */
	public static function plain( $text, $max = 500 ) {
		$text = sanitize_text_field( wp_strip_all_tags( (string) $text ) );
		if ( function_exists( 'mb_substr' ) ) {
			$text = mb_substr( $text, 0, $max );
		}
		return $text;
	}

	/**
	 * Removes script/style elements including their contents.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function strip_code_blocks( $html ) {
		return (string) preg_replace( '@<(script|style|template|noscript)[^>]*?>.*?</\\1\s*>@si', '', $html );
	}

	/**
	 * Escapes plain text for HTML output and neutralizes shortcodes.
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	public static function text( $text ) {
		return self::neutralize_shortcodes( esc_html( (string) $text ) );
	}

	/**
	 * Neutralizes shortcodes in already sanitized inline HTML for output.
	 *
	 * @param string $html Sanitized inline HTML.
	 * @return string
	 */
	public static function html( $html ) {
		return self::neutralize_shortcodes( (string) $html );
	}

	/**
	 * Replaces square brackets so no shortcode can ever be executed from model output.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function neutralize_shortcodes( $text ) {
		return str_replace( array( '[', ']' ), array( '&#091;', '&#093;' ), (string) $text );
	}

	/**
	 * Allowlist for the HTML fallback renderer output.
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function page_tags() {
		$common = array(
			'class'           => true,
			'id'              => true,
			'style'           => true,
			'dir'             => true,
			'lang'            => true,
			'role'            => true,
			'aria-label'      => true,
			'aria-hidden'     => true,
			'aria-labelledby' => true,
		);
		$tags   = array();
		foreach ( array( 'div', 'section', 'header', 'footer', 'main', 'article', 'aside', 'nav', 'figure', 'figcaption', 'blockquote', 'cite', 'p', 'span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'details', 'summary', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'strong', 'em', 'b', 'i', 'br', 'code', 'mark', 's', 'sub', 'sup', 'small', 'dl', 'dt', 'dd', 'label' ) as $tag ) {
			$tags[ $tag ] = $common;
		}
		$tags['a']     = array_merge(
			$common,
			array(
				'href'  => true,
				'rel'   => true,
				'title' => true,
			)
		);
		$tags['img']   = array_merge(
			$common,
			array(
				'src'      => true,
				'alt'      => true,
				'width'    => true,
				'height'   => true,
				'loading'  => true,
				'decoding' => true,
			)
		);
		$tags['th']    = array_merge( $common, array( 'scope' => true ) );
		$tags['label'] = array_merge( $common, array( 'for' => true ) );

		/**
		 * Filters the allowlist for HTML fallback output.
		 *
		 * @since 1.0.0
		 *
		 * @param array $tags wp_kses allowlist.
		 */
		return self::strip_dangerous( (array) apply_filters( 'aipd_page_allowed_html', $tags ) );
	}

	/**
	 * Cleans full-page HTML from the fallback renderer.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function page( $html ) {
		return self::neutralize_shortcodes( wp_kses( self::strip_code_blocks( (string) $html ), self::page_tags(), array( 'http', 'https', 'mailto', 'tel' ) ) );
	}

	/**
	 * Removes tags and attributes that must never be allowed, even via filters.
	 *
	 * @param array $tags Allowlist.
	 * @return array
	 */
	private static function strip_dangerous( array $tags ) {
		foreach ( array( 'script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'link', 'meta', 'base', 'svg', 'math' ) as $blocked ) {
			unset( $tags[ $blocked ] );
		}
		foreach ( $tags as $tag => $attributes ) {
			if ( ! is_array( $attributes ) ) {
				$tags[ $tag ] = array();
				continue;
			}
			foreach ( array_keys( $attributes ) as $attribute ) {
				if ( 0 === stripos( (string) $attribute, 'on' ) || in_array( strtolower( (string) $attribute ), array( 'srcdoc', 'formaction', 'xlink:href' ), true ) ) {
					unset( $tags[ $tag ][ $attribute ] );
				}
			}
		}
		return $tags;
	}
}
