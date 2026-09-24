<?php
/**
 * Generation jobs and history storage.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Jobs;

use AIPageDesigner\Core\Installer;
use AIPageDesigner\Core\Options;

defined( 'ABSPATH' ) || exit;

/**
 * All queries use $wpdb->prepare() or $wpdb->insert()/update() with formats.
 */
final class JobRepository {

	const STATUSES = array( 'queued', 'running', 'completed', 'failed' );
	const TYPES    = array( 'outline', 'page', 'section' );

	/**
	 * Minutes after which a running job is considered interrupted.
	 */
	const STALE_MINUTES = 10;

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private static function table() {
		$tables = Installer::tables();
		return $tables['jobs'];
	}

	/**
	 * Creates a job, or returns the existing one for the same idempotency key.
	 *
	 * @param string $uuid    Client idempotency key (UUID v4).
	 * @param int    $user_id User id.
	 * @param string $type    Job type.
	 * @param array  $input   Input payload (no secrets).
	 * @param array  $meta    provider, model, builder, title.
	 * @return array|null Job row, or null on failure or key reuse by another user.
	 */
	public static function create( $uuid, $user_id, $type, array $input, array $meta = array() ) {
		global $wpdb;

		$existing = self::get( $uuid );
		if ( $existing ) {
			return (int) $existing['user_id'] === (int) $user_id ? $existing : null;
		}

		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table write.
		$ok = $wpdb->insert(
			self::table(),
			array(
				'uuid'       => $uuid,
				'user_id'    => (int) $user_id,
				'type'       => $type,
				'status'     => 'queued',
				'provider'   => isset( $meta['provider'] ) ? substr( sanitize_key( $meta['provider'] ), 0, 64 ) : '',
				'model'      => isset( $meta['model'] ) ? substr( sanitize_text_field( $meta['model'] ), 0, 100 ) : '',
				'builder'    => isset( $meta['builder'] ) ? substr( sanitize_key( $meta['builder'] ), 0, 40 ) : '',
				'title'      => isset( $meta['title'] ) ? substr( sanitize_text_field( $meta['title'] ), 0, 200 ) : '',
				'input'      => wp_json_encode( $input ),
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return $ok ? self::get( $uuid ) : null;
	}

	/**
	 * Fetches a job by uuid.
	 *
	 * @param string $uuid UUID.
	 * @return array|null
	 */
	public static function get( $uuid ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; name is not user input.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE uuid = %s", $uuid ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Atomically moves a queued job to running. Only one caller can win.
	 *
	 * @param string $uuid UUID.
	 * @return bool
	 */
	public static function claim( $uuid ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'running', attempts = attempts + 1, updated_at = %s WHERE uuid = %s AND status = 'queued'", current_time( 'mysql', true ), $uuid ) );
		return 1 === (int) $updated;
	}

	/**
	 * Marks a job completed.
	 *
	 * @param string $uuid   UUID.
	 * @param array  $result Result payload.
	 * @param array  $usage  model, tokens_in, tokens_out.
	 * @return void
	 */
	public static function complete( $uuid, array $result, array $usage = array() ) {
		global $wpdb;
		$data   = array(
			'status'     => 'completed',
			'result'     => wp_json_encode( $result ),
			'tokens_in'  => isset( $usage['tokens_in'] ) ? (int) $usage['tokens_in'] : 0,
			'tokens_out' => isset( $usage['tokens_out'] ) ? (int) $usage['tokens_out'] : 0,
			'updated_at' => current_time( 'mysql', true ),
		);
		$format = array( '%s', '%s', '%d', '%d', '%s' );
		if ( ! empty( $usage['model'] ) ) {
			$data['model'] = substr( sanitize_text_field( $usage['model'] ), 0, 100 );
			$format[]      = '%s';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->update( self::table(), $data, array( 'uuid' => $uuid ), $format, array( '%s' ) );
	}

	/**
	 * Marks a job failed with a user-safe message.
	 *
	 * @param string $uuid    UUID.
	 * @param string $code    Error code.
	 * @param string $message User-safe message.
	 * @return void
	 */
	public static function fail( $uuid, $code, $message ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->update(
			self::table(),
			array(
				'status'        => 'failed',
				'error_code'    => substr( sanitize_key( $code ), 0, 64 ),
				'error_message' => sanitize_text_field( $message ),
				'updated_at'    => current_time( 'mysql', true ),
			),
			array( 'uuid' => $uuid ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Links a created draft to a job.
	 *
	 * @param string $uuid    UUID.
	 * @param int    $post_id Post id.
	 * @return void
	 */
	public static function set_post( $uuid, $post_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->update( self::table(), array( 'post_id' => (int) $post_id ), array( 'uuid' => $uuid ), array( '%d' ), array( '%s' ) );
	}

	/**
	 * Removes stored brief and result (used when history is disabled).
	 *
	 * @param string $uuid UUID.
	 * @return void
	 */
	public static function strip_payload( $uuid ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET input = NULL, result = NULL, title = '' WHERE uuid = %s AND status IN ('completed','failed')", $uuid ) );
	}

	/**
	 * Marks jobs stuck in "running" as interrupted.
	 *
	 * @return int Rows updated.
	 */
	public static function fail_stale() {
		global $wpdb;
		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::STALE_MINUTES * MINUTE_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'failed', error_code = 'aipd_interrupted', error_message = %s, updated_at = %s WHERE status = 'running' AND updated_at < %s", __( 'The generation was interrupted before it finished. Please try again.', 'ai-page-designer' ), current_time( 'mysql', true ), $cutoff ) );
	}

	/**
	 * Paginated list.
	 *
	 * @param int $user_id  User id, or 0 for all users.
	 * @param int $page     Page (1-based).
	 * @param int $per_page Per page.
	 * @return array{items:array,total:int}
	 */
	public static function query( $user_id, $page = 1, $per_page = 20 ) {
		global $wpdb;
		$table    = self::table();
		$per_page = max( 1, min( 100, (int) $per_page ) );
		$offset   = ( max( 1, (int) $page ) - 1 ) * $per_page;
		$columns  = 'id, uuid, user_id, type, status, provider, model, builder, title, error_code, error_message, tokens_in, tokens_out, post_id, created_at, updated_at';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; column list is a constant.
		if ( $user_id > 0 ) {
			$items = $wpdb->get_results( $wpdb->prepare( "SELECT {$columns} FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d", $user_id, $per_page, $offset ), ARRAY_A );
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id ) );
		} else {
			$items = $wpdb->get_results( $wpdb->prepare( "SELECT {$columns} FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A );
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}
		// phpcs:enable

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Deletes a job.
	 *
	 * @param string $uuid UUID.
	 * @return void
	 */
	public static function delete( $uuid ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->delete( self::table(), array( 'uuid' => $uuid ), array( '%s' ) );
	}

	/**
	 * Applies the retention policy.
	 *
	 * @return int Rows deleted.
	 */
	public static function purge() {
		global $wpdb;
		$table = self::table();
		$days  = Options::get( 'history_enabled' ) ? (int) Options::get( 'retention_days' ) : 1;
		$cut   = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cut ) );
	}

	/**
	 * Jobs for a user (privacy export).
	 *
	 * @param int $user_id User id.
	 * @param int $limit   Limit.
	 * @param int $offset  Offset.
	 * @return array
	 */
	public static function for_user( $user_id, $limit, $offset ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT uuid, type, status, title, input, created_at FROM {$table} WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d", $user_id, $limit, $offset ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Deletes all jobs of a user (privacy erasure).
	 *
	 * @param int $user_id User id.
	 * @return int Rows deleted.
	 */
	public static function delete_for_user( $user_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		return (int) $wpdb->delete( self::table(), array( 'user_id' => (int) $user_id ), array( '%d' ) );
	}

	/**
	 * Public representation of a job for REST responses.
	 *
	 * @param array $row          Row.
	 * @param bool  $with_payload Include decoded result.
	 * @return array
	 */
	public static function to_public( array $row, $with_payload = true ) {
		$out = array(
			'id'         => $row['uuid'],
			'type'       => $row['type'],
			'status'     => $row['status'],
			'provider'   => $row['provider'],
			'model'      => $row['model'],
			'builder'    => $row['builder'],
			'title'      => $row['title'],
			'error'      => '' !== $row['error_code'] ? array(
				'code'    => $row['error_code'],
				'message' => (string) $row['error_message'],
			) : null,
			'tokens_in'  => (int) $row['tokens_in'],
			'tokens_out' => (int) $row['tokens_out'],
			'post_id'    => (int) $row['post_id'],
			'created_at' => mysql_to_rfc3339( $row['created_at'] ),
			'updated_at' => mysql_to_rfc3339( $row['updated_at'] ),
		);
		if ( $with_payload && isset( $row['result'] ) && 'completed' === $row['status'] ) {
			$decoded       = json_decode( (string) $row['result'], true );
			$out['result'] = is_array( $decoded ) ? $decoded : null;
		}
		return $out;
	}
}
