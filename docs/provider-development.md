# Adding an AI provider

Providers implement `AIPageDesigner\AI\Contracts\ProviderInterface`. Extending `AIPageDesigner\AI\Providers\AbstractProvider` gives you settings storage, secret encryption, `wp-config.php` constants and public (secret-free) settings for free.

## Rules

1. Send requests from the server only, with `wp_safe_remote_request()` (or through a trusted SDK that does the same).
2. Never return, log or pass credentials to hooks. Use `Redactor::redact_string()` on any provider error text you surface.
3. Return `WP_Error` objects with user-safe, translatable messages. Add `array( 'retryable' => true )` in error data for timeouts, rate limits and 5xx responses if you implement retries.
4. `is_configured()` must return `false` until everything needed to send a request is present. The plugin never sends a request otherwise.
5. `is_paid()` should return `true` if a request can cost money; users will then confirm each request.
6. Describe exactly what is sent in `get_privacy_info()`, and link the service's terms and privacy policy. Add the service to your own plugin's readme **External services** section.
7. Treat everything the model returns as untrusted. Return the raw text; the plugin validates it.

## Example: a custom JSON API

```php
<?php
/**
 * Plugin Name: Example AI Provider for AI Page Designer
 */

use AIPageDesigner\AI\DTO\CompletionRequest;
use AIPageDesigner\AI\DTO\CompletionResponse;
use AIPageDesigner\AI\Providers\AbstractProvider;
use AIPageDesigner\Security\Redactor;
use AIPageDesigner\Security\UrlValidator;

add_action( 'aipd_register_providers', function ( $registry ) {

	class Example_AI_Provider extends AbstractProvider {

		public function get_id() {
			return 'example_ai';
		}

		public function get_name() {
			return __( 'Example AI', 'example-ai' );
		}

		public function get_description() {
			return __( 'Connects to the Example AI service.', 'example-ai' );
		}

		public function get_settings_fields() {
			return array(
				array( 'id' => 'api_key', 'label' => __( 'API key', 'example-ai' ), 'type' => 'password', 'secret' => true, 'default' => '' ),
				array( 'id' => 'model', 'label' => __( 'Model', 'example-ai' ), 'type' => 'text', 'default' => 'example-large' ),
			);
		}

		public function is_configured() {
			return '' !== $this->secret( 'api_key' );
		}

		public function get_privacy_info() {
			return array(
				'service'     => 'api.example-ai.test',
				'terms_url'   => 'https://example-ai.test/terms',
				'privacy_url' => 'https://example-ai.test/privacy',
				'data_sent'   => __( 'The page brief and approved outline.', 'example-ai' ),
			);
		}

		public function complete( CompletionRequest $request ) {
			if ( ! $this->is_configured() ) {
				return new WP_Error( 'aipd_not_configured', __( 'Example AI is not configured.', 'example-ai' ) );
			}
			$response = wp_safe_remote_post(
				'https://api.example-ai.test/v1/generate',
				array(
					'timeout'     => 90,
					'redirection' => 0,
					'headers'     => array(
						'Authorization' => 'Bearer ' . $this->secret( 'api_key' ),
						'Content-Type'  => 'application/json',
					),
					'body'        => wp_json_encode(
						array(
							'model'  => $this->setting( 'model' ),
							'system' => $request->system,
							'input'  => $request->user,
							'format' => $request->json ? 'json' : 'text',
						)
					),
				)
			);
			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'aipd_connection_failed', __( 'Could not reach Example AI.', 'example-ai' ), array( 'retryable' => true ) );
			}
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( 200 !== wp_remote_retrieve_response_code( $response ) || empty( $data['output'] ) ) {
				$details = isset( $data['error'] ) ? Redactor::redact_string( (string) $data['error'] ) : '';
				return new WP_Error( 'aipd_request_rejected', __( 'Example AI rejected the request.', 'example-ai' ), array( 'details' => $details ) );
			}
			return new CompletionResponse( $data['output'], $this->setting( 'model' ), $data['usage']['in'] ?? 0, $data['usage']['out'] ?? 0, 'stop' );
		}

		public function test_connection() {
			return $this->is_configured() ? true : new WP_Error( 'aipd_not_configured', __( 'Add an API key.', 'example-ai' ) );
		}
	}

	$registry->register( new Example_AI_Provider() );
} );
```

The provider automatically appears on **AI Page Designer > AI Providers**, its key is stored encrypted, and it can also be supplied with `define( 'AIPD_EXAMPLE_AI_API_KEY', '…' );` (pattern: `AIPD_{PROVIDER_ID}_{FIELD_ID}` in upper case).

## Reference implementations

* `OpenAICompatibleProvider`: one endpoint, Bearer auth, retries and error mapping. Override `base_url()` and `auth_headers( $path )` to reuse it for similar APIs.
* `OpenCodeProvider`: one key, several native API formats chosen per model (Chat Completions, Responses, Anthropic Messages, Gemini), each with its own auth header and response parser, plus provider-specific error mapping from `provider_type`.

## Testing

Use the `pre_http_request` filter to fake responses (see `tests/TestCase.php::queue_completion()`), and assert that:

* no HTTP request is made when `is_configured()` is false;
* the key never appears in `get_public_settings()`, errors, logs or REST responses;
* timeouts and 429/5xx responses map to user-safe errors.
