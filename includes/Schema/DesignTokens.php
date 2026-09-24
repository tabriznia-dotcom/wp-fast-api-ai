<?php
/**
 * Design token presets, font stacks and color utilities.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Design token system shared by all page builder adapters.
 */
final class DesignTokens {

	const STYLES = array( 'corporate', 'minimal', 'creative', 'luxury', 'saas', 'ecommerce', 'editorial' );

	const SPACING = array(
		'xs' => 16,
		'sm' => 32,
		'md' => 56,
		'lg' => 80,
		'xl' => 112,
	);

	/**
	 * Human-readable style names.
	 *
	 * @return array<string,string>
	 */
	public static function style_labels() {
		return array(
			'corporate' => __( 'Corporate', 'ai-page-designer' ),
			'minimal'   => __( 'Minimal', 'ai-page-designer' ),
			'creative'  => __( 'Creative', 'ai-page-designer' ),
			'luxury'    => __( 'Luxury', 'ai-page-designer' ),
			'saas'      => __( 'SaaS', 'ai-page-designer' ),
			'ecommerce' => __( 'E-commerce', 'ai-page-designer' ),
			'editorial' => __( 'Editorial', 'ai-page-designer' ),
		);
	}

	/**
	 * Font choices. No font file is ever loaded by the plugin: these are local
	 * system stacks or references to fonts the active theme already provides.
	 *
	 * @return array<string,array{label:string,stack:string}>
	 */
	public static function fonts() {
		$fonts = array(
			'theme'        => array(
				'label' => __( 'Theme default', 'ai-page-designer' ),
				'stack' => 'inherit',
			),
			'system-sans'  => array(
				'label' => __( 'System sans-serif', 'ai-page-designer' ),
				'stack' => 'system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans", "Vazirmatn", Tahoma, Arial, sans-serif',
			),
			'system-serif' => array(
				'label' => __( 'System serif', 'ai-page-designer' ),
				'stack' => 'Charter, "Bitstream Charter", "Noto Serif", Georgia, "Times New Roman", serif',
			),
			'humanist'     => array(
				'label' => __( 'Humanist sans-serif', 'ai-page-designer' ),
				'stack' => 'Seravek, "Gill Sans Nova", Ubuntu, Calibri, "DejaVu Sans", "Vazirmatn", Tahoma, sans-serif',
			),
			'geometric'    => array(
				'label' => __( 'Geometric sans-serif', 'ai-page-designer' ),
				'stack' => 'Avenir, Montserrat, Corbel, "URW Gothic", "Vazirmatn", Tahoma, sans-serif',
			),
			'monospace'    => array(
				'label' => __( 'Monospace', 'ai-page-designer' ),
				'stack' => 'ui-monospace, "Cascadia Code", "Source Code Pro", Menlo, Consolas, monospace',
			),
		);

		foreach ( self::theme_font_families() as $slug => $name ) {
			$fonts[ 'theme-' . $slug ] = array(
				/* translators: %s: Font family name provided by the active theme. */
				'label' => sprintf( __( 'Theme font: %s', 'ai-page-designer' ), $name ),
				'stack' => 'var(--wp--preset--font-family--' . $slug . ')',
			);
		}

		/**
		 * Filters available font choices. Stacks must reference fonts that are
		 * already available; the plugin never downloads font files.
		 *
		 * @since 1.0.0
		 *
		 * @param array $fonts Font id => array( label, stack ).
		 */
		return (array) apply_filters( 'aipd_fonts', $fonts );
	}

