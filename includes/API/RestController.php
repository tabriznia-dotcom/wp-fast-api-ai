<?php
/**
 * REST API for the wizard and admin screens.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\API;

use AIPageDesigner\AI\AIService;
use AIPageDesigner\AI\DTO\Brief;
use AIPageDesigner\AI\RateLimiter;
use AIPageDesigner\Core\Capabilities;
use AIPageDesigner\Core\Options;
use AIPageDesigner\Core\Plugin;
use AIPageDesigner\Jobs\JobRepository;
use AIPageDesigner\Jobs\JobRunner;
use AIPageDesigner\Logging\Logger;
use AIPageDesigner\PageBuilders\AbstractAdapter;
use AIPageDesigner\PageBuilders\Classic\HtmlRenderer;
use AIPageDesigner\Schema\DesignTokens;
use AIPageDesigner\Schema\SchemaService;
use AIPageDesigner\Templates\TemplateRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Namespace aipd/v1. Cookie-authenticated requests require the wp_rest nonce
 * (CSRF protection is provided by the REST API). Every route declares a
 * permission_callback. No response ever contains credentials.
 */
final class RestController {

	const NAMESPACE_V1 = 'aipd/v1';

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
	 * Permission: generate pages.
	 *
	 * @return bool
	 */
	public function can_generate() {
		return Capabilities::can_generate();
	}

	/**
	 * Permission: manage settings.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return Capabilities::can_manage();
	}

	/**
	 * Registers routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$uuid_arg = array(
			'type'              => 'string',
			'required'          => true,
			'validate_callback' => static function ( $value ) {
				return is_string( $value ) && wp_is_uuid( $value );
			},
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'can_generate' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/jobs',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_jobs' ),
					'permission_callback' => array( $this, 'can_generate' ),
					'args'                => array(
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 100,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_job' ),
					'permission_callback' => array( $this, 'can_generate' ),
					'args'                => array(
						'id'           => $uuid_arg,
						'type'         => array(
							'type'     => 'string',
							'enum'     => JobRepository::TYPES,
							'required' => true,
						),
						'confirm_cost' => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'brief'        => array(
							'type'    => 'object',
							'default' => array(),
						),
						'outline'      => array(
							'type'    => 'object',
							'default' => array(),
						),
						'tokens'       => array(
							'type'    => 'object',
							'default' => array(),
						),
						'schema'       => array(
							'type'    => 'object',
							'default' => array(),
						),
						'section_id'   => array(
							'type'    => 'string',
							'default' => '',
							'pattern' => '^[a-z0-9-]{0,40}$',
						),
						'instruction'  => array(
							'type'      => 'string',
							'default'   => '',
							'maxLength' => 1000,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/jobs/(?P<id>[0-9a-f-]{36})',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_job' ),
					'permission_callback' => array( $this, 'can_generate' ),
					'args'                => array( 'id' => $uuid_arg ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_job' ),
					'permission_callback' => array( $this, 'can_generate' ),
					'args'                => array( 'id' => $uuid_arg ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/jobs/(?P<id>[0-9a-f-]{36})/run',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run_job' ),
				'permission_callback' => array( $this, 'can_generate' ),
				'args'                => array( 'id' => $uuid_arg ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/schema/validate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'validate_schema' ),
				'permission_callback' => array( $this, 'can_generate' ),
				'args'                => array(
					'schema' => array(
						'type'     => 'object',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => array( $this, 'can_generate' ),
				'args'                => array(
					'schema' => array(
						'type'     => 'object',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/tokens',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'tokens' ),
				'permission_callback' => array( $this, 'can_generate' ),
				'args'                => array(
					'brief' => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/drafts',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_draft' ),
				'permission_callback' => array( $this, 'can_generate' ),
				'args'                => array(
					'schema'    => array(
						'type'     => 'object',
						'required' => true,
					),
					'builder'   => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[a-z0-9_-]{1,40}$',
					),
					'post_type' => array(
						'type'    => 'string',
						'default' => 'page',
						'pattern' => '^[a-z0-9_-]{1,20}$',
					),
					'job_id'    => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/drafts/(?P<post_id>\d+)/sections/(?P<section_id>[a-z0-9-]{1,40})',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_section' ),
				'permission_callback' => function ( WP_REST_Request $request ) {
					return $this->can_generate() && current_user_can( 'edit_post', (int) $request['post_id'] );
				},
				'args'                => array(
					'schema' => array(
						'type'     => 'object',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/drafts/(?P<post_id>\d+)/schema',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_draft_schema' ),
				'permission_callback' => function ( WP_REST_Request $request ) {
					return $this->can_generate() && current_user_can( 'edit_post', (int) $request['post_id'] );
				},
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/templates',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_templates' ),
					'permission_callback' => array( $this, 'can_generate' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_template' ),
					'permission_callback' => array( $this, 'can_generate' ),
					'args'                => array(
						'title'  => array(
							'type'      => 'string',
							'default'   => '',
							'maxLength' => 200,
						),
						'schema' => array(
							'type'     => 'object',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/templates/(?P<id>(builtin:[a-z0-9_-]+|user:\d+))',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_template' ),
					'permission_callback' => array( $this, 'can_generate' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_template' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/providers/(?P<provider>[a-z0-9_]+)/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_provider' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Environment status for the wizard.
	 *
	 * @return WP_REST_Response
	 */
	public function status() {
		$provider = $this->plugin->providers->active();
		$builders = array();
		foreach ( $this->plugin->builders->all() as $id => $adapter ) {
			$builders[] = array(
				'id'        => $id,
				'name'      => $adapter->get_name(),
				'available' => $adapter->is_available(),
			);
		}
		$fonts = array();
		foreach ( DesignTokens::fonts() as $id => $font ) {
			$fonts[] = array(
				'id'    => $id,
				'label' => $font['label'],
			);
		}

		return rest_ensure_response(
			array(
				'privacy_acknowledged' => (bool) Options::get( 'privacy_acknowledged' ),
				'provider'             => $provider ? array(
					'id'         => $provider->get_id(),
					'name'       => $provider->get_name(),
					'configured' => $provider->is_configured(),
					'paid'       => $provider->is_paid(),
					'privacy'    => $provider->get_privacy_info(),
				) : null,
				'confirm_cost'         => (bool) Options::get( 'confirm_paid_requests' ) && $provider && $provider->is_paid(),
				'rate_remaining'       => RateLimiter::remaining( get_current_user_id() ),
				'builders'             => $builders,
				'default_builder'      => (string) Options::get( 'default_builder' ),
				'fonts'                => $fonts,
				'styles'               => DesignTokens::style_labels(),
				'brand_kit'            => Options::brand_kit(),
				'can_manage'           => Capabilities::can_manage(),
				'site_language'        => str_replace( '_', '-', get_locale() ),
			)
		);
	}

