<?php
/**
 * Resolves section and button colors from design tokens.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\PageBuilders;

use AIPageDesigner\Schema\DesignTokens;

defined( 'ABSPATH' ) || exit;

/**
 * Shared color logic so every adapter produces the same, accessible result.
 */
final class Palette {

	/**
	 * Colors for a section background token.
	 *
	 * @param array  $tokens     Design tokens.
	 * @param string $background default|surface|primary|accent|dark.
	 * @return array{background:string,text:string,muted:string,is_brand:bool}
	 */
	public static function section( array $tokens, $background ) {
		$c = $tokens['colors'];
		switch ( $background ) {
			case 'surface':
				$bg    = $c['surface'];
				$text  = $c['text'];
				$muted = $c['text_muted'];
				break;
			case 'primary':
				$bg    = $c['primary'];
				$text  = $c['on_primary'];
				$muted = $c['on_primary'];
				break;
			case 'accent':
				$bg    = $c['accent'];
				$text  = $c['on_primary'];
				$muted = $c['on_primary'];
				break;
			case 'dark':
				$bg    = DesignTokens::luminance( $c['text'] ) < 0.2 ? $c['text'] : '#111827';
				$text  = DesignTokens::readable_on( $c['background'], $bg );
				$muted = $text;
				break;
			default:
				$bg    = $c['background'];
				$text  = $c['text'];
				$muted = $c['text_muted'];
		}
		return array(
			'background' => $bg,
			'text'       => DesignTokens::readable_on( $text, $bg ),
			'muted'      => DesignTokens::readable_on( $muted, $bg ),
			'is_brand'   => in_array( $background, array( 'primary', 'accent', 'dark' ), true ),
		);
	}

	/**
	 * Colors for a button variant placed on a section.
	 *
	 * @param array  $tokens  Design tokens.
	 * @param array  $section Result of section().
	 * @param string $variant primary|secondary|outline|link.
	 * @return array{background:string,text:string,outline:bool}
	 */
	public static function button( array $tokens, array $section, $variant ) {
		$c = $tokens['colors'];
		if ( 'outline' === $variant || 'link' === $variant ) {
			return array(
				'background' => '',
				'text'       => $section['is_brand'] ? $section['text'] : DesignTokens::readable_on( $c['primary'], $section['background'] ),
				'outline'    => true,
			);
		}
		if ( $section['is_brand'] ) {
			// Invert on brand-colored bands.
			return array(
				'background' => $section['text'],
				'text'       => DesignTokens::readable_on( $section['background'], $section['text'] ),
				'outline'    => false,
			);
		}
		$bg = 'secondary' === $variant ? $c['secondary'] : $c['primary'];
		return array(
			'background' => $bg,
			'text'       => DesignTokens::readable_on( $c['on_primary'], $bg ),
			'outline'    => false,
		);
	}

	/**
	 * Section padding in pixels.
	 *
	 * @param string $size xs..xl.
	 * @return int
	 */
	public static function padding( $size ) {
		return isset( DesignTokens::SPACING[ $size ] ) ? DesignTokens::SPACING[ $size ] : DesignTokens::SPACING['lg'];
	}

	/**
	 * Paragraph font size in pixels.
	 *
	 * @param array  $tokens Tokens.
	 * @param string $size   small|normal|large.
	 * @return int
	 */
	public static function font_size( array $tokens, $size ) {
		$base  = (int) $tokens['typography']['base_size'];
		$scale = (float) $tokens['typography']['scale'];
		if ( 'large' === $size ) {
			return (int) round( $base * $scale );
		}
		if ( 'small' === $size ) {
			return (int) round( $base / $scale );
		}
		return $base;
	}
}
