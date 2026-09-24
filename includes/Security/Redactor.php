<?php
/**
 * Removes secrets from data before it is logged or passed to hooks.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Recursive redaction helper.
 */
final class Redactor {

	const MASK = '[redacted]';

	/**
	 * Keys whose values are always removed.
	 *
	 * @var string[]
	 */
	private static $sensitive_keys = array(
		'api_key',
		'apikey',
		'api_key_encrypted',
		'authorization',
		'auth',
		'token',
		'access_token',
		'refresh_token',
		'secret',
		'password',
		'pass',
		'cookie',
		'set-cookie',
		'x-api-key',
		'key',
	);

	/**
	 * Redacts a value of any type.
	 *
	 * @param mixed $value Value to clean.
	 * @param int   $depth Current recursion depth.
	 * @return mixed
	 */
	public static function redact( $value, $depth = 0 ) {
		if ( $depth > 8 ) {
			return self::MASK;
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				if ( is_string( $key ) && in_array( strtolower( $key ), self::$sensitive_keys, true ) ) {
					$out[ $key ] = self::MASK;
					continue;
				}
				$out[ $key ] = self::redact( $item, $depth + 1 );
			}
			return $out;
		}
		if ( is_object( $value ) ) {
			return self::redact( get_object_vars( $value ), $depth + 1 );
		}
		if ( is_string( $value ) ) {
			return self::redact_string( $value );
		}
		return $value;
	}

	/**
	 * Masks token-like substrings (bearer tokens, sk- keys, long hex/base64 blobs).
	 *
	 * @param string $text Input.
	 * @return string
	 */
	public static function redact_string( $text ) {
		$patterns = array(
			'/Bearer\s+[A-Za-z0-9._\-~+\/=]+/i',
			'/\b(sk|pk|rk)-[A-Za-z0-9_\-]{8,}\b/',
			'/\baipd1:[A-Za-z0-9+\/=]+/',
			'/\b[A-Za-z0-9_\-]{40,}\b/',
		);
		return (string) preg_replace( $patterns, self::MASK, $text );
	}
}
