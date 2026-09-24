<?php
/**
 * Shared test helpers.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Tests;

use AIPageDesigner\AI\Providers\OpenAICompatibleProvider;
use AIPageDesigner\Core\Options;
use AIPageDesigner\Core\Plugin;
use AIPageDesigner\Schema\PageSchema;

/**
 * Base test case.
 */
abstract class TestCase extends \WP_UnitTestCase {

	/**
	 * Captured outgoing HTTP requests.
	 *
	 * @var array
	 */
	protected $http_requests = array();

	/**
	 * Queue of fake HTTP responses (array or WP_Error).
	 *
	 * @var array
	 */
	protected $http_responses = array();

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		PageSchema::reset();
		$this->http_requests  = array();
		$this->http_responses = array();
		add_filter( 'pre_http_request', array( $this, 'intercept_http' ), 10, 3 );
		add_filter(
			'aipd_resolve_host',
			static function ( $ips, $host ) {
				$map = array(
					'api.example.com'  => array( '93.184.216.34' ),
					'api.openai.com'   => array( '104.18.7.192' ),
					'internal.example' => array( '10.0.0.5' ),
					'metadata.example' => array( '169.254.169.254' ),
				);
				return isset( $map[ $host ] ) ? $map[ $host ] : array();
			},
			10,
			2
		);
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'aipd_resolve_host' );
		parent::tear_down();
	}

	/**
	 * pre_http_request callback.
	 *
	 * @param mixed  $pre  Pre value.
	 * @param array  $args Args.
	 * @param string $url  URL.
	 * @return mixed
	 */
	public function intercept_http( $pre, $args, $url ) {
		$this->http_requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		if ( empty( $this->http_responses ) ) {
			return new \WP_Error( 'http_request_failed', 'No fake response queued' );
		}
		return array_shift( $this->http_responses );
	}

	/**
	 * Queues a fake JSON response.
	 *
	 * @param int   $code    Status.
	 * @param mixed $body    Body (array is JSON-encoded).
	 * @param array $headers Headers.
	 * @return void
	 */
	protected function queue_response( $code, $body, array $headers = array() ) {
		$this->http_responses[] = array(
			'headers'  => $headers,
			'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Queues a chat completion with content.
	 *
	 * @param string $content Content.
	 * @param string $finish  Finish reason.
	 * @return void
	 */
	protected function queue_completion( $content, $finish = 'stop' ) {
		$this->queue_response(
			200,
			array(
				'model'   => 'test-model',
				'choices' => array(
					array(
						'message'       => array(
							'role'    => 'assistant',
							'content' => $content,
						),
						'finish_reason' => $finish,
					),
				),
				'usage'   => array(
					'prompt_tokens'     => 100,
					'completion_tokens' => 200,
				),
			)
		);
	}

	/**
	 * Loads a bundled template as an array.
	 *
	 * @param string $name File name without extension.
	 * @return array
	 */
	protected function template( $name ) {
		return json_decode( (string) file_get_contents( AIPD_DIR . 'templates/' . $name . '.json' ), true );
	}

	/**
	 * Creates and logs in an administrator.
	 *
	 * @return int
	 */
	protected function login_admin() {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $id );
		}
		wp_set_current_user( $id );
		return $id;
	}

	/**
	 * Configures the OpenAI-compatible provider and consent.
	 *
	 * @param bool $with_key Store an API key.
	 * @return OpenAICompatibleProvider
	 */
	protected function configure_provider( $with_key = true ) {
		$provider          = Plugin::instance()->providers->get( 'openai_compatible' );
		$provider->sleeper = static function () {};
		$result            = $provider->save_settings(
			array(
				'api_url'     => 'https://api.example.com/v1',
				'api_key'     => $with_key ? 'sk-test-SECRET-1234567890abcdef' : '',
				'model'       => 'test-model',
				'timeout'     => 30,
				'temperature' => 0.5,
				'max_tokens'  => 4000,
				'max_retries' => 2,
				'json_mode'   => '1',
				'is_paid'     => '1',
			)
		);
		$this->assertTrue( $result );
		Options::update_settings(
			array(
				'active_provider'      => 'openai_compatible',
				'privacy_acknowledged' => true,
			)
		);
		return $provider;
	}
}
