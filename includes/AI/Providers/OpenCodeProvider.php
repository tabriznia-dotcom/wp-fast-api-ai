<?php
/**
 * Provider for the OpenCode Zen and OpenCode Go model gateways.
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
 * OpenCode serves many model families behind one API key, each with its native
 * API format:
 *
 * - chat:      POST {base}/chat/completions            (OpenAI-compatible, Bearer key)
 * - responses: POST {base}/responses                    (OpenAI Responses API, Bearer key)
 * - messages:  POST {base}/messages                     (Anthropic Messages API, x-api-key)
 * - google:    POST {base}/models/{model}:generateContent (Google Gemini API, x-goog-api-key)
 *
 * The format is detected from the model id, or chosen explicitly in settings.
 */
class OpenCodeProvider extends OpenAICompatibleProvider {

	const ID = 'opencode';

	const PLANS = array(
		'zen' => 'https://opencode.ai/zen/v1',
		'go'  => 'https://opencode.ai/zen/go/v1',
	);

	const FORMATS = array( 'auto', 'chat', 'responses', 'messages', 'google' );

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
		return __( 'OpenCode (Zen / Go)', 'ai-page-designer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Use models from OpenCode Zen (pay as you go) or OpenCode Go (subscription) with one API key. GPT, Claude, Gemini, Qwen, DeepSeek, GLM, Kimi and other models are called through their native API format automatically. Requests are sent from your server only.', 'ai-page-designer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_fields() {
		return array(
			array(
				'id'          => 'plan',
				'label'       => __( 'Plan', 'ai-page-designer' ),
				'type'        => 'select',
				'default'     => 'zen',
				'options'     => array(
					'zen' => __( 'OpenCode Zen (pay as you go)', 'ai-page-designer' ),
					'go'  => __( 'OpenCode Go (subscription)', 'ai-page-designer' ),
				),
				'description' => __( 'Create an API key in the OpenCode console at opencode.ai.', 'ai-page-designer' ),
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
				'description' => __( 'Model id, for example claude-sonnet-5, gpt-5.5, gemini-3.5-flash or deepseek-v4-flash. Use "Test connection" to list the models of your plan.', 'ai-page-designer' ),
			),
			array(
				'id'          => 'api_format',
				'label'       => __( 'API format', 'ai-page-designer' ),
				'type'        => 'select',
				'default'     => 'auto',
				'options'     => array(
					'auto'      => __( 'Automatic (from the model id)', 'ai-page-designer' ),
					'chat'      => __( 'Chat Completions (OpenAI-compatible)', 'ai-page-designer' ),
					'responses' => __( 'Responses (OpenAI)', 'ai-page-designer' ),
					'messages'  => __( 'Messages (Anthropic)', 'ai-page-designer' ),
					'google'    => __( 'Gemini (Google)', 'ai-page-designer' ),
				),
				'description' => __( 'Only change this if OpenCode serves your model through a different endpoint than detected.', 'ai-page-designer' ),
			),
			array(
				'id'      => 'timeout',
				'label'   => __( 'Timeout (seconds)', 'ai-page-designer' ),
				'type'    => 'number',
				'default' => 120,
				'min'     => 10,
				'max'     => 300,
				'step'    => 1,
			),
			array(
				'id'          => 'temperature',
				'label'       => __( 'Temperature', 'ai-page-designer' ),
				'type'        => 'number',
				'default'     => 0.7,
				'min'         => 0,
				'max'         => 2,
				'step'        => 0.1,
				'description' => __( 'Sent to Chat Completions and Gemini models only; reasoning models choose their own sampling.', 'ai-page-designer' ),
			),
			array(
				'id'          => 'max_tokens',
				'label'       => __( 'Maximum output tokens', 'ai-page-designer' ),
				'type'        => 'number',
				'default'     => 16000,
				'min'         => 256,
				'max'         => 64000,
				'step'        => 1,
				'description' => __( 'Reasoning models count their thinking in this limit, so keep it generous.', 'ai-page-designer' ),
			),
			array(
				'id'      => 'max_retries',
				'label'   => __( 'Retries on temporary errors', 'ai-page-designer' ),
				'type'    => 'number',
				'default' => 2,
				'min'     => 0,
				'max'     => 3,
				'step'    => 1,
			),
			array(
				'id'          => 'json_mode',
				'label'       => __( 'Request JSON output mode', 'ai-page-designer' ),
				'type'        => 'checkbox',
				'default'     => true,
				'description' => __( 'Asks Chat Completions, Responses and Gemini models for a JSON object. Disable if a model rejects it.', 'ai-page-designer' ),
			),
			array(
				'id'          => 'allow_training_models',
				'label'       => __( 'Allow free models that may use data for training', 'ai-page-designer' ),
				'type'        => 'checkbox',
				'default'     => false,
				'description' => __( 'OpenCode states that some free models may use submitted data to improve the model. They are blocked unless you enable this.', 'ai-page-designer' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $input Raw input.
	 */
	public function save_settings( array $input ) {
		$model = isset( $input['model'] ) ? strtolower( trim( (string) $input['model'] ) ) : '';
		if ( '' !== $model ) {
			if ( ! preg_match( '/^[a-z0-9._-]{1,100}$/', $model ) ) {
				return new WP_Error( 'aipd_invalid_model', __( 'The model name contains unsupported characters.', 'ai-page-designer' ) );
			}
			if ( 'unsupported' === self::detect_format( $model ) ) {
				return new WP_Error( 'aipd_invalid_model', __( 'This OpenCode model does not generate text and cannot be used to design pages.', 'ai-page-designer' ) );
			}
			if ( self::may_train_on_data( $model ) && empty( $input['allow_training_models'] ) ) {
				return new WP_Error( 'aipd_training_model', __( 'This free model may use your data for training. Enable "Allow free models that may use data for training" to use it.', 'ai-page-designer' ) );
			}
			$input['model'] = $model;
		}
		// Bypass the parent's custom URL handling: OpenCode URLs are fixed per plan.
		return AbstractProvider::save_settings( $input );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured() {
		return '' !== $this->secret( 'api_key' ) && '' !== (string) $this->setting( 'model' );
	}

	/**
	 * Zen bills per request (except free models); Go is a flat subscription.
	 *
	 * @return bool
	 */
	public function is_paid() {
		return 'go' !== $this->plan() && ! self::is_free_model( (string) $this->setting( 'model' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_privacy_info() {
		return array(
			'service'     => 'OpenCode (opencode.ai)',
			'terms_url'   => 'https://opencode.ai/legal/terms-of-service',
			'privacy_url' => 'https://opencode.ai/legal/privacy-policy',
			'data_sent'   => __( 'The page brief you enter (business description, audience, goals, tone, language, colors and call to action), the approved outline and, when regenerating a section, that section\'s content. OpenCode forwards the request to the provider of the selected model; see OpenCode\'s privacy policy for retention rules per model.', 'ai-page-designer' ),
		);
	}

	/**
	 * Selected plan id.
	 *
	 * @return string
	 */
	private function plan() {
		$plan = (string) $this->setting( 'plan' );
		return isset( self::PLANS[ $plan ] ) ? $plan : 'zen';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function base_url() {
		/**
		 * Filters the OpenCode API base URL (for example to use a regional gateway).
		 * The URL is validated like any provider endpoint.
		 *
		 * @since 1.1.0
		 *
		 * @param string $url  Base URL.
		 * @param string $plan zen|go.
		 */
		$url = (string) apply_filters( 'aipd_opencode_base_url', self::PLANS[ $this->plan() ], $this->plan() );
		return UrlValidator::validate_endpoint( $url );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $path Request path.
	 */
	protected function auth_headers( $path ) {
		$key = $this->secret( 'api_key' );
		if ( '/messages' === $path ) {
			return array(
				'x-api-key'         => $key,
				'anthropic-version' => '2023-06-01',
			);
		}
		if ( 0 === strpos( $path, '/models/' ) ) {
			return array( 'x-goog-api-key' => $key );
		}
		return array( 'Authorization' => 'Bearer ' . $key );
	}

	/**
	 * API format for a model id.
	 *
	 * @param string $model Model id.
	 * @return string chat|responses|messages|google|unsupported.
	 */
	public static function detect_format( $model ) {
		$model = strtolower( (string) $model );
		if ( 0 === strpos( $model, 'jev-' ) ) {
			return 'unsupported'; // Structured-decision model (System One), not text generation.
		}
		if ( 0 === strpos( $model, 'claude-' ) || 0 === strpos( $model, 'qwen' ) ) {
			return 'messages';
		}
		if ( 0 === strpos( $model, 'gemini-' ) ) {
			return 'google';
		}
		if ( preg_match( '/^(gpt-|grok-|muse-|o[0-9])/', $model ) ) {
			return 'responses';
		}
		return 'chat';
	}

	/**
	 * Whether a model id is free of charge.
	 *
	 * @param string $model Model id.
	 * @return bool
	 */
	public static function is_free_model( $model ) {
		$model = strtolower( (string) $model );
		return 'big-pickle' === $model || (bool) preg_match( '/-free$/', $model );
	}

	/**
	 * Whether OpenCode documents that the model may use data for training.
	 * Free models do, except the ones with a documented zero-retention policy.
	 *
	 * @param string $model Model id.
	 * @return bool
	 */
	public static function may_train_on_data( $model ) {
		$zero_retention_free = array( 'space-bunny-free' );

		/**
		 * Filters free OpenCode models known to follow a zero-retention policy.
		 *
		 * @since 1.1.0
		 *
		 * @param string[] $zero_retention_free Model ids.
		 */
		$zero_retention_free = (array) apply_filters( 'aipd_opencode_zero_retention_free_models', $zero_retention_free );
		return self::is_free_model( $model ) && ! in_array( strtolower( (string) $model ), $zero_retention_free, true );
	}

	/**
	 * Effective API format for the configured model.
	 *
	 * @return string
	 */
	public function format() {
		$format = (string) $this->setting( 'api_format' );
		if ( in_array( $format, self::FORMATS, true ) && 'auto' !== $format ) {
			return $format;
		}
		return self::detect_format( (string) $this->setting( 'model' ) );
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
		$model = (string) $this->setting( 'model' );
		if ( self::may_train_on_data( $model ) && ! $this->setting( 'allow_training_models' ) ) {
			return new WP_Error( 'aipd_training_model', __( 'This free model may use your data for training. Enable "Allow free models that may use data for training" to use it.', 'ai-page-designer' ) );
		}

		$max_tokens = (int) ( null !== $request->max_tokens ? $request->max_tokens : $this->setting( 'max_tokens' ) );
		// Reasoning models spend part of the budget on thinking; never go below the configured value.
		$max_tokens = max( $max_tokens, (int) $this->setting( 'max_tokens' ) );
		$json       = $request->json && $this->setting( 'json_mode' );

		switch ( $this->format() ) {
			case 'responses':
				return $this->complete_responses( $request, $model, $max_tokens, $json );
			case 'messages':
				return $this->complete_messages( $request, $model, $max_tokens );
			case 'google':
				return $this->complete_google( $request, $model, $max_tokens, $json );
			case 'unsupported':
				return new WP_Error( 'aipd_invalid_model', __( 'This OpenCode model does not generate text and cannot be used to design pages.', 'ai-page-designer' ) );
		}

		$chat_request             = clone $request;
		$chat_request->max_tokens = $max_tokens;
		return parent::complete( $chat_request );
	}

	/**
	 * OpenAI Responses API.
	 *
	 * @param CompletionRequest $request    Request.
	 * @param string            $model      Model.
	 * @param int               $max_tokens Output limit.
	 * @param bool              $json       JSON mode.
	 * @return CompletionResponse|WP_Error
	 */
	private function complete_responses( CompletionRequest $request, $model, $max_tokens, $json ) {
		$body = array(
			'model'             => $model,
			'instructions'      => $request->system,
			'input'             => $request->user,
			'max_output_tokens' => $max_tokens,
			'store'             => false,
		);
		if ( $json ) {
			$body['text'] = array( 'format' => array( 'type' => 'json_object' ) );
		}
		$response = $this->request( 'POST', '/responses', $this->filter_body( $body, $request, 'responses' ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$text    = '';
		$refusal = false;
		foreach ( isset( $response['output'] ) && is_array( $response['output'] ) ? $response['output'] : array() as $item ) {
			if ( ! is_array( $item ) || 'message' !== ( isset( $item['type'] ) ? $item['type'] : '' ) || empty( $item['content'] ) || ! is_array( $item['content'] ) ) {
				continue;
			}
			foreach ( $item['content'] as $part ) {
				$type = isset( $part['type'] ) ? $part['type'] : '';
				if ( 'output_text' === $type && isset( $part['text'] ) && is_string( $part['text'] ) ) {
					$text .= $part['text'];
				} elseif ( 'refusal' === $type ) {
					$refusal = true;
				}
			}
		}

		$status = isset( $response['status'] ) ? (string) $response['status'] : '';
		$reason = isset( $response['incomplete_details']['reason'] ) ? (string) $response['incomplete_details']['reason'] : '';
		if ( 'incomplete' === $status && 'max_output_tokens' === $reason ) {
			return self::truncated();
		}
		if ( '' === $text ) {
			return $refusal ? self::refused() : self::incomplete();
		}

		return new CompletionResponse(
			$text,
			isset( $response['model'] ) ? $response['model'] : $model,
			isset( $response['usage']['input_tokens'] ) ? $response['usage']['input_tokens'] : 0,
			isset( $response['usage']['output_tokens'] ) ? $response['usage']['output_tokens'] : 0,
			$status
		);
	}

	/**
	 * Anthropic Messages API.
	 *
	 * @param CompletionRequest $request    Request.
	 * @param string            $model      Model.
	 * @param int               $max_tokens Output limit.
	 * @return CompletionResponse|WP_Error
	 */
	private function complete_messages( CompletionRequest $request, $model, $max_tokens ) {
		$body     = array(
			'model'      => $model,
			'max_tokens' => $max_tokens,
			'system'     => $request->system,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $request->user,
				),
			),
		);
		$response = $this->request( 'POST', '/messages', $this->filter_body( $body, $request, 'messages' ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$text = '';
		foreach ( isset( $response['content'] ) && is_array( $response['content'] ) ? $response['content'] : array() as $block ) {
			if ( is_array( $block ) && 'text' === ( isset( $block['type'] ) ? $block['type'] : '' ) && isset( $block['text'] ) && is_string( $block['text'] ) ) {
				$text .= $block['text'];
			}
		}

		$stop = isset( $response['stop_reason'] ) ? (string) $response['stop_reason'] : '';
		if ( 'max_tokens' === $stop ) {
			return self::truncated();
		}
		if ( 'refusal' === $stop ) {
			return self::refused();
		}
		if ( '' === $text ) {
			return self::incomplete();
		}

		return new CompletionResponse(
			$text,
			isset( $response['model'] ) ? $response['model'] : $model,
			isset( $response['usage']['input_tokens'] ) ? $response['usage']['input_tokens'] : 0,
			isset( $response['usage']['output_tokens'] ) ? $response['usage']['output_tokens'] : 0,
			$stop
		);
	}

	/**
	 * Google Gemini generateContent API.
	 *
	 * @param CompletionRequest $request    Request.
	 * @param string            $model      Model.
	 * @param int               $max_tokens Output limit.
	 * @param bool              $json       JSON mode.
	 * @return CompletionResponse|WP_Error
	 */
	private function complete_google( CompletionRequest $request, $model, $max_tokens, $json ) {
		$config = array(
			'maxOutputTokens' => $max_tokens,
			'temperature'     => max( 0, min( 2, (float) ( null !== $request->temperature ? $request->temperature : $this->setting( 'temperature' ) ) ) ),
		);
		if ( $json ) {
			$config['responseMimeType'] = 'application/json';
		}
		$body     = array(
			'systemInstruction' => array( 'parts' => array( array( 'text' => $request->system ) ) ),
			'contents'          => array(
				array(
					'role'  => 'user',
					'parts' => array( array( 'text' => $request->user ) ),
				),
			),
			'generationConfig'  => $config,
		);
		$response = $this->request( 'POST', '/models/' . rawurlencode( $model ) . ':generateContent', $this->filter_body( $body, $request, 'google' ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! empty( $response['promptFeedback']['blockReason'] ) ) {
			return self::refused();
		}
		$candidate = isset( $response['candidates'][0] ) && is_array( $response['candidates'][0] ) ? $response['candidates'][0] : array();
		$finish    = isset( $candidate['finishReason'] ) ? (string) $candidate['finishReason'] : '';
		$text      = '';
		foreach ( isset( $candidate['content']['parts'] ) && is_array( $candidate['content']['parts'] ) ? $candidate['content']['parts'] : array() as $part ) {
			if ( is_array( $part ) && empty( $part['thought'] ) && isset( $part['text'] ) && is_string( $part['text'] ) ) {
				$text .= $part['text'];
			}
		}

		if ( 'MAX_TOKENS' === $finish ) {
			return self::truncated();
		}
		if ( in_array( $finish, array( 'SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'SPII', 'RECITATION' ), true ) ) {
			return self::refused();
		}
		if ( '' === $text ) {
			return self::incomplete();
		}

		return new CompletionResponse(
			$text,
			isset( $response['modelVersion'] ) ? $response['modelVersion'] : $model,
			isset( $response['usageMetadata']['promptTokenCount'] ) ? $response['usageMetadata']['promptTokenCount'] : 0,
			isset( $response['usageMetadata']['candidatesTokenCount'] ) ? $response['usageMetadata']['candidatesTokenCount'] : 0,
			strtolower( $finish )
		);
	}

	/**
	 * Lets extensions adjust a request body. Credentials are never part of it.
	 *
	 * @param array             $body    Body.
	 * @param CompletionRequest $request Request.
	 * @param string            $format  API format.
	 * @return array
	 */
	private function filter_body( array $body, CompletionRequest $request, $format ) {
		/**
		 * Filters the request body sent to OpenCode.
		 *
		 * @since 1.1.0
		 *
		 * @param array             $body    Request body.
		 * @param CompletionRequest $request Request object.
		 * @param string            $format  responses|messages|google (chat uses aipd_openai_compatible_body).
		 */
		return (array) apply_filters( 'aipd_opencode_body', $body, $request, $format );
	}

	/**
	 * {@inheritDoc}
	 *
	 * The model list is public, so this checks connectivity; the key is verified on the first generation.
	 */
	public function list_models() {
		$response = $this->request( 'GET', '/models', null, 1, 20 );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$ids = array();
		foreach ( isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array() as $model ) {
			$id = isset( $model['id'] ) && is_string( $model['id'] ) ? strtolower( $model['id'] ) : '';
			if ( preg_match( '/^[a-z0-9._-]{1,100}$/', $id ) && 'unsupported' !== self::detect_format( $id ) ) {
				$ids[] = $id;
			}
		}
		sort( $ids );
		return array_slice( $ids, 0, 200 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array|WP_Error $response wp_remote_* result.
	 */
	protected function handle_response( $response ) {
		$result = parent::handle_response( $response );
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}
		$data = $result->get_error_data();
		$type = is_array( $data ) && isset( $data['provider_type'] ) ? (string) $data['provider_type'] : '';
		$map  = array(
			'CreditsError'        => array( 'aipd_no_credits', __( 'Your OpenCode Zen balance is too low. Add credits in the OpenCode console.', 'ai-page-designer' ) ),
			'MonthlyLimitError'   => array( 'aipd_monthly_limit', __( 'The monthly usage limit of your OpenCode workspace was reached.', 'ai-page-designer' ) ),
			'UserLimitError'      => array( 'aipd_monthly_limit', __( 'Your monthly OpenCode usage limit was reached.', 'ai-page-designer' ) ),
			'ModelError'          => array( 'aipd_not_found', __( 'OpenCode does not offer this model on your plan. Check the model id.', 'ai-page-designer' ) ),
			'GoUsageLimitError'   => array( 'aipd_rate_limited', __( 'The OpenCode Go usage limit was reached. Try again later.', 'ai-page-designer' ) ),
			'FreeUsageLimitError' => array( 'aipd_rate_limited', __( 'The free usage limit for this OpenCode model was reached. Try again later.', 'ai-page-designer' ) ),
			'RegionError'         => array( 'aipd_request_rejected', __( 'OpenCode does not serve this model in your server\'s region.', 'ai-page-designer' ) ),
			'DataPolicyError'     => array( 'aipd_request_rejected', __( 'Your OpenCode workspace data policy blocks this model.', 'ai-page-designer' ) ),
		);
		if ( isset( $map[ $type ] ) ) {
			return new WP_Error( $map[ $type ][0], $map[ $type ][1], $data );
		}
		return $result;
	}

	/**
	 * Truncation error.
	 *
	 * @return WP_Error
	 */
	private static function truncated() {
		return new WP_Error( 'aipd_truncated', __( 'The AI response was cut off because it reached the maximum output tokens. Increase the limit or request fewer sections.', 'ai-page-designer' ) );
	}

	/**
	 * Refusal error.
	 *
	 * @return WP_Error
	 */
	private static function refused() {
		return new WP_Error( 'aipd_refused', __( 'The AI model declined this request. Please revise the brief.', 'ai-page-designer' ) );
	}

	/**
	 * Incomplete response error.
	 *
	 * @return WP_Error
	 */
	private static function incomplete() {
		return new WP_Error( 'aipd_incomplete_response', __( 'The AI service returned an incomplete response.', 'ai-page-designer' ) );
	}
}
