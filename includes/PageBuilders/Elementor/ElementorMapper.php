<?php
/**
 * Maps a Page Schema to Elementor element data.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\PageBuilders\Elementor;

use AIPageDesigner\PageBuilders\Palette;
use AIPageDesigner\Schema\DesignTokens;
use AIPageDesigner\Schema\PageSchema;
use AIPageDesigner\Security\Kses;

defined( 'ABSPATH' ) || exit;

/**
 * Produces the public JSON element structure Elementor stores in _elementor_data,
 * using only standard (free) widgets unless Elementor Pro widgets are confirmed.
 * Pure PHP: no Elementor classes are referenced here.
 */
final class ElementorMapper {

	/**
	 * Font Awesome icon names bundled with Elementor for each schema icon.
	 */
	const ICON_MAP = array(
		'check'    => 'fas fa-check',
		'star'     => 'fas fa-star',
		'arrow'    => 'fas fa-arrow-right',
		'bolt'     => 'fas fa-bolt',
		'shield'   => 'fas fa-shield-alt',
		'heart'    => 'fas fa-heart',
		'globe'    => 'fas fa-globe',
		'clock'    => 'far fa-clock',
		'chat'     => 'far fa-comments',
		'phone'    => 'fas fa-phone',
		'spark'    => 'fas fa-magic',
		'diamond'  => 'far fa-gem',
		'circle'   => 'fas fa-circle',
		'plus'     => 'fas fa-plus',
		'leaf'     => 'fas fa-leaf',
		'sun'      => 'far fa-sun',
		'flag'     => 'far fa-flag',
		'gear'     => 'fas fa-cog',
		'pin'      => 'fas fa-map-marker-alt',
		'infinity' => 'fas fa-infinity',
	);

	/**
	 * Schema.
	 *
	 * @var array
	 */
	private $schema;

	/**
	 * Tokens.
	 *
	 * @var array
	 */
	private $tokens;

	/**
	 * Use flexbox containers (true) or legacy sections/columns (false).
	 *
	 * @var bool
	 */
	private $containers;

	/**
	 * Available widget types (empty = assume free core widgets).
	 *
	 * @var string[]
	 */
	private $widgets;

	/**
	 * Constructor.
	 *
	 * @param array    $schema     Trusted schema.
	 * @param bool     $containers Use containers.
	 * @param string[] $widgets    Registered widget types; Pro widgets (form, price-table) are used only when present.
	 */
	public function __construct( array $schema, $containers = true, array $widgets = array() ) {
		$this->schema     = $schema;
		$this->tokens     = $schema['design_tokens'];
		$this->containers = (bool) $containers;
		$this->widgets    = $widgets;
	}

	/**
	 * All top-level elements.
	 *
	 * @return array
	 */
	public function elements() {
		$out = array();
		foreach ( $this->schema['sections'] as $section ) {
			$out[] = $this->section( $section );
		}
		return $out;
	}

