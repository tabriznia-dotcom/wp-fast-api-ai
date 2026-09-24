<?php
/**
 * REST API permission, flow and secrecy tests.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Tests\Integration;

use AIPageDesigner\Core\Capabilities;
use AIPageDesigner\Core\Options;
use AIPageDesigner\Jobs\JobRepository;
use AIPageDesigner\Tests\TestCase;
use WP_REST_Request;

/**
 * @covers \AIPageDesigner\API\RestController
 */
class RestApiTest extends TestCase {

	/**
	 * Server.
	 *
	 * @var \WP_REST_Server
	 */
	private $server;

	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * Dispatches a request.
	 *
	 * @param string $method Method.
	 * @param string $route  Route.
	 * @param array  $params Body params.
	 * @return \WP_REST_Response
	 */
	private function call( $method, $route, array $params = array() ) {
		$request = new WP_REST_Request( $method, '/aipd/v1' . $route );
		if ( $params ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $params ) );
		}
		return $this->server->dispatch( $request );
	}

	/**
	 * Outline JSON returned by the fake model.
	 *
	 * @return string
	 */
	private function fake_outline() {
		return wp_json_encode(
			array(
				'sections' => array(
					array( 'id' => 'hero', 'type' => 'hero', 'label' => 'Hero', 'purpose' => 'Intro' ),
					array( 'id' => 'cta', 'type' => 'cta', 'label' => 'CTA', 'purpose' => 'Action' ),
				),
			)
		);
	}

	public function test_routes_are_registered_with_permission_callbacks() {
		$routes = $this->server->get_routes( 'aipd/v1' );
		$this->assertArrayHasKey( '/aipd/v1/jobs', $routes );
		foreach ( $routes as $route => $handlers ) {
			if ( '/aipd/v1' === $route ) {
				continue; // Namespace index registered by core.
			}
			foreach ( $handlers as $handler ) {
				if ( isset( $handler['callback'] ) ) {
					$this->assertNotEmpty( $handler['permission_callback'], $route );
					$this->assertNotSame( '__return_true', $handler['permission_callback'], $route );
				}
			}
		}
	}

	public function test_unauthenticated_and_unprivileged_users_are_denied() {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->call( 'GET', '/status' )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->call( 'GET', '/status' )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/jobs', array( 'id' => wp_generate_uuid4(), 'type' => 'outline' ) )->get_status() );

		// Editors do not get the plugin capability by default.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $this->call( 'POST', '/drafts', array( 'schema' => $this->template( 'landing-page-en' ), 'builder' => 'gutenberg' ) )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/providers/openai_compatible/test' )->get_status() );
	}

	public function test_generator_without_manage_cannot_test_providers() {
		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_userdata( $user )->add_cap( Capabilities::GENERATE );
		wp_set_current_user( $user );
		$this->assertSame( 200, $this->call( 'GET', '/status' )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/providers/openai_compatible/test' )->get_status() );
		$this->assertSame( 403, $this->call( 'DELETE', '/templates/user:1' )->get_status() );
	}

	public function test_privacy_acknowledgement_is_required_before_any_request() {
		$this->login_admin();
		$this->configure_provider();
		Options::update_settings( array( 'privacy_acknowledged' => false ) );
		$response = $this->call( 'POST', '/jobs', array( 'id' => wp_generate_uuid4(), 'type' => 'outline', 'confirm_cost' => true, 'brief' => array( 'topic' => 'x' ) ) );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'aipd_privacy_not_acknowledged', $response->get_data()['code'] );
		$this->assertCount( 0, $this->http_requests );
	}

	public function test_no_external_request_without_api_key() {
		$this->login_admin();
		$this->configure_provider( false );
		$response = $this->call( 'POST', '/jobs', array( 'id' => wp_generate_uuid4(), 'type' => 'outline', 'confirm_cost' => true ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'aipd_not_configured', $response->get_data()['code'] );
		$this->assertCount( 0, $this->http_requests );
	}

	public function test_paid_requests_require_confirmation() {
		$this->login_admin();
		$this->configure_provider();
		$response = $this->call( 'POST', '/jobs', array( 'id' => wp_generate_uuid4(), 'type' => 'outline' ) );
		$this->assertSame( 428, $response->get_status() );
		$this->assertCount( 0, $this->http_requests );
	}

	public function test_local_rate_limit() {
		$this->login_admin();
		$this->configure_provider();
		Options::update_settings( array( 'rate_limit_per_hour' => 1 ) );
		$this->assertSame( 202, $this->call( 'POST', '/jobs', array( 'id' => wp_generate_uuid4(), 'type' => 'outline', 'confirm_cost' => true ) )->get_status() );
		$this->assertSame( 429, $this->call( 'POST', '/jobs', array( 'id' => wp_generate_uuid4(), 'type' => 'outline', 'confirm_cost' => true ) )->get_status() );
	}

	public function test_full_flow_outline_page_draft() {
		$this->login_admin();
		$this->configure_provider();

		// 1. Outline.
		$id  = wp_generate_uuid4();
		$res = $this->call( 'POST', '/jobs', array( 'id' => $id, 'type' => 'outline', 'confirm_cost' => true, 'brief' => array( 'topic' => 'Coffee shop', 'language' => 'fa', 'sections_count' => 3 ) ) );
		$this->assertSame( 202, $res->get_status() );
		$this->assertSame( 'queued', $res->get_data()['status'] );

		// Idempotency: same id returns the same job and does not queue twice.
		$again = $this->call( 'POST', '/jobs', array( 'id' => $id, 'type' => 'outline', 'confirm_cost' => true ) );
		$this->assertSame( 200, $again->get_status() );
		$this->assertSame( $id, $again->get_data()['id'] );

		$this->queue_completion( $this->fake_outline() );
		$run = $this->call( 'POST', '/jobs/' . $id . '/run' );
		$this->assertSame( 'completed', $run->get_data()['status'] );
		$outline = $run->get_data()['result']['outline'];
		$this->assertCount( 2, $outline['sections'] );
		$this->assertCount( 1, $this->http_requests );

		// Running again does not call the provider again.
		$this->call( 'POST', '/jobs/' . $id . '/run' );
		$this->assertCount( 1, $this->http_requests );

		// 2. Page content.
		$page_id = wp_generate_uuid4();
		$res     = $this->call( 'POST', '/jobs', array( 'id' => $page_id, 'type' => 'page', 'confirm_cost' => true, 'brief' => array( 'topic' => 'Coffee shop', 'language' => 'fa', 'title' => 'کافه' ), 'outline' => $outline ) );
		$this->assertSame( 202, $res->get_status() );
		$model_page                                       = $this->template( 'landing-page-fa' );
		$model_page['design_tokens']['colors']['primary'] = '#000001';
		$this->queue_completion( wp_json_encode( $model_page ) );
		$run  = $this->call( 'POST', '/jobs/' . $page_id . '/run' );
		$data = $run->get_data();
		$this->assertSame( 'completed', $data['status'], wp_json_encode( $data['error'] ) );
		$schema = $data['result']['schema'];
		$this->assertSame( 'rtl', $schema['meta']['direction'] );
		$this->assertSame( 'کافه', $schema['meta']['title'] );
		$this->assertNotSame( '#000001', $schema['design_tokens']['colors']['primary'], 'Approved tokens win over model tokens' );

		// 3. Draft.
		$draft = $this->call( 'POST', '/drafts', array( 'schema' => $schema, 'builder' => 'gutenberg', 'job_id' => $page_id ) );
		$this->assertSame( 201, $draft->get_status() );
		$post_id = $draft->get_data()['post_id'];
		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$this->assertSame( $post_id, (int) JobRepository::get( $page_id )['post_id'] );

		// No secret in any response body.
		foreach ( array( $res, $run, $draft, $this->call( 'GET', '/status' ), $this->call( 'GET', '/jobs' ) ) as $response ) {
			$this->assertStringNotContainsString( 'SECRET', wp_json_encode( $response->get_data() ) );
		}
		// Nor in logs.
		global $wpdb;
		$logs = $wpdb->get_col( "SELECT CONCAT(message, context) FROM {$wpdb->prefix}aipd_logs" ); // phpcs:ignore
		$this->assertStringNotContainsString( 'SECRET', implode( ' ', $logs ) );
	}

	public function test_invalid_model_json_fails_the_job_gracefully() {
		$this->login_admin();
		$this->configure_provider();
		$id = wp_generate_uuid4();
		$this->call( 'POST', '/jobs', array( 'id' => $id, 'type' => 'outline', 'confirm_cost' => true ) );
		$this->queue_completion( 'Sorry, I cannot do JSON today.' );
		$data = $this->call( 'POST', '/jobs/' . $id . '/run' )->get_data();
		$this->assertSame( 'failed', $data['status'] );
		$this->assertSame( 'aipd_invalid_json', $data['error']['code'] );
	}

	public function test_prompt_injection_output_is_neutralized() {
		$this->login_admin();
		$this->configure_provider();
		$outline = json_decode( $this->fake_outline(), true );
		$id      = wp_generate_uuid4();
		$this->call( 'POST', '/jobs', array( 'id' => $id, 'type' => 'page', 'confirm_cost' => true, 'outline' => $outline ) );
		$evil = array(
			'meta'     => array( 'title' => '<?php system("id"); ?>' ),
			'sections' => array(
				array(
					'id'      => 'hero',
					'type'    => 'hero',
					'columns' => array(
						array(
							'components' => array(
								array( 'type' => 'heading', 'level' => 1, 'text' => '<script>fetch("//evil")</script>Hi' ),
								array( 'type' => 'paragraph', 'text' => '[wp_exec cmd="x"] <iframe src="//evil"></iframe>' ),
							),
						),
					),
				),
			),
		);
		$this->queue_completion( wp_json_encode( $evil ) );
		$schema = $this->call( 'POST', '/jobs/' . $id . '/run' )->get_data()['result']['schema'];
		$json   = wp_json_encode( $schema );
		$this->assertStringNotContainsString( '<script', $json );
		$this->assertStringNotContainsString( '<iframe', $json );
		$this->assertStringNotContainsString( '<?php', $json );

		$draft   = $this->call( 'POST', '/drafts', array( 'schema' => $schema, 'builder' => 'gutenberg' ) );
		$content = get_post( $draft->get_data()['post_id'] )->post_content;
		$this->assertStringNotContainsString( '[wp_exec', $content );
		$this->assertStringContainsString( '&#091;wp_exec', $content );
	}

	public function test_users_cannot_read_other_users_jobs() {
		$owner = $this->login_admin();
		$this->configure_provider();
		$id = wp_generate_uuid4();
		$this->call( 'POST', '/jobs', array( 'id' => $id, 'type' => 'outline', 'confirm_cost' => true ) );

		$other = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_userdata( $other )->add_cap( Capabilities::GENERATE );
		wp_set_current_user( $other );
		$this->assertSame( 403, $this->call( 'GET', '/jobs/' . $id )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/jobs/' . $id . '/run' )->get_status() );
		$this->assertSame( 409, $this->call( 'POST', '/jobs', array( 'id' => $id, 'type' => 'outline', 'confirm_cost' => true ) )->get_status() );
		$this->assertCount( 0, $this->http_requests );
		$this->assertNotSame( $owner, $other );
	}

	public function test_interrupted_job_is_marked_failed() {
		$this->login_admin();
		$this->configure_provider();
		$id = wp_generate_uuid4();
		$this->call( 'POST', '/jobs', array( 'id' => $id, 'type' => 'outline', 'confirm_cost' => true ) );
		JobRepository::claim( $id );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'aipd_jobs', array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ), array( 'uuid' => $id ) ); // phpcs:ignore
		$data = $this->call( 'GET', '/jobs/' . $id )->get_data();
		$this->assertSame( 'failed', $data['status'] );
		$this->assertSame( 'aipd_interrupted', $data['error']['code'] );
	}

	public function test_history_disabled_strips_payload_after_delivery() {
		$this->login_admin();
		$this->configure_provider();
		Options::update_settings( array( 'history_enabled' => false ) );
		$id = wp_generate_uuid4();
		$this->call( 'POST', '/jobs', array( 'id' => $id, 'type' => 'outline', 'confirm_cost' => true, 'brief' => array( 'business' => 'Private business details' ) ) );
		$this->queue_completion( $this->fake_outline() );
		$data = $this->call( 'POST', '/jobs/' . $id . '/run' )->get_data();
		$this->assertArrayHasKey( 'result', $data );
		$row = JobRepository::get( $id );
		$this->assertNull( $row['input'] );
		$this->assertNull( $row['result'] );
	}

	public function test_preview_returns_sandboxable_document() {
		$this->login_admin();
		$res  = $this->call( 'POST', '/preview', array( 'schema' => $this->template( 'landing-page-fa' ) ) );
		$html = $res->get_data()['html'];
		$this->assertStringContainsString( 'dir="rtl"', $html );
		$this->assertStringContainsString( 'lang="fa-IR"', $html );
		$this->assertStringNotContainsString( '<script', $html );
	}

	public function test_provider_test_endpoint_returns_models_without_key() {
		$this->login_admin();
		$this->configure_provider();
		$this->queue_response( 200, array( 'data' => array( array( 'id' => 'm1' ) ) ) );
		$res = $this->call( 'POST', '/providers/openai_compatible/test' );
		$this->assertSame( array( 'm1' ), $res->get_data()['models'] );
		$this->assertStringNotContainsString( 'SECRET', wp_json_encode( $res->get_data() ) );
	}

	public function test_templates_endpoints() {
		$this->login_admin();
		$list = $this->call( 'GET', '/templates' )->get_data();
		$this->assertContains( 'builtin:landing-page-fa', wp_list_pluck( $list, 'id' ) );
		$tpl = $this->call( 'GET', '/templates/builtin:landing-page-en' )->get_data();
		$this->assertSame( 'ltr', $tpl['schema']['meta']['direction'] );
		$saved = $this->call( 'POST', '/templates', array( 'title' => 'Mine', 'schema' => $tpl['schema'] ) );
		$this->assertSame( 201, $saved->get_status() );
		$this->assertSame( 200, $this->call( 'DELETE', '/templates/' . $saved->get_data()['id'] )->get_status() );
		$this->assertSame( 400, $this->call( 'DELETE', '/templates/builtin:landing-page-en' )->get_status() );
	}
}
