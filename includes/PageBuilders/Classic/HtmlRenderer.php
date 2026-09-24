<?php
/**
 * Semantic HTML renderer used by the classic adapter and the preview.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\PageBuilders\Classic;

use AIPageDesigner\PageBuilders\Palette;
use AIPageDesigner\PageBuilders\StyleSheet;
use AIPageDesigner\Schema\PageSchema;
use AIPageDesigner\Security\Kses;

defined( 'ABSPATH' ) || exit;

/**
 * Renders accessible, semantic HTML with inline token colors.
 */
final class HtmlRenderer {

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
	 * Constructor.
	 *
	 * @param array $schema Trusted schema.
	 */
	public function __construct( array $schema ) {
		$this->schema = $schema;
		$this->tokens = $schema['design_tokens'];
	}

	/**
	 * Page HTML (sanitized with the page allowlist).
	 *
	 * @param bool $with_markers Add section comment markers for later replacement.
	 * @return string
	 */
	public function page( $with_markers = true ) {
		$meta = $this->schema['meta'];
		$html = sprintf(
			'<div class="aipd-page aipd-dir-%1$s" dir="%1$s" lang="%2$s">',
			esc_attr( $meta['direction'] ),
			esc_attr( $meta['language'] )
		);
		foreach ( $this->schema['sections'] as $section ) {
			$html .= $this->section_html( $section, $with_markers );
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * One section's HTML, optionally wrapped in markers.
	 *
	 * @param array $section      Section.
	 * @param bool  $with_markers Markers.
	 * @return string
	 */
	public function section_html( array $section, $with_markers = true ) {
		$html = $this->section( $section );
		if ( $with_markers ) {
			$html = "\n<!-- aipd:section " . $section['id'] . " -->\n" . $html . "\n<!-- /aipd:section " . $section['id'] . " -->\n";
		}
		return $html;
	}

	/**
	 * Standalone preview document for a sandboxed iframe.
	 *
	 * @return string
	 */
	public function preview_document() {
		$meta  = $this->schema['meta'];
		$css   = StyleSheet::for_schema( $this->schema );
		$reset = '*,*::before,*::after{box-sizing:border-box}body{margin:0}img{max-width:100%;height:auto;display:block}'
			. '.aipd-inner{max-width:var(--container);margin-inline:auto}'
			. '.aipd-columns{display:flex;gap:24px;flex-wrap:wrap}.aipd-columns>.aipd-column{flex:1 1 0;min-width:0}'
			. '.aipd-buttons{display:flex;flex-wrap:wrap;gap:12px}.aipd-button{display:inline-block;padding:.75em 1.4em;text-decoration:none;font-weight:600;border:2px solid transparent}'
			. '.aipd-button--outline{border-color:currentColor;background:transparent}'
			. '.aipd-placeholder-image{background:repeating-linear-gradient(45deg,#e5e7eb,#e5e7eb 12px,#f3f4f6 12px,#f3f4f6 24px);display:flex;align-items:center;justify-content:center;color:#374151;font-size:14px;padding:16px;text-align:center}'
			. 'blockquote{margin:0}figure{margin:0}'
			. '@media (max-width:' . (int) $this->tokens['breakpoints']['tablet'] . 'px){.aipd-columns.is-stacked{flex-direction:column}}';

		return '<!doctype html><html lang="' . esc_attr( $meta['language'] ) . '" dir="' . esc_attr( $meta['direction'] ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<title>' . esc_html( $meta['title'] ) . '</title><style>' . $reset . ':root{--container:' . (int) $this->tokens['container_width'] . 'px}' . $css . '</style></head><body>'
			. Kses::page( $this->page( false ) )
			. '</body></html>';
	}

	/**
	 * Section markup.
	 *
	 * @param array $section Section.
	 * @return string
	 */
	private function section( array $section ) {
		$colors  = Palette::section( $this->tokens, $section['background'] );
		$padding = Palette::padding( $section['padding'] );
		$context = array(
			'colors'  => $colors,
			'align'   => $section['align'],
			'section' => $section,
		);

		$intro = '';
		foreach ( isset( $section['intro'] ) ? $section['intro'] : array() as $component ) {
			$intro .= $this->component( $component, $context );
		}

		$columns = '';
		$count   = count( $section['columns'] );
		foreach ( $section['columns'] as $column ) {
			$style = $column['width'] > 0 ? ' style="flex:0 0 ' . (int) $column['width'] . '%"' : '';
			$class = 'aipd-column' . ( 'card' === $column['style'] ? ' aipd-card' : '' );
			$inner = '';
			foreach ( $column['components'] as $component ) {
				$inner .= $this->component( $component, $context );
			}
			$columns .= $count > 1 ? '<div class="' . esc_attr( $class ) . '"' . $style . '>' . $inner . '</div>' : ( 'card' === $column['style'] ? '<div class="aipd-card">' . $inner . '</div>' : $inner );
		}
		if ( $count > 1 ) {
			$valign  = array(
				'top'    => 'flex-start',
				'center' => 'center',
				'bottom' => 'flex-end',
			);
			$columns = '<div class="aipd-columns' . ( $section['layout']['stack_on_mobile'] ? ' is-stacked' : '' ) . '" style="align-items:' . $valign[ $section['layout']['vertical_align'] ] . '">' . $columns . '</div>';
		}
		$columns = $intro . $columns;

		$label = '' !== $section['label'] ? ' aria-label="' . esc_attr( $section['label'] ) . '"' : '';
		return sprintf(
			'<section id="%1$s" class="aipd-section aipd-section--%2$s aipd-bg-%3$s"%4$s style="background-color:%5$s;color:%6$s;padding-block:%7$dpx;padding-inline:24px"><div class="aipd-inner">%8$s</div></section>',
			esc_attr( $section['id'] ),
			esc_attr( $section['type'] ),
			esc_attr( $section['background'] ),
			$label,
			esc_attr( $colors['background'] ),
			esc_attr( $colors['text'] ),
			$padding,
			$columns
		);
	}

	/**
	 * CSS text-align value for a logical alignment.
	 *
	 * @param string $align start|center|end.
	 * @return string
	 */
	private static function align_style( $align ) {
		return 'start' === $align ? '' : ' style="text-align:' . ( 'center' === $align ? 'center' : 'end' ) . '"';
	}

	/**
	 * Component markup.
	 *
	 * @param array $c       Component.
	 * @param array $context Context.
	 * @return string
	 */
	private function component( array $c, array $context ) {
		/**
		 * Short-circuits HTML rendering of a component.
		 *
		 * @since 1.0.0
		 *
		 * @param string|null $html    Null for default rendering. Returned HTML is sanitized.
		 * @param array       $c       Component.
		 * @param array       $context Context.
		 */
		$custom = apply_filters( 'aipd_html_render_component', null, $c, $context );
		if ( is_string( $custom ) ) {
			return Kses::page( $custom );
		}

		$align = isset( $c['align'] ) ? $c['align'] : $context['align'];
		switch ( $c['type'] ) {
			case 'heading':
				$tag = 'h' . (int) $c['level'];
				return '<' . $tag . self::align_style( $align ) . '>' . $c['text'] . '</' . $tag . '>';
			case 'paragraph':
				$styles = array();
				if ( 'start' !== $align ) {
					$styles[] = 'text-align:' . ( 'center' === $align ? 'center' : 'end' );
				}
				if ( 'normal' !== $c['size'] ) {
					$styles[] = 'font-size:' . Palette::font_size( $this->tokens, $c['size'] ) . 'px';
				}
				if ( $c['muted'] ) {
					$styles[] = 'color:' . $context['colors']['muted'];
				}
				return '<p' . ( $styles ? ' style="' . esc_attr( implode( ';', $styles ) ) . '"' : '' ) . '>' . $c['text'] . '</p>';
			case 'buttons':
				$justify = array(
					'start'  => 'flex-start',
					'center' => 'center',
					'end'    => 'flex-end',
				);
				$out     = '<div class="aipd-buttons" style="justify-content:' . $justify[ $align ] . '">';
				foreach ( $c['items'] as $item ) {
					$out .= $this->button( $item, $context['colors'] );
				}
				return $out . '</div>';
			case 'list':
				$tag = $c['ordered'] ? 'ol' : 'ul';
				$out = '<' . $tag . '>';
				foreach ( $c['items'] as $item ) {
					$out .= '<li>' . $item . '</li>';
				}
				return $out . '</' . $tag . '>';
			case 'image':
				return $this->image( $c );
			case 'icon':
				return '<p class="aipd-icon"' . self::align_style( $align ) . '><span aria-hidden="true">' . esc_html( PageSchema::glyph( $c['name'] ) ) . '</span>' . ( '' !== $c['label'] ? ' ' . esc_html( $c['label'] ) : '' ) . '</p>';
			case 'testimonial':
				return '<figure class="aipd-testimonial"><blockquote><p>' . $c['quote'] . '</p></blockquote><figcaption>' . esc_html( $c['author'] ) . ( '' !== $c['role'] ? ', <span>' . esc_html( $c['role'] ) . '</span>' : '' ) . '</figcaption></figure>';
			case 'faq':
				$out = '';
				foreach ( $c['items'] as $item ) {
					$out .= '<details class="wp-block-details"><summary>' . esc_html( $item['question'] ) . '</summary><p>' . $item['answer'] . '</p></details>';
				}
				return $out;
			case 'pricing':
				$out = '<div class="aipd-columns is-stacked">';
				foreach ( $c['plans'] as $plan ) {
					$out .= '<div class="aipd-column aipd-card' . ( $plan['highlighted'] ? ' aipd-card--highlight' : '' ) . '">';
					$out .= '<h3>' . esc_html( $plan['name'] ) . '</h3>';
					$out .= '<p style="font-size:' . Palette::font_size( $this->tokens, 'large' ) . 'px"><strong>' . esc_html( $plan['price'] ) . '</strong> ' . esc_html( $plan['period'] ) . '</p>';
					if ( '' !== $plan['description'] ) {
						$out .= '<p>' . esc_html( $plan['description'] ) . '</p>';
					}
					if ( ! empty( $plan['features'] ) ) {
						$out .= '<ul>';
						foreach ( $plan['features'] as $feature ) {
							$out .= '<li>' . esc_html( $feature ) . '</li>';
						}
						$out .= '</ul>';
					}
					if ( isset( $plan['cta'] ) ) {
						$out .= '<div class="aipd-buttons">' . $this->button( $plan['cta'], Palette::section( $this->tokens, 'default' ) ) . '</div>';
					}
					$out .= '</div>';
				}
				return $out . '</div>';
			case 'form':
				$out = '<div class="aipd-form-placeholder"><p><strong>' . esc_html__( 'Form placeholder', 'ai-page-designer' ) . '</strong> ' . esc_html__( 'Replace this with a form from your preferred forms plugin. Suggested fields:', 'ai-page-designer' ) . '</p><ul>';
				foreach ( $c['fields'] as $field ) {
					$out .= '<li>' . esc_html( $field['label'] ) . ( $field['required'] ? ' *' : '' ) . '</li>';
				}
				$out .= '</ul>';
				if ( '' !== $c['email'] ) {
					$out .= '<div class="aipd-buttons">' . $this->button(
						array(
							'text'       => $c['submit_text'],
							'url'        => 'mailto:' . $c['email'],
							'variant'    => 'primary',
							'aria_label' => '',
						),
						$context['colors']
					) . '</div>';
				}
				return $out . '</div>';
			case 'stat':
				return '<p class="aipd-stat-value"' . self::align_style( $align ) . '><strong>' . esc_html( $c['value'] ) . '</strong></p><p' . self::align_style( $align ) . '>' . esc_html( $c['label'] ) . '</p>';
			case 'spacer':
				return '<div aria-hidden="true" style="height:' . Palette::padding( $c['size'] ) . 'px"></div>';
			case 'separator':
				return '<hr>';
		}
		return '';
	}

	/**
	 * Button link.
	 *
	 * @param array $item    Button.
	 * @param array $section Section colors.
	 * @return string
	 */
	private function button( array $item, array $section ) {
		$colors = Palette::button( $this->tokens, $section, $item['variant'] );
		$style  = 'border-radius:' . (int) $this->tokens['radius'] . 'px;color:' . $colors['text'] . ( $colors['outline'] ? '' : ';background-color:' . $colors['background'] );
		$aria   = '' !== $item['aria_label'] ? ' aria-label="' . esc_attr( $item['aria_label'] ) . '"' : '';
		return '<a class="aipd-button' . ( $colors['outline'] ? ' aipd-button--outline' : '' ) . '" href="' . esc_url( $item['url'], array( 'http', 'https', 'mailto', 'tel' ) ) . '"' . $aria . ' style="' . esc_attr( $style ) . '">' . esc_html( $item['text'] ) . '</a>';
	}

	/**
	 * Image or placeholder.
	 *
	 * @param array $c Image component.
	 * @return string
	 */
	private function image( array $c ) {
		$alt     = $c['decorative'] ? '' : $c['alt'];
		$caption = '' !== $c['caption'] ? '<figcaption>' . esc_html( $c['caption'] ) . '</figcaption>' : '';
		if ( 'media' === $c['source'] && $c['attachment_id'] > 0 ) {
			$src = wp_get_attachment_image_url( $c['attachment_id'], 'large' );
			if ( $src ) {
				return '<figure><img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '" loading="lazy" decoding="async" style="aspect-ratio:' . esc_attr( $c['aspect'] ) . ';object-fit:cover;width:100%">' . $caption . '</figure>';
			}
		}
		$label = '' !== $alt ? $alt : __( 'Image placeholder', 'ai-page-designer' );
		return '<figure><div class="aipd-placeholder-image" role="img" aria-label="' . esc_attr( $label ) . '" style="aspect-ratio:' . esc_attr( $c['aspect'] ) . '">' . esc_html( $label ) . '</div>' . $caption . '</figure>';
	}
}
