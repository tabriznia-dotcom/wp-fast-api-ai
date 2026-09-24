<?php
/**
 * Pipeline for turning untrusted input into a trusted Page Schema.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Schema;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Parse → pre-fill → sanitize → strict validate → normalize.
 */
final class SchemaService {

	/**
	 * Maximum accepted JSON size in bytes.
	 */
	const MAX_BYTES = 262144;

	/**
	 * Extracts a JSON object from model text (tolerates code fences and prose).
	 *
	 * @param string $text Raw model output.
	 * @return array|WP_Error
	 */
	public static function decode( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return new WP_Error( 'aipd_empty_response', __( 'The AI service returned an empty response.', 'ai-page-designer' ) );
		}
		if ( strlen( $text ) > self::MAX_BYTES ) {
			return new WP_Error( 'aipd_response_too_large', __( 'The AI response is too large to process safely.', 'ai-page-designer' ) );
		}

		if ( preg_match( '/```(?:json)?\s*(\{.*\})\s*```/s', $text, $m ) ) {
			$text = $m[1];
		} else {
			$start = strpos( $text, '{' );
			$end   = strrpos( $text, '}' );
			if ( false === $start || false === $end || $end <= $start ) {
				return new WP_Error( 'aipd_invalid_json', __( 'The AI response did not contain a page structure. Try again or simplify the brief.', 'ai-page-designer' ) );
			}
			$text = substr( $text, $start, $end - $start + 1 );
		}

