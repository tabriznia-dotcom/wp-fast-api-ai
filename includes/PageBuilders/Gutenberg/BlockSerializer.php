<?php
/**
 * Converts a Page Schema into core block markup.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\PageBuilders\Gutenberg;

use AIPageDesigner\PageBuilders\Palette;
use AIPageDesigner\Schema\PageSchema;
use AIPageDesigner\Security\Kses;
use WP_Block_Type_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Produces markup identical to what each core block's save() function outputs,
 * so the block editor loads it without "invalid block" warnings. Only core
 * blocks are used, so content remains intact if this plugin is deactivated.
 */
final class BlockSerializer {

	/**
	 * Page Schema being rendered.
	 *
	 * @var array
	 */
	private $schema;

	/**
	 * Design tokens shortcut.
	 *
	 * @var array
	 */
	private $tokens;

	/**
	 * Whether paragraph/heading use the typography.textAlign block support (WP 7.0+).
	 *
	 * @var bool
	 */
	private $modern_align;

	/**
	 * Whether the group block supports ariaLabel.
	 *
	 * @var bool
	 */
	private $group_aria;

	/**
	 * Constructor.
	 *
	 * @param array $schema Trusted Page Schema.
	 */
	public function __construct( array $schema ) {
		$this->schema       = $schema;
		$this->tokens       = $schema['design_tokens'];
		$this->modern_align = self::block_supports( 'core/paragraph', array( 'typography', 'textAlign' ) );
		$this->group_aria   = self::block_supports( 'core/group', array( 'ariaLabel' ) );
	}

	/**
	 * Whether a registered block type declares a support flag.
	 *
	 * @param string   $block_name Block name.
	 * @param string[] $path       Path inside supports.
	 * @return bool
	 */
	public static function block_supports( $block_name, array $path ) {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( $block_name );
		if ( ! $type || ! is_array( $type->supports ) ) {
			return false;
		}
		$node = $type->supports;
		foreach ( $path as $key ) {
			if ( ! is_array( $node ) || ! isset( $node[ $key ] ) ) {
				return false;
			}
			$node = $node[ $key ];
		}
		return (bool) $node;
	}

	/**
	 * Full page markup: one wrapper group containing all sections.
	 *
	 * @return string
	 */
	public function page() {
		$sections = array();
		foreach ( $this->schema['sections'] as $section ) {
			$sections[] = $this->section_block( $section );
		}
		return serialize_blocks( array( $this->wrapper( $sections ) ) );
	}

	/**
	 * Markup of a single section (used for section replacement).
	 *
	 * @param string $section_id Section id.
	 * @return array|null Parsed-block array.
	 */
	public function section( $section_id ) {
		foreach ( $this->schema['sections'] as $section ) {
			if ( $section['id'] === $section_id ) {
				return $this->section_block( $section );
			}
		}
		return null;
	}

	/**
	 * Page wrapper group with direction and scope classes.
	 *
	 * @param array $inner Section blocks.
	 * @return array
	 */
	private function wrapper( array $inner ) {
		$class = 'aipd-page aipd-dir-' . $this->schema['meta']['direction'];
		$attrs = array(
			'align'     => 'full',
			'className' => $class,
			'layout'    => array( 'type' => 'constrained' ),
			'metadata'  => array( 'name' => $this->schema['meta']['title'] ),
		);
		return self::block( 'core/group', $attrs, '<div class="wp-block-group alignfull ' . esc_attr( $class ) . '">', $inner, '</div>' );
	}

