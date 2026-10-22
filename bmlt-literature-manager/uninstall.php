<?php
/**
 * Uninstall routine.
 *
 * Data is removed ONLY when "Delete plugin data when uninstalling" was explicitly enabled
 * (Literature → Settings → Advanced). Otherwise the JSON data directory is left untouched.
 *
 * @package BMLT_Lit
 * @version 6.2.0
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-helpers.php';
require_once __DIR__ . '/includes/class-store.php';

/**
 * Remove plugin data for the current site if the administrator opted in.
 */
function bmlt_lit_uninstall_site() {
	global $wpdb;

	wp_clear_scheduled_hook( 'bmlt_lit_cron_refresh' );

	$settings = \BMLT_Lit\Store::get( 'settings' );
	$delete   = ! empty( $settings['general']['delete_data_on_uninstall'] );
	if ( ! $delete ) {
		// v1.0 installs that never migrated kept the flag in wp_options.
		$legacy = get_option( 'bmlt_lit_settings', array() );
		$delete = is_array( $legacy ) && ! empty( $legacy['delete_data_on_uninstall'] );
	}
	if ( ! $delete ) {
		return;
	}

	// JSON data directory (only if it really is ours: it must contain settings or a catalog).
	$dir = \BMLT_Lit\Store::dir();
	if ( '' !== $dir && is_dir( $dir ) && ( \BMLT_Lit\Store::exists( 'settings' ) || \BMLT_Lit\Store::exists( 'catalog' ) ) ) {
		\BMLT_Lit\Store::rrmdir( $dir );
	}

	// Legacy v1.0 tables and options.
	foreach ( array( 'items', 'inventory', 'orders', 'order_items', 'order_notes', 'audit' ) as $t ) {
		$name = $wpdb->prefix . 'bmlt_lit_' . $t;
		$wpdb->query( "DROP TABLE IF EXISTS `{$name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
	}
	foreach ( array( 'bmlt_lit_settings', 'bmlt_lit_bmlt', 'bmlt_lit_areas', 'bmlt_lit_smtp', 'bmlt_lit_templates', 'bmlt_lit_db_version', 'bmlt_lit_version', 'bmlt_lit_cache_service_bodies', 'bmlt_lit_cache_meetings', 'bmlt_lit_bmlt_status' ) as $o ) {
		delete_option( $o );
	}
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_bmlt\_lit\_%' OR option_name LIKE '\_transient\_timeout\_bmlt\_lit\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	$caps = array( 'bmlt_lit_manage_inventory', 'bmlt_lit_manage_catalog', 'bmlt_lit_view_orders', 'bmlt_lit_manage_orders', 'bmlt_lit_manage_settings' );
	remove_role( 'bmlt_lit_worker' );
	remove_role( 'bmlt_lit_chair' );
	foreach ( wp_roles()->role_objects as $role ) {
		foreach ( $caps as $cap ) {
			$role->remove_cap( $cap );
		}
	}
	delete_metadata( 'user', 0, 'bmlt_lit_restrict_areas', '', true );
	delete_metadata( 'user', 0, 'bmlt_lit_orders_per_page', '', true );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $bmlt_lit_site ) {
		switch_to_blog( (int) $bmlt_lit_site );
		\BMLT_Lit\Store::flush_cache();
		bmlt_lit_uninstall_site();
		restore_current_blog();
	}
} else {
	bmlt_lit_uninstall_site();
}