	/**
	 * One top-level element for a section.
	 *
	 * @param array $section Section.
	 * @return array
	 */
	public function section( array $section ) {
		$colors  = Palette::section( $this->tokens, $section['background'] );
		$padding = (string) Palette::padding( $section['padding'] );
		$context = array(
			'colors'  => $colors,
			'align'   => $section['align'],
			'section' => $section,
		);
		$intro   = isset( $section['intro'] ) ? $section['intro'] : array();
		$classes = 'aipd-page aipd-dir-' . $this->schema['meta']['direction'] . ' aipd-section aipd-section--' . $section['type'] . ' aipd-bg-' . $section['background'];

		$settings = array(
			'background_background' => 'classic',
			'background_color'      => $colors['background'],
			'padding'               => array(
				'unit'     => 'px',
				'top'      => $padding,
				'right'    => '24',
				'bottom'   => $padding,
				'left'     => '24',
				'isLinked' => false,
			),
			'_element_id'           => $section['id'],
			'css_classes'           => $classes,
			'_title'                => '' !== $section['label'] ? $section['label'] : ucfirst( $section['type'] ),
		);

		if ( $this->containers ) {
			$settings = array_merge(
				$settings,
				array(
					'container_type' => 'flex',
					'content_width'  => 'boxed',
					'boxed_width'    => array(
						'unit'  => 'px',
						'size'  => (int) $this->tokens['container_width'],
						'sizes' => array(),
					),
					'flex_direction' => 'column',
					'html_tag'       => 'section',
				)
			);
			$count    = count( $section['columns'] );
			$children = $this->column_children(
				array(
					'components' => $intro,
					'style'      => 'plain',
				),
				$context
			);
			if ( 1 === $count ) {
				$content = $this->column_children( $section['columns'][0], $context );
				if ( 'card' === $section['columns'][0]['style'] ) {
					$content = array( $this->inner_container( $content, 100, true, false ) );
				}
				$children = array_merge( $children, $content );
			} else {
				$children[] = $this->row_container( $section, $context );
			}
			return $this->element( 'container', $settings, $children, false );
		}

		// Legacy section/column structure.
		$settings = array_merge(
			$settings,
			array(
				'layout'        => 'boxed',
				'content_width' => array(
					'unit' => 'px',
					'size' => (int) $this->tokens['container_width'],
				),
				'html_tag'      => 'section',
				'gap'           => 'extended',
			)
		);
		if ( ! $section['layout']['stack_on_mobile'] ) {
			$settings['reverse_order_mobile'] = '';
		}
		$columns = array();
		$count   = count( $section['columns'] );
		if ( ! empty( $intro ) ) {
			// Legacy structure: one column holding the intro and an inner section with the columns.
			$inner_columns = array();
			foreach ( $section['columns'] as $column ) {
				$size            = $column['width'] > 0 ? (int) $column['width'] : (int) floor( 100 / $count );
				$inner_columns[] = $this->element(
					'column',
					array(
						'_column_size' => $size,
						'css_classes'  => 'card' === $column['style'] ? 'aipd-card' : '',
					),
					$this->column_children( $column, $context ),
					true
				);
			}
			$children   = $this->column_children(
				array(
					'components' => $intro,
					'style'      => 'plain',
				),
				$context
			);
			$children[] = $this->element( 'section', array( 'structure' => (string) ( $count * 10 ) ), $inner_columns, true );
			return $this->element( 'section', $settings, array( $this->element( 'column', array( '_column_size' => 100 ), $children, false ) ), false );
		}
		foreach ( $section['columns'] as $column ) {
			$size      = $column['width'] > 0 ? (int) $column['width'] : (int) floor( 100 / $count );
			$columns[] = $this->element(
				'column',
				array(
					'_column_size' => $size,
					'_inline_size' => $column['width'] > 0 ? $size : null,
					'css_classes'  => 'card' === $column['style'] ? 'aipd-card' : '',
				),
				$this->column_children( $column, $context ),
				true
			);
		}
		return $this->element( 'section', $settings, $columns, false );
	}

	/**
	 * Row container holding columns (container mode).
	 *
	 * @param array $section Section.
	 * @param array $context Context.
	 * @return array
	 */
	private function row_container( array $section, array $context ) {
		$count    = count( $section['columns'] );
		$children = array();
		foreach ( $section['columns'] as $column ) {
			$width      = $column['width'] > 0 ? (int) $column['width'] : round( ( 100 - ( $count - 1 ) * 2 ) / $count, 2 );
			$children[] = $this->inner_container( $this->column_children( $column, $context ), $width, 'card' === $column['style'], true );
		}
		$align = array(
			'top'    => 'flex-start',
			'center' => 'center',
			'bottom' => 'flex-end',
		);
		return $this->element(
			'container',
			array(
				'container_type'        => 'flex',
				'content_width'         => 'full',
				'flex_direction'        => 'row',
				'flex_direction_mobile' => $section['layout']['stack_on_mobile'] ? 'column' : 'row',
				'flex_align_items'      => $align[ $section['layout']['vertical_align'] ],
				'flex_wrap'             => 'wrap',
				'flex_gap'              => array(
					'unit'     => 'px',
					'column'   => '24',
					'row'      => '24',
					'isLinked' => true,
				),
				'padding'               => array(
					'unit'     => 'px',
					'top'      => '0',
					'right'    => '0',
					'bottom'   => '0',
					'left'     => '0',
					'isLinked' => true,
				),
			),
			$children,
			true
		);
	}

