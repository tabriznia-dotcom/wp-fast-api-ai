<?php
/**
 * Per-user request limiter.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\AI;

use AIPageDesigner\Core\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Fixed-window counter stored in a transient (object cache when available).
 */
final class RateLimiter {

	/**
	 * Transient key for a user and the current hour.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	private static function key( $user_id ) {
		return 'aipd_rl_' . absint( $user_id ) . '_' . gmdate( 'YmdH' );
	}

	/**
	 * Hourly limit.
	 *
	 * @return int
	 */
	public static function limit() {
		/**
		 * Filters the number of AI requests a user can make per hour.
		 *
		 * @since 1.0.0
		 *
		 * @param int $limit Limit from settings.
		 */
		return max( 1, (int) apply_filters( 'aipd_rate_limit_per_hour', (int) Options::get( 'rate_limit_per_hour' ) ) );
	}

	/**
	 * Remaining requests for a user in the current window.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	public static function remaining( $user_id ) {
		return max( 0, self::limit() - (int) get_transient( self::key( $user_id ) ) );
	}

	/**
	 * Consumes one request if available.
	 *
	 * @param int $user_id User id.
	 * @return bool False when the limit is reached.
	 */
	public static function hit( $user_id ) {
		$key   = self::key( $user_id );
		$count = (int) get_transient( $key );
		if ( $count >= self::limit() ) {
			return false;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}
}