		$data = json_decode( $text, true, 64 );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'aipd_invalid_json', __( 'The AI response was not valid JSON. It may have been cut off; try increasing the maximum tokens or reducing the number of sections.', 'ai-page-designer' ) );
		}
		return $data;
	}

	/**
	 * Full processing of an untrusted schema array.
	 *
	 * @param array $data Decoded, untrusted data.
	 * @return array{schema:array,warnings:string[]}|WP_Error
	 */
	public static function process( array $data ) {
		$data = self::prefill( $data );

		$definition               = PageSchema::definition();
		$sanitizer                = new Sanitizer();
		list( $clean, $warnings ) = $sanitizer->sanitize( $data, $definition );

		if ( null === $clean ) {
			return new WP_Error(
				'aipd_schema_invalid',
				__( 'The generated page structure was incomplete and could not be repaired.', 'ai-page-designer' ),
				array( 'details' => array_slice( $warnings, 0, 20 ) )
			);
		}

		$validator = new Validator();
		$errors    = $validator->validate( $clean, $definition );

		/**
		 * Filters validation errors, allowing extra rules for a Page Schema.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $errors Error messages. Non-empty rejects the schema.
		 * @param array    $clean  Sanitized schema.
		 */
		$errors = (array) apply_filters( 'aipd_validate_schema', $errors, $clean );
		if ( ! empty( $errors ) ) {
			return new WP_Error(
				'aipd_schema_invalid',
				__( 'The generated page structure did not pass validation.', 'ai-page-designer' ),
				array( 'details' => array_slice( array_map( 'sanitize_text_field', $errors ), 0, 20 ) )
			);
		}

		$normalizer                 = new Normalizer();
		list( $clean, $a11y_notes ) = $normalizer->normalize( $clean );

		/**
		 * Filters a validated and normalized Page Schema before it is stored or rendered.
		 * Filtered output is sanitized and validated again.
		 *
		 * @since 1.0.0
		 *
		 * @param array $clean Page Schema.
		 */
		$filtered = apply_filters( 'aipd_page_schema', $clean );
		if ( $filtered !== $clean && is_array( $filtered ) ) {
			list( $filtered ) = ( new Sanitizer() )->sanitize( $filtered, $definition );
			if ( is_array( $filtered ) && empty( ( new Validator() )->validate( $filtered, $definition ) ) ) {
				$clean = $filtered;
			}
		}

		return array(
			'schema'   => $clean,
			'warnings' => array_values( array_unique( array_merge( $warnings, $a11y_notes ) ) ),
		);
	}

	/**
	 * Decodes and processes model text in one step.
	 *
	 * @param string $text Model output.
	 * @return array{schema:array,warnings:string[]}|WP_Error
	 */
	public static function from_text( $text ) {
		$data = self::decode( $text );
		return is_wp_error( $data ) ? $data : self::process( $data );
	}

	/**
	 * Validates an already-trusted schema (for example after client edits).
	 *
	 * @param mixed $data Data.
	 * @return array{schema:array,warnings:string[]}|WP_Error
	 */
	public static function from_client( $data ) {
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'aipd_schema_invalid', __( 'The page structure is missing or malformed.', 'ai-page-designer' ) );
		}
		$encoded = wp_json_encode( $data );
		if ( false === $encoded || strlen( $encoded ) > self::MAX_BYTES ) {
			return new WP_Error( 'aipd_schema_too_large', __( 'The page structure is too large.', 'ai-page-designer' ) );
		}
		return self::process( $data );
	}

	/**
	 * Sanitizes a design token set (from the wizard) on top of a style preset.
	 *
	 * @param mixed  $tokens Untrusted tokens.
	 * @param string $style  Style preset.
	 * @return array
	 */
	public static function sanitize_tokens( $tokens, $style ) {
		$style         = in_array( $style, DesignTokens::STYLES, true ) ? $style : 'corporate';
		$preset        = DesignTokens::preset( $style );
		$tokens        = is_array( $tokens ) ? DesignTokens::merge( $preset, $tokens ) : $preset;
		$def           = PageSchema::definition();
		list( $clean ) = ( new Sanitizer() )->sanitize( $tokens, $def['properties']['design_tokens'] );
		return is_array( $clean ) ? DesignTokens::merge( $preset, $clean ) : $preset;
	}

	/**
	 * Fills structural values the model commonly omits, before sanitization.
	 *
	 * @param array $data Untrusted data.
	 * @return array
	 */
	private static function prefill( array $data ) {
		$data['schema_version'] = PageSchema::VERSION;

		$meta  = isset( $data['meta'] ) && is_array( $data['meta'] ) ? $data['meta'] : array();
		$style = isset( $meta['style'] ) && is_string( $meta['style'] ) && in_array( strtolower( $meta['style'] ), DesignTokens::STYLES, true ) ? strtolower( $meta['style'] ) : 'corporate';

		$tokens = isset( $data['design_tokens'] ) && is_array( $data['design_tokens'] ) ? $data['design_tokens'] : array();
		$preset = DesignTokens::preset( $style );
		foreach ( array( 'colors', 'typography', 'breakpoints' ) as $group ) {
			$given = isset( $tokens[ $group ] ) && is_array( $tokens[ $group ] ) ? $tokens[ $group ] : array();
			if ( 'colors' === $group ) {
				$given = array_filter(
					array_map(
						function ( $value ) {
							return is_string( $value ) ? DesignTokens::sanitize_hex( $value ) : '';
						},
						$given
					)
				);
			}
			$tokens[ $group ] = array_merge( $preset[ $group ], $given );
		}
		$data['design_tokens'] = $tokens;

		if ( isset( $data['sections'] ) && is_array( $data['sections'] ) ) {
			foreach ( $data['sections'] as $index => $section ) {
				if ( ! is_array( $section ) ) {
					continue;
				}
				if ( empty( $section['id'] ) || ! is_string( $section['id'] ) ) {
					$type                             = isset( $section['type'] ) && is_string( $section['type'] ) ? $section['type'] : 'section';
					$data['sections'][ $index ]['id'] = $type . '-' . ( (int) $index + 1 );
				}
				// Accept a flat component list and wrap it in a single column.
				if ( empty( $section['columns'] ) && ! empty( $section['components'] ) && is_array( $section['components'] ) ) {
					$data['sections'][ $index ]['columns'] = array( array( 'components' => $section['components'] ) );
					unset( $data['sections'][ $index ]['components'] );
				}
			}
		}

		return $data;
	}
}
