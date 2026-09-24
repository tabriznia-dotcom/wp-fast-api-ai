<?php
/**
 * Executes generation jobs.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Jobs;

use AIPageDesigner\AI\AIService;
use AIPageDesigner\AI\DTO\Brief;
use AIPageDesigner\AI\ProviderRegistry;
use AIPageDesigner\Core\Installer;
use AIPageDesigner\Core\Options;
use AIPageDesigner\Schema\SchemaService;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Jobs are created by the REST API (after all preflight checks) and run
 * either immediately by a follow-up request from the wizard or, if the
 * browser leaves, by a single WP-Cron event. claim() guarantees one run.
 */
final class JobRunner {

	/**
	 * AI service.
	 *
	 * @var AIService
	 */
	private $ai;

	/**
	 * Registry.
	 *
	 * @var ProviderRegistry
	 */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @param AIService        $ai       Service.
	 * @param ProviderRegistry $registry Registry.
	 */
	public function __construct( AIService $ai, ProviderRegistry $registry ) {
		$this->ai       = $ai;
		$this->registry = $registry;
	}

	/**
	 * Schedules the cron fallback for a job.
	 *
	 * @param string $uuid UUID.
	 * @return void
	 */
	public static function schedule_fallback( $uuid ) {
		if ( ! wp_next_scheduled( Installer::CRON_RUN_JOB, array( $uuid ) ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, Installer::CRON_RUN_JOB, array( $uuid ) );
		}
	}

	/**
	 * Cron callback.
	 *
	 * @param string $uuid UUID.
	 * @return void
	 */
	public function cron( $uuid ) {
		if ( is_string( $uuid ) && preg_match( '/^[0-9a-f-]{36}$/', $uuid ) ) {
			$this->run( $uuid );
		}
	}

	/**
	 * Runs a queued job.
	 *
	 * @param string $uuid UUID.
	 * @return array|null Job row after running, or null if not found.
	 */
	public function run( $uuid ) {
		$job = JobRepository::get( $uuid );
		if ( ! $job ) {
			return null;
		}
		if ( ! JobRepository::claim( $uuid ) ) {
			// Already running or finished (idempotent re-run).
			return JobRepository::get( $uuid );
		}

		wp_clear_scheduled_hook( Installer::CRON_RUN_JOB, array( $uuid ) );

		$input  = json_decode( (string) $job['input'], true );
		$result = is_array( $input ) ? $this->execute( $job['type'], $input ) : new WP_Error( 'aipd_job_invalid', __( 'The job data is invalid.', 'ai-page-designer' ) );

		if ( is_wp_error( $result ) ) {
			JobRepository::fail( $uuid, $result->get_error_code(), $result->get_error_message() );
		} else {
			$response = $result['response'];
			unset( $result['response'] );
			JobRepository::complete(
				$uuid,
				$result,
				array(
					'model'      => $response->model,
					'tokens_in'  => $response->tokens_in,
					'tokens_out' => $response->tokens_out,
				)
			);
		}

		if ( ! Options::get( 'history_enabled' ) ) {
			// Keep only what the wizard needs; the brief is no longer required.
			global $wpdb;
			$tables = Installer::tables();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->update( $tables['jobs'], array( 'input' => null ), array( 'uuid' => $uuid ), array( '%s' ), array( '%s' ) );
		}

		return JobRepository::get( $uuid );
	}

	/**
	 * Dispatches by job type.
	 *
	 * @param string $type  Type.
	 * @param array  $input Input.
	 * @return array|WP_Error
	 */
	private function execute( $type, array $input ) {
		$provider = $this->registry->active();
		if ( ! $provider || ! $provider->is_configured() ) {
			return new WP_Error( 'aipd_not_configured', __( 'The AI provider is not configured. No request was sent.', 'ai-page-designer' ) );
		}

		$brief = Brief::from_array( isset( $input['brief'] ) && is_array( $input['brief'] ) ? $input['brief'] : array() );

		switch ( $type ) {
			case 'outline':
				return $this->ai->outline( $provider, $brief );

			case 'page':
				$outline = AIService::process_outline( isset( $input['outline'] ) && is_array( $input['outline'] ) ? $input['outline'] : array() );
				if ( is_wp_error( $outline ) ) {
					return $outline;
				}
				$tokens = isset( $input['tokens'] ) && is_array( $input['tokens'] ) ? $input['tokens'] : $brief->design_tokens( Options::brand_kit() );
				return $this->ai->page( $provider, $brief, $outline['sections'], $tokens );

			case 'section':
				$schema = SchemaService::from_client( isset( $input['schema'] ) ? $input['schema'] : null );
				if ( is_wp_error( $schema ) ) {
					return $schema;
				}
				return $this->ai->section(
					$provider,
					$schema['schema'],
					isset( $input['section_id'] ) ? (string) $input['section_id'] : '',
					isset( $input['instruction'] ) ? (string) $input['instruction'] : ''
				);
		}
		return new WP_Error( 'aipd_job_invalid', __( 'Unknown job type.', 'ai-page-designer' ) );
	}
}
