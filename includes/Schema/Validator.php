<?php
/**
 * Strict JSON Schema validator for the keywords used by the Page Schema.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Supports: type, enum, const, required, properties, additionalProperties (bool),
 * items, minItems, maxItems, minLength, maxLength, pattern, minimum, maximum and
 * oneOf (with an optional x-discriminator shortcut for clear error messages).
 */
final class Validator {

	/**
	 * Collected errors.
	 *
	 * @var string[]
	 */
	private $errors = array();

	/**
	 * Validates data against a schema.
	 *
	 * @param mixed $data   Decoded JSON.
	 * @param array $schema Schema.
	 * @return string[] Error messages; empty when valid.
	 */
	public function validate( $data, array $schema ) {
		$this->errors = array();
		$this->check( $data, $schema, '$' );
		return $this->errors;
	}

	/**
	 * Recursive validation.
	 *
	 * @param mixed  $data   Value.
	 * @param array  $schema Schema node.
	 * @param string $path   JSON path for messages.
	 * @return void
	 */
	private function check( $data, array $schema, $path ) {
		if ( isset( $schema['oneOf'] ) ) {
			$this->check_one_of( $data, $schema, $path );
			return;
		}

		if ( isset( $schema['type'] ) && ! self::is_type( $data, $schema['type'] ) ) {
			$this->errors[] = sprintf( '%s: expected %s', $path, $schema['type'] );
			return;
		}

		if ( array_key_exists( 'const', $schema ) && $data !== $schema['const'] ) {
			$this->errors[] = sprintf( '%s: must equal %s', $path, wp_json_encode( $schema['const'] ) );
		}

		if ( isset( $schema['enum'] ) && ! in_array( $data, $schema['enum'], true ) ) {
			$this->errors[] = sprintf( '%s: value is not allowed', $path );
		}

		if ( is_string( $data ) ) {
			$length = function_exists( 'mb_strlen' ) ? mb_strlen( $data ) : strlen( $data );
			if ( isset( $schema['minLength'] ) && $length < $schema['minLength'] ) {
				$this->errors[] = sprintf( '%s: shorter than %d characters', $path, $schema['minLength'] );
			}
			if ( isset( $schema['maxLength'] ) && $length > $schema['maxLength'] ) {
				$this->errors[] = sprintf( '%s: longer than %d characters', $path, $schema['maxLength'] );
			}
			if ( isset( $schema['pattern'] ) && ! preg_match( '/' . str_replace( '/', '\/', $schema['pattern'] ) . '/u', $data ) ) {
				$this->errors[] = sprintf( '%s: does not match the required format', $path );
			}
		}

		if ( is_int( $data ) || is_float( $data ) ) {
			if ( isset( $schema['minimum'] ) && $data < $schema['minimum'] ) {
				$this->errors[] = sprintf( '%s: below minimum %s', $path, $schema['minimum'] );
			}
			if ( isset( $schema['maximum'] ) && $data > $schema['maximum'] ) {
				$this->errors[] = sprintf( '%s: above maximum %s', $path, $schema['maximum'] );
			}
		}

		if ( isset( $schema['type'] ) && 'array' === $schema['type'] ) {
			$count = count( $data );
			if ( isset( $schema['minItems'] ) && $count < $schema['minItems'] ) {
				$this->errors[] = sprintf( '%s: needs at least %d items', $path, $schema['minItems'] );
			}
			if ( isset( $schema['maxItems'] ) && $count > $schema['maxItems'] ) {
				$this->errors[] = sprintf( '%s: allows at most %d items', $path, $schema['maxItems'] );
			}
			if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
				foreach ( $data as $index => $item ) {
					$this->check( $item, $schema['items'], $path . '[' . $index . ']' );
				}
			}
		}

		if ( isset( $schema['type'] ) && 'object' === $schema['type'] ) {
			$properties = isset( $schema['properties'] ) ? $schema['properties'] : array();
			foreach ( isset( $schema['required'] ) ? $schema['required'] : array() as $required ) {
				if ( ! array_key_exists( $required, $data ) ) {
					$this->errors[] = sprintf( '%s: missing required property "%s"', $path, $required );
				}
			}
			foreach ( $data as $key => $value ) {
				if ( isset( $properties[ $key ] ) ) {
					$this->check( $value, $properties[ $key ], $path . '.' . $key );
				} elseif ( isset( $schema['additionalProperties'] ) && false === $schema['additionalProperties'] ) {
					$this->errors[] = sprintf( '%s: unknown property "%s"', $path, $key );
				}
			}
		}
	}

	/**
	 * Validates oneOf, using the discriminator when all variants declare a const.
	 *
	 * @param mixed  $data   Value.
	 * @param array  $schema Schema node.
	 * @param string $path   Path.
	 * @return void
	 */
	private function check_one_of( $data, array $schema, $path ) {
		$discriminator = isset( $schema['x-discriminator'] ) ? $schema['x-discriminator'] : '';
		if ( $discriminator ) {
			if ( ! is_array( $data ) || ! isset( $data[ $discriminator ] ) ) {
				$this->errors[] = sprintf( '%s: missing "%s"', $path, $discriminator );
				return;
			}
			foreach ( $schema['oneOf'] as $variant ) {
				if ( isset( $variant['properties'][ $discriminator ]['const'] ) && $variant['properties'][ $discriminator ]['const'] === $data[ $discriminator ] ) {
					$this->check( $data, $variant, $path );
					return;
				}
			}
			$this->errors[] = sprintf( '%s: unknown %s', $path, $discriminator );
			return;
		}

		$matches = 0;
		foreach ( $schema['oneOf'] as $variant ) {
			$sub = new self();
			if ( empty( $sub->validate( $data, $variant ) ) ) {
				++$matches;
			}
		}
		if ( 1 !== $matches ) {
			$this->errors[] = sprintf( '%s: must match exactly one allowed shape', $path );
		}
	}

	/**
	 * JSON type check on decoded PHP values.
	 *
	 * @param mixed  $data Value.
	 * @param string $type JSON type.
	 * @return bool
	 */
	public static function is_type( $data, $type ) {
		switch ( $type ) {
			case 'object':
				return is_array( $data ) && ( empty( $data ) || ! self::is_list( $data ) );
			case 'array':
				return is_array( $data ) && self::is_list( $data );
			case 'string':
				return is_string( $data );
			case 'integer':
				return is_int( $data );
			case 'number':
				return is_int( $data ) || is_float( $data );
			case 'boolean':
				return is_bool( $data );
			case 'null':
				return null === $data;
		}
		return false;
	}

	/**
	 * Whether an array is a zero-indexed list.
	 *
	 * @param array $data Array.
	 * @return bool
	 */
	public static function is_list( array $data ) {
		return array_keys( $data ) === range( 0, count( $data ) - 1 ) || array() === $data;
	}
}
