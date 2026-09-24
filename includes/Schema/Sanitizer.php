<?php
/**
 * Coercive sanitizer: turns untrusted model output into schema-shaped data.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Schema;

use AIPageDesigner\Security\Kses;
use AIPageDesigner\Security\UrlValidator;

defined( 'ABSPATH' ) || exit;

/**
 * Walks the schema definition and:
 * - drops unknown properties and unknown component types,
 * - cleans strings according to x-format (kses, links, colors, ids),
 * - replaces invalid enum values with defaults,
 * - clamps numbers and truncates arrays and strings,
 * - drops items that cannot be repaired.
 *
 * Every repair is reported as a warning. The result is then validated strictly.
 */
final class Sanitizer {

	/**
	 * Marker for values that must be removed.
	 *
	 * @var object|null
	 */
	private static $invalid = null;

	/**
	 * Repair warnings.
	 *
	 * @var string[]
	 */
	private $warnings = array();

	/**
	 * Sanitizes data against a schema node.
	 *
	 * @param mixed $data   Untrusted value.
	 * @param array $schema Schema node.
	 * @return array{0:mixed,1:string[]} Clean value (null if unrecoverable) and warnings.
	 */
	public function sanitize( $data, array $schema ) {
		$this->warnings = array();
		$clean          = $this->walk( $data, $schema, '$' );
		return array( self::is_invalid( $clean ) ? null : $clean, $this->warnings );
	}

	/**
	 * Returns the invalid marker.
	 *
	 * @return object
	 */
	private static function invalid() {
		if ( null === self::$invalid ) {
			self::$invalid = new \stdClass();
		}
		return self::$invalid;
	}

	/**
	 * Whether a value is the invalid marker.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_invalid( $value ) {
		return is_object( $value ) && self::invalid() === $value;
	}

	/**
	 * Recursive sanitization.
	 *
	 * @param mixed  $data   Value.
	 * @param array  $schema Schema node.
	 * @param string $path   Path for warnings.
	 * @return mixed
	 */
	private function walk( $data, array $schema, $path ) {
		if ( isset( $schema['oneOf'] ) ) {
			return $this->walk_one_of( $data, $schema, $path );
		}

		$type = isset( $schema['type'] ) ? $schema['type'] : null;

		switch ( $type ) {
			case 'object':
				return $this->walk_object( $data, $schema, $path );
			case 'array':
				return $this->walk_array( $data, $schema, $path );
			case 'string':
				return $this->walk_string( $data, $schema, $path );
			case 'integer':
			case 'number':
				return $this->walk_number( $data, $schema, $path, $type );
			case 'boolean':
				if ( is_bool( $data ) ) {
					return $data;
				}
				if ( is_int( $data ) || in_array( $data, array( '0', '1', 'true', 'false' ), true ) ) {
					return in_array( $data, array( 1, '1', 'true' ), true );
				}
				return $this->fallback( $schema, $path, 'invalid boolean' );
		}

		return self::invalid();
	}

	/**
	 * Objects: keep known properties, fill defaults, enforce required.
	 *
	 * @param mixed  $data   Value.
	 * @param array  $schema Schema.
	 * @param string $path   Path.
	 * @return mixed
	 */
	private function walk_object( $data, array $schema, $path ) {
		if ( ! is_array( $data ) || ( ! empty( $data ) && Validator::is_list( $data ) ) ) {
			if ( isset( $schema['properties'] ) && empty( $schema['required'] ) ) {
				$data = array();
			} else {
				$this->warnings[] = sprintf( '%s: expected an object and it was removed', $path );
				return self::invalid();
			}
		}

		$properties = isset( $schema['properties'] ) ? $schema['properties'] : array();
		$required   = isset( $schema['required'] ) ? $schema['required'] : array();
		$out        = array();

		foreach ( $data as $key => $value ) {
			if ( ! is_string( $key ) || ! isset( $properties[ $key ] ) ) {
				$this->warnings[] = sprintf( '%s: removed unknown property "%s"', $path, is_string( $key ) ? sanitize_key( $key ) : (string) $key );
			}
		}

		foreach ( $properties as $key => $property_schema ) {
			if ( array_key_exists( $key, $data ) ) {
				$value = $this->walk( $data[ $key ], $property_schema, $path . '.' . $key );
				if ( ! self::is_invalid( $value ) ) {
					$out[ $key ] = $value;
					continue;
				}
			}
			if ( array_key_exists( 'default', $property_schema ) ) {
				$out[ $key ] = $property_schema['default'];
			} elseif ( 'object' === ( isset( $property_schema['type'] ) ? $property_schema['type'] : '' ) && empty( $property_schema['required'] ) ) {
				// Optional objects without required members are filled with their defaults.
				$out[ $key ] = $this->walk( array(), $property_schema, $path . '.' . $key );
			} elseif ( in_array( $key, $required, true ) ) {
				$this->warnings[] = sprintf( '%s: missing required "%s"', $path, $key );
				return self::invalid();
			}
		}

		return $out;
	}