	/**
	 * Column-like inner container.
	 *
	 * @param array     $children Children.
	 * @param float|int $width    Width in percent.
	 * @param bool      $card     Card style.
	 * @param bool      $in_row   Whether it sits in a row (mobile width 100%).
	 * @return array
	 */
	private function inner_container( array $children, $width, $card, $in_row ) {
		$settings = array(
			'container_type' => 'flex',
			'content_width'  => 'full',
			'flex_direction' => 'column',
			'width'          => array(
				'unit'  => '%',
				'size'  => $width,
				'sizes' => array(),
			),
			'width_mobile'   => array(
				'unit'  => '%',
				'size'  => 100,
				'sizes' => array(),
			),
		);
		if ( $card ) {
			$radius                            = (string) (int) $this->tokens['radius'];
			$settings['css_classes']           = 'aipd-card';
			$settings['background_background'] = 'classic';
			$settings['background_color']      = $this->tokens['colors']['background'];
			$settings['border_radius']         = array(
				'unit'     => 'px',
				'top'      => $radius,
				'right'    => $radius,
				'bottom'   => $radius,
				'left'     => $radius,
				'isLinked' => true,
			);
			$settings['padding']               = array(
				'unit'     => 'px',
				'top'      => '28',
				'right'    => '28',
				'bottom'   => '28',
				'left'     => '28',
				'isLinked' => true,
			);
		}
		if ( ! $in_row ) {
			unset( $settings['width_mobile'] );
		}
		return $this->element( 'container', $settings, $children, true );
	}

	/**
	 * Widgets for a column.
	 *
	 * @param array $column  Column.
	 * @param array $context Context.
	 * @return array
	 */
	private function column_children( array $column, array $context ) {
		$context['on_card'] = 'card' === $column['style'];
		if ( $context['on_card'] ) {
			$context['colors'] = Palette::section( $this->tokens, 'default' );
		}
		$out = array();
		foreach ( $column['components'] as $component ) {
			foreach ( $this->component( $component, $context ) as $element ) {
				$out[] = $element;
			}
		}
		return $out;
	}

