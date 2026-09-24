<?php
/**
 * Contract every AI provider must implement.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\AI\Contracts;

use AIPageDesigner\AI\DTO\CompletionRequest;
use AIPageDesigner\AI\DTO\CompletionResponse;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Providers are registered on the `aipd_register_providers` action.
 *
 * Implementations must:
 * - send requests from the server only,
 * - never return, log or pass API keys to hooks,
 * - return WP_Error objects with user-safe messages.
 */
interface ProviderInterface {

	/**
	 * Unique provider id (sanitize_key compatible).
	 *
	 * @return string
	 */
	public function get_id();

	/**
	 * Human-readable, translated provider name.
	 *
	 * @return string
	 */
	public function get_name();

	/**
	 * Short translated description shown on the settings screen.
	 *
	 * @return string
	 */
	public function get_description();

	/**
	 * Whether the provider can run in this environment (for example a required core API exists).
	 *
	 * @return bool
	 */
	public function is_available();

	/**
	 * Whether enough settings exist to send a request (for example an API key is set).
	 *
	 * @return bool
	 */
	public function is_configured();

	/**
	 * Whether requests may cost money. When true, users confirm each generation request.
	 *
	 * @return bool
	 */
	public function is_paid();

	/**
	 * Settings field definitions for the settings screen.
	 *
	 * Each field: array( 'id', 'label', 'type' => text|url|password|number|checkbox|select,
	 * 'default', 'description', 'options', 'min', 'max', 'step', 'secret' => bool ).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_settings_fields();

	/**
	 * Returns non-secret settings for display. Secret fields are reported only as "set" or "not set".
	 *
	 * @return array<string,mixed>
	 */
	public function get_public_settings();

	/**
	 * Validates and stores settings. Empty secret fields keep the existing secret.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @return true|WP_Error
	 */
	public function save_settings( array $input );

	/**
	 * Sends a completion request.
	 *
	 * @param CompletionRequest $request Request.
	 * @return CompletionResponse|WP_Error
	 */
	public function complete( CompletionRequest $request );

	/**
	 * Checks connectivity and credentials without incurring generation costs when possible.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection();

	/**
	 * Information about the external service for privacy disclosures.
	 *
	 * @return array{service:string,terms_url:string,privacy_url:string,data_sent:string}
	 */
	public function get_privacy_info();
}