	/**
	 * Font families registered by the active theme (theme.json).
	 *
	 * @return array<string,string> slug => name.
	 */
	public static function theme_font_families() {
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return array();
		}
		$families = wp_get_global_settings( array( 'typography', 'fontFamilies' ) );
		$out      = array();
		if ( is_array( $families ) ) {
			foreach ( array( 'theme', 'custom', 'default' ) as $origin ) {
				if ( empty( $families[ $origin ] ) || ! is_array( $families[ $origin ] ) ) {
					continue;
				}
				foreach ( $families[ $origin ] as $family ) {
					if ( isset( $family['slug'], $family['name'] ) ) {
						$out[ sanitize_title( $family['slug'] ) ] = sanitize_text_field( $family['name'] );
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Font stack for a font id.
	 *
	 * @param string $font_id Font id.
	 * @return string
	 */
	public static function font_stack( $font_id ) {
		$fonts = self::fonts();
		return isset( $fonts[ $font_id ] ) ? $fonts[ $font_id ]['stack'] : $fonts['system-sans']['stack'];
	}

	/**
	 * Default tokens for a design style.
	 *
	 * @param string $style Style id.
	 * @return array<string,mixed>
	 */
	public static function preset( $style ) {
		$base = array(
			'colors'          => array(
				'primary'    => '#1f4fd1',
				'secondary'  => '#0f766e',
				'accent'     => '#b45309',
				'text'       => '#111827',
				'text_muted' => '#4b5563',
				'background' => '#ffffff',
				'surface'    => '#f3f4f6',
				'on_primary' => '#ffffff',
			),
			'typography'      => array(
				'heading_font'   => 'system-sans',
				'body_font'      => 'system-sans',
				'base_size'      => 17,
				'scale'          => 1.25,
				'line_height'    => 1.6,
				'heading_weight' => 700,
			),
			'radius'          => 8,
			'shadow'          => 'soft',
			'section_spacing' => 'lg',
			'container_width' => 1140,
			'breakpoints'     => array(
				'mobile' => 480,
				'tablet' => 782,
			),
		);

		$presets = array(
			'minimal'   => array(
				'colors' => array(
					'primary'    => '#111827',
					'secondary'  => '#374151',
					'accent'     => '#2563eb',
					'surface'    => '#f9fafb',
					'on_primary' => '#ffffff',
				),
				'radius' => 4,
				'shadow' => 'none',
			),
			'creative'  => array(
				'colors'     => array(
					'primary'    => '#7c3aed',
					'secondary'  => '#db2777',
					'accent'     => '#0e7490',
					'surface'    => '#faf5ff',
					'on_primary' => '#ffffff',
				),
				'typography' => array(
					'heading_font' => 'geometric',
					'scale'        => 1.333,
				),
				'radius'     => 16,
			),
			'luxury'    => array(
				'colors'     => array(
					'primary'    => '#1c1917',
					'secondary'  => '#78350f',
					'accent'     => '#a16207',
					'text'       => '#1c1917',
					'text_muted' => '#57534e',
					'background' => '#fffdf8',
					'surface'    => '#f5f0e6',
					'on_primary' => '#fefce8',
				),
				'typography' => array(
					'heading_font'   => 'system-serif',
					'heading_weight' => 500,
					'scale'          => 1.333,
				),
				'radius'     => 0,
				'shadow'     => 'none',
			),
			'saas'      => array(
				'colors'     => array(
					'primary'    => '#4338ca',
					'secondary'  => '#0f766e',
					'accent'     => '#c2410c',
					'surface'    => '#eef2ff',
					'on_primary' => '#ffffff',
				),
				'typography' => array( 'heading_weight' => 800 ),
				'radius'     => 12,
				'shadow'     => 'medium',
			),
			'ecommerce' => array(
				'colors' => array(
					'primary'    => '#b91c1c',
					'secondary'  => '#1f2937',
					'accent'     => '#047857',
					'surface'    => '#fef2f2',
					'on_primary' => '#ffffff',
				),
				'radius' => 10,
			),
			'editorial' => array(
				'colors'          => array(
					'primary'    => '#0f172a',
					'secondary'  => '#9f1239',
					'accent'     => '#1d4ed8',
					'surface'    => '#f8fafc',
					'on_primary' => '#ffffff',
				),
				'typography'      => array(
					'heading_font' => 'system-serif',
					'body_font'    => 'system-serif',
					'base_size'    => 19,
					'line_height'  => 1.7,
				),
				'radius'          => 0,
				'shadow'          => 'none',
				'container_width' => 960,
			),
		);

		$tokens = isset( $presets[ $style ] ) ? self::merge( $base, $presets[ $style ] ) : $base;

		/**
		 * Filters default design tokens for a style preset.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $tokens Design tokens.
		 * @param string $style  Style id.
		 */
		return (array) apply_filters( 'aipd_design_tokens', $tokens, $style );
	}

	/**
	 * Applies brand kit colors and fonts on top of a preset.
	 *
	 * @param array $tokens    Tokens.
	 * @param array $brand_kit Brand kit option.
	 * @return array
	 */
	public static function apply_brand_kit( array $tokens, array $brand_kit ) {
		if ( ! empty( $brand_kit['colors'] ) && is_array( $brand_kit['colors'] ) ) {
			foreach ( array( 'primary', 'secondary', 'accent', 'text', 'background' ) as $key ) {
				$color = isset( $brand_kit['colors'][ $key ] ) ? self::sanitize_hex( $brand_kit['colors'][ $key ] ) : '';
				if ( '' !== $color ) {
					$tokens['colors'][ $key ] = $color;
				}
			}
		}
		foreach ( array(
			'font_heading' => 'heading_font',
			'font_body'    => 'body_font',
		) as $kit_key => $token_key ) {
			if ( ! empty( $brand_kit[ $kit_key ] ) && isset( self::fonts()[ $brand_kit[ $kit_key ] ] ) ) {
				$tokens['typography'][ $token_key ] = $brand_kit[ $kit_key ];
			}
		}
		return $tokens;
	}

	/**
	 * Recursive merge where later scalar values win.
	 *
	 * @param array $base     Base.
	 * @param array $override Override.
	 * @return array
	 */
	public static function merge( array $base, array $override ) {
		foreach ( $override as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
				$base[ $key ] = self::merge( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}

	/**
	 * Normalizes a hex color (#rgb or #rrggbb) to lowercase #rrggbb.
	 *
	 * @param string $color Raw color.
	 * @return string Empty when invalid.
	 */
	public static function sanitize_hex( $color ) {
		$color = strtolower( trim( (string) $color ) );
		if ( preg_match( '/^#([0-9a-f]{3})$/', $color, $m ) ) {
			return '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
		}
		return preg_match( '/^#[0-9a-f]{6}$/', $color ) ? $color : '';
	}

	/**
	 * WCAG relative luminance.
	 *
	 * @param string $hex Hex color.
	 * @return float
	 */
	public static function luminance( $hex ) {
		$hex = ltrim( self::sanitize_hex( $hex ), '#' );
		if ( '' === $hex ) {
			return 0.0;
		}
		$channels = array();
		foreach ( array( 0, 2, 4 ) as $offset ) {
			$c          = hexdec( substr( $hex, $offset, 2 ) ) / 255;
			$channels[] = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		}
		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}

	/**
	 * WCAG contrast ratio between two colors.
	 *
	 * @param string $a Color A.
	 * @param string $b Color B.
	 * @return float
	 */
	public static function contrast( $a, $b ) {
		$l1 = self::luminance( $a );
		$l2 = self::luminance( $b );
		return ( max( $l1, $l2 ) + 0.05 ) / ( min( $l1, $l2 ) + 0.05 );
	}

	/**
	 * Returns $preferred if it meets the ratio on $background, otherwise black or white.
	 *
	 * @param string $preferred  Preferred foreground.
	 * @param string $background Background.
	 * @param float  $ratio      Required ratio (4.5 for normal text in WCAG AA).
	 * @return string
	 */
	public static function readable_on( $preferred, $background, $ratio = 4.5 ) {
		if ( self::contrast( $preferred, $background ) >= $ratio ) {
			return $preferred;
		}
		return self::contrast( '#111111', $background ) >= self::contrast( '#ffffff', $background ) ? '#111111' : '#ffffff';
	}
}