	/**
	 * Arrays: sanitize items, drop invalid ones, truncate, enforce minItems.
	 *
	 * @param mixed  $data   Value.
	 * @param array  $schema Schema.
	 * @param string $path   Path.
	 * @return mixed
	 */
	private function walk_array( $data, array $schema, $path ) {
		if ( ! is_array( $data ) ) {
			return $this->fallback( $schema, $path, 'expected a list' );
		}
		$data = array_values( $data );
		$max  = isset( $schema['maxItems'] ) ? (int) $schema['maxItems'] : PHP_INT_MAX;
		if ( count( $data ) > $max ) {
			$this->warnings[] = sprintf( '%s: truncated to %d items', $path, $max );
			$data             = array_slice( $data, 0, $max );
		}

		$out = array();
		foreach ( $data as $index => $item ) {
			$value = isset( $schema['items'] ) ? $this->walk( $item, $schema['items'], $path . '[' . $index . ']' ) : self::invalid();
			if ( self::is_invalid( $value ) ) {
				$this->warnings[] = sprintf( '%s[%d]: removed invalid item', $path, $index );
				continue;
			}
			$out[] = $value;
		}

		if ( isset( $schema['minItems'] ) && count( $out ) < $schema['minItems'] ) {
			return $this->fallback( $schema, $path, 'not enough valid items' );
		}
		return $out;
	}

	/**
	 * Strings: format-specific cleaning, enum and length checks.
	 *
	 * @param mixed  $data   Value.
	 * @param array  $schema Schema.
	 * @param string $path   Path.
	 * @return mixed
	 */
	private function walk_string( $data, array $schema, $path ) {
		if ( is_int( $data ) || is_float( $data ) ) {
			$data = (string) $data;
		}
		if ( ! is_string( $data ) ) {
			return $this->fallback( $schema, $path, 'expected text' );
		}

		$max    = isset( $schema['maxLength'] ) ? (int) $schema['maxLength'] : 5000;
		$format = isset( $schema['x-format'] ) ? $schema['x-format'] : 'plain';

		switch ( $format ) {
			case 'inline':
				$value = Kses::inline( $data, $max );
				break;
			case 'link':
				$value = UrlValidator::sanitize_link( $data );
				break;
			case 'color':
				$value = DesignTokens::sanitize_hex( $data );
				break;
			case 'id':
				$value = substr( trim( preg_replace( '/[^a-z0-9-]+/', '-', strtolower( remove_accents( $data ) ) ), '-' ), 0, 40 );
				if ( '' !== $value && ! preg_match( '/^[a-z]/', $value ) ) {
					$value = 's-' . substr( $value, 0, 38 );
				}
				break;
			case 'lang':
				$value = str_replace( '_', '-', trim( $data ) );
				$value = preg_replace( '/[^A-Za-z0-9-]/', '', $value );
				$parts = explode( '-', $value );
				if ( ! empty( $parts[0] ) ) {
					$parts[0] = strtolower( $parts[0] );
				}
				$value = implode( '-', $parts );
				break;
			case 'font':
				$value = sanitize_key( $data );
				if ( ! isset( DesignTokens::fonts()[ $value ] ) ) {
					$value = '';
				}
				break;
			case 'email':
				$value = sanitize_email( $data );
				break;
			default:
				$value = Kses::plain( $data, $max );
		}

		if ( 'inline' !== $format && function_exists( 'mb_substr' ) && mb_strlen( $value ) > $max ) {
			$value = mb_substr( $value, 0, $max );
		}

		if ( array_key_exists( 'const', $schema ) && $value !== $schema['const'] ) {
			return $this->fallback( $schema, $path, 'unexpected value' );
		}
		if ( isset( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
			$lower = strtolower( $value );
			if ( in_array( $lower, $schema['enum'], true ) ) {
				return $lower;
			}
			return $this->fallback( $schema, $path, 'value not allowed' );
		}
		if ( isset( $schema['pattern'] ) && '' !== $value && ! preg_match( '/' . str_replace( '/', '\/', $schema['pattern'] ) . '/u', $value ) ) {
			return $this->fallback( $schema, $path, 'invalid format' );
		}
		if ( '' === $value && ( isset( $schema['pattern'] ) || ( isset( $schema['minLength'] ) && $schema['minLength'] > 0 ) ) ) {
			return $this->fallback( $schema, $path, 'empty value' );
		}
		if ( isset( $schema['minLength'] ) && ( function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value ) ) < $schema['minLength'] ) {
			return $this->fallback( $schema, $path, 'too short' );
		}

		return $value;
	}