	/**
	 * Maps one component to Elementor elements.
	 *
	 * @param array $c       Component.
	 * @param array $context Context.
	 * @return array[]
	 */
	private function component( array $c, array $context ) {
		/**
		 * Short-circuits Elementor mapping of a component.
		 *
		 * @since 1.0.0
		 *
		 * @param array|null $elements Null for default mapping.
		 * @param array      $c        Component.
		 * @param array      $context  Context.
		 */
		$custom = apply_filters( 'aipd_elementor_render_component', null, $c, $context );
		if ( is_array( $custom ) ) {
			return $custom;
		}

		$align  = $this->physical_align( isset( $c['align'] ) ? $c['align'] : $context['align'] );
		$colors = $context['colors'];

		switch ( $c['type'] ) {
			case 'heading':
				return array(
					$this->widget(
						'heading',
						array(
							'title'       => Kses::html( $c['text'] ),
							'header_size' => 'h' . (int) $c['level'],
							'align'       => $align,
							'title_color' => $colors['text'],
						)
					),
				);
			case 'paragraph':
				$settings = array(
					'editor'     => '<p>' . Kses::html( $c['text'] ) . '</p>',
					'align'      => $align,
					'text_color' => $c['muted'] ? $colors['muted'] : $colors['text'],
				);
				if ( 'normal' !== $c['size'] ) {
					$settings['typography_typography'] = 'custom';
					$settings['typography_font_size']  = array(
						'unit'  => 'px',
						'size'  => Palette::font_size( $this->tokens, $c['size'] ),
						'sizes' => array(),
					);
				}
				return array( $this->widget( 'text-editor', $settings ) );
			case 'buttons':
				$out = array();
				foreach ( $c['items'] as $item ) {
					$out[] = $this->button( $item, $colors, $align );
				}
				return $out;
			case 'list':
				$items = array();
				foreach ( $c['items'] as $index => $item ) {
					$items[] = array(
						'_id'           => self::id(),
						'text'          => Kses::text( wp_strip_all_tags( $item ) ),
						'selected_icon' => array(
							'value'   => $c['ordered'] ? '' : 'fas fa-check',
							'library' => $c['ordered'] ? '' : 'fa-solid',
						),
					);
					if ( $c['ordered'] ) {
						$items[ $index ]['text'] = ( $index + 1 ) . '. ' . $items[ $index ]['text'];
					}
				}
				return array(
					$this->widget(
						'icon-list',
						array(
							'icon_list'  => $items,
							'icon_color' => $colors['text'],
							'text_color' => $colors['text'],
						)
					),
				);
			case 'image':
				$settings = array(
					'image'      => array(
						'url' => '',
						'id'  => '',
						'alt' => $c['decorative'] ? '' : $c['alt'],
					),
					'image_size' => 'large',
					'align'      => $align,
				);
				if ( 'media' === $c['source'] && $c['attachment_id'] > 0 ) {
					$settings['image']['id']  = (int) $c['attachment_id'];
					$settings['image']['url'] = (string) wp_get_attachment_image_url( $c['attachment_id'], 'full' );
				}
				if ( '' !== $c['caption'] ) {
					$settings['caption_source'] = 'custom';
					$settings['caption']        = $c['caption'];
				}
				return array( $this->widget( 'image', $settings ) );
			case 'icon':
				$out = array(
					$this->widget(
						'icon',
						array(
							'selected_icon' => array(
								'value'   => self::ICON_MAP[ $c['name'] ],
								'library' => 0 === strpos( self::ICON_MAP[ $c['name'] ], 'far' ) ? 'fa-regular' : 'fa-solid',
							),
							'align'         => $align,
							'primary_color' => DesignTokens::readable_on( $this->tokens['colors']['primary'], $colors['background'], 3.0 ),
						)
					),
				);
				if ( '' !== $c['label'] ) {
					$out[] = $this->widget(
						'text-editor',
						array(
							'editor'     => '<p>' . Kses::text( $c['label'] ) . '</p>',
							'align'      => $align,
							'text_color' => $colors['text'],
						)
					);
				}
				return $out;
			case 'testimonial':
				return array(
					$this->widget(
						'testimonial',
						array(
							'testimonial_content'   => wp_strip_all_tags( $c['quote'] ),
							'testimonial_name'      => $c['author'],
							'testimonial_job'       => $c['role'],
							'testimonial_alignment' => 'center' === $align ? 'center' : ( 'rtl' === $this->schema['meta']['direction'] ? 'right' : 'left' ),
							'content_content_color' => $colors['text'],
							'name_text_color'       => $colors['text'],
							'job_text_color'        => $colors['muted'],
						)
					),
				);
			case 'faq':
				$tabs = array();
				foreach ( $c['items'] as $item ) {
					$tabs[] = array(
						'_id'         => self::id(),
						'tab_title'   => $item['question'],
						'tab_content' => '<p>' . Kses::html( $item['answer'] ) . '</p>',
					);
				}
				return array(
					$this->widget(
						'accordion',
						array(
							'tabs'           => $tabs,
							'title_html_tag' => 'h3',
							'faq_schema'     => 'yes',
							'title_color'    => $colors['text'],
							'content_color'  => $colors['text'],
						)
					),
				);
			case 'pricing':
				return $this->pricing( $c, $context );
			case 'form':
				return $this->form( $c, $context );
			case 'stat':
				return array(
					$this->widget(
						'heading',
						array(
							'title'        => Kses::text( $c['value'] ),
							'header_size'  => 'p',
							'size'         => 'xl',
							'align'        => $align,
							'title_color'  => $colors['text'],
							'_css_classes' => 'aipd-stat-value',
						)
					),
					$this->widget(
						'text-editor',
						array(
							'editor'     => '<p>' . Kses::text( $c['label'] ) . '</p>',
							'align'      => $align,
							'text_color' => $colors['muted'],
						)
					),
				);
			case 'spacer':
				return array(
					$this->widget(
						'spacer',
						array(
							'space' => array(
								'unit'  => 'px',
								'size'  => Palette::padding( $c['size'] ),
								'sizes' => array(),
							),
						)
					),
				);
			case 'separator':
				return array( $this->widget( 'divider', array( 'color' => $colors['muted'] ) ) );
		}
		return array();
	}

	/**
	 * Button widget.
	 *
	 * @param array  $item   Button.
	 * @param array  $colors Section colors.
	 * @param string $align  Physical alignment.
	 * @return array
	 */
	private function button( array $item, array $colors, $align ) {
		$palette  = Palette::button( $this->tokens, $colors, $item['variant'] );
		$radius   = (string) (int) $this->tokens['radius'];
		$settings = array(
			'text'              => $item['text'],
			'link'              => array(
				'url'         => $item['url'],
				'is_external' => '',
				'nofollow'    => '',
			),
			'align'             => $align,
			'button_text_color' => $palette['text'],
			'border_radius'     => array(
				'unit'     => 'px',
				'top'      => $radius,
				'right'    => $radius,
				'bottom'   => $radius,
				'left'     => $radius,
				'isLinked' => true,
			),
		);
		if ( $palette['outline'] ) {
			$settings['background_background'] = 'classic';
			$settings['background_color']      = 'transparent';
			$settings['border_border']         = 'solid';
			$settings['border_width']          = array(
				'unit'     => 'px',
				'top'      => '2',
				'right'    => '2',
				'bottom'   => '2',
				'left'     => '2',
				'isLinked' => true,
			);
			$settings['border_color']          = $palette['text'];
		} else {
			$settings['background_background'] = 'classic';
			$settings['background_color']      = $palette['background'];
		}
		if ( '' !== $item['aria_label'] ) {
			$settings['link']['custom_attributes'] = 'aria-label|' . str_replace( array( '|', ',' ), ' ', $item['aria_label'] );
		}
		return $this->widget( 'button', $settings );
	}

