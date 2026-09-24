<?php
/**
 * Uninstall routine.
 *
 * Removes only data owned by this plugin, and only when the administrator
 * enabled "Delete all plugin data" on the Privacy screen. Capabilities and
 * scheduled events are always removed. Pages created with the plugin are
 * user content and are never deleted.
 *
 * @package AIPageDesigner
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Cleans one site.
 *
 * @return void
 */
function aipd_uninstall_site() {
	global $wpdb;

	// Always: remove our capabilities and cron events.
	foreach ( array_keys( wp_roles()->roles ) as $aipd_role_name ) {
		$aipd_role = get_role( $aipd_role_name );
		if ( $aipd_role ) {
			$aipd_role->remove_cap( 'aipd_manage_settings' );
			$aipd_role->remove_cap( 'aipd_generate_pages' );
		}
	}
	wp_clear_scheduled_hook( 'aipd_daily_cleanup' );
	wp_unschedule_hook( 'aipd_run_job' );

	$settings = get_option( 'aipd_settings', array() );
	if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) ) {
		return;
	}

	// Plugin tables.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dropping our own tables on uninstall.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aipd_jobs" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aipd_logs" );
	// phpcs:enable

	// Options.
	foreach ( array( 'aipd_settings', 'aipd_provider_settings', 'aipd_brand_kit', 'aipd_db_version' ) as $aipd_option ) {
		delete_option( $aipd_option );
	}

	// Rate-limit transients (prefix aipd_rl_).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Removing our own transients.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_aipd_rl_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_aipd_rl_' ) . '%'
		)
	);

	// Saved templates (private post type owned by this plugin).
	$aipd_templates = get_posts(
		array(
			'post_type'      => 'aipd_template',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);
	foreach ( $aipd_templates as $aipd_template_id ) {
		wp_delete_post( $aipd_template_id, true );
	}

	// Our post meta on generated pages. The pages themselves are kept.
	foreach ( array( '_aipd_schema', '_aipd_builder', '_aipd_generated', '_aipd_css' ) as $aipd_meta_key ) {
		delete_post_meta_by_key( $aipd_meta_key );
	}
}

if ( is_multisite() ) {
	$aipd_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $aipd_site_ids as $aipd_site_id ) {
		switch_to_blog( $aipd_site_id );
		aipd_uninstall_site();
		restore_current_blog();
	}
} else {
	aipd_uninstall_site();
}