	/**
	 * A section group.
	 *
	 * @param array $section Section.
	 * @return array
	 */
	private function section_block( array $section ) {
		$colors  = Palette::section( $this->tokens, $section['background'] );
		$padding = Palette::padding( $section['padding'] ) . 'px';
		$class   = 'aipd-section aipd-section--' . $section['type'] . ' aipd-bg-' . $section['background'];
		$label   = '' !== $section['label'] ? $section['label'] : '';

		$attrs = array(
			'tagName'   => 'section',
			'align'     => 'full',
			'anchor'    => $section['id'],
			'className' => $class,
			'style'     => array(
				'color'   => array(
					'background' => $colors['background'],
					'text'       => $colors['text'],
				),
				'spacing' => array(
					'padding' => array(
						'top'    => $padding,
						'bottom' => $padding,
						'left'   => '24px',
						'right'  => '24px',
					),
				),
			),
			'layout'    => array(
				'type'        => 'constrained',
				'contentSize' => (int) $this->tokens['container_width'] . 'px',
			),
			'metadata'  => array( 'name' => '' !== $label ? $label : ucfirst( $section['type'] ) ),
		);

		$aria = '';
		if ( $this->group_aria && '' !== $label ) {
			$attrs['ariaLabel'] = $label;
			$aria               = ' aria-label="' . esc_attr( $label ) . '"';
		}

		$style = sprintf(
			'color:%1$s;background-color:%2$s;padding-top:%3$s;padding-right:24px;padding-bottom:%3$s;padding-left:24px',
			$colors['text'],
			$colors['background'],
			$padding
		);

		$context = array(
			'colors'  => $colors,
			'align'   => $section['align'],
			'section' => $section,
		);

		$inner = $this->column_content( array( 'components' => isset( $section['intro'] ) ? $section['intro'] : array() ), $context );

		$columns = $section['columns'];
		if ( 1 === count( $columns ) ) {
			$content = $this->column_content( $columns[0], $context );
			if ( 'card' === $columns[0]['style'] ) {
				$content = array( $this->card_group( $content, false ) );
			}
			$inner = array_merge( $inner, $content );
		} else {
			$inner[] = $this->columns_block( $section, $context );
		}

		$open = sprintf(
			'<section class="wp-block-group alignfull %1$s has-text-color has-background" id="%2$s"%3$s style="%4$s">',
			esc_attr( $class ),
			esc_attr( $section['id'] ),
			$aria,
			esc_attr( $style )
		);

		return self::block( 'core/group', $attrs, $open, $inner, '</section>' );
	}

	/**
	 * Columns block for multi-column sections.
	 *
	 * @param array $section Section.
	 * @param array $context Render context.
	 * @return array
	 */
	private function columns_block( array $section, array $context ) {
		$layout   = $section['layout'];
		$attrs    = array();
		$classes  = array( 'wp-block-columns' );
		$valign   = array(
			'center' => 'center',
			'bottom' => 'bottom',
		);
		$vertical = isset( $valign[ $layout['vertical_align'] ] ) ? $valign[ $layout['vertical_align'] ] : '';

		if ( $vertical ) {
			$attrs['verticalAlignment'] = $vertical;
			$classes[]                  = 'are-vertically-aligned-' . $vertical;
		}
		if ( ! $layout['stack_on_mobile'] ) {
			$attrs['isStackedOnMobile'] = false;
			$classes[]                  = 'is-not-stacked-on-mobile';
		}

		$inner = array();
		foreach ( $section['columns'] as $column ) {
			$inner[] = $this->column_block( $column, $context, $vertical );
		}

		return self::block( 'core/columns', $attrs, '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">', $inner, '</div>' );
	}

	/**
	 * One column.
	 *
	 * @param array  $column   Column.
	 * @param array  $context  Context.
	 * @param string $vertical Vertical alignment.
	 * @return array
	 */
	private function column_block( array $column, array $context, $vertical ) {
		$attrs   = array();
		$classes = array( 'wp-block-column' );
		$style   = '';

		if ( $vertical ) {
			$attrs['verticalAlignment'] = $vertical;
			$classes[]                  = 'is-vertically-aligned-' . $vertical;
		}
		if ( $column['width'] > 0 ) {
			$attrs['width'] = (int) $column['width'] . '%';
			$style          = ' style="flex-basis:' . (int) $column['width'] . '%"';
		}

		$inner = $this->column_content( $column, $context );
		if ( 'card' === $column['style'] ) {
			$inner = array( $this->card_group( $inner, $this->column_highlighted( $column ) ) );
		}

		return self::block( 'core/column', $attrs, '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"' . $style . '>', $inner, '</div>' );
	}

