<?php
/**
 * The user's page brief.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\AI\DTO;

use AIPageDesigner\Schema\DesignTokens;
use AIPageDesigner\Schema\Normalizer;
use AIPageDesigner\Schema\PageSchema;
use AIPageDesigner\Security\Kses;
use AIPageDesigner\Security\UrlValidator;

defined( 'ABSPATH' ) || exit;

/**
 * Sanitized brief. Everything here is user data and is sent to the model only
 * inside the data boundary created by PromptBuilder.
 */
final class Brief {

	const TONES = array( 'professional', 'friendly', 'bold', 'luxurious', 'playful', 'technical', 'warm', 'formal' );

	/**
	 * Sanitized values.
	 *
	 * @var array<string,mixed>
	 */
	private $data;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $data Sanitized data.
	 */
	private function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Builds a brief from untrusted input.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @return self
	 */
	public static function from_array( array $input ) {
		$text = function ( $key, $max ) use ( $input ) {
			return isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? Kses::plain( (string) $input[ $key ], $max ) : '';
		};
		$enum = function ( $key, array $allowed, $fallback ) use ( $input ) {
			$value = isset( $input[ $key ] ) && is_string( $input[ $key ] ) ? sanitize_key( $input[ $key ] ) : '';
			return in_array( $value, $allowed, true ) ? $value : $fallback;
		};

		$language = isset( $input['language'] ) && is_string( $input['language'] ) ? str_replace( '_', '-', preg_replace( '/[^A-Za-z0-9_-]/', '', $input['language'] ) ) : '';
		if ( ! preg_match( '/^[a-zA-Z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $language ) ) {
			$language = str_replace( '_', '-', get_locale() );
		}
		$language = strtolower( substr( $language, 0, 2 ) ) . substr( $language, 2 );

		$colors = array();
		if ( isset( $input['colors'] ) && is_array( $input['colors'] ) ) {
			foreach ( array( 'primary', 'secondary', 'accent', 'text', 'background' ) as $key ) {
				$color = isset( $input['colors'][ $key ] ) ? DesignTokens::sanitize_hex( $input['colors'][ $key ] ) : '';
				if ( '' !== $color ) {
					$colors[ $key ] = $color;
				}
			}
		}

		$fonts        = DesignTokens::fonts();
		$heading_font = isset( $input['heading_font'] ) && is_string( $input['heading_font'] ) && isset( $fonts[ $input['heading_font'] ] ) ? $input['heading_font'] : 'system-sans';
		$body_font    = isset( $input['body_font'] ) && is_string( $input['body_font'] ) && isset( $fonts[ $input['body_font'] ] ) ? $input['body_font'] : 'system-sans';

		$builder = isset( $input['builder'] ) && is_string( $input['builder'] ) ? sanitize_key( $input['builder'] ) : 'gutenberg';

		$data = array(
			'title'          => $text( 'title', 120 ),
			'topic'          => $text( 'topic', 300 ),
			'goal'           => $text( 'goal', 500 ),
			'page_type'      => $enum( 'page_type', PageSchema::PAGE_TYPES, 'landing' ),
			'business'       => $text( 'business', 1000 ),
			'brand_name'     => $text( 'brand_name', 100 ),
			'audience'       => $text( 'audience', 500 ),
			'tone'           => $enum( 'tone', self::TONES, 'professional' ),
			'language'       => $language,
			'direction'      => Normalizer::is_rtl_language( $language ) ? 'rtl' : 'ltr',
			'style'          => $enum( 'style', DesignTokens::STYLES, 'corporate' ),
			'colors'         => $colors,
			'heading_font'   => $heading_font,
			'body_font'      => $body_font,
			'cta_text'       => $text( 'cta_text', 60 ),
			'cta_url'        => isset( $input['cta_url'] ) && is_string( $input['cta_url'] ) ? UrlValidator::sanitize_link( $input['cta_url'] ) : '#',
			'sections_count' => isset( $input['sections_count'] ) ? max( 3, min( 10, (int) $input['sections_count'] ) ) : 6,
			'builder'        => $builder,
			'notes'          => $text( 'notes', 1000 ),
		);

		return new self( $data );
	}

	/**
	 * A single value.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public function get( $key ) {
		return isset( $this->data[ $key ] ) ? $this->data[ $key ] : null;
	}

	/**
	 * All sanitized values.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array() {
		return $this->data;
	}

	/**
	 * Fields sent to the AI provider (no internal settings).
	 *
	 * @return array<string,mixed>
	 */
	public function to_prompt_data() {
		$data = $this->data;
		unset( $data['builder'], $data['heading_font'], $data['body_font'] );
		return array_filter(
			$data,
			function ( $value ) {
				return '' !== $value && array() !== $value;
			}
		);
	}

	/**
	 * Design tokens implied by the brief and the brand kit.
	 *
	 * @param array $brand_kit Brand kit.
	 * @return array
	 */
	public function design_tokens( array $brand_kit = array() ) {
		$tokens = DesignTokens::preset( $this->data['style'] );
		$tokens = DesignTokens::apply_brand_kit( $tokens, $brand_kit );
		foreach ( $this->data['colors'] as $key => $color ) {
			$tokens['colors'][ $key ] = $color;
		}
		$tokens['typography']['heading_font'] = $this->data['heading_font'];
		$tokens['typography']['body_font']    = $this->data['body_font'];
		return $tokens;
	}
}
