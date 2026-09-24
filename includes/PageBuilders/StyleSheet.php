<?php
/**
 * Scoped CSS generated from design tokens.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\PageBuilders;

use AIPageDesigner\Schema\DesignTokens;

defined( 'ABSPATH' ) || exit;

/**
 * Produces a small stylesheet scoped to one generated page. Colors, spacing
 * and alignment already live in the builder markup; this CSS only adds
 * typography, cards and focus styles, so content stays usable without it.
 */
final class StyleSheet {

	/**
	 * CSS for a page scope.
	 *
	 * @param array  $schema Page Schema.
	 * @param string $scope  CSS selector for the page wrapper.
	 * @return string
	 */
	public static function for_schema( array $schema, $scope = '.aipd-page' ) {
		$t       = $schema['design_tokens'];
		$c       = $t['colors'];
		$heading = DesignTokens::font_stack( $t['typography']['heading_font'] );
		$body    = DesignTokens::font_stack( $t['typography']['body_font'] );
		$shadows = array(
			'none'   => 'none',
			'soft'   => '0 1px 3px rgba(0,0,0,.08), 0 4px 12px rgba(0,0,0,.05)',
			'medium' => '0 4px 10px rgba(0,0,0,.08), 0 12px 28px rgba(0,0,0,.08)',
		);
		$shadow  = isset( $shadows[ $t['shadow'] ] ) ? $shadows[ $t['shadow'] ] : 'none';
		$mobile  = (int) $t['breakpoints']['mobile'];

		$vars = array(
			'--aipd-primary'    => $c['primary'],
			'--aipd-secondary'  => $c['secondary'],
			'--aipd-accent'     => $c['accent'],
			'--aipd-text'       => $c['text'],
			'--aipd-muted'      => $c['text_muted'],
			'--aipd-bg'         => $c['background'],
			'--aipd-surface'    => $c['surface'],
			'--aipd-on-primary' => $c['on_primary'],
			'--aipd-radius'     => (int) $t['radius'] . 'px',
			'--aipd-shadow'     => $shadow,
			'--aipd-font-body'  => $body,
			'--aipd-font-head'  => $heading,
			'--aipd-line'       => (string) (float) $t['typography']['line_height'],
			'--aipd-weight'     => (string) (int) $t['typography']['heading_weight'],
		);

		$css = $scope . '{';
		foreach ( $vars as $name => $value ) {
			$css .= $name . ':' . $value . ';';
		}
		$css .= '}';

		$rules = array(
			'{font-family:var(--aipd-font-body);line-height:var(--aipd-line)}',
			' :is(h1,h2,h3,h4,h5,h6){font-family:var(--aipd-font-head);font-weight:var(--aipd-weight);line-height:1.2}',
			' .aipd-card{background:var(--aipd-bg);color:var(--aipd-text);border-radius:var(--aipd-radius);box-shadow:var(--aipd-shadow);padding:clamp(20px,3vw,32px);border:1px solid rgba(0,0,0,.06)}',
			' .aipd-card--highlight{outline:2px solid var(--aipd-primary);outline-offset:-2px}',
			' .aipd-section :is(a,button,summary):focus-visible{outline:3px solid currentColor;outline-offset:3px}',
			' .aipd-bg-primary a:not(.wp-block-button__link),' . $scope . ' .aipd-bg-accent a:not(.wp-block-button__link),' . $scope . ' .aipd-bg-dark a:not(.wp-block-button__link){color:inherit;text-decoration:underline}',
			' .aipd-icon{font-size:1.75em;line-height:1;margin-block-end:.25em}',
			' .aipd-stat-value{font-size:2.25em;line-height:1.1;margin-block-end:.25em}',
			' .wp-block-details{border-block-end:1px solid rgba(0,0,0,.12);padding-block:.75em}',
			' .wp-block-details summary{cursor:pointer;font-weight:600}',
			' .aipd-form-placeholder{border:2px dashed currentColor;border-radius:var(--aipd-radius);padding:24px}',
			'.aipd-dir-rtl{direction:rtl}',
			'.aipd-dir-rtl .wp-block-quote{border-right-style:solid;border-right-width:var(--aipd-quote-border,1px);border-left-width:0;padding-left:0;padding-right:1.25em}',
			'.aipd-dir-ltr{direction:ltr}',
		);
		foreach ( $rules as $rule ) {
			$css .= $scope . $rule;
		}
		$css .= '@media (max-width:' . $mobile . 'px){' . $scope . ' .aipd-section{padding-inline:16px!important}}';
		$css .= '@media (prefers-reduced-motion:reduce){' . $scope . ' *{scroll-behavior:auto!important;transition:none!important}}';

		/**
		 * Filters the scoped CSS for a generated page.
		 *
		 * @since 1.0.0
		 *
		 * @param string $css    CSS.
		 * @param array  $schema Page Schema.
		 * @param string $scope  Scope selector.
		 */
		$css = (string) apply_filters( 'aipd_page_css', $css, $schema, $scope );

		// Never allow markup breakouts from the style element.
		return str_ireplace( array( '</style', '<script', '<!--', '-->' ), '', wp_strip_all_tags( $css ) );
	}
}