	/**
	 * Whether a column contains a highlighted pricing plan.
	 *
	 * @param array $column Column.
	 * @return bool
	 */
	private function column_highlighted( array $column ) {
		foreach ( $column['components'] as $component ) {
			if ( 'pricing' === $component['type'] && 1 === count( $component['plans'] ) && $component['plans'][0]['highlighted'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A card group (styled by the scoped stylesheet).
	 *
	 * @param array $inner     Inner blocks.
	 * @param bool  $highlight Highlight.
	 * @return array
	 */
	private function card_group( array $inner, $highlight ) {
		$class = 'aipd-card' . ( $highlight ? ' aipd-card--highlight' : '' );
		return self::block(
			'core/group',
			array(
				'className' => $class,
				'layout'    => array( 'type' => 'constrained' ),
			),
			'<div class="wp-block-group ' . esc_attr( $class ) . '">',
			$inner,
			'</div>'
		);
	}

	/**
	 * Blocks for a column's components.
	 *
	 * @param array $column  Column.
	 * @param array $context Context.
	 * @return array
	 */
	private function column_content( array $column, array $context ) {
		$blocks = array();
		foreach ( $column['components'] as $component ) {
			foreach ( $this->component( $component, $context ) as $block ) {
				$blocks[] = $block;
			}
		}
		return $blocks;
	}

	/**
	 * Renders one component to zero or more blocks.
	 *
	 * @param array $component Component.
	 * @param array $context   Context.
	 * @return array[]
	 */
	private function component( array $component, array $context ) {
		/**
		 * Short-circuits rendering of a component in the block editor adapter.
		 * Return an array of parsed-block arrays to replace the default output.
		 *
		 * @since 1.0.0
		 *
		 * @param array|null $blocks    Null to use the default renderer.
		 * @param array      $component Component data (trusted, sanitized).
		 * @param array      $context   Render context.
		 */
		$custom = apply_filters( 'aipd_gutenberg_render_component', null, $component, $context );
		if ( is_array( $custom ) ) {
			return $custom;
		}

		$align = isset( $component['align'] ) ? $component['align'] : $context['align'];

		switch ( $component['type'] ) {
			case 'heading':
				return array( $this->heading( $component['level'], $component['text'], $align ) );
			case 'paragraph':
				$size = Palette::font_size( $this->tokens, $component['size'] );
				return array( $this->paragraph( $component['text'], $align, 'normal' !== $component['size'] ? $size : 0, $component['muted'] ? $context['colors']['muted'] : '' ) );
			case 'buttons':
				return array( $this->buttons( $component['items'], $align, $context ) );
			case 'list':
				return array( $this->list_block( $component['items'], $component['ordered'] ) );
			case 'image':
				return array( $this->image( $component ) );
			case 'icon':
				$glyph = PageSchema::glyph( $component['name'] );
				$text  = '<span aria-hidden="true">' . $glyph . '</span>' . ( '' !== $component['label'] ? ' ' . Kses::text( $component['label'] ) : '' );
				return array( $this->paragraph( $text, $align, 0, '', 'aipd-icon' ) );
			case 'testimonial':
				return array( $this->quote( $component ) );
			case 'faq':
				$blocks = array();
				foreach ( $component['items'] as $item ) {
					$blocks[] = $this->details( $item['question'], $item['answer'] );
				}
				return $blocks;
			case 'pricing':
				return $this->pricing( $component, $context );
			case 'form':
				return array( $this->form( $component, $context ) );
			case 'stat':
				return array(
					$this->paragraph( '<strong>' . Kses::text( $component['value'] ) . '</strong>', $align, 0, '', 'aipd-stat-value' ),
					$this->paragraph( Kses::text( $component['label'] ), $align, 0, $context['colors']['muted'] ),
				);
			case 'spacer':
				$height = Palette::padding( $component['size'] ) . 'px';
				return array( self::block( 'core/spacer', array( 'height' => $height ), '<div style="height:' . esc_attr( $height ) . '" aria-hidden="true" class="wp-block-spacer"></div>' ) );
			case 'separator':
				return array( self::block( 'core/separator', array(), '<hr class="wp-block-separator has-alpha-channel-opacity" />' ) );
		}
		return array();
	}

	/**
	 * Maps schema alignment to a physical text-align value.
	 *
	 * @param string $align start|center|end.
	 * @return string '', center, left or right.
	 */
	private function text_align( $align ) {
		if ( 'center' === $align ) {
			return 'center';
		}
		if ( 'end' === $align ) {
			return 'rtl' === $this->schema['meta']['direction'] ? 'left' : 'right';
		}
		return '';
	}

	/**
	 * Heading block.
	 *
	 * @param int    $level Level.
	 * @param string $html  Sanitized inline HTML.
	 * @param string $align Alignment.
	 * @return array
	 */
	private function heading( $level, $html, $align ) {
		$level   = max( 1, min( 6, (int) $level ) );
		$attrs   = array();
		$classes = array( 'wp-block-heading' );
		$text    = $this->text_align( $align );

		if ( 2 !== $level ) {
			$attrs['level'] = $level;
		}
		if ( $text ) {
			if ( $this->modern_align ) {
				$attrs['style'] = array( 'typography' => array( 'textAlign' => $text ) );
			} else {
				$attrs['textAlign'] = $text;
			}
			$classes[] = 'has-text-align-' . $text;
		}

		$tag = 'h' . $level;
		return self::block( 'core/heading', $attrs, '<' . $tag . ' class="' . esc_attr( implode( ' ', $classes ) ) . '">' . Kses::html( $html ) . '</' . $tag . '>' );
	}

	/**
	 * Paragraph block.
	 *
	 * @param string $html      Sanitized inline HTML.
	 * @param string $align     Alignment.
	 * @param int    $font_size Custom font size in px (0 for default).
	 * @param string $color     Custom text color ('' for inherit).
	 * @param string $class_name Extra class name.
	 * @return array
	 */
	private function paragraph( $html, $align, $font_size = 0, $color = '', $class_name = '' ) {
		$attrs   = array();
		$classes = array();
		$styles  = array();
		$text    = $this->text_align( $align );

		if ( $class_name ) {
			$attrs['className'] = $class_name;
		}
		if ( $text ) {
			if ( $this->modern_align ) {
				$attrs['style']['typography']['textAlign'] = $text;
			} else {
				$attrs['align'] = $text;
			}
			$classes[] = 'has-text-align-' . $text;
		}
		if ( $color ) {
			$attrs['style']['color']['text'] = $color;
			$classes[]                       = 'has-text-color';
			$styles[]                        = 'color:' . $color;
		}
		if ( $font_size > 0 ) {
			$attrs['style']['typography']['fontSize'] = $font_size . 'px';
			$styles[]                                 = 'font-size:' . $font_size . 'px';
		}
		if ( $class_name ) {
			$classes[] = $class_name;
		}

		$open = '<p';
		if ( $classes ) {
			$open .= ' class="' . esc_attr( implode( ' ', $classes ) ) . '"';
		}
		if ( $styles ) {
			$open .= ' style="' . esc_attr( implode( ';', $styles ) ) . '"';
		}
		return self::block( 'core/paragraph', $attrs, $open . '>' . Kses::html( $html ) . '</p>' );
	}

	/**
	 * Buttons group.
	 *
	 * @param array  $items   Buttons.
	 * @param string $align   Alignment.
	 * @param array  $context Context.
	 * @return array
	 */
	private function buttons( array $items, $align, array $context ) {
		$justify = array(
			'center' => 'center',
			'end'    => 'right',
		);
		$attrs   = array();
		if ( isset( $justify[ $align ] ) ) {
			$attrs['layout'] = array(
				'type'           => 'flex',
				'justifyContent' => $justify[ $align ],
			);
		}
		$inner = array();
		foreach ( $items as $item ) {
			$inner[] = $this->button( $item, $context );
		}
		return self::block( 'core/buttons', $attrs, '<div class="wp-block-buttons">', $inner, '</div>' );
	}

	/**
	 * Single button.
	 *
	 * @param array $item    Button.
	 * @param array $context Context.
	 * @return array
	 */
	private function button( array $item, array $context ) {
		$colors  = Palette::button( $this->tokens, $context['colors'], $item['variant'] );
		$radius  = (int) $this->tokens['radius'] . 'px';
		$attrs   = array();
		$wrapper = array( 'wp-block-button' );
		$link    = array( 'wp-block-button__link' );
		$styles  = array( 'border-radius:' . $radius );

		$attrs['style'] = array(
			'color'  => array( 'text' => $colors['text'] ),
			'border' => array( 'radius' => $radius ),
		);
		$link[]         = 'has-text-color';
		$styles[]       = 'color:' . $colors['text'];

		if ( $colors['outline'] ) {
			$attrs['className'] = 'is-style-outline';
			$wrapper[]          = 'is-style-outline';
		} else {
			$attrs['style']['color']['background'] = $colors['background'];
			$link[]                                = 'has-background';
			$styles[]                              = 'background-color:' . $colors['background'];
		}
		$link[] = 'wp-element-button';

		// Sourced attributes (text, url) live in the HTML, not in the block comment.
		$open = sprintf(
			'<div class="%1$s"><a class="%2$s" href="%3$s" style="%4$s">%5$s</a></div>',
			esc_attr( implode( ' ', $wrapper ) ),
			esc_attr( implode( ' ', $link ) ),
			esc_url( $item['url'], array( 'http', 'https', 'mailto', 'tel' ) ),
			esc_attr( implode( ';', $styles ) ),
			Kses::text( $item['text'] )
		);
		return self::block( 'core/button', $attrs, $open );
	}

	/**
	 * List block with list-item children.
	 *
	 * @param string[] $items   Sanitized inline HTML items.
	 * @param bool     $ordered Ordered.
	 * @return array
	 */
	private function list_block( array $items, $ordered ) {
		$inner = array();
		foreach ( $items as $item ) {
			$inner[] = self::block( 'core/list-item', array(), '<li>' . Kses::html( $item ) . '</li>' );
		}
		$tag = $ordered ? 'ol' : 'ul';
		return self::block( 'core/list', $ordered ? array( 'ordered' => true ) : array(), '<' . $tag . ' class="wp-block-list">', $inner, '</' . $tag . '>' );
	}

	/**
	 * Image block. Placeholders have no src so the editor shows its upload UI.
	 *
	 * @param array $component Image component.
	 * @return array
	 */
	private function image( array $component ) {
		$attrs = array(
			'aspectRatio' => $component['aspect'],
			'scale'       => 'cover',
		);
		$alt   = $component['decorative'] ? '' : $component['alt'];

		$figure_classes = array( 'wp-block-image' );
		$img            = '<img';
		if ( 'media' === $component['source'] && $component['attachment_id'] > 0 ) {
			$id  = (int) $component['attachment_id'];
			$src = wp_get_attachment_image_url( $id, 'large' );
			if ( $src ) {
				$attrs            = array_merge(
					array( 'id' => $id ),
					$attrs,
					array( 'sizeSlug' => 'large' )
				);
				$figure_classes[] = 'size-large';
				$img             .= ' src="' . esc_url( $src ) . '"';
			}
		}
		$img .= ' alt="' . esc_attr( $alt ) . '"';
		if ( isset( $attrs['id'] ) ) {
			$img .= ' class="wp-image-' . (int) $attrs['id'] . '"';
		}
		$img .= ' style="aspect-ratio:' . esc_attr( $component['aspect'] ) . ';object-fit:cover" />';

		$caption = '';
		if ( '' !== $component['caption'] ) {
			$caption = '<figcaption class="wp-element-caption">' . Kses::text( $component['caption'] ) . '</figcaption>';
		}

		return self::block( 'core/image', $attrs, '<figure class="' . esc_attr( implode( ' ', $figure_classes ) ) . '">' . $img . $caption . '</figure>' );
	}

	/**
	 * Testimonial as a quote block.
	 *
	 * @param array $component Testimonial.
	 * @return array
	 */
	private function quote( array $component ) {
		$cite = Kses::text( $component['author'] ) . ( '' !== $component['role'] ? ', ' . Kses::text( $component['role'] ) : '' );
		return self::block(
			'core/quote',
			array(),
			'<blockquote class="wp-block-quote">',
			array( $this->paragraph( $component['quote'], 'start' ) ),
			'<cite>' . $cite . '</cite></blockquote>'
		);
	}

	/**
	 * Details block for one FAQ item.
	 *
	 * @param string $question Question.
	 * @param string $answer   Sanitized inline HTML answer.
	 * @return array
	 */
	private function details( $question, $answer ) {
		$summary = Kses::text( $question );
		return self::block(
			'core/details',
			array(),
			'<details class="wp-block-details"><summary>' . $summary . '</summary>',
			array( $this->paragraph( $answer, 'start' ) ),
			'</details>'
		);
	}

	/**
	 * Pricing plans rendered as card columns.
	 *
	 * @param array $component Pricing.
	 * @param array $context   Context.
	 * @return array[]
	 */
	private function pricing( array $component, array $context ) {
		$cards = array();
		foreach ( $component['plans'] as $plan ) {
			$inner = array(
				$this->heading( 3, Kses::text( $plan['name'] ), 'start' ),
				$this->paragraph( '<strong>' . Kses::text( $plan['price'] ) . '</strong>' . ( '' !== $plan['period'] ? ' <span>' . Kses::text( $plan['period'] ) . '</span>' : '' ), 'start', Palette::font_size( $this->tokens, 'large' ) ),
			);
			if ( '' !== $plan['description'] ) {
				$inner[] = $this->paragraph( Kses::text( $plan['description'] ), 'start' );
			}
			if ( ! empty( $plan['features'] ) ) {
				$inner[] = $this->list_block( array_map( 'esc_html', $plan['features'] ), false );
			}
			if ( isset( $plan['cta'] ) ) {
				$cta_context           = $context;
				$cta_context['colors'] = Palette::section( $this->tokens, 'default' );
				$inner[]               = $this->buttons( array( $plan['cta'] ), 'start', $cta_context );
			}
			$cards[] = $this->card_group( $inner, $plan['highlighted'] );
		}

		if ( 1 === count( $cards ) ) {
			return $cards;
		}

		$columns = array();
		foreach ( $cards as $card ) {
			$columns[] = self::block( 'core/column', array(), '<div class="wp-block-column">', array( $card ), '</div>' );
		}
		return array( self::block( 'core/columns', array(), '<div class="wp-block-columns">', $columns, '</div>' ) );
	}

	/**
	 * Form placeholder: core has no form block, so we describe the fields and
	 * offer a mailto fallback. Users can replace it with a form plugin block.
	 *
	 * @param array $component Form.
	 * @param array $context   Context.
	 * @return array
	 */
	private function form( array $component, array $context ) {
		$labels = array();
		foreach ( $component['fields'] as $field ) {
			$labels[] = Kses::text( $field['label'] ) . ( $field['required'] ? ' *' : '' );
		}
		$inner = array(
			$this->paragraph( '<strong>' . esc_html__( 'Form placeholder', 'ai-page-designer' ) . '</strong> ' . esc_html__( 'Replace this group with a form block from your preferred forms plugin. Suggested fields:', 'ai-page-designer' ), 'start' ),
			$this->list_block( $labels, false ),
		);
		if ( '' !== $component['email'] ) {
			$inner[] = $this->buttons(
				array(
					array(
						'text'       => $component['submit_text'],
						'url'        => 'mailto:' . $component['email'],
						'variant'    => 'primary',
						'aria_label' => '',
					),
				),
				'start',
				$context
			);
		}
		return self::block(
			'core/group',
			array(
				'className' => 'aipd-form-placeholder',
				'layout'    => array( 'type' => 'constrained' ),
			),
			'<div class="wp-block-group aipd-form-placeholder">',
			$inner,
			'</div>'
		);
	}

	/**
	 * Builds a parsed-block array compatible with serialize_blocks().
	 *
	 * @param string $name  Block name.
	 * @param array  $attrs Attributes.
	 * @param string $open  Opening HTML (or the whole HTML for leaf blocks).
	 * @param array  $inner Inner blocks.
	 * @param string $close Closing HTML.
	 * @return array
	 */
	public static function block( $name, array $attrs, $open, array $inner = array(), $close = '' ) {
		$content = array( $open );
		foreach ( $inner as $index => $unused ) {
			$content[] = null;
		}
		if ( '' !== $close ) {
			$content[] = $close;
		}
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner,
			'innerHTML'    => $open . $close,
			'innerContent' => $content,
		);
	}
}
