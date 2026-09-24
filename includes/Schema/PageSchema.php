<?php
/**
 * The Page Schema: the only format the model is allowed to produce.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * JSON Schema (draft 2020-12 compatible subset) for generated pages.
 *
 * Custom annotation keywords:
 * - x-format: how the sanitizer cleans a string (plain, inline, link, color, id, lang, font).
 * - default:  value used when the field is missing or invalid.
 */
final class PageSchema {

	const VERSION = '1.0';

	const PAGE_TYPES = array( 'landing', 'services', 'about', 'contact', 'product', 'article' );

	const SECTION_TYPES = array( 'hero', 'features', 'content', 'testimonials', 'faq', 'pricing', 'cta', 'contact', 'stats', 'steps', 'gallery', 'team', 'logos' );

	const COMPONENT_TYPES = array( 'heading', 'paragraph', 'buttons', 'list', 'image', 'icon', 'testimonial', 'faq', 'pricing', 'form', 'stat', 'spacer', 'separator' );

	const BACKGROUNDS = array( 'default', 'surface', 'primary', 'accent', 'dark' );

	const SIZES = array( 'xs', 'sm', 'md', 'lg', 'xl' );

	/**
	 * Decorative glyphs available to the icon component (no icon fonts or remote SVGs).
	 */
	const ICONS = array(
		'check'    => '✓',
		'star'     => '★',
		'arrow'    => '→',
		'bolt'     => '⚡',
		'shield'   => '⛨',
		'heart'    => '♥',
		'globe'    => '◍',
		'clock'    => '◷',
		'chat'     => '✉',
		'phone'    => '☎',
		'spark'    => '✦',
		'diamond'  => '◆',
		'circle'   => '●',
		'plus'     => '+',
		'leaf'     => '❦',
		'sun'      => '☀',
		'flag'     => '⚑',
		'gear'     => '⚙',
		'pin'      => '⌖',
		'infinity' => '∞',
	);

	/**
	 * Cached definition.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $cache = null;

	/**
	 * Glyph for an icon with text presentation forced (U+FE0E), so emoji scripts
	 * and fonts do not swap it for a remote image or colored emoji.
	 *
	 * @param string $name Icon name.
	 * @return string
	 */
	public static function glyph( $name ) {
		return ( isset( self::ICONS[ $name ] ) ? self::ICONS[ $name ] : self::ICONS['circle'] ) . "\u{FE0E}";
	}

	/**
	 * Returns the full schema definition.
	 *
	 * @return array<string,mixed>
	 */
	public static function definition() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$color     = array(
			'type'     => 'string',
			'pattern'  => '^#[0-9a-f]{6}$',
			'x-format' => 'color',
		);
		$size_enum = array(
			'type' => 'string',
			'enum' => self::SIZES,
		);
		$align     = array(
			'type'    => 'string',
			'enum'    => array( 'start', 'center', 'end' ),
			'default' => 'start',
		);