	/**
	 * Creates a job after all preflight checks. Idempotent per client UUID.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_job( WP_REST_Request $request ) {
		$uuid    = strtolower( (string) $request['id'] );
		$user_id = get_current_user_id();

		$existing = JobRepository::get( $uuid );
		if ( $existing ) {
			if ( (int) $existing['user_id'] !== $user_id ) {
				return new WP_Error( 'aipd_job_conflict', __( 'This request id is already in use.', 'ai-page-designer' ), array( 'status' => 409 ) );
			}
			return new WP_REST_Response( JobRepository::to_public( $existing ), 200 );
		}

		$type  = (string) $request['type'];
		$brief = Brief::from_array( (array) $request['brief'] );
		$input = array( 'brief' => $brief->to_array() );

		if ( 'page' === $type ) {
			$outline = AIService::process_outline( (array) $request['outline'] );
			if ( is_wp_error( $outline ) ) {
				return self::with_status( $outline, 400 );
			}
			$input['outline'] = $outline;
			$input['tokens']  = SchemaService::sanitize_tokens( (array) $request['tokens'], $brief->get( 'style' ) );
		} elseif ( 'section' === $type ) {
			$schema = SchemaService::from_client( $request['schema'] );
			if ( is_wp_error( $schema ) ) {
				return self::with_status( $schema, 400 );
			}
			if ( null === AIService::find_section( $schema['schema'], (string) $request['section_id'] ) ) {
				return new WP_Error( 'aipd_section_not_found', __( 'The section could not be found.', 'ai-page-designer' ), array( 'status' => 404 ) );
			}
			$input['schema']      = $schema['schema'];
			$input['section_id']  = (string) $request['section_id'];
			$input['instruction'] = sanitize_textarea_field( (string) $request['instruction'] );
		}

		$provider = $this->plugin->ai->preflight( (bool) $request['confirm_cost'], $user_id );
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$title = '' !== $brief->get( 'title' ) ? $brief->get( 'title' ) : $brief->get( 'topic' );
		if ( 'section' === $type && isset( $input['schema']['meta']['title'] ) ) {
			$title = $input['schema']['meta']['title'];
		}

		$job = JobRepository::create(
			$uuid,
			$user_id,
			$type,
			$input,
			array(
				'provider' => $provider->get_id(),
				'builder'  => $brief->get( 'builder' ),
				'title'    => (string) $title,
			)
		);
		if ( ! $job ) {
			return new WP_Error( 'aipd_job_failed', __( 'The request could not be queued.', 'ai-page-designer' ), array( 'status' => 500 ) );
		}

		JobRunner::schedule_fallback( $uuid );
		return new WP_REST_Response( JobRepository::to_public( $job ), 202 );
	}

	/**
	 * Runs a job in this request (the wizard calls this right after creating it).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function run_job( WP_REST_Request $request ) {
		$job = $this->owned_job( (string) $request['id'] );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		ignore_user_abort( true );
		$row = $this->plugin->runner->run( $job['uuid'] );
		return $this->job_response( $row );
	}

	/**
	 * Returns a job.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_job( WP_REST_Request $request ) {
		$job = $this->owned_job( (string) $request['id'] );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		if ( 'running' === $job['status'] ) {
			JobRepository::fail_stale();
			$job = JobRepository::get( $job['uuid'] );
		}
		return $this->job_response( $job );
	}

	/**
	 * Deletes a job from history.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_job( WP_REST_Request $request ) {
		$job = $this->owned_job( (string) $request['id'] );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		JobRepository::delete( $job['uuid'] );
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Lists history. Managers see all users; others see their own.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_jobs( WP_REST_Request $request ) {
		$user   = Capabilities::can_manage() ? 0 : get_current_user_id();
		$result = JobRepository::query( $user, (int) $request['page'], (int) $request['per_page'] );
		$items  = array();
		foreach ( $result['items'] as $row ) {
			$items[] = JobRepository::to_public( $row, false );
		}
		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $result['total'] / max( 1, (int) $request['per_page'] ) ) ) );
		return $response;
	}

	/**
	 * Validates and normalizes a schema without calling any AI service.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function validate_schema( WP_REST_Request $request ) {
		$result = SchemaService::from_client( $request['schema'] );
		return is_wp_error( $result ) ? self::with_status( $result, 400 ) : rest_ensure_response( $result );
	}

	/**
	 * Returns a preview document for a sandboxed iframe.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function preview( WP_REST_Request $request ) {
		$result = SchemaService::from_client( $request['schema'] );
		if ( is_wp_error( $result ) ) {
			return self::with_status( $result, 400 );
		}
		return rest_ensure_response(
			array(
				'html'     => ( new HtmlRenderer( $result['schema'] ) )->preview_document(),
				'schema'   => $result['schema'],
				'warnings' => $result['warnings'],
			)
		);
	}

	/**
	 * Design tokens for a brief (preset + brand kit + brief colors). No AI call.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function tokens( WP_REST_Request $request ) {
		$brief  = Brief::from_array( (array) $request['brief'] );
		$tokens = SchemaService::sanitize_tokens( $brief->design_tokens( Options::brand_kit() ), $brief->get( 'style' ) );
		return rest_ensure_response( array( 'tokens' => $tokens ) );
	}

	/**
	 * Creates a draft with the chosen builder.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_draft( WP_REST_Request $request ) {
		$adapter = $this->plugin->builders->get( (string) $request['builder'] );
		if ( ! $adapter ) {
			return new WP_Error( 'aipd_unknown_builder', __( 'Unknown page builder.', 'ai-page-designer' ), array( 'status' => 400 ) );
		}
		if ( ! $adapter->is_available() ) {
			return new WP_Error( 'aipd_builder_unavailable', __( 'The selected page builder is not active. Choose another builder.', 'ai-page-designer' ), array( 'status' => 400 ) );
		}
		$result = SchemaService::from_client( $request['schema'] );
		if ( is_wp_error( $result ) ) {
			return self::with_status( $result, 400 );
		}

		$post_id = $adapter->create_draft( $result['schema'], array( 'post_type' => (string) $request['post_type'] ) );
		if ( is_wp_error( $post_id ) ) {
			Logger::warning( 'draft_failed', 'Draft creation failed', array( 'code' => $post_id->get_error_code() ) );
			return $post_id;
		}

		$job_id = strtolower( (string) $request['job_id'] );
		if ( wp_is_uuid( $job_id ) ) {
			$job = JobRepository::get( $job_id );
			if ( $job && get_current_user_id() === (int) $job['user_id'] ) {
				JobRepository::set_post( $job_id, $post_id );
			}
		}

		Logger::info( 'draft_created', 'Draft created', array( 'builder' => $adapter->get_id() ) );

		return new WP_REST_Response(
			array(
				'post_id'     => $post_id,
				'status'      => get_post_status( $post_id ),
				'edit_url'    => $adapter->get_edit_url( $post_id ),
				'preview_url' => (string) get_preview_post_link( $post_id ),
				'builder'     => $adapter->get_id(),
			),
			201
		);
	}

	/**
	 * Returns the stored schema of a generated draft.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_draft_schema( WP_REST_Request $request ) {
		$schema = AbstractAdapter::stored_schema( (int) $request['post_id'] );
		if ( ! $schema ) {
			return new WP_Error( 'aipd_not_generated', __( 'This page was not created with AI Page Designer.', 'ai-page-designer' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response(
			array(
				'schema'  => $schema,
				'builder' => (string) get_post_meta( (int) $request['post_id'], AbstractAdapter::META_BUILDER, true ),
			)
		);
	}

	/**
	 * Replaces one section of a generated draft.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_section( WP_REST_Request $request ) {
		$post_id = (int) $request['post_id'];
		$builder = (string) get_post_meta( $post_id, AbstractAdapter::META_BUILDER, true );
		$adapter = $this->plugin->builders->get( $builder );
		if ( ! $adapter ) {
			return new WP_Error( 'aipd_not_generated', __( 'This page was not created with AI Page Designer.', 'ai-page-designer' ), array( 'status' => 400 ) );
		}
		$result = SchemaService::from_client( $request['schema'] );
		if ( is_wp_error( $result ) ) {
			return self::with_status( $result, 400 );
		}
		$updated = $adapter->update_section( $post_id, $result['schema'], (string) $request['section_id'] );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		return rest_ensure_response(
			array(
				'updated'  => true,
				'edit_url' => $adapter->get_edit_url( $post_id ),
			)
		);
	}

	/**
	 * Lists templates.
	 *
	 * @return WP_REST_Response
	 */
	public function list_templates() {
		return rest_ensure_response( TemplateRepository::all() );
	}

