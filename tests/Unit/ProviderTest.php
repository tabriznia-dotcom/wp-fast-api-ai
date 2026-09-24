<?php
/**
 * OpenAI-compatible provider tests (HTTP is mocked).
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Tests\Unit;

use AIPageDesigner\AI\DTO\CompletionRequest;
use AIPageDesigner\AI\DTO\CompletionResponse;
use AIPageDesigner\Core\Options;
use AIPageDesigner\Tests\TestCase;

/**
 * @covers \AIPageDesigner\AI\Providers\OpenAICompatibleProvider
 */
class ProviderTest extends TestCase {

	private function request() {
		return new CompletionRequest( 'system', 'user', 'page' );
	}

	public function test_no_request_is_sent_without_api_key() {
		$provider = $this->configure_provider( false );
		$this->assertFalse( $provider->is_configured() );
		$result = $provider->complete( $this->request() );
		$this->assertWPError( $result );
		$this->assertSame( 'aipd_not_configured', $result->get_error_code() );
		$this->assertCount( 0, $this->http_requests );
	}

	public function test_api_key_is_encrypted_at_rest_and_not_public() {
		$provider = $this->configure_provider();
		$raw      = wp_json_encode( get_option( Options::PROVIDERS ) );
		$this->assertStringNotContainsString( 'SECRET', $raw );
		$public = wp_json_encode( $provider->get_public_settings() );
		$this->assertStringNotContainsString( 'SECRET', $public );
		$this->assertStringNotContainsString( 'aipd1:', $public );
	}

	public function test_empty_key_field_keeps_existing_key_and_remove_clears_it() {
		$provider = $this->configure_provider();
		$provider->save_settings( array( 'api_url' => 'https://api.example.com/v1', 'api_key' => '', 'model' => 'm' ) );
		$this->assertTrue( $provider->is_configured() );
		$provider->save_settings( array( 'api_url' => 'https://api.example.com/v1', 'api_key_remove' => '1', 'model' => 'm' ) );
		$this->assertFalse( $provider->is_configured() );
	}

	public function test_successful_completion_uses_safe_http_and_bearer_header() {
		$provider = $this->configure_provider();
		$this->queue_completion( '{"a":1}' );
		$result = $provider->complete( $this->request() );
		$this->assertInstanceOf( CompletionResponse::class, $result );
		$this->assertSame( '{"a":1}', $result->text );
		$this->assertSame( 300, $result->tokens_in + $result->tokens_out );
		$this->assertSame( 'https://api.example.com/v1/chat/completions', $this->http_requests[0]['url'] );
		$args = $this->http_requests[0]['args'];
		$this->assertTrue( $args['reject_unsafe_urls'], 'wp_safe_remote_request must be used' );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertSame( 'Bearer sk-test-SECRET-1234567890abcdef', $args['headers']['Authorization'] );
		$body = json_decode( $args['body'], true );
		$this->assertSame( 'json_object', $body['response_format']['type'] );
		$this->assertArrayNotHasKey( 'api_key', $body );
	}

	public function test_invalid_api_key_maps_to_safe_error() {
		$provider = $this->configure_provider();
		$this->queue_response( 401, array( 'error' => array( 'message' => 'Incorrect API key provided: sk-test-SECRET-1234567890abcdef' ) ) );
		$result = $provider->complete( $this->request() );
		$this->assertSame( 'aipd_auth_failed', $result->get_error_code() );
		$this->assertStringNotContainsString( 'SECRET', wp_json_encode( array( $result->get_error_message(), $result->get_error_data() ) ) );
		$this->assertCount( 1, $this->http_requests, 'Auth errors are not retried' );
	}

	public function test_timeout_is_retried_then_reported() {
		$provider               = $this->configure_provider();
		$this->http_responses[] = new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 30001 milliseconds' );
		$this->http_responses[] = new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		$this->http_responses[] = new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		$result                 = $provider->complete( $this->request() );
		$this->assertSame( 'aipd_timeout', $result->get_error_code() );
		$this->assertCount( 3, $this->http_requests, 'Initial attempt plus two retries' );
	}

	public function test_rate_limit_retry_then_success() {
		$provider          = $this->configure_provider();
		$waits             = array();
		$provider->sleeper = static function ( $seconds ) use ( &$waits ) {
			$waits[] = $seconds;
		};
		$this->queue_response( 429, array( 'error' => array( 'message' => 'Rate limit' ) ), array( 'retry-after' => '3' ) );
		$this->queue_completion( '{"ok":true}' );
		$result = $provider->complete( $this->request() );
		$this->assertInstanceOf( CompletionResponse::class, $result );
		$this->assertSame( array( 3 ), $waits );
	}

	public function test_server_error_is_retried() {
		$provider = $this->configure_provider();
		$this->queue_response( 503, 'Service Unavailable' );
		$this->queue_completion( '{"ok":true}' );
		$this->assertInstanceOf( CompletionResponse::class, $provider->complete( $this->request() ) );
	}

	public function test_incomplete_and_truncated_responses() {
		$provider = $this->configure_provider();
		$this->queue_response( 200, array( 'choices' => array() ) );
		$this->assertSame( 'aipd_incomplete_response', $provider->complete( $this->request() )->get_error_code() );

		$this->queue_completion( '{"cut', 'length' );
		$this->assertSame( 'aipd_truncated', $provider->complete( $this->request() )->get_error_code() );

		$this->queue_response( 200, 'not json' );
		$this->assertSame( 'aipd_invalid_response', $provider->complete( $this->request() )->get_error_code() );
	}

	public function test_saving_private_endpoint_is_rejected() {
		$provider = $this->configure_provider();
		$result   = $provider->save_settings( array( 'api_url' => 'https://169.254.169.254/v1' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'https://api.example.com/v1', $provider->get_public_settings()['api_url'] );
	}

	public function test_list_models() {
		$provider = $this->configure_provider();
		$this->queue_response( 200, array( 'data' => array( array( 'id' => 'b-model' ), array( 'id' => 'a-model' ), array( 'id' => '<script>' ) ) ) );
		$this->assertSame( array( 'a-model', 'b-model' ), $provider->list_models() );
		$this->assertStringEndsWith( '/models', $this->http_requests[0]['url'] );
	}

	public function test_wp_ai_client_provider_availability_matches_core() {
		$provider = \AIPageDesigner\Core\Plugin::instance()->providers->get( 'wp_ai_client' );
		$this->assertSame( function_exists( 'wp_ai_client_prompt' ) && wp_supports_ai(), $provider->is_available() );
	}

	public function test_custom_provider_can_be_registered() {
		$registry = new \AIPageDesigner\AI\ProviderRegistry();
		$mock     = $this->createMock( \AIPageDesigner\AI\Contracts\ProviderInterface::class );
		$mock->method( 'get_id' )->willReturn( 'custom_one' );
		add_action(
			'aipd_register_providers',
			static function ( $r ) use ( $mock ) {
				$r->register( $mock );
			}
		);
		$this->assertSame( $mock, $registry->get( 'custom_one' ) );
	}
}