		$button = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'text', 'url' ),
			'properties'           => array(
				'text'       => self::text( 1, 60, 'plain' ),
				'url'        => array(
					'type'      => 'string',
					'maxLength' => 2048,
					'x-format'  => 'link',
					'default'   => '#',
				),
				'variant'    => array(
					'type'    => 'string',
					'enum'    => array( 'primary', 'secondary', 'outline', 'link' ),
					'default' => 'primary',
				),
				'aria_label' => self::text( 0, 120, 'plain' ),
			),
		);

		$components = array(
			'heading'     => array(
				'required'   => array( 'level', 'text' ),
				'properties' => array(
					'level' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 6,
						'default' => 2,
					),
					'text'  => self::text( 1, 200, 'inline' ),
					'align' => $align,
				),
			),
			'paragraph'   => array(
				'required'   => array( 'text' ),
				'properties' => array(
					'text'  => self::text( 1, 2000, 'inline' ),
					'size'  => array(
						'type'    => 'string',
						'enum'    => array( 'small', 'normal', 'large' ),
						'default' => 'normal',
					),
					'align' => $align,
					'muted' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			),
			'buttons'     => array(
				'required'   => array( 'items' ),
				'properties' => array(
					'items' => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => 3,
						'items'    => $button,
					),
					'align' => $align,
				),
			),
			'list'        => array(
				'required'   => array( 'items' ),
				'properties' => array(
					'ordered' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'items'   => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => 20,
						'items'    => self::text( 1, 300, 'inline' ),
					),
				),
			),
			'image'       => array(
				'required'   => array( 'alt' ),
				'properties' => array(
					'source'        => array(
						'type'    => 'string',
						'enum'    => array( 'placeholder', 'media' ),
						'default' => 'placeholder',
					),
					'attachment_id' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
					'alt'           => self::text( 0, 250, 'plain' ),
					'decorative'    => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'caption'       => self::text( 0, 250, 'plain' ),
					'aspect'        => array(
						'type'    => 'string',
						'enum'    => array( '16/9', '4/3', '1/1', '3/4' ),
						'default' => '16/9',
					),
				),
			),
			'icon'        => array(
				'required'   => array( 'name' ),
				'properties' => array(
					'name'  => array(
						'type'    => 'string',
						'enum'    => array_keys( self::ICONS ),
						'default' => 'check',
					),
					'label' => self::text( 0, 80, 'plain' ),
				),
			),
			'testimonial' => array(
				'required'   => array( 'quote', 'author' ),
				'properties' => array(
					'quote'  => self::text( 1, 800, 'inline' ),
					'author' => self::text( 1, 100, 'plain' ),
					'role'   => self::text( 0, 120, 'plain' ),
				),
			),
			'faq'         => array(
				'required'   => array( 'items' ),
				'properties' => array(
					'items' => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => 12,
						'items'    => array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'required'             => array( 'question', 'answer' ),
							'properties'           => array(
								'question' => self::text( 1, 250, 'plain' ),
								'answer'   => self::text( 1, 1500, 'inline' ),
							),
						),
					),
				),
			),
			'pricing'     => array(
				'required'   => array( 'plans' ),
				'properties' => array(
					'plans' => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => 4,
						'items'    => array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'required'             => array( 'name', 'price' ),
							'properties'           => array(
								'name'        => self::text( 1, 60, 'plain' ),
								'price'       => self::text( 1, 30, 'plain' ),
								'period'      => self::text( 0, 30, 'plain' ),
								'description' => self::text( 0, 250, 'plain' ),
								'features'    => array(
									'type'     => 'array',
									'maxItems' => 12,
									'default'  => array(),
									'items'    => self::text( 1, 150, 'plain' ),
								),
								'cta'         => $button,
								'highlighted' => array(
									'type'    => 'boolean',
									'default' => false,
								),
							),
						),
					),
				),
			),
			'form'        => array(
				'required'   => array( 'fields' ),
				'properties' => array(
					'fields'      => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => 8,
						'items'    => array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'required'             => array( 'type', 'label' ),
							'properties'           => array(
								'type'     => array(
									'type'    => 'string',
									'enum'    => array( 'text', 'email', 'tel', 'textarea' ),
									'default' => 'text',
								),
								'label'    => self::text( 1, 80, 'plain' ),
								'required' => array(
									'type'    => 'boolean',
									'default' => false,
								),
							),
						),
					),
					'submit_text' => self::text( 1, 40, 'plain' ),
					'email'       => array(
						'type'      => 'string',
						'maxLength' => 254,
						'x-format'  => 'email',
						'default'   => '',
					),
				),
			),
			'stat'        => array(
				'required'   => array( 'value', 'label' ),
				'properties' => array(
					'value' => self::text( 1, 20, 'plain' ),
					'label' => self::text( 1, 80, 'plain' ),
				),
			),
			'spacer'      => array(
				'required'   => array(),
				'properties' => array(
					'size' => array_merge( $size_enum, array( 'default' => 'sm' ) ),
				),
			),
			'separator'   => array(
				'required'   => array(),
				'properties' => array(),
			),
		);

		$variants = array();
		foreach ( $components as $type => $component ) {
			$variants[] = array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array_merge( array( 'type' ), $component['required'] ),
				'properties'           => array_merge(
					array(
						'type' => array(
							'type'  => 'string',
							'const' => $type,
						),
					),
					$component['properties']
				),
			);
		}

		$definition = array(
			'$schema'              => 'https://json-schema.org/draft/2020-12/schema',
			'$id'                  => 'https://github.com/tabriznia-dotcom/wp-fast-api-ai/schema/page-schema-1.0.json',
			'title'                => 'AI Page Designer Page Schema',
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'schema_version', 'meta', 'design_tokens', 'sections' ),
			'properties'           => array(
				'schema_version' => array(
					'type'    => 'string',
					'const'   => self::VERSION,
					'default' => self::VERSION,
				),
				'meta'           => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'title', 'language', 'direction', 'page_type', 'style' ),
					'properties'           => array(
						'title'       => array_merge( self::text( 1, 120, 'plain' ), array( 'default' => __( 'Untitled page', 'ai-page-designer' ) ) ),
						'description' => self::text( 0, 300, 'plain' ),
						'language'    => array(
							'type'     => 'string',
							'pattern'  => '^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$',
							'x-format' => 'lang',
							'default'  => 'en',
						),
						'direction'   => array(
							'type'    => 'string',
							'enum'    => array( 'ltr', 'rtl' ),
							'default' => 'ltr',
						),
						'page_type'   => array(
							'type'    => 'string',
							'enum'    => self::PAGE_TYPES,
							'default' => 'landing',
						),
						'style'       => array(
							'type'    => 'string',
							'enum'    => DesignTokens::STYLES,
							'default' => 'corporate',
						),
					),
				),
				'design_tokens'  => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'colors', 'typography' ),
					'properties'           => array(
						'colors'          => array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'required'             => array( 'primary', 'text', 'background' ),
							'properties'           => array(
								'primary'    => $color,
								'secondary'  => $color,
								'accent'     => $color,
								'text'       => $color,
								'text_muted' => $color,
								'background' => $color,
								'surface'    => $color,
								'on_primary' => $color,
							),
						),
						'typography'      => array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'properties'           => array(
								'heading_font'   => array(
									'type'     => 'string',
									'pattern'  => '^[a-z0-9-]{2,60}$',
									'x-format' => 'font',
									'default'  => 'system-sans',
								),
								'body_font'      => array(
									'type'     => 'string',
									'pattern'  => '^[a-z0-9-]{2,60}$',
									'x-format' => 'font',
									'default'  => 'system-sans',
								),
								'base_size'      => array(
									'type'    => 'integer',
									'minimum' => 14,
									'maximum' => 22,
									'default' => 17,
								),
								'scale'          => array(
									'type'    => 'number',
									'minimum' => 1.1,
									'maximum' => 1.5,
									'default' => 1.25,
								),
								'line_height'    => array(
									'type'    => 'number',
									'minimum' => 1.2,
									'maximum' => 2,
									'default' => 1.6,
								),
								'heading_weight' => array(
									'type'    => 'integer',
									'enum'    => array( 400, 500, 600, 700, 800, 900 ),
									'default' => 700,
								),
							),
						),
						'radius'          => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 32,
							'default' => 8,
						),
						'shadow'          => array(
							'type'    => 'string',
							'enum'    => array( 'none', 'soft', 'medium' ),
							'default' => 'soft',
						),
						'section_spacing' => array_merge( $size_enum, array( 'default' => 'lg' ) ),
						'container_width' => array(
							'type'    => 'integer',
							'minimum' => 640,
							'maximum' => 1440,
							'default' => 1140,
						),
						'breakpoints'     => array(
							'type'                 => 'object',
							'additionalProperties' => false,
							'properties'           => array(
								'mobile' => array(
									'type'    => 'integer',
									'minimum' => 320,
									'maximum' => 600,
									'default' => 480,
								),
								'tablet' => array(
									'type'    => 'integer',
									'minimum' => 601,
									'maximum' => 1024,
									'default' => 782,
								),
							),
						),
					),
				),
				'sections'       => array(
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 12,
					'items'    => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'id', 'type', 'columns' ),
						'properties'           => array(
							'id'         => array(
								'type'     => 'string',
								'pattern'  => '^[a-z][a-z0-9-]{0,39}$',
								'x-format' => 'id',
							),
							'type'       => array(
								'type'    => 'string',
								'enum'    => self::SECTION_TYPES,
								'default' => 'content',
							),
							'label'      => self::text( 0, 80, 'plain' ),
							'background' => array(
								'type'    => 'string',
								'enum'    => self::BACKGROUNDS,
								'default' => 'default',
							),
							'align'      => $align,
							'padding'    => array_merge( $size_enum, array( 'default' => 'lg' ) ),
							'layout'     => array(
								'type'                 => 'object',
								'additionalProperties' => false,
								'properties'           => array(
									'stack_on_mobile' => array(
										'type'    => 'boolean',
										'default' => true,
									),
									'vertical_align'  => array(
										'type'    => 'string',
										'enum'    => array( 'top', 'center', 'bottom' ),
										'default' => 'top',
									),
								),
							),
							'intro'      => array(
								'type'     => 'array',
								'maxItems' => 4,
								'default'  => array(),
								'items'    => array(
									'oneOf'           => $variants,
									'x-discriminator' => 'type',
								),
							),
							'columns'    => array(
								'type'     => 'array',
								'minItems' => 1,
								'maxItems' => 4,
								'items'    => array(
									'type'                 => 'object',
									'additionalProperties' => false,
									'required'             => array( 'components' ),
									'properties'           => array(
										'width'      => array(
											'type'    => 'integer',
											'minimum' => 0,
											'maximum' => 100,
											'default' => 0,
										),
										'style'      => array(
											'type'    => 'string',
											'enum'    => array( 'plain', 'card' ),
											'default' => 'plain',
										),
										'components' => array(
											'type'     => 'array',
											'maxItems' => 20,
											'items'    => array(
												'oneOf' => $variants,
												'x-discriminator' => 'type',
											),
										),
									),
								),
							),
						),
					),
				),
			),
		);

		/**
		 * Filters the Page Schema definition. Adding components also requires
		 * adapter support through the aipd_render_component_* filters.
		 *
		 * @since 1.0.0
		 *
		 * @param array $definition JSON Schema as a PHP array.
		 */
		self::$cache = (array) apply_filters( 'aipd_page_schema_definition', $definition );

		return self::$cache;
	}

	/**
	 * Schema for the outline (structure proposal) step.
	 *
	 * @return array<string,mixed>
	 */
	public static function outline_definition() {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'sections' ),
			'properties'           => array(
				'sections' => array(
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 12,
					'items'    => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'id', 'type', 'label' ),
						'properties'           => array(
							'id'      => array(
								'type'     => 'string',
								'pattern'  => '^[a-z][a-z0-9-]{0,39}$',
								'x-format' => 'id',
							),
							'type'    => array(
								'type'    => 'string',
								'enum'    => self::SECTION_TYPES,
								'default' => 'content',
							),
							'label'   => self::text( 1, 80, 'plain' ),
							'purpose' => self::text( 0, 300, 'plain' ),
						),
					),
				),
				'notes'    => self::text( 0, 500, 'plain' ),
			),
		);
	}

	/**
	 * Clears the static definition cache (used by tests and filters).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$cache = null;
	}

	/**
	 * Text field definition helper.
	 *
	 * @param int    $min    Min length.
	 * @param int    $max    Max length.
	 * @param string $format x-format.
	 * @return array<string,mixed>
	 */
	private static function text( $min, $max, $format ) {
		$def = array(
			'type'      => 'string',
			'maxLength' => $max,
			'x-format'  => $format,
		);
		if ( $min > 0 ) {
			$def['minLength'] = $min;
		} else {
			$def['default'] = '';
		}
		return $def;
	}

	/**
	 * Returns the schema as pretty JSON (for docs and the model instructions).
	 *
	 * @return string
	 */
	public static function to_json() {
		return (string) wp_json_encode( self::definition(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
