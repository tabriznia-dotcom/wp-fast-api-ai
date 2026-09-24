<?php
/**
 * Provider that delegates to the AI Client built into WordPress 7.0+.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\AI\Providers;

use AIPageDesigner\AI\DTO\CompletionRequest;
use AIPageDesigner\AI\DTO\CompletionResponse;
use AIPageDesigner\Security\Redactor;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Uses wp_ai_client_prompt(). Credentials are managed by WordPress in
 * Settings > Connectors; this plugin never sees or stores them.
 */
class WPAIClientProvider extends AbstractProvider {

	const ID = 'wp_ai_client';

	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return self::ID;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'WordPress AI Client (Connectors)', 'ai-page-designer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Uses the AI services connected in Settings > Connectors (WordPress 7.0 or newer). API keys stay in WordPress core settings.', 'ai-page-designer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return function_exists( 'wp_ai_client_prompt' ) && function_exists( 'wp_supports_ai' ) && wp_supports_ai();
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured() {
		if ( ! $this->is_available() ) {
			return false;
		}
		try {
			return (bool) wp_ai_client_prompt( 'ping' )->is_supported_for_text_generation();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields() {
		return array(
			array(
				'id'          => 'model',
				'label'       => __( 'Preferred model (optional)', 'ai-page-designer' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'Leave empty to let WordPress choose a suitable connected model.', 'ai-page-designer' ),
			),
			array(
				'id'      => 'temperature',
				'label'   => __( 'Temperature', 'ai-page-designer' ),
				'type'    => 'number',
				'default' => 0.7,
				'min'     => 0,
				'max'     => 2,
				'step'    => 0.1,
			),
			array(
				'id'      => 'max_tokens',
				'label'   => __( 'Maximum output tokens', 'ai-page-designer' ),
				'type'    => 'number',
				'default' => 6000,
				'min'     => 256,
				'max'     => 32000,
				'step'    => 1,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_privacy_info() {
		return array(
			'service'     => __( 'the AI service connected in Settings > Connectors', 'ai-page-designer' ),
			'terms_url'   => '',
			'privacy_url' => '',
			'data_sent'   => __( 'The page brief you enter, the approved outline and, when regenerating a section, that section\'s content.', 'ai-page-designer' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param CompletionRequest $request Request.
	 */
	public function complete( CompletionRequest $request ) {
		if ( ! $this->is_available() ) {
			return new WP_Error( 'aipd_not_configured', __( 'The WordPress AI Client is not available on this site.', 'ai-page-designer' ) );
		}

		try {
			$builder = wp_ai_client_prompt( $request->user )
				->using_system_instruction( $request->system )
				->using_temperature( (float) ( null !== $request->temperature ? $request->temperature : $this->setting( 'temperature' ) ) )
				->using_max_tokens( (int) ( null !== $request->max_tokens ? $request->max_tokens : $this->setting( 'max_tokens' ) ) );

			$model = (string) $this->setting( 'model' );
			if ( '' !== $model ) {
				$builder = $builder->using_model_preference( $model );
			}
			if ( $request->json ) {
				$builder = $builder->as_json_response();
			}

			$result = $builder->generate_text_result();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'aipd_service_error', __( 'The WordPress AI Client could not complete the request.', 'ai-page-designer' ), array( 'details' => Redactor::redact_string( substr( $e->getMessage(), 0, 200 ) ) ) );
		}

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'aipd_service_error', __( 'The WordPress AI Client could not complete the request.', 'ai-page-designer' ), array( 'details' => Redactor::redact_string( substr( $result->get_error_message(), 0, 200 ) ) ) );
		}

		try {
			$usage = $result->getTokenUsage();
			return new CompletionResponse(
				$result->toText(),
				$result->getModelMetadata()->getId(),
				$usage->getPromptTokens(),
				$usage->getCompletionTokens(),
				'stop'
			);
		} catch ( \Throwable $e ) {
			return new WP_Error( 'aipd_incomplete_response', __( 'The AI service returned an incomplete response.', 'ai-page-designer' ) );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function test_connection() {
		if ( ! $this->is_available() ) {
			return new WP_Error( 'aipd_not_available', __( 'The WordPress AI Client requires WordPress 7.0 or newer with AI features enabled.', 'ai-page-designer' ) );
		}
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'aipd_not_configured', __( 'No connected AI service supports text generation. Connect one in Settings > Connectors.', 'ai-page-designer' ) );
		}
		return true;
	}
}
