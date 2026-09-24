<?php
/**
 * OpenCode Zen / Go provider tests (HTTP is mocked).
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Tests\Unit;

use AIPageDesigner\AI\DTO\CompletionRequest;
use AIPageDesigner\AI\DTO\CompletionResponse;
use AIPageDesigner\AI\Providers\OpenCodeProvider;
use AIPageDesigner\Core\Options;
use AIPageDesigner\Core\Plugin;
use AIPageDesigner\Tests\TestCase;

/**
 * @covers \AIPageDesigner\AI\Providers\OpenCodeProvider
 */
class OpenCodeProviderTest extends TestCase {

	/**
	 * Configures the OpenCode provider.
	 *
	 * @param string $model Model id.
	 * @param array  $extra Extra settings.
	 * @return OpenCodeProvider
	 */
	private function opencode( $model, array $extra = array() ) {
		$provider          = Plugin::instance()->providers->get( 'opencode' );
		$provider->sleeper = static function () {};
		$result            = $provider->save_settings(
			array_merge(
				array(
					'plan'        => 'zen',
					'api_key'     => 'oc-SECRET-key-1234567890abcdef',
					'model'       => $model,
					'api_format'  => 'auto',
					'max_tokens'  => 8000,
					'max_retries' => 1,
					'json_mode'   => '1',
				),
				$extra
			)
		);
		$this->assertTrue( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		Options::update_settings(
			array(
				'active_provider'      => 'opencode',
				'privacy_acknowledged' => true,
			)
		);
		return $provider;
	}

	/**
	 * Request helper.
	 *
	 * @return CompletionRequest
	 */
	private function request() {
		return new CompletionRequest( 'SYSTEM', 'USER', 'page' );
	}

	/**
	 * Last request body.
	 *
	 * @return array
	 */
	private function last_body() {
		return json_decode( end( $this->http_requests )['args']['body'], true );
	}

	public function test_registered_and_format_detection() {
		$this->assertInstanceOf( OpenCodeProvider::class, Plugin::instance()->providers->get( 'opencode' ) );
		$this->assertSame( 'messages', OpenCodeProvider::detect_format( 'claude-sonnet-5' ) );
		$this->assertSame( 'messages', OpenCodeProvider::detect_format( 'qwen3.7-plus' ) );
		$this->assertSame( 'responses', OpenCodeProvider::detect_format( 'gpt-5.5' ) );
		$this->assertSame( 'responses', OpenCodeProvider::detect_format( 'grok-4.7' ) );
		$this->assertSame( 'google', OpenCodeProvider::detect_format( 'gemini-3.5-flash' ) );
		$this->assertSame( 'chat', OpenCodeProvider::detect_format( 'deepseek-v4-flash' ) );
		$this->assertSame( 'chat', OpenCodeProvider::detect_format( 'glm-5.3' ) );
		$this->assertSame( 'unsupported', OpenCodeProvider::detect_format( 'jev-1.13' ) );
	}

	public function test_no_request_without_key_and_key_is_private() {
		$provider = Plugin::instance()->providers->get( 'opencode' );
		$provider->save_settings( array( 'model' => 'glm-5.3' ) );
		$this->assertFalse( $provider->is_configured() );
		$this->assertWPError( $provider->complete( $this->request() ) );
		$this->assertCount( 0, $this->http_requests );

		$provider = $this->opencode( 'glm-5.3' );
		$this->assertStringNotContainsString( 'SECRET', wp_json_encode( $provider->get_public_settings() ) );
		$this->assertStringNotContainsString( 'SECRET', wp_json_encode( get_option( Options::PROVIDERS ) ) );
	}

	public function test_chat_completions_format() {
		$provider = $this->opencode( 'deepseek-v4-flash' );
		$this->queue_completion( '{"ok":1}' );
		$result = $provider->complete( $this->request() );
		$this->assertInstanceOf( CompletionResponse::class, $result );
		$call = $this->http_requests[0];
		$this->assertSame( 'https://opencode.ai/zen/v1/chat/completions', $call['url'] );
		$this->assertSame( 'Bearer oc-SECRET-key-1234567890abcdef', $call['args']['headers']['Authorization'] );
		$this->assertTrue( $call['args']['reject_unsafe_urls'] );
		$body = $this->last_body();
		$this->assertSame( 'deepseek-v4-flash', $body['model'] );
		$this->assertSame( 8000, $body['max_tokens'] );
	}

	public function test_responses_format() {
		$provider = $this->opencode( 'gpt-5.5' );
		$this->queue_response(
			200,
			array(
				'model'  => 'gpt-5.5',
				'status' => 'completed',
				'output' => array(
					array( 'type' => 'reasoning' ),
					array(
						'type'    => 'message',
						'content' => array(
							array(
								'type' => 'output_text',
								'text' => '{"a":',
							),
							array(
								'type' => 'output_text',
								'text' => '1}',
							),
						),
					),
				),
				'usage'  => array(
					'input_tokens'  => 11,
					'output_tokens' => 22,
				),
			)
		);
		$result = $provider->complete( $this->request() );
		$this->assertSame( '{"a":1}', $result->text );
		$this->assertSame( 33, $result->tokens_in + $result->tokens_out );
		$call = $this->http_requests[0];
		$this->assertSame( 'https://opencode.ai/zen/v1/responses', $call['url'] );
		$this->assertArrayHasKey( 'Authorization', $call['args']['headers'] );
		$body = $this->last_body();
		$this->assertSame( 'SYSTEM', $body['instructions'] );
		$this->assertSame( 'USER', $body['input'] );
		$this->assertSame( 'json_object', $body['text']['format']['type'] );
		$this->assertFalse( $body['store'] );
		$this->assertArrayNotHasKey( 'temperature', $body );

		$this->queue_response(
			200,
			array(
				'status'             => 'incomplete',
				'incomplete_details' => array( 'reason' => 'max_output_tokens' ),
				'output'             => array(),
			)
		);
		$this->assertSame( 'aipd_truncated', $provider->complete( $this->request() )->get_error_code() );
	}

	public function test_messages_format() {
		$provider = $this->opencode( 'claude-sonnet-5' );
		$this->queue_response(
			200,
			array(
				'model'       => 'claude-sonnet-5',
				'content'     => array(
					array(
						'type' => 'text',
						'text' => '{"b":2}',
					),
				),
				'stop_reason' => 'end_turn',
				'usage'       => array(
					'input_tokens'  => 5,
					'output_tokens' => 6,
				),
			)
		);
		$result = $provider->complete( $this->request() );
		$this->assertSame( '{"b":2}', $result->text );
		$call = $this->http_requests[0];
		$this->assertSame( 'https://opencode.ai/zen/v1/messages', $call['url'] );
		$this->assertSame( 'oc-SECRET-key-1234567890abcdef', $call['args']['headers']['x-api-key'] );
		$this->assertSame( '2023-06-01', $call['args']['headers']['anthropic-version'] );
		$this->assertArrayNotHasKey( 'Authorization', $call['args']['headers'] );
		$body = $this->last_body();
		$this->assertSame( 'SYSTEM', $body['system'] );
		$this->assertSame( 'USER', $body['messages'][0]['content'] );
		$this->assertSame( 8000, $body['max_tokens'] );

		$this->queue_response(
			200,
			array(
				'content'     => array(),
				'stop_reason' => 'refusal',
			)
		);
		$this->assertSame( 'aipd_refused', $provider->complete( $this->request() )->get_error_code() );
		$this->queue_response(
			200,
			array(
				'content'     => array(
					array(
						'type' => 'text',
						'text' => '{"cut',
					),
				),
				'stop_reason' => 'max_tokens',
			)
		);
		$this->assertSame( 'aipd_truncated', $provider->complete( $this->request() )->get_error_code() );
	}

	public function test_google_format() {
		$provider = $this->opencode( 'gemini-3.5-flash' );
		$this->queue_response(
			200,
			array(
				'candidates'    => array(
					array(
						'content'      => array(
							'parts' => array(
								array(
									'text'    => 'thinking...',
									'thought' => true,
								),
								array( 'text' => '{"c":3}' ),
							),
						),
						'finishReason' => 'STOP',
					),
				),
				'usageMetadata' => array(
					'promptTokenCount'     => 7,
					'candidatesTokenCount' => 8,
				),
			)
		);
		$result = $provider->complete( $this->request() );
		$this->assertSame( '{"c":3}', $result->text );
		$call = $this->http_requests[0];
		$this->assertSame( 'https://opencode.ai/zen/v1/models/gemini-3.5-flash:generateContent', $call['url'] );
		$this->assertSame( 'oc-SECRET-key-1234567890abcdef', $call['args']['headers']['x-goog-api-key'] );
		$body = $this->last_body();
		$this->assertSame( 'SYSTEM', $body['systemInstruction']['parts'][0]['text'] );
		$this->assertSame( 'application/json', $body['generationConfig']['responseMimeType'] );

		$this->queue_response(
			200,
			array(
				'candidates' => array(
					array(
						'content'      => array( 'parts' => array() ),
						'finishReason' => 'SAFETY',
					),
				),
			)
		);
		$this->assertSame( 'aipd_refused', $provider->complete( $this->request() )->get_error_code() );
	}

	public function test_go_plan_uses_go_base_url_and_is_not_per_request_paid() {
		$provider = $this->opencode( 'glm-5.3', array( 'plan' => 'go' ) );
		$this->assertFalse( $provider->is_paid() );
		$this->queue_completion( '{}' );
		$provider->complete( $this->request() );
		$this->assertSame( 'https://opencode.ai/zen/go/v1/chat/completions', $this->http_requests[0]['url'] );
	}

	public function test_zen_paid_unless_free_model() {
		$this->assertTrue( $this->opencode( 'claude-sonnet-5' )->is_paid() );
		$this->assertFalse( $this->opencode( 'space-bunny-free' )->is_paid() );
	}

	public function test_training_models_require_opt_in() {
		$provider = Plugin::instance()->providers->get( 'opencode' );
		$result   = $provider->save_settings(
			array(
				'api_key' => 'k-123',
				'model'   => 'big-pickle',
			)
		);
		$this->assertSame( 'aipd_training_model', $result->get_error_code() );
		$this->assertTrue(
			$provider->save_settings(
				array(
					'api_key'               => 'k-123',
					'model'                 => 'big-pickle',
					'allow_training_models' => '1',
				)
			)
		);
		$this->assertTrue( OpenCodeProvider::may_train_on_data( 'mimo-v2.5-free' ) );
		$this->assertFalse( OpenCodeProvider::may_train_on_data( 'space-bunny-free' ) );
		$this->assertFalse( OpenCodeProvider::may_train_on_data( 'claude-sonnet-5' ) );
	}

	public function test_non_text_models_are_rejected() {
		$provider = Plugin::instance()->providers->get( 'opencode' );
		$this->assertSame( 'aipd_invalid_model', $provider->save_settings( array( 'model' => 'jev-1.13' ) )->get_error_code() );
		$this->assertSame( 'aipd_invalid_model', $provider->save_settings( array( 'model' => 'bad model!' ) )->get_error_code() );
	}

	public function test_zen_errors_are_mapped_without_secrets() {
		$provider = $this->opencode( 'claude-sonnet-5' );
		$cases    = array(
			'CreditsError'      => 'aipd_no_credits',
			'MonthlyLimitError' => 'aipd_monthly_limit',
			'ModelError'        => 'aipd_not_found',
			'AuthError'         => 'aipd_auth_failed',
		);
		foreach ( $cases as $type => $code ) {
			$this->queue_response(
				401,
				array(
					'type'  => 'error',
					'error' => array(
						'type'    => $type,
						'message' => 'Problem with key oc-SECRET-key-1234567890abcdef',
					),
				)
			);
			$error = $provider->complete( $this->request() );
			$this->assertSame( $code, $error->get_error_code(), $type );
			$this->assertStringNotContainsString( 'SECRET', wp_json_encode( array( $error->get_error_message(), $error->get_error_data() ) ) );
		}

		$this->queue_response(
			429,
			array(
				'type'  => 'error',
				'error' => array(
					'type'    => 'RateLimitError',
					'message' => 'slow down',
				),
			),
			array( 'retry-after' => '2' )
		);
		$this->queue_completion( '{"ok":true}' );
		$provider->save_settings( array_merge( $provider->get_public_settings(), array( 'api_key' => '', 'model' => 'glm-5.3' ) ) );
		$this->assertInstanceOf( CompletionResponse::class, $provider->complete( $this->request() ) );
	}

	public function test_list_models_filters_non_text_models() {
		$provider = $this->opencode( 'glm-5.3' );
		$this->queue_response(
			200,
			array(
				'object' => 'list',
				'data'   => array(
					array( 'id' => 'glm-5.3' ),
					array( 'id' => 'jev-1.13' ),
					array( 'id' => 'claude-sonnet-5' ),
					array( 'id' => '<script>' ),
				),
			)
		);
		$this->assertSame( array( 'claude-sonnet-5', 'glm-5.3' ), $provider->list_models() );
		$this->assertSame( 'https://opencode.ai/zen/v1/models', $this->http_requests[0]['url'] );
	}

	public function test_privacy_info_links() {
		$info = $this->opencode( 'glm-5.3' )->get_privacy_info();
		$this->assertSame( 'https://opencode.ai/legal/terms-of-service', $info['terms_url'] );
		$this->assertSame( 'https://opencode.ai/legal/privacy-policy', $info['privacy_url'] );
	}
}
