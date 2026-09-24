<?php
/**
 * Shared test fixtures.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Tests;

/**
 * Schema fixtures used by several tests.
 */
final class Fixtures {

	/**
	 * Every component type in one schema, LTR and RTL.
	 *
	 * @return array
	 */
	public static function kitchen_sink( $language ) {
		return array(
			'meta'          => array(
				'title'     => 'Kitchen sink',
				'language'  => $language,
				'direction' => 'ltr',
				'page_type' => 'landing',
				'style'     => 'saas',
			),
			'design_tokens' => array(),
			'sections'      => array(
				array(
					'id'         => 'hero',
					'type'       => 'hero',
					'label'      => 'Intro & more',
					'background' => 'primary',
					'align'      => 'center',
					'columns'    => array(
						array(
							'components' => array(
								array( 'type' => 'heading', 'level' => 1, 'text' => 'Big <strong>title</strong> & "quotes"' ),
								array( 'type' => 'paragraph', 'text' => 'Para with <a href="https://example.com">link</a> [gallery] and <em>emphasis</em>', 'size' => 'large', 'muted' => true ),
								array( 'type' => 'paragraph', 'text' => 'End aligned', 'align' => 'end' ),
								array(
									'type'  => 'buttons',
									'items' => array(
										array( 'text' => 'Primary', 'url' => '#contact', 'variant' => 'primary' ),
										array( 'text' => 'Outline', 'url' => 'https://example.com/a?b=1&c=2', 'variant' => 'outline' ),
										array( 'text' => 'Secondary', 'url' => 'tel:+123', 'variant' => 'secondary' ),
									),
								),
								array( 'type' => 'image', 'alt' => 'Hero image', 'caption' => 'A caption', 'aspect' => '4/3' ),
								array( 'type' => 'image', 'alt' => '', 'decorative' => true ),
							),
						),
					),
				),
				array(
					'id'         => 'features',
					'type'       => 'features',
					'background' => 'surface',
					'layout'     => array( 'stack_on_mobile' => false, 'vertical_align' => 'center' ),
					'columns'    => array(
						array(
							'style'      => 'card',
							'width'      => 40,
							'components' => array(
								array( 'type' => 'icon', 'name' => 'bolt', 'label' => 'Fast' ),
								array( 'type' => 'heading', 'level' => 3, 'text' => 'Skipped level' ),
								array( 'type' => 'list', 'items' => array( 'One', '<strong>Two</strong>' ) ),
								array( 'type' => 'list', 'ordered' => true, 'items' => array( 'A', 'B' ) ),
							),
						),
						array(
							'style'      => 'plain',
							'components' => array(
								array( 'type' => 'stat', 'value' => '99%', 'label' => 'Uptime' ),
								array( 'type' => 'spacer', 'size' => 'md' ),
								array( 'type' => 'separator' ),
							),
						),
						array(
							'components' => array(
								array( 'type' => 'testimonial', 'quote' => 'Great <em>service</em>', 'author' => 'Jane', 'role' => 'CEO, Acme' ),
								array( 'type' => 'testimonial', 'quote' => 'No role', 'author' => 'Sam' ),
							),
						),
					),
				),
				array(
					'id'         => 'faq',
					'type'       => 'faq',
					'background' => 'dark',
					'columns'    => array(
						array(
							'components' => array(
								array( 'type' => 'heading', 'level' => 2, 'text' => 'FAQ' ),
								array(
									'type'  => 'faq',
									'items' => array(
										array( 'question' => 'Why <b>bold</b>?', 'answer' => 'Because <strong>yes</strong>.' ),
										array( 'question' => 'Second?', 'answer' => 'Answer.' ),
									),
								),
							),
						),
					),
				),
				array(
					'id'         => 'pricing',
					'type'       => 'pricing',
					'background' => 'accent',
					'columns'    => array(
						array(
							'style'      => 'card',
							'components' => array(
								array(
									'type'  => 'pricing',
									'plans' => array(
										array( 'name' => 'Basic', 'price' => '$9', 'period' => '/mo', 'features' => array( 'A', 'B' ), 'cta' => array( 'text' => 'Buy', 'url' => '#' ) ),
										array( 'name' => 'Pro', 'price' => '$29', 'description' => 'Best', 'highlighted' => true ),
									),
								),
								array(
									'type'  => 'pricing',
									'plans' => array( array( 'name' => 'Solo', 'price' => '1', 'highlighted' => true ) ),
								),
							),
						),
					),
				),
				array(
					'id'         => 'contact',
					'type'       => 'contact',
					'columns'    => array(
						array(
							'components' => array(
								array(
									'type'        => 'form',
									'fields'      => array(
										array( 'type' => 'text', 'label' => 'Name', 'required' => true ),
										array( 'type' => 'email', 'label' => 'Email' ),
									),
									'submit_text' => 'Send',
									'email'       => 'hello@example.com',
								),
							),
						),
					),
				),
			),
		);
	}

}
