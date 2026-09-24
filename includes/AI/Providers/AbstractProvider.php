<?php
/**
 * Shared settings handling for providers.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\AI\Providers;

use AIPageDesigner\AI\Contracts\ProviderInterface;
use AIPageDesigner\Core\Options;
use AIPageDesigner\Security\Secrets;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Base class implementing settings storage based on get_settings_fields().
 */
abstract class AbstractProvider implements ProviderInterface {

	/**
	 * Stored settings merged with field defaults. Secret fields stay encrypted here.
	 *
	 * @return array<string,mixed>
	 */
	protected function settings() {
		$stored = Options::provider_settings( $this->get_id() );
		$out    = array();
		foreach ( $this->get_settings_fields() as $field ) {
			$id         = $field['id'];
			$out[ $id ] = array_key_exists( $id, $stored ) ? $stored[ $id ] : ( isset( $field['default'] ) ? $field['default'] : '' );
		}
		return $out;
	}

	/**
	 * Reads a single setting.
	 *
	 * @param string $id Field id.
	 * @return mixed
	 */
	protected function setting( $id ) {
		$settings = $this->settings();
		return isset( $settings[ $id ] ) ? $settings[ $id ] : null;
	}

	/**
	 * Name of the wp-config.php constant that can hold a secret field.
	 *
	 * @param string $field_id Field id.
	 * @return string
	 */
	public function secret_constant( $field_id ) {
		return strtoupper( 'AIPD_' . $this->get_id() . '_' . $field_id );
	}

	/**
	 * Whether a secret comes from wp-config.php.
	 *
	 * @param string $field_id Field id.
	 * @return bool
	 */
	public function secret_from_constant( $field_id ) {
		$constant = $this->secret_constant( $field_id );
		return defined( $constant ) && '' !== (string) constant( $constant );
	}

	/**
	 * Decrypted secret. Never expose the return value outside the HTTP request.
	 *
	 * @param string $field_id Field id.
	 * @return string
	 */
	protected function secret( $field_id ) {
		if ( $this->secret_from_constant( $field_id ) ) {
			return (string) constant( $this->secret_constant( $field_id ) );
		}
		$stored = $this->setting( $field_id );
		return is_string( $stored ) ? Secrets::decrypt( $stored ) : '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_public_settings() {
		$out = array();
		foreach ( $this->get_settings_fields() as $field ) {
			$id = $field['id'];
			if ( ! empty( $field['secret'] ) ) {
				$out[ $id ] = array(
					'is_set'        => '' !== $this->secret( $id ),
					'from_constant' => $this->secret_from_constant( $id ),
				);
				continue;
			}
			$out[ $id ] = $this->setting( $id );
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $input Raw input.
	 */
	public function save_settings( array $input ) {
		$stored = Options::provider_settings( $this->get_id() );
		$clean  = array();

		foreach ( $this->get_settings_fields() as $field ) {
			$id    = $field['id'];
			$type  = isset( $field['type'] ) ? $field['type'] : 'text';
			$value = array_key_exists( $id, $input ) ? $input[ $id ] : null;

			if ( ! empty( $field['secret'] ) ) {
				$remove = ! empty( $input[ $id . '_remove' ] );
				if ( $remove ) {
					$clean[ $id ] = '';
				} elseif ( is_string( $value ) && '' !== trim( $value ) ) {
					$secret = trim( wp_unslash( $value ) );
					if ( strlen( $secret ) > 512 || preg_match( '/\s/', $secret ) ) {
						return new WP_Error( 'aipd_invalid_secret', __( 'The API key format is not valid.', 'ai-page-designer' ) );
					}
					$clean[ $id ] = Secrets::encrypt( $secret );
				} else {
					$clean[ $id ] = isset( $stored[ $id ] ) ? $stored[ $id ] : '';
				}
				continue;
			}

			$result = $this->sanitize_field( $field, $type, $value );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$clean[ $id ] = $result;
		}

		Options::update_provider_settings( $this->get_id(), $clean );
		return true;
	}

	/**
	 * Sanitizes one non-secret field.
	 *
	 * @param array  $field Field definition.
	 * @param string $type  Field type.
	 * @param mixed  $value Raw value.
	 * @return mixed|WP_Error
	 */
	protected function sanitize_field( array $field, $type, $value ) {
		$default = isset( $field['default'] ) ? $field['default'] : '';
		switch ( $type ) {
			case 'checkbox':
				return ! empty( $value );
			case 'number':
				if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
					return $default;
				}
				$number = ( isset( $field['step'] ) && floor( (float) $field['step'] ) !== (float) $field['step'] ) ? (float) $value : (int) $value;
				if ( isset( $field['min'] ) ) {
					$number = max( $field['min'], $number );
				}
				if ( isset( $field['max'] ) ) {
					$number = min( $field['max'], $number );
				}
				return $number;
			case 'select':
				$value = is_string( $value ) ? sanitize_key( $value ) : '';
				return isset( $field['options'][ $value ] ) ? $value : $default;
			case 'url':
				return is_string( $value ) ? trim( wp_unslash( $value ) ) : $default;
			default:
				return is_string( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : $default;
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_paid() {
		return true;
	}
}
