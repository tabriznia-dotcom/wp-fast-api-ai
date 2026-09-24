<?php
/**
 * Central access to plugin options with defaults and sanitization.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Option names, defaults and typed accessors.
 */
final class Options {

	const SETTINGS  = 'aipd_settings';
	const PROVIDERS = 'aipd_provider_settings';
	const BRAND_KIT = 'aipd_brand_kit';
	const DB_VER    = 'aipd_db_version';

	/**
	 * Default general settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function default_settings() {
		return array(
			'active_provider'          => 'openai_compatible',
			'confirm_paid_requests'    => true,
			'rate_limit_per_hour'      => 30,
			'history_enabled'          => true,
			'logs_enabled'             => true,
			'log_level'                => 'warning',
			'retention_days'           => 30,
			'delete_data_on_uninstall' => false,
			'default_builder'          => 'gutenberg',
			'privacy_acknowledged'     => false,
			'privacy_acknowledged_at'  => '',
			'privacy_acknowledged_by'  => 0,
		);
	}

	/**
	 * Returns merged general settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings() {
		$stored = get_option( self::SETTINGS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::default_settings(), array_intersect_key( $stored, self::default_settings() ) );
	}

	/**
	 * Reads a single general setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$settings = self::settings();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Updates general settings after sanitizing them.
	 *
	 * @param array<string,mixed> $changes Partial settings.
	 * @return array<string,mixed> The saved settings.
	 */
	public static function update_settings( array $changes ) {
		$settings = array_merge( self::settings(), $changes );
		$clean    = self::sanitize_settings( $settings );
		update_option( self::SETTINGS, $clean, true );
		return $clean;
	}

	/**
	 * Sanitizes the full general settings array.
	 *
	 * @param array<string,mixed> $input Raw settings.
	 * @return array<string,mixed>
	 */
	public static function sanitize_settings( $input ) {
		$defaults = self::default_settings();
		$input    = is_array( $input ) ? $input : array();
		$out      = array();

		$out['active_provider']          = isset( $input['active_provider'] ) ? sanitize_key( $input['active_provider'] ) : $defaults['active_provider'];
		$out['confirm_paid_requests']    = ! empty( $input['confirm_paid_requests'] );
		$out['rate_limit_per_hour']      = isset( $input['rate_limit_per_hour'] ) ? max( 1, min( 1000, (int) $input['rate_limit_per_hour'] ) ) : $defaults['rate_limit_per_hour'];
		$out['history_enabled']          = ! empty( $input['history_enabled'] );
		$out['logs_enabled']             = ! empty( $input['logs_enabled'] );
		$out['log_level']                = ( isset( $input['log_level'] ) && in_array( $input['log_level'], array( 'debug', 'info', 'warning', 'error' ), true ) ) ? $input['log_level'] : $defaults['log_level'];
		$out['retention_days']           = isset( $input['retention_days'] ) ? max( 1, min( 365, (int) $input['retention_days'] ) ) : $defaults['retention_days'];
		$out['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] );
		$out['default_builder']          = isset( $input['default_builder'] ) ? sanitize_key( $input['default_builder'] ) : $defaults['default_builder'];
		$out['privacy_acknowledged']     = ! empty( $input['privacy_acknowledged'] );
		$out['privacy_acknowledged_at']  = isset( $input['privacy_acknowledged_at'] ) ? sanitize_text_field( (string) $input['privacy_acknowledged_at'] ) : '';
		$out['privacy_acknowledged_by']  = isset( $input['privacy_acknowledged_by'] ) ? absint( $input['privacy_acknowledged_by'] ) : 0;

		return $out;
	}

	/**
	 * Returns the stored (not decrypted) settings for one provider.
	 *
	 * @param string $provider_id Provider identifier.
	 * @return array<string,mixed>
	 */
	public static function provider_settings( $provider_id ) {
		$all = get_option( self::PROVIDERS, array() );
		if ( ! is_array( $all ) || ! isset( $all[ $provider_id ] ) || ! is_array( $all[ $provider_id ] ) ) {
			return array();
		}
		return $all[ $provider_id ];
	}

	/**
	 * Replaces the stored settings for one provider.
	 *
	 * @param string              $provider_id Provider identifier.
	 * @param array<string,mixed> $values      Already sanitized values.
	 * @return void
	 */
	public static function update_provider_settings( $provider_id, array $values ) {
		$all = get_option( self::PROVIDERS, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$all[ sanitize_key( $provider_id ) ] = $values;
		// Not autoloaded: only needed when talking to a provider or on the settings screen.
		update_option( self::PROVIDERS, $all, false );
	}

	/**
	 * Default brand kit.
	 *
	 * @return array<string,mixed>
	 */
	public static function default_brand_kit() {
		return array(
			'brand_name'   => '',
			'logo_id'      => 0,
			'colors'       => array(
				'primary'    => '#1f4fd1',
				'secondary'  => '#0f766e',
				'accent'     => '#b45309',
				'text'       => '#111827',
				'background' => '#ffffff',
			),
			'font_heading' => 'system-sans',
			'font_body'    => 'system-sans',
			'style'        => 'corporate',
			'tone'         => 'professional',
			'language'     => '',
		);
	}

	/**
	 * Returns the brand kit merged with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function brand_kit() {
		$stored = get_option( self::BRAND_KIT, array() );
		$kit    = self::default_brand_kit();
		if ( is_array( $stored ) ) {
			foreach ( $kit as $key => $value ) {
				if ( isset( $stored[ $key ] ) ) {
					$kit[ $key ] = 'colors' === $key && is_array( $stored[ $key ] ) ? array_merge( $value, $stored[ $key ] ) : $stored[ $key ];
				}
			}
		}
		return $kit;
	}
}
