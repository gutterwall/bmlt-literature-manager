<?php
/**
 * Activation and deactivation.
 *
 * @package BMLT_Lit
 * @version 6.2.0
 */

namespace BMLT_Lit;

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin activation and deactivation.
 */
class Activator {

	/**
	 * Activation callback.
	 *
	 * @param bool $network_wide Whether network-activated on multisite.
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$sites = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $sites as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::activate_site();
				restore_current_blog();
			}
			return;
		}
		self::activate_site();
	}

	/**
	 * Activate for the current site.
	 */
	private static function activate_site() {
		Permissions::install_roles();
		delete_transient( 'bmlt_lit_init_attempt' );
		// Creates the JSON data directory, and migrates v1.0 database data when present.
		Migrator::maybe_initialize();
		Bmlt_Client::schedule_cron( true );
	}

	/**
	 * Deactivation callback. Never deletes data.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( Bmlt_Client::CRON_HOOK );
	}
}