	/**
	 * Numbers: cast and clamp.
	 *
	 * @param mixed  $data   Value.
	 * @param array  $schema Schema.
	 * @param string $path   Path.
	 * @param string $type   integer|number.
	 * @return mixed
	 */
	private function walk_number( $data, array $schema, $path, $type ) {
		if ( is_bool( $data ) || ! is_numeric( $data ) ) {
			return $this->fallback( $schema, $path, 'expected a number' );
		}
		$value = 'integer' === $type ? (int) round( (float) $data ) : (float) $data;
		if ( isset( $schema['minimum'] ) && $value < $schema['minimum'] ) {
			$value = 'integer' === $type ? (int) $schema['minimum'] : (float) $schema['minimum'];
		}
		if ( isset( $schema['maximum'] ) && $value > $schema['maximum'] ) {
			$value = 'integer' === $type ? (int) $schema['maximum'] : (float) $schema['maximum'];
		}
		if ( isset( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
			return $this->fallback( $schema, $path, 'value not allowed' );
		}
		return $value;
	}

	/**
	 * Handles oneOf with a discriminator: sanitize against the matching variant only.
	 *
	 * @param mixed  $data   Value.
	 * @param array  $schema Schema.
	 * @param string $path   Path.
	 * @return mixed
	 */
	private function walk_one_of( $data, array $schema, $path ) {
		$key = isset( $schema['x-discriminator'] ) ? $schema['x-discriminator'] : 'type';
		if ( ! is_array( $data ) || ! isset( $data[ $key ] ) || ! is_string( $data[ $key ] ) ) {
			$this->warnings[] = sprintf( '%s: removed item without a type', $path );
			return self::invalid();
		}
		$wanted = sanitize_key( $data[ $key ] );
		foreach ( $schema['oneOf'] as $variant ) {
			if ( isset( $variant['properties'][ $key ]['const'] ) && $variant['properties'][ $key ]['const'] === $wanted ) {
				$data[ $key ] = $wanted;
				return $this->walk( $data, $variant, $path );
			}
		}
		$this->warnings[] = sprintf( '%s: removed unsupported component "%s"', $path, $wanted );
		return self::invalid();
	}

	/**
	 * Default value or invalid marker, with a warning.
	 *
	 * @param array  $schema Schema.
	 * @param string $path   Path.
	 * @param string $reason Reason.
	 * @return mixed
	 */
	private function fallback( array $schema, $path, $reason ) {
		if ( array_key_exists( 'default', $schema ) ) {
			$this->warnings[] = sprintf( '%s: %s, default used', $path, $reason );
			return $schema['default'];
		}
		$this->warnings[] = sprintf( '%s: %s', $path, $reason );
		return self::invalid();
	}
}