	/**
	 * Pricing: Elementor Pro price-table when available, otherwise card containers.
	 *
	 * @param array $c       Pricing component.
	 * @param array $context Context.
	 * @return array[]
	 */
	private function pricing( array $c, array $context ) {
		$card_context           = $context;
		$card_context['colors'] = Palette::section( $this->tokens, 'default' );
		$use_pro                = in_array( 'price-table', $this->widgets, true );
		$cards                  = array();

		foreach ( $c['plans'] as $plan ) {
			if ( $use_pro ) {
				$features = array();
				foreach ( $plan['features'] as $feature ) {
					$features[] = array(
						'_id'       => self::id(),
						'item_text' => $feature,
					);
				}
				$cards[] = $this->widget(
					'price-table',
					array(
						'heading'       => $plan['name'],
						'sub_heading'   => $plan['description'],
						'price'         => $plan['price'],
						'period'        => $plan['period'],
						'features_list' => $features,
						'button_text'   => isset( $plan['cta'] ) ? $plan['cta']['text'] : '',
						'link'          => array( 'url' => isset( $plan['cta'] ) ? $plan['cta']['url'] : '#' ),
						'show_ribbon'   => $plan['highlighted'] ? 'yes' : '',
					)
				);
				continue;
			}

			$children = array(
				$this->widget(
					'heading',
					array(
						'title'       => Kses::text( $plan['name'] ),
						'header_size' => 'h3',
						'title_color' => $card_context['colors']['text'],
					)
				),
				$this->widget(
					'text-editor',
					array(
						'editor'     => '<p><strong>' . Kses::text( $plan['price'] ) . '</strong> ' . Kses::text( $plan['period'] ) . '</p>',
						'text_color' => $card_context['colors']['text'],
					)
				),
			);
			if ( '' !== $plan['description'] ) {
				$children[] = $this->widget(
					'text-editor',
					array(
						'editor'     => '<p>' . Kses::text( $plan['description'] ) . '</p>',
						'text_color' => $card_context['colors']['muted'],
					)
				);
			}
			if ( ! empty( $plan['features'] ) ) {
				$children = array_merge(
					$children,
					$this->component(
						array(
							'type'    => 'list',
							'ordered' => false,
							'items'   => $plan['features'],
						),
						$card_context
					)
				);
			}
			if ( isset( $plan['cta'] ) ) {
				$children[] = $this->button( $plan['cta'], $card_context['colors'], '' );
			}
			$cards[] = $this->containers
				? $this->inner_container( $children, round( 98 / count( $c['plans'] ), 2 ), true, true )
				: $this->widget(
					'text-editor',
					array(
						'editor' => $this->legacy_pricing_html( $plan ),
					)
				);
		}

		if ( ! $this->containers || $use_pro ) {
			return $cards;
		}
		return array(
			$this->element(
				'container',
				array(
					'container_type'        => 'flex',
					'content_width'         => 'full',
					'flex_direction'        => 'row',
					'flex_direction_mobile' => 'column',
					'flex_wrap'             => 'wrap',
					'flex_gap'              => array(
						'unit'     => 'px',
						'column'   => '24',
						'row'      => '24',
						'isLinked' => true,
					),
				),
				$cards,
				true
			),
		);
	}

