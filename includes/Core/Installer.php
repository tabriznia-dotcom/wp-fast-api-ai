<?php
/**
 * Activation, upgrade and deactivation routines.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Creates plugin tables, capabilities and scheduled events.
 */
final class Installer {

	const CRON_CLEANUP = 'aipd_daily_cleanup';
	const CRON_RUN_JOB = 'aipd_run_job';

	/**
	 * Activation hook. Network activation installs lazily per site (see maybe_upgrade()).
	 *
	 * @param bool $network_wide Whether the plugin is being network activated.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			// Sites are installed on first admin visit to avoid long loops on big networks.
			return;
		}
		self::install();
	}

	/**
	 * Deactivation hook: stop scheduled work. Data is kept until uninstall.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_CLEANUP );
		wp_unschedule_hook( self::CRON_RUN_JOB );
	}

	/**
	 * Runs the installer when the stored schema version is outdated.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( Options::DB_VER ) !== AIPD_DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Installs tables, capabilities, default options and cron for the current site.
	 *
	 * @return void
	 */
	public static function install() {
		self::create_tables();
		Capabilities::add_to_roles();

		if ( false === get_option( Options::SETTINGS ) ) {
			add_option( Options::SETTINGS, Options::default_settings(), '', true );
		}

		if ( ! wp_next_scheduled( self::CRON_CLEANUP ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_CLEANUP );
		}

		update_option( Options::DB_VER, AIPD_DB_VERSION, true );
	}

	/**
	 * Table names for the current site.
	 *
	 * @return array{jobs:string,logs:string}
	 */
	public static function tables() {
		global $wpdb;
		return array(
			'jobs' => $wpdb->prefix . 'aipd_jobs',
			'logs' => $wpdb->prefix . 'aipd_logs',
		);
	}

	/**
	 * Creates or updates the plugin tables with dbDelta.
	 *
	 * @return void
	 */
	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$tables  = self::tables();

		$jobs = "CREATE TABLE {$tables['jobs']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			uuid varchar(36) NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			type varchar(20) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'queued',
			provider varchar(64) NOT NULL DEFAULT '',
			model varchar(100) NOT NULL DEFAULT '',
			builder varchar(40) NOT NULL DEFAULT '',
			title varchar(200) NOT NULL DEFAULT '',
			input longtext NULL,
			result longtext NULL,
			error_code varchar(64) NOT NULL DEFAULT '',
			error_message text NULL,
			tokens_in int(10) unsigned NOT NULL DEFAULT 0,
			tokens_out int(10) unsigned NOT NULL DEFAULT 0,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			KEY user_status (user_id,status),
			KEY created_at (created_at)
		) $charset;";

		$logs = "CREATE TABLE {$tables['logs']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			level varchar(10) NOT NULL,
			event varchar(64) NOT NULL,
			message text NOT NULL,
			context longtext NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY level (level),
			KEY user_id (user_id),
			KEY created_at (created_at)
		) $charset;";

		dbDelta( $jobs );
		dbDelta( $logs );
	}
}