	/**
	 * Returns a template schema.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_template( WP_REST_Request $request ) {
		$schema = TemplateRepository::get( (string) $request['id'] );
		return is_wp_error( $schema ) ? $schema : rest_ensure_response( array( 'schema' => $schema ) );
	}

	/**
	 * Saves a template.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_template( WP_REST_Request $request ) {
		$id = TemplateRepository::save( (string) $request['title'], $request['schema'] );
		if ( is_wp_error( $id ) ) {
			return self::with_status( $id, 400 );
		}
		return new WP_REST_Response( array( 'id' => 'user:' . $id ), 201 );
	}

	/**
	 * Deletes a user template.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_template( WP_REST_Request $request ) {
		$result = TemplateRepository::delete( (string) $request['id'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Tests a provider connection. Returns only status and model ids.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function test_provider( WP_REST_Request $request ) {
		$provider = $this->plugin->providers->get( sanitize_key( (string) $request['provider'] ) );
		if ( ! $provider ) {
			return new WP_Error( 'aipd_unknown_provider', __( 'Unknown provider.', 'ai-page-designer' ), array( 'status' => 404 ) );
		}
		$models = array();
		if ( method_exists( $provider, 'list_models' ) ) {
			$result = $provider->list_models();
			if ( ! is_wp_error( $result ) ) {
				$models = $result;
				$result = true;
			}
		} else {
			$result = $provider->test_connection();
		}
		if ( is_wp_error( $result ) ) {
			Logger::warning( 'provider_test_failed', 'Provider connection test failed', array( 'code' => $result->get_error_code() ) );
			return self::with_status( $result, 400 );
		}
		return rest_ensure_response(
			array(
				'ok'     => true,
				'models' => $models,
			)
		);
	}

	/**
	 * Loads a job the current user may access.
	 *
	 * @param string $uuid UUID.
	 * @return array|WP_Error
	 */
	private function owned_job( $uuid ) {
		$job = JobRepository::get( strtolower( $uuid ) );
		if ( ! $job ) {
			return new WP_Error( 'aipd_job_not_found', __( 'The request was not found. It may have expired.', 'ai-page-designer' ), array( 'status' => 404 ) );
		}
		if ( get_current_user_id() !== (int) $job['user_id'] && ! Capabilities::can_manage() ) {
			return new WP_Error( 'aipd_forbidden', __( 'You cannot access this request.', 'ai-page-designer' ), array( 'status' => 403 ) );
		}
		return $job;
	}

	/**
	 * Job REST response; strips payload afterwards when history is disabled.
	 *
	 * @param array|null $row Row.
	 * @return WP_REST_Response|WP_Error
	 */
	private function job_response( $row ) {
		if ( ! $row ) {
			return new WP_Error( 'aipd_job_not_found', __( 'The request was not found. It may have expired.', 'ai-page-designer' ), array( 'status' => 404 ) );
		}
		$public = JobRepository::to_public( $row );
		if ( ! Options::get( 'history_enabled' ) && in_array( $row['status'], array( 'completed', 'failed' ), true ) && get_current_user_id() === (int) $row['user_id'] ) {
			JobRepository::strip_payload( $row['uuid'] );
		}
		return rest_ensure_response( $public );
	}

	/**
	 * Adds an HTTP status to an error when missing.
	 *
	 * @param WP_Error $error  Error.
	 * @param int      $status Status.
	 * @return WP_Error
	 */
	private static function with_status( WP_Error $error, $status ) {
		$data = $error->get_error_data();
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		if ( ! isset( $data['status'] ) ) {
			$data['status'] = $status;
		}
		$error->add_data( $data );
		return $error;
	}
}
