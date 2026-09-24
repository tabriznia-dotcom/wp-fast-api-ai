<?php
/**
 * Provider for OpenAI and OpenAI-compatible Chat Completions APIs.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\AI\Providers;

use AIPageDesigner\AI\DTO\CompletionRequest;
use AIPageDesigner\AI\DTO\CompletionResponse;
use AIPageDesigner\Security\UrlValidator;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Works with any service exposing POST {base}/chat/completions and GET {base}/models.
 */
class OpenAICompatibleProvider extends AbstractProvider {

	const ID = 'openai_compatible';

	/**
	 * Injectable sleep for retry backoff (tests replace it).
	 *
	 * @var callable
	 */
	public $sleeper = 'sleep';

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
		return __( 'OpenAI-compatible API', 'ai-page-designer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Connect to OpenAI or any service that implements the OpenAI Chat Completions API. Requests are sent from your server only.', 'ai-page-designer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields() {
		return array(
			array(
				'id'          => 'api_url',
				'label'       => __( 'API base URL', 'ai-page-designer' ),
				'type'        => 'url',
				'default'     => '',
				'description' => __( 'The base URL of your provider, without /chat/completions, for example https://api.openai.com/v1. HTTPS is required.', 'ai-page-designer' ),
			),
			array(
				'id'          => 'api_key',
				'label'       => __( 'API key', 'ai-page-designer' ),
				'type'        => 'password',
				'secret'      => true,
				'default'     => '',
				'description' => __( 'Stored encrypted. It is never shown again, sent to the browser or written to logs. You can also define it in wp-config.php.', 'ai-page-designer' ),
			),
			array(
				'id'          => 'model',
				'label'       => __( 'Model', 'ai-page-designer' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'The model identifier offered by your provider. Use "Test connection" to list available models.', 'ai-page-designer' ),
			),
			array(
				'id'      => 'timeout',
				'label'   => __( 'Timeout (seconds)', 'ai-page-designer' ),
				'type'    => 'number',
				'default' => 90,
				'min'     => 10,
				'max'     => 300,
				'step'    => 1,
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
			array(
				'id'          => 'max_retries',
				'label'       => __( 'Retries on temporary errors', 'ai-page-designer' ),
				'type'        => 'number',
				'default'     => 2,
				'min'         => 0,
				'max'         => 3,
				'step'        => 1,
				'description' => __( 'Retries happen only for timeouts, rate limits and server errors.', 'ai-page-designer' ),
			),
			array(
				'id'          => 'json_mode',
				'label'       => __( 'Request JSON output mode', 'ai-page-designer' ),
				'type'        => 'checkbox',
				'default'     => true,
				'description' => __( 'Sends response_format = json_object. Disable if your provider does not support it.', 'ai-page-designer' ),
			),
			array(
				'id'          => 'is_paid',
				'label'       => __( 'Requests may cost money', 'ai-page-designer' ),
				'type'        => 'checkbox',
				'default'     => true,
				'description' => __( 'When enabled, users confirm every generation request before it is sent.', 'ai-page-designer' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $input Raw input.
	 */
	public function save_settings( array $input ) {
		if ( isset( $input['api_url'] ) ) {
			$url = UrlValidator::validate_endpoint( wp_unslash( (string) $input['api_url'] ) );
			if ( is_wp_error( $url ) ) {
				return $url;
			}
			$input['api_url'] = $url;
		}
		if ( isset( $input['model'] ) && ! preg_match( '/^[A-Za-z0-9._:\/@-]{0,128}$/', (string) $input['model'] ) ) {
			return new WP_Error( 'aipd_invalid_model', __( 'The model name contains unsupported characters.', 'ai-page-designer' ) );
		}
		return parent::save_settings( $input );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_paid() {
		return (bool) $this->setting( 'is_paid' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured() {
		return '' !== $this->secret( 'api_key' ) && '' !== (string) $this->setting( 'model' ) && '' !== (string) $this->setting( 'api_url' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_privacy_info() {
		$host = wp_parse_url( (string) $this->setting( 'api_url' ), PHP_URL_HOST );
		return array(
			'service'     => $host ? $host : __( 'the configured AI service', 'ai-page-designer' ),
			'terms_url'   => 'api.openai.com' === $host ? 'https://openai.com/policies/terms-of-use/' : '',
			'privacy_url' => 'api.openai.com' === $host ? 'https://openai.com/policies/privacy-policy/' : '',
			'data_sent'   => __( 'The page brief you enter (business description, audience, goals, tone, language, colors and call to action), the approved outline and, when regenerating a section, that section\'s content.', 'ai-page-designer' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param CompletionRequest $request Request.
	 */
	public function complete( CompletionRequest $request ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'aipd_not_configured', __( 'The AI provider is not configured. Add an API key and model in AI Providers.', 'ai-page-designer' ) );
		}

		$body = array(
			'model'    => (string) $this->setting( 'model' ),
			'messages' => array(
				array(
					'role'    => 'system',
					'content' => $request->system,
				),
				array(
					'role'    => 'user',
					'content' => $request->user,
				),
			),
		);

		$temperature = null !== $request->temperature ? $request->temperature : (float) $this->setting( 'temperature' );
		$max_tokens  = null !== $request->max_tokens ? $request->max_tokens : (int) $this->setting( 'max_tokens' );

		$body['temperature'] = max( 0, min( 2, (float) $temperature ) );
		$body['max_tokens']  = max( 16, (int) $max_tokens );
		if ( $request->json && $this->setting( 'json_mode' ) ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		/**
		 * Filters the request body sent to OpenAI-compatible APIs (for example to add
		 * provider-specific parameters). Never add credentials here.
		 *
		 * @since 1.0.0
		 *
		 * @param array             $body    Request body.
		 * @param CompletionRequest $request Request object.
		 */
		$body = (array) apply_filters( 'aipd_openai_compatible_body', $body, $request );

		$response = $this->request( 'POST', '/chat/completions', $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['choices'][0]['message'] ) || ! is_array( $response['choices'][0]['message'] ) ) {
			return new WP_Error( 'aipd_incomplete_response', __( 'The AI service returned an incomplete response.', 'ai-page-designer' ) );
		}

		$message = $response['choices'][0]['message'];
		$text    = isset( $message['content'] ) && is_string( $message['content'] ) ? $message['content'] : '';
		$finish  = isset( $response['choices'][0]['finish_reason'] ) ? (string) $response['choices'][0]['finish_reason'] : '';

		if ( '' === $text && ! empty( $message['refusal'] ) ) {
			return new WP_Error( 'aipd_refused', __( 'The AI model declined this request. Please revise the brief.', 'ai-page-designer' ) );
		}
		if ( 'length' === $finish ) {
			return new WP_Error( 'aipd_truncated', __( 'The AI response was cut off because it reached the maximum output tokens. Increase the limit or request fewer sections.', 'ai-page-designer' ) );
		}

		return new CompletionResponse(
			$text,
			isset( $response['model'] ) ? $response['model'] : $body['model'],
			isset( $response['usage']['prompt_tokens'] ) ? $response['usage']['prompt_tokens'] : 0,
			isset( $response['usage']['completion_tokens'] ) ? $response['usage']['completion_tokens'] : 0,
			$finish
		);
	}

	/**
	 * Lists models. Costs nothing on OpenAI-compatible APIs.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		$models = $this->list_models();
		return is_wp_error( $models ) ? $models : true;
	}

	/**
	 * Available model ids (up to 100).
	 *
	 * @return string[]|WP_Error
	 */
	public function list_models() {
		if ( '' === $this->secret( 'api_key' ) ) {
			return new WP_Error( 'aipd_not_configured', __( 'Add an API key before testing the connection.', 'ai-page-designer' ) );
		}
		$response = $this->request( 'GET', '/models', null, 1, 20 );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$ids = array();
		if ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
			foreach ( $response['data'] as $model ) {
				if ( isset( $model['id'] ) && is_string( $model['id'] ) && preg_match( '/^[A-Za-z0-9._:\/@-]{1,128}$/', $model['id'] ) ) {
					$ids[] = $model['id'];
				}
			}
		}
		sort( $ids );
		return array_slice( $ids, 0, 100 );
	}

	/**
	 * Validated base URL of the API.
	 *
	 * @return string|WP_Error
	 */
	protected function base_url() {
		return UrlValidator::validate_endpoint( (string) $this->setting( 'api_url' ) );
	}

	/**
	 * Authentication headers for a request path. Never logged or passed to hooks.
	 *
	 * @param string $path Request path.
	 * @return array<string,string>
	 */
	protected function auth_headers( $path ) {
		unset( $path );
		return array( 'Authorization' => 'Bearer ' . $this->secret( 'api_key' ) );
	}

	/**
	 * Performs an HTTP request with retries.
	 *
	 * @param string     $method  GET or POST.
	 * @param string     $path    Path appended to the base URL.
	 * @param array|null $body    JSON body.
	 * @param int|null   $retries Override retry count.
	 * @param int|null   $timeout Override timeout.
	 * @return array|WP_Error Decoded JSON.
	 */
	protected function request( $method, $path, $body = null, $retries = null, $timeout = null ) {
		$base = $this->base_url();
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$retries = null === $retries ? (int) $this->setting( 'max_retries' ) : (int) $retries;
		$timeout = null === $timeout ? (int) $this->setting( 'timeout' ) : (int) $timeout;

		$args = array(
			'method'      => $method,
			'timeout'     => $timeout,
			'redirection' => 0,
			'headers'     => array_merge(
				array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				$this->auth_headers( $path )
			),
			'user-agent'  => 'AI-Page-Designer/' . AIPD_VERSION . '; WordPress',
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$url     = $base . $path;
		$attempt = 0;
		$started = time();

		do {
			$response = UrlValidator::local_endpoints_allowed() ? wp_remote_request( $url, $args ) : wp_safe_remote_request( $url, $args );
			$result   = $this->handle_response( $response );

			if ( ! is_wp_error( $result ) ) {
				return $result;
			}

			$data      = $result->get_error_data();
			$retryable = is_array( $data ) && ! empty( $data['retryable'] );
			if ( ! $retryable || $attempt >= $retries ) {
				return $result;
			}

			$wait = isset( $data['retry_after'] ) ? (int) $data['retry_after'] : (int) pow( 2, $attempt );
			$wait = max( 1, min( 10, $wait ) );
			if ( ( time() - $started ) + $wait + $timeout > 300 ) {
				return $result;
			}
			call_user_func( $this->sleeper, $wait );
			++$attempt;
		} while ( true );
	}

	/**
	 * Maps HTTP results to decoded JSON or user-safe errors.
	 *
	 * @param array|WP_Error $response wp_remote_* result.
	 * @return array|WP_Error
	 */
	protected function handle_response( $response ) {
		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			if ( false !== stripos( $message, 'timed out' ) || false !== stripos( $message, 'timeout' ) ) {
				return new WP_Error( 'aipd_timeout', __( 'The AI service did not respond in time. Try again, or increase the timeout in AI Providers.', 'ai-page-designer' ), array( 'retryable' => true ) );
			}
			return new WP_Error(
				'aipd_connection_failed',
				__( 'Could not connect to the AI service. Check the API URL and your server\'s outbound connections.', 'ai-page-designer' ),
				array(
					'retryable' => true,
					'details'   => $this->scrub( substr( $message, 0, 200 ) ),
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );

		if ( $code >= 200 && $code < 300 ) {
			if ( ! is_array( $json ) ) {
				return new WP_Error( 'aipd_invalid_response', __( 'The AI service returned a response that could not be read.', 'ai-page-designer' ) );
			}
			return $json;
		}

		$provider_message = '';
		$provider_type    = '';
		if ( is_array( $json ) && isset( $json['error']['message'] ) && is_string( $json['error']['message'] ) ) {
			$provider_message = $this->scrub( sanitize_text_field( substr( $json['error']['message'], 0, 300 ) ) );
		}
		if ( is_array( $json ) && isset( $json['error']['type'] ) && is_string( $json['error']['type'] ) ) {
			$provider_type = substr( preg_replace( '/[^A-Za-z0-9_.-]/', '', $json['error']['type'] ), 0, 64 );
		}
		$data = array(
			'status'        => $code,
			'details'       => $provider_message,
			'provider_type' => $provider_type,
		);

		if ( 401 === $code || 403 === $code ) {
			return new WP_Error( 'aipd_auth_failed', __( 'The AI service rejected the API key. Check the key and its permissions.', 'ai-page-designer' ), $data );
		}
		if ( 404 === $code ) {
			return new WP_Error( 'aipd_not_found', __( 'The AI service could not find the endpoint or model. Check the API URL and model name.', 'ai-page-designer' ), $data );
		}
		if ( 429 === $code ) {
			$retry_after       = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			$data['retryable'] = true;
			if ( $retry_after > 0 ) {
				$data['retry_after'] = $retry_after;
			}
			return new WP_Error( 'aipd_rate_limited', __( 'The AI service rate limit or quota was reached. Wait a moment and try again, or check your plan.', 'ai-page-designer' ), $data );
		}
		if ( $code >= 500 ) {
			$data['retryable'] = true;
			return new WP_Error( 'aipd_service_error', __( 'The AI service had a temporary problem. Please try again.', 'ai-page-designer' ), $data );
		}
		return new WP_Error( 'aipd_request_rejected', __( 'The AI service rejected the request.', 'ai-page-designer' ), $data );
	}
}
