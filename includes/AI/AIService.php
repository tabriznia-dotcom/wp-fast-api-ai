<?php
/**
 * Orchestrates guarded AI requests.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\AI;

use AIPageDesigner\AI\Contracts\ProviderInterface;
use AIPageDesigner\AI\DTO\Brief;
use AIPageDesigner\AI\DTO\CompletionRequest;
use AIPageDesigner\AI\DTO\CompletionResponse;
use AIPageDesigner\Core\Options;
use AIPageDesigner\Logging\Logger;
use AIPageDesigner\Schema\PageSchema;
use AIPageDesigner\Schema\Sanitizer;
use AIPageDesigner\Schema\SchemaService;
use AIPageDesigner\Schema\Validator;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Every AI call goes through preflight(): privacy acknowledgement, configured
 * provider (so nothing is sent without credentials), cost confirmation and
 * rate limiting.
 */
final class AIService {

	/**
	 * Provider registry.
	 *
	 * @var ProviderRegistry
	 */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @param ProviderRegistry $registry Registry.
	 */
	public function __construct( ProviderRegistry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Checks whether a request may be sent.
	 *
	 * @param bool $cost_confirmed Whether the user confirmed a possibly paid request.
	 * @param int  $user_id        User id for rate limiting.
	 * @return ProviderInterface|WP_Error
	 */
	public function preflight( $cost_confirmed, $user_id ) {
		if ( ! Options::get( 'privacy_acknowledged' ) ) {
			return new WP_Error( 'aipd_privacy_not_acknowledged', __( 'An administrator must review and accept the data sharing notice in AI Page Designer > Privacy before AI requests can be sent.', 'ai-page-designer' ), array( 'status' => 403 ) );
		}
		$provider = $this->registry->active();
		if ( ! $provider ) {
			return new WP_Error( 'aipd_no_provider', __( 'No AI provider is selected or available.', 'ai-page-designer' ), array( 'status' => 400 ) );
		}
		if ( ! $provider->is_configured() ) {
			return new WP_Error( 'aipd_not_configured', __( 'The AI provider is not configured yet. No request was sent.', 'ai-page-designer' ), array( 'status' => 400 ) );
		}
		if ( $provider->is_paid() && Options::get( 'confirm_paid_requests' ) && ! $cost_confirmed ) {
			return new WP_Error( 'aipd_cost_not_confirmed', __( 'This request may incur costs with your AI provider. Please confirm before sending.', 'ai-page-designer' ), array( 'status' => 428 ) );
		}
		if ( ! RateLimiter::hit( $user_id ) ) {
			return new WP_Error( 'aipd_rate_limited_local', __( 'You reached the hourly limit of AI requests for this site. Please try again later.', 'ai-page-designer' ), array( 'status' => 429 ) );
		}
		return $provider;
	}

	/**
	 * Sends a request through a provider with hooks and logging.
	 *
	 * @param ProviderInterface $provider Provider.
	 * @param CompletionRequest $request  Request.
	 * @return CompletionResponse|WP_Error
	 */
	public function send( ProviderInterface $provider, CompletionRequest $request ) {
		/**
		 * Filters a completion request before it is sent.
		 *
		 * @since 1.0.0
		 *
		 * @param CompletionRequest $request     Request (contains no credentials).
		 * @param string            $provider_id Provider id.
		 */
		$filtered = apply_filters( 'aipd_ai_request', $request, $provider->get_id() );
		if ( $filtered instanceof CompletionRequest ) {
			$request = $filtered;
		}

		/**
		 * Fires before an AI request is sent. Receives a summary only (sizes and
		 * parameters), never the prompt or credentials.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $summary     Request summary.
		 * @param string $provider_id Provider id.
		 */
		do_action( 'aipd_before_ai_request', $request->summary(), $provider->get_id() );

		$started  = microtime( true );
		$response = $provider->complete( $request );
		$elapsed  = round( microtime( true ) - $started, 2 );

		$result_summary = is_wp_error( $response )
			? array(
				'error'   => $response->get_error_code(),
				'seconds' => $elapsed,
			)
			: array(
				'model'      => $response->model,
				'tokens_in'  => $response->tokens_in,
				'tokens_out' => $response->tokens_out,
				'seconds'    => $elapsed,
			);

		/**
		 * Fires after an AI request completes or fails.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $summary        Request summary.
		 * @param array  $result_summary Result summary (model, tokens, timing or error code).
		 * @param string $provider_id    Provider id.
		 */
		do_action( 'aipd_after_ai_request', $request->summary(), $result_summary, $provider->get_id() );

		if ( is_wp_error( $response ) ) {
			Logger::error(
				'ai_request_failed',
				sprintf( 'AI request (%s) failed: %s', $request->purpose, $response->get_error_code() ),
				array_merge( $result_summary, array( 'provider' => $provider->get_id() ) )
			);
		} else {
			Logger::info( 'ai_request_ok', sprintf( 'AI request (%s) completed', $request->purpose ), array_merge( $result_summary, array( 'provider' => $provider->get_id() ) ) );
		}

		return $response;
	}

	/**
	 * Generates an outline for a brief.
	 *
	 * @param ProviderInterface $provider Provider.
	 * @param Brief             $brief    Brief.
	 * @return array{outline:array,response:CompletionResponse}|WP_Error
	 */
	public function outline( ProviderInterface $provider, Brief $brief ) {
		$response = $this->send( $provider, PromptBuilder::outline( $brief ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$outline = self::process_outline( $response->text );
		if ( is_wp_error( $outline ) ) {
			return $outline;
		}
		return array(
			'outline'  => $outline,
			'response' => $response,
		);
	}

	/**
	 * Validates outline JSON (from the model or edited by the user).
	 *
	 * @param string|array $input Model text or decoded outline.
	 * @return array|WP_Error
	 */
	public static function process_outline( $input ) {
		$data = is_array( $input ) ? $input : SchemaService::decode( $input );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$definition       = PageSchema::outline_definition();
		list( $outline, ) = ( new Sanitizer() )->sanitize( $data, $definition );
		if ( ! is_array( $outline ) || ! empty( ( new Validator() )->validate( $outline, $definition ) ) ) {
			return new WP_Error( 'aipd_outline_invalid', __( 'The proposed structure was not valid. Please try again.', 'ai-page-designer' ) );
		}
		$seen = array();
		foreach ( $outline['sections'] as $index => $section ) {
			$id = $section['id'];
			while ( isset( $seen[ $id ] ) ) {
				$id .= '-' . ( $index + 1 );
			}
			$seen[ $id ]                         = true;
			$outline['sections'][ $index ]['id'] = substr( $id, 0, 40 );
		}
		return $outline;
	}

	/**
	 * Generates the full page schema.
	 *
	 * @param ProviderInterface $provider Provider.
	 * @param Brief             $brief    Brief.
	 * @param array             $outline  Approved outline sections.
	 * @param array             $tokens   Approved design tokens.
	 * @return array{schema:array,warnings:string[],response:CompletionResponse}|WP_Error
	 */
	public function page( ProviderInterface $provider, Brief $brief, array $outline, array $tokens ) {
		$response = $this->send( $provider, PromptBuilder::page( $brief, $outline, $tokens ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = SchemaService::decode( $response->text );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// The user's approved choices always win over the model's.
		$data['design_tokens'] = $tokens;
		$data['meta']          = array_merge(
			isset( $data['meta'] ) && is_array( $data['meta'] ) ? $data['meta'] : array(),
			array(
				'language'  => $brief->get( 'language' ),
				'direction' => $brief->get( 'direction' ),
				'page_type' => $brief->get( 'page_type' ),
				'style'     => $brief->get( 'style' ),
			)
		);
		if ( '' !== $brief->get( 'title' ) ) {
			$data['meta']['title'] = $brief->get( 'title' );
		}

		$result = SchemaService::process( $data );
		if ( is_wp_error( $result ) ) {
			Logger::warning( 'schema_rejected', 'Generated schema failed validation', array( 'details' => $result->get_error_data() ) );
			return $result;
		}
		$result['response'] = $response;
		return $result;
	}

	/**
	 * Regenerates one section of a page schema.
	 *
	 * @param ProviderInterface $provider    Provider.
	 * @param array             $schema      Current trusted page schema.
	 * @param string            $section_id  Section id.
	 * @param string            $instruction User instruction.
	 * @return array{section:array,warnings:string[],response:CompletionResponse}|WP_Error
	 */
	public function section( ProviderInterface $provider, array $schema, $section_id, $instruction ) {
		$index = self::find_section( $schema, $section_id );
		if ( null === $index ) {
			return new WP_Error( 'aipd_section_not_found', __( 'The section could not be found.', 'ai-page-designer' ), array( 'status' => 404 ) );
		}

		$response = $this->send( $provider, PromptBuilder::section( $schema['meta'], $schema['sections'][ $index ], $instruction ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = SchemaService::decode( $response->text );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( isset( $data['sections'][0] ) && is_array( $data['sections'][0] ) ) {
			$data = $data['sections'][0];
		}
		$data['id'] = $section_id;

		$candidate                       = $schema;
		$candidate['sections'][ $index ] = $data;
		$result                          = SchemaService::process( $candidate );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$new_index = self::find_section( $result['schema'], $section_id );
		if ( null === $new_index ) {
			return new WP_Error( 'aipd_schema_invalid', __( 'The regenerated section was not valid.', 'ai-page-designer' ) );
		}
		return array(
			'section'  => $result['schema']['sections'][ $new_index ],
			'schema'   => $result['schema'],
			'warnings' => $result['warnings'],
			'response' => $response,
		);
	}

	/**
	 * Index of a section by id.
	 *
	 * @param array  $schema Schema.
	 * @param string $id     Section id.
	 * @return int|null
	 */
	public static function find_section( array $schema, $id ) {
		foreach ( $schema['sections'] as $index => $section ) {
			if ( $section['id'] === $id ) {
				return $index;
			}
		}
		return null;
	}
}
