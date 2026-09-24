<?php
/**
 * Custom capabilities and their multisite mapping.
 *
 * @package AIPageDesigner
 */

namespace AIPageDesigner\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Capability definitions.
 *
 * - aipd_manage_settings: change providers, API keys, privacy, logs, import/export.
 * - aipd_generate_pages: use the wizard and send briefs to the configured provider.
 *
 * Creating a draft additionally requires the post type's own edit capability,
 * and publishing always stays with WordPress core capabilities.
 */
final class Capabilities {

	const MANAGE   = 'aipd_manage_settings';
	const GENERATE = 'aipd_generate_pages';

	/**
	 * Hooks capability mapping.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'map_meta_cap', array( __CLASS__, 'map_meta_cap' ), 10, 2 );
	}

	/**
	 * Grants the plugin capabilities to the administrator role.
	 *
	 * @return void
	 */
	public static function add_to_roles() {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( self::MANAGE );
			$admin->add_cap( self::GENERATE );
		}

		/**
		 * Filters extra roles that receive the page generation capability on activation.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $roles Role names. Default empty: only administrators.
		 */
		$roles = (array) apply_filters( 'aipd_generator_roles', array() );
		foreach ( $roles as $role_name ) {
			$role = get_role( sanitize_key( $role_name ) );
			if ( $role ) {
				$role->add_cap( self::GENERATE );
			}
		}
	}

	/**
	 * Removes plugin capabilities from every role. Only touches our own caps.
	 *
	 * @return void
	 */
	public static function remove_from_roles() {
		$wp_roles = wp_roles();
		foreach ( array_keys( $wp_roles->roles ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->remove_cap( self::MANAGE );
				$role->remove_cap( self::GENERATE );
			}
		}
	}

	/**
	 * On multisite, settings management can be restricted to super admins.
	 *
	 * Super admins always pass capability checks. Site administrators keep the
	 * settings capability unless the network restricts it through the
	 * `aipd_site_admins_can_manage_settings` filter or the
	 * AIPD_NETWORK_MANAGED_SETTINGS constant.
	 *
	 * @param string[] $caps Primitive caps required.
	 * @param string   $cap  Requested capability.
	 * @return string[]
	 */
	public static function map_meta_cap( $caps, $cap ) {
		if ( self::MANAGE !== $cap || ! is_multisite() ) {
			return $caps;
		}

		$site_admins_allowed = ! ( defined( 'AIPD_NETWORK_MANAGED_SETTINGS' ) && AIPD_NETWORK_MANAGED_SETTINGS );

		/**
		 * Filters whether site administrators may manage AI settings on multisite.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $allowed Default true unless AIPD_NETWORK_MANAGED_SETTINGS is set.
		 */
		if ( ! apply_filters( 'aipd_site_admins_can_manage_settings', $site_admins_allowed ) ) {
			return array( 'manage_network_options' );
		}

		return $caps;
	}

	/**
	 * Whether the current user may manage settings.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( self::MANAGE );
	}

	/**
	 * Whether the current user may generate pages.
	 *
	 * @return bool
	 */
	public static function can_generate() {
		return current_user_can( self::GENERATE ) && current_user_can( 'edit_pages' );
	}
}
