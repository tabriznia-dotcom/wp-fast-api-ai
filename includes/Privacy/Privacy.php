<?php
/**
 * Privacy policy text, personal data exporter and eraser.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Privacy;

use AIPageDesigner\Jobs\JobRepository;
use AIPageDesigner\Logging\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Integrates with the WordPress privacy tools.
 */
final class Privacy {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'add_policy_content' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	/**
	 * Suggested text for the site's privacy policy.
	 *
	 * @return void
	 */
	public static function add_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content( __( 'AI Page Designer', 'ai-page-designer' ), wp_kses_post( wpautop( self::policy_text(), false ) ) );
	}

	/**
	 * Policy text (also shown on the plugin's Privacy screen).
	 *
	 * @return string
	 */
	public static function policy_text() {
		$paragraphs = array(
			__( 'This site uses the AI Page Designer plugin to help administrators and editors draft pages. The plugin does not collect data from site visitors and does not add tracking or analytics.', 'ai-page-designer' ),
			__( 'When an authorized user requests a page draft, the brief they enter (for example business description, target audience, goals, tone, language, brand colors and call to action) is sent from this site\'s server to the AI service configured by the site administrator. That service processes the data under its own terms and privacy policy. Site content is not sent unless the user explicitly includes it in the brief.', 'ai-page-designer' ),
			__( 'If generation history is enabled, the brief, the generated page structure, the user ID and timestamps are stored in this site\'s database for the configured retention period. Operational logs store error codes and timings, never API keys, prompts or generated text. Both can be disabled by an administrator.', 'ai-page-designer' ),
			__( 'Users can request an export or erasure of this data through the standard WordPress personal data tools.', 'ai-page-designer' ),
		);
		return implode( "\n\n", $paragraphs );
	}

	/**
	 * Registers the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['ai-page-designer'] = array(
			'exporter_friendly_name' => __( 'AI Page Designer history', 'ai-page-designer' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Registers the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['ai-page-designer'] = array(
			'eraser_friendly_name' => __( 'AI Page Designer history', 'ai-page-designer' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Exports a user's generation history.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		$user  = get_user_by( 'email', $email );
		$items = array();
		$limit = 50;
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		$rows = JobRepository::for_user( $user->ID, $limit, ( max( 1, (int) $page ) - 1 ) * $limit );
		foreach ( $rows as $row ) {
			$items[] = array(
				'group_id'    => 'aipd-history',
				'group_label' => __( 'AI Page Designer history', 'ai-page-designer' ),
				'item_id'     => 'aipd-job-' . $row['uuid'],
				'data'        => array(
					array(
						'name'  => __( 'Request type', 'ai-page-designer' ),
						'value' => $row['type'],
					),
					array(
						'name'  => __( 'Status', 'ai-page-designer' ),
						'value' => $row['status'],
					),
					array(
						'name'  => __( 'Title', 'ai-page-designer' ),
						'value' => $row['title'],
					),
					array(
						'name'  => __( 'Brief', 'ai-page-designer' ),
						'value' => (string) $row['input'],
					),
					array(
						'name'  => __( 'Date', 'ai-page-designer' ),
						'value' => $row['created_at'],
					),
				),
			);
		}
		return array(
			'data' => $items,
			'done' => count( $rows ) < $limit,
		);
	}

	/**
	 * Erases a user's history and anonymizes their log entries.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature required by the eraser API; all rows are removed in one pass.
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}
		$removed    = JobRepository::delete_for_user( $user->ID );
		$anonymized = Logger::anonymize_user( $user->ID );
		return array(
			'items_removed'  => $removed > 0 || $anonymized > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