	/**
	 * Plan HTML for legacy section mode.
	 *
	 * @param array $plan Plan.
	 * @return string
	 */
	private function legacy_pricing_html( array $plan ) {
		$html = '<div class="aipd-card' . ( $plan['highlighted'] ? ' aipd-card--highlight' : '' ) . '"><h3>' . Kses::text( $plan['name'] ) . '</h3><p><strong>' . Kses::text( $plan['price'] ) . '</strong> ' . Kses::text( $plan['period'] ) . '</p>';
		if ( ! empty( $plan['features'] ) ) {
			$html .= '<ul>';
			foreach ( $plan['features'] as $feature ) {
				$html .= '<li>' . Kses::text( $feature ) . '</li>';
			}
			$html .= '</ul>';
		}
		if ( isset( $plan['cta'] ) ) {
			$html .= '<p><a href="' . esc_url( $plan['cta']['url'], array( 'http', 'https', 'mailto', 'tel' ) ) . '">' . Kses::text( $plan['cta']['text'] ) . '</a></p>';
		}
		return $html . '</div>';
	}

	/**
	 * Form: Elementor Pro form widget when available, otherwise a placeholder.
	 *
	 * @param array $c       Form component.
	 * @param array $context Context.
	 * @return array[]
	 */
	private function form( array $c, array $context ) {
		if ( in_array( 'form', $this->widgets, true ) ) {
			$fields = array();
			foreach ( $c['fields'] as $index => $field ) {
				$fields[] = array(
					'_id'         => self::id(),
					'custom_id'   => 'field_' . ( $index + 1 ),
					'field_type'  => $field['type'],
					'field_label' => $field['label'],
					'placeholder' => '',
					'required'    => $field['required'] ? 'true' : '',
					'width'       => '100',
				);
			}
			$settings = array(
				'form_name'   => $this->schema['meta']['title'],
				'form_fields' => $fields,
				'button_text' => $c['submit_text'],
			);
			if ( '' !== $c['email'] ) {
				$settings['email_to'] = $c['email'];
			}
			return array( $this->widget( 'form', $settings ) );
		}

		$labels = array();
		foreach ( $c['fields'] as $field ) {
			$labels[] = '<li>' . Kses::text( $field['label'] ) . ( $field['required'] ? ' *' : '' ) . '</li>';
		}
		$out = array(
			$this->widget(
				'text-editor',
				array(
					'editor'       => '<p><strong>' . esc_html__( 'Form placeholder', 'ai-page-designer' ) . '</strong> ' . esc_html__( 'Replace this with a form widget from Elementor Pro or your preferred forms plugin. Suggested fields:', 'ai-page-designer' ) . '</p><ul>' . implode( '', $labels ) . '</ul>',
					'text_color'   => $context['colors']['text'],
					'_css_classes' => 'aipd-form-placeholder',
				)
			),
		);
		if ( '' !== $c['email'] ) {
			$out[] = $this->button(
				array(
					'text'       => $c['submit_text'],
					'url'        => 'mailto:' . $c['email'],
					'variant'    => 'primary',
					'aria_label' => '',
				),
				$context['colors'],
				''
			);
		}
		return $out;
	}

	/**
	 * Elementor alignment values are physical (left/center/right) and flip for RTL.
	 *
	 * @param string $align start|center|end.
	 * @return string
	 */
	private function physical_align( $align ) {
		if ( 'center' === $align ) {
			return 'center';
		}
		$rtl = 'rtl' === $this->schema['meta']['direction'];
		if ( 'end' === $align ) {
			return $rtl ? 'left' : 'right';
		}
		return $rtl ? 'right' : 'left';
	}

	/**
	 * Generic element.
	 *
	 * @param string $type     elType.
	 * @param array  $settings Settings.
	 * @param array  $children Children.
	 * @param bool   $is_inner Inner flag.
	 * @return array
	 */
	private function element( $type, array $settings, array $children, $is_inner ) {
		return array(
			'id'       => self::id(),
			'elType'   => $type,
			'isInner'  => (bool) $is_inner,
			'settings' => $settings,
			'elements' => $children,
		);
	}

	/**
	 * Widget element.
	 *
	 * @param string $type     Widget type.
	 * @param array  $settings Settings.
	 * @return array
	 */
	private function widget( $type, array $settings ) {
		return array(
			'id'         => self::id(),
			'elType'     => 'widget',
			'widgetType' => $type,
			'isInner'    => false,
			'settings'   => array_filter(
				$settings,
				function ( $value ) {
					return '' !== $value && null !== $value;
				}
			),
			'elements'   => array(),
		);
	}

	/**
	 * Random 7-character hex id in Elementor's format.
	 *
	 * @return string
	 */
	public static function id() {
		return substr( str_pad( dechex( wp_rand( 0, 0xFFFFFFF ) ), 7, '0', STR_PAD_LEFT ), 0, 7 );
	}
}
