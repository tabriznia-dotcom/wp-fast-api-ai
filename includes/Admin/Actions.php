<?php
/**
 * Form handlers for admin-post.php.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Admin;

use AIPageDesigner\Core\Capabilities;
use AIPageDesigner\Core\Options;
use AIPageDesigner\Core\Plugin;
use AIPageDesigner\Jobs\JobRepository;
use AIPageDesigner\Logging\Logger;
use AIPageDesigner\Schema\DesignTokens;
use AIPageDesigner\Templates\TemplateRepository;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Missing -- Every handler calls guard() (check_admin_referer + capability) before reading input.

/**
 * Every handler verifies a nonce and a capability before doing anything.
 */
final class Actions {

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function init() {
		$actions = array(
			'aipd_save_provider',
			'aipd_save_settings',
			'aipd_save_privacy',
			'aipd_save_brand_kit',
			'aipd_import_template',
			'aipd_export_template',
			'aipd_delete_template',
			'aipd_clear_logs',
			'aipd_delete_job',
			'aipd_export_settings',
			'aipd_import_settings',
		);
		foreach ( $actions as $action ) {
			add_action( 'admin_post_' . $action, array( $this, substr( $action, 5 ) ) );
		}
	}

	/**
	 * Verifies nonce and capability, or stops.
	 *
	 * @param string $action Nonce action.
	 * @param string $cap    Capability.
	 * @return void
	 */
	private function guard( $action, $cap ) {
		check_admin_referer( $action );
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'ai-page-designer' ), 403 );
		}
	}

	/**
	 * Redirects back to a screen with a notice code.
	 *
	 * @param string $screen Screen suffix.
	 * @param string $notice Notice code.
	 * @param bool   $error  Error notice.
	 * @return void
	 */
	private function back( $screen, $notice, $error = false ) {
		$args = array( 'aipd_notice' => $notice );
		if ( $error ) {
			$args['aipd_type'] = 'error';
		}
		wp_safe_redirect( Admin::url( $screen, $args ) );
		exit;
	}

	/**
	 * Saves one provider.
	 *
	 * @return void
	 */
	public function save_provider() {
		$this->guard( 'aipd_save_provider', Capabilities::MANAGE );
		$id       = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		$provider = $this->plugin->providers->get( $id );
		if ( ! $provider ) {
			$this->back( 'providers', 'invalid_input', true );
		}
		// Each field is sanitized by the provider (secrets are encrypted, never echoed).
		$input  = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per field in save_settings().
		$result = $provider->save_settings( $input );
		if ( is_wp_error( $result ) ) {
			$this->back( 'providers', 'aipd_invalid_url' === $result->get_error_code() || 'aipd_private_url' === $result->get_error_code() || 'aipd_insecure_url' === $result->get_error_code() ? 'invalid_url' : 'invalid_input', true );
		}
		if ( ! empty( $_POST['make_active'] ) ) {
			Options::update_settings( array( 'active_provider' => $id ) );
		}
		Logger::info( 'provider_saved', 'Provider settings updated', array( 'provider' => $id ) );
		$this->back( 'providers', 'provider_saved' );
	}

	/**
	 * Saves API settings.
	 *
	 * @return void
	 */
	public function save_settings() {
		$this->guard( 'aipd_save_settings', Capabilities::MANAGE );
		$builder = isset( $_POST['default_builder'] ) ? sanitize_key( wp_unslash( $_POST['default_builder'] ) ) : 'gutenberg';
		if ( ! $this->plugin->builders->get( $builder ) ) {
			$builder = 'gutenberg';
		}
		Options::update_settings(
			array(
				'confirm_paid_requests' => ! empty( $_POST['confirm_paid_requests'] ),
				'rate_limit_per_hour'   => isset( $_POST['rate_limit_per_hour'] ) ? absint( $_POST['rate_limit_per_hour'] ) : 30,
				'default_builder'       => $builder,
				'logs_enabled'          => ! empty( $_POST['logs_enabled'] ),
				'log_level'             => isset( $_POST['log_level'] ) ? sanitize_key( wp_unslash( $_POST['log_level'] ) ) : 'warning',
			)
		);
		$this->back( 'settings', 'saved' );
	}

	/**
	 * Saves privacy settings and consent.
	 *
	 * @return void
	 */
	public function save_privacy() {
		$this->guard( 'aipd_save_privacy', Capabilities::MANAGE );
		$current = Options::settings();
		$ack     = ! empty( $_POST['privacy_acknowledged'] );
		$changes = array(
			'privacy_acknowledged'     => $ack,
			'history_enabled'          => ! empty( $_POST['history_enabled'] ),
			'retention_days'           => isset( $_POST['retention_days'] ) ? absint( $_POST['retention_days'] ) : 30,
			'delete_data_on_uninstall' => ! empty( $_POST['delete_data_on_uninstall'] ),
		);
		if ( $ack && ! $current['privacy_acknowledged'] ) {
			$changes['privacy_acknowledged_at'] = current_time( 'mysql' );
			$changes['privacy_acknowledged_by'] = get_current_user_id();
		} elseif ( ! $ack ) {
			$changes['privacy_acknowledged_at'] = '';
			$changes['privacy_acknowledged_by'] = 0;
		}
		Options::update_settings( $changes );
		$this->back( 'privacy', 'privacy_saved' );
	}

	/**
	 * Saves the brand kit.
	 *
	 * @return void
	 */
	public function save_brand_kit() {
		$this->guard( 'aipd_save_brand_kit', Capabilities::MANAGE );
		update_option( Options::BRAND_KIT, self::sanitize_brand_kit( wp_unslash( $_POST ) ), false ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized field by field below.
		$this->back( 'brand', 'saved' );
	}

	/**
	 * Sanitizes brand kit input.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize_brand_kit( $input ) {
		$input = is_array( $input ) ? $input : array();
		$kit   = Options::default_brand_kit();
		$fonts = DesignTokens::fonts();

		$kit['brand_name'] = isset( $input['brand_name'] ) ? substr( sanitize_text_field( (string) $input['brand_name'] ), 0, 100 ) : '';
		$logo              = isset( $input['logo_id'] ) ? absint( $input['logo_id'] ) : 0;
		$kit['logo_id']    = $logo && wp_attachment_is_image( $logo ) ? $logo : 0;
		if ( isset( $input['colors'] ) && is_array( $input['colors'] ) ) {
			foreach ( array_keys( $kit['colors'] ) as $key ) {
				$color = isset( $input['colors'][ $key ] ) ? DesignTokens::sanitize_hex( (string) $input['colors'][ $key ] ) : '';
				if ( '' !== $color ) {
					$kit['colors'][ $key ] = $color;
				}
			}
		}
		foreach ( array( 'font_heading', 'font_body' ) as $key ) {
			$value       = isset( $input[ $key ] ) ? sanitize_key( (string) $input[ $key ] ) : '';
			$kit[ $key ] = isset( $fonts[ $value ] ) ? $value : 'system-sans';
		}
		$style           = isset( $input['style'] ) ? sanitize_key( (string) $input['style'] ) : '';
		$kit['style']    = in_array( $style, DesignTokens::STYLES, true ) ? $style : 'corporate';
		$tone            = isset( $input['tone'] ) ? sanitize_key( (string) $input['tone'] ) : '';
		$kit['tone']     = array_key_exists( $tone, Pages::tones() ) ? $tone : 'professional';
		$language        = isset( $input['language'] ) ? str_replace( '_', '-', sanitize_text_field( (string) $input['language'] ) ) : '';
		$kit['language'] = preg_match( '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $language ) ? $language : '';
		return $kit;
	}

	/**
	 * Imports a template upload.
	 *
	 * @return void
	 */
	public function import_template() {
		$this->guard( 'aipd_import_template', Capabilities::MANAGE );
		if ( empty( $_FILES['template_file'] ) || ! is_array( $_FILES['template_file'] ) ) {
			$this->back( 'tools', 'import_failed', true );
		}
		$file   = array_map( 'wp_unslash', $_FILES['template_file'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated in import_upload().
		$result = TemplateRepository::import_upload( $file );
		if ( is_wp_error( $result ) ) {
			Logger::warning( 'template_import_failed', 'Template import rejected', array( 'code' => $result->get_error_code() ) );
			$this->back( 'tools', 'import_failed', true );
		}
		wp_safe_redirect( Admin::url( 'templates', array( 'aipd_notice' => 'imported' ) ) );
		exit;
	}

	/**
	 * Downloads a template as JSON.
	 *
	 * @return void
	 */
	public function export_template() {
		check_admin_referer( 'aipd_export_template' );
		if ( ! Capabilities::can_generate() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'ai-page-designer' ), 403 );
		}
		$id      = isset( $_GET['template'] ) ? sanitize_text_field( wp_unslash( $_GET['template'] ) ) : '';
		$payload = TemplateRepository::export( $id );
		if ( is_wp_error( $payload ) ) {
			wp_die( esc_html( $payload->get_error_message() ), 404 );
		}
		self::download( sanitize_file_name( 'aipd-template-' . sanitize_title( $payload['title'] ) . '.json' ), $payload );
	}

	/**
	 * Deletes a user template.
	 *
	 * @return void
	 */
	public function delete_template() {
		$this->guard( 'aipd_delete_template', Capabilities::MANAGE );
		$id = isset( $_POST['template'] ) ? sanitize_text_field( wp_unslash( $_POST['template'] ) ) : '';
		TemplateRepository::delete( $id );
		$this->back( 'templates', 'deleted' );
	}

	/**
	 * Clears logs.
	 *
	 * @return void
	 */
	public function clear_logs() {
		$this->guard( 'aipd_clear_logs', Capabilities::MANAGE );
		Logger::clear();
		$this->back( 'logs', 'logs_cleared' );
	}

	/**
	 * Deletes a history item (own items, or any item for managers).
	 *
	 * @return void
	 */
	public function delete_job() {
		$this->guard( 'aipd_delete_job', Capabilities::GENERATE );
		$uuid = isset( $_POST['job'] ) ? sanitize_text_field( wp_unslash( $_POST['job'] ) ) : '';
		$job  = wp_is_uuid( $uuid ) ? JobRepository::get( $uuid ) : null;
		if ( ! $job || ( get_current_user_id() !== (int) $job['user_id'] && ! Capabilities::can_manage() ) ) {
			$this->back( 'history', 'forbidden', true );
		}
		JobRepository::delete( $uuid );
		$this->back( 'history', 'deleted' );
	}

	/**
	 * Exports settings without secrets.
	 *
	 * @return void
	 */
	public function export_settings() {
		check_admin_referer( 'aipd_export_settings' );
		if ( ! Capabilities::can_manage() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'ai-page-designer' ), 403 );
		}
		$settings = Options::settings();
		unset( $settings['privacy_acknowledged'], $settings['privacy_acknowledged_at'], $settings['privacy_acknowledged_by'] );

		$providers = array();
		foreach ( $this->plugin->providers->all() as $id => $provider ) {
			$public = $provider->get_public_settings();
			foreach ( $provider->get_settings_fields() as $field ) {
				if ( ! empty( $field['secret'] ) ) {
					unset( $public[ $field['id'] ] );
				}
			}
			$providers[ $id ] = $public;
		}

		self::download(
			'aipd-settings-' . gmdate( 'Y-m-d' ) . '.json',
			array(
				'format'    => 'ai-page-designer-settings',
				'version'   => 1,
				'settings'  => $settings,
				'brand_kit' => Options::brand_kit(),
				'providers' => $providers,
			)
		);
	}

	/**
	 * Imports settings (never secrets, never consent).
	 *
	 * @return void
	 */
	public function import_settings() {
		$this->guard( 'aipd_import_settings', Capabilities::MANAGE );
		$file = isset( $_FILES['settings_file'] ) && is_array( $_FILES['settings_file'] ) ? $_FILES['settings_file'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated below.
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) || (int) $file['size'] > 65536 || '.json' !== strtolower( substr( sanitize_file_name( (string) $file['name'] ), -5 ) ) ) {
			$this->back( 'tools', 'invalid_input', true );
		}
		$data = json_decode( (string) file_get_contents( $file['tmp_name'] ), true, 16 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Uploaded temp file.
		if ( ! is_array( $data ) || ! isset( $data['format'] ) || 'ai-page-designer-settings' !== $data['format'] ) {
			$this->back( 'tools', 'invalid_input', true );
		}

		if ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
			$allowed = array_diff_key( $data['settings'], array_flip( array( 'privacy_acknowledged', 'privacy_acknowledged_at', 'privacy_acknowledged_by' ) ) );
			Options::update_settings( $allowed );
		}
		if ( isset( $data['brand_kit'] ) && is_array( $data['brand_kit'] ) ) {
			update_option( Options::BRAND_KIT, self::sanitize_brand_kit( $data['brand_kit'] ), false );
		}
		if ( isset( $data['providers'] ) && is_array( $data['providers'] ) ) {
			foreach ( $data['providers'] as $id => $values ) {
				$provider = $this->plugin->providers->get( sanitize_key( (string) $id ) );
				if ( $provider && is_array( $values ) ) {
					foreach ( $provider->get_settings_fields() as $field ) {
						if ( ! empty( $field['secret'] ) ) {
							unset( $values[ $field['id'] ], $values[ $field['id'] . '_remove' ] );
						}
					}
					$provider->save_settings( $values );
				}
			}
		}
		$this->back( 'tools', 'settings_imported' );
	}

	/**
	 * Sends a JSON download.
	 *
	 * @param string $filename File name.
	 * @param array  $payload  Data.
	 * @return void
	 */
	private static function download( $filename, array $payload ) {
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
		exit;
	}
}
