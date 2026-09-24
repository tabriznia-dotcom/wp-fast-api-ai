<?php
/**
 * Privacy-aware logger.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Logging;

use AIPageDesigner\Core\Installer;
use AIPageDesigner\Core\Options;
use AIPageDesigner\Security\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Stores operational events in a custom table. Context is always redacted, and
 * briefs, prompts or model output are never logged.
 */
final class Logger {

	const LEVELS = array(
		'debug'   => 100,
		'info'    => 200,
		'warning' => 300,
		'error'   => 400,
	);

	/**
	 * Writes a log entry if logging is enabled for the level.
	 *
	 * @param string $level   debug|info|warning|error.
	 * @param string $event   Machine-readable event code.
	 * @param string $message Human-readable message written by the plugin (no user content).
	 * @param array  $context Extra data; redacted before storage.
	 * @return void
	 */
	public static function log( $level, $event, $message, array $context = array() ) {
		if ( ! isset( self::LEVELS[ $level ] ) || ! Options::get( 'logs_enabled' ) ) {
			return;
		}
		$min = (string) Options::get( 'log_level' );
		if ( self::LEVELS[ $level ] < ( isset( self::LEVELS[ $min ] ) ? self::LEVELS[ $min ] : 300 ) ) {
			return;
		}

		global $wpdb;
		$tables = Installer::tables();
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table write.
			$tables['logs'],
			array(
				'level'      => $level,
				'event'      => substr( sanitize_key( $event ), 0, 64 ),
				'message'    => Redactor::redact_string( sanitize_text_field( substr( $message, 0, 500 ) ) ),
				'context'    => wp_json_encode( Redactor::redact( $context ) ),
				'user_id'    => get_current_user_id(),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Convenience wrappers.
	 *
	 * @param string $event   Event.
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	public static function error( $event, $message, array $context = array() ) {
		self::log( 'error', $event, $message, $context );
	}

	/**
	 * Warning entry.
	 *
	 * @param string $event   Event.
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	public static function warning( $event, $message, array $context = array() ) {
		self::log( 'warning', $event, $message, $context );
	}

	/**
	 * Info entry.
	 *
	 * @param string $event   Event.
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	public static function info( $event, $message, array $context = array() ) {
		self::log( 'info', $event, $message, $context );
	}

	/**
	 * Paginated log query.
	 *
	 * @param int    $page     Page number (1-based).
	 * @param int    $per_page Items per page.
	 * @param string $level    Optional level filter.
	 * @return array{items:array,total:int}
	 */
	public static function query( $page = 1, $per_page = 20, $level = '' ) {
		global $wpdb;
		$tables   = Installer::tables();
		$per_page = max( 1, min( 100, (int) $per_page ) );
		$offset   = ( max( 1, (int) $page ) - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; table name is not user input.
		if ( isset( self::LEVELS[ $level ] ) ) {
			$items = $wpdb->get_results( $wpdb->prepare( "SELECT id, level, event, message, context, user_id, created_at FROM {$tables['logs']} WHERE level = %s ORDER BY id DESC LIMIT %d OFFSET %d", $level, $per_page, $offset ), ARRAY_A );
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['logs']} WHERE level = %s", $level ) );
		} else {
			$items = $wpdb->get_results( $wpdb->prepare( "SELECT id, level, event, message, context, user_id, created_at FROM {$tables['logs']} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A );
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['logs']}" );
		}
		// phpcs:enable

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Deletes entries older than N days.
	 *
	 * @param int $days Days.
	 * @return int Rows deleted.
	 */
	public static function purge( $days ) {
		global $wpdb;
		$tables = Installer::tables();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $days ) * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$tables['logs']} WHERE created_at < %s", $cutoff ) );
	}

	/**
	 * Deletes all entries.
	 *
	 * @return void
	 */
	public static function clear() {
		global $wpdb;
		$tables = Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$wpdb->query( "DELETE FROM {$tables['logs']}" );
	}

	/**
	 * Entries for a user (privacy export).
	 *
	 * @param int $user_id User id.
	 * @param int $limit   Limit.
	 * @param int $offset  Offset.
	 * @return array
	 */
	public static function for_user( $user_id, $limit = 100, $offset = 0 ) {
		global $wpdb;
		$tables = Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, level, event, message, created_at FROM {$tables['logs']} WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d", $user_id, $limit, $offset ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Removes the user association from entries (privacy erasure).
	 *
	 * @param int $user_id User id.
	 * @return int Rows changed.
	 */
	public static function anonymize_user( $user_id ) {
		global $wpdb;
		$tables = Installer::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		return (int) $wpdb->update( $tables['logs'], array( 'user_id' => 0 ), array( 'user_id' => (int) $user_id ), array( '%d' ), array( '%d' ) );
	}
}
