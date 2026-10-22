<?php
/**
 * Initialization of the literature data, and moving earlier versions' data into it: the JSON data folder of
 * versions 2 to 6, or the database tables and options of version 1.0.
 *
 * @package BMLT_Lit
 * @version 7.0.0
 */

namespace BMLT_Lit;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the tables and copies earlier versions' data into them, in one transaction. Never modifies the earlier
 * data: the version 6 folder and the version 1.0 tables are only removed on request.
 */
class Migrator {

	/**
	 * Legacy table short names.
	 */
	const LEGACY_TABLES = array( 'items', 'inventory', 'orders', 'order_items', 'order_notes', 'audit' );

	/**
	 * Legacy option names.
	 */
	const LEGACY_OPTIONS = array( 'bmlt_lit_settings', 'bmlt_lit_bmlt', 'bmlt_lit_areas', 'bmlt_lit_smtp', 'bmlt_lit_templates', 'bmlt_lit_db_version', 'bmlt_lit_version', 'bmlt_lit_cache_service_bodies', 'bmlt_lit_cache_meetings', 'bmlt_lit_bmlt_status' );

	/**
	 * Legacy table name.
	 *
	 * @param string $t Short name.
	 * @return string
	 */
	private static function table( $t ) {
		global $wpdb;
		return $wpdb->prefix . 'bmlt_lit_' . $t;
	}

	/**
	 * Whether a legacy table exists.
	 *
	 * @param string $t Short name.
	 * @return bool
	 */
	private static function table_exists( $t ) {
		global $wpdb;
		$name = self::table( $t );
		return $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
	}

	/**
	 * Whether v1.0 data exists in the database.
	 *
	 * @return bool
	 */
	public static function legacy_present() {
		return self::table_exists( 'items' ) || self::table_exists( 'orders' ) || false !== get_option( 'bmlt_lit_settings', false );
	}

	/**
	 * Create the tables and initialize the data on first run (moving earlier versions' data in when present).
	 * Runs on init and activation; cheap once done.
	 */
	public static function maybe_initialize() {
		$ready = Db::maybe_install();
		if ( is_wp_error( $ready ) ) {
			set_transient( 'bmlt_lit_init_error', $ready->get_error_message(), HOUR_IN_SECONDS );
			return;
		}
		if ( Settings::initialized() || get_transient( 'bmlt_lit_init_attempt' ) ) {
			return; // Done, or another request is at it (or failed a moment ago: not on every request).
		}
		set_transient( 'bmlt_lit_init_attempt', 1, MINUTE_IN_SECONDS );
		if ( Legacy_Files::present() ) {
			$result = self::migrate_files();
		} elseif ( self::legacy_present() ) {
			$result = self::migrate();
		} else {
			$result = Db::tx(
				static function () {
					if ( ! Settings::initialized() ) {
						Settings::save_meta( array() );
					}
					return true;
				}
			);
		}
		if ( is_wp_error( $result ) && 'bmlt_lit_initialized' !== $result->get_error_code() ) {
			Helpers::log( 'The literature data could not be initialized.', array( 'error' => $result->get_error_message() ) );
			set_transient( 'bmlt_lit_init_error', $result->get_error_message(), DAY_IN_SECONDS );
			return;
		}
		delete_transient( 'bmlt_lit_init_error' );
		delete_transient( 'bmlt_lit_init_attempt' );
	}

	/**
	 * Why the data could not be initialized (shown to administrators), or ''.
	 *
	 * @return string
	 */
	public static function init_error() {
		return (string) get_transient( 'bmlt_lit_init_error' );
	}

	/**
	 * Move the JSON data folder of version 6 (or earlier) into the database. The files are left untouched.
	 *
	 * @return array|\WP_Error Counts { items, inventory, orders, audit, users }.
	 */
	public static function migrate_files() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		try {
			$raw = Legacy_Files::documents();
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'bmlt_lit_files', $e->getMessage() );
		}
		try {
			$cache = Legacy_Files::read( 'cache/bmlt' ); // The last BMLT answers, so ordering works even if BMLT is down now.
		} catch ( \RuntimeException $e ) {
			$cache = null; // A cache: BMLT is simply asked again.
		}
		$valid = Import_Export::validate_backup(
			array(
				'format'    => Import_Export::BACKUP_FORMAT,
				'version'   => Import_Export::BACKUP_VERSION,
				'documents' => $raw,
			)
		);
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$old  = isset( $raw[ Settings::DOC ]['meta'] ) && is_array( $raw[ Settings::DOC ]['meta'] ) ? $raw[ Settings::DOC ]['meta'] : array();
		$meta = array();
		foreach ( array( 'created', 'migrated_from_db', 'adopted', 'restored_at' ) as $k ) {
			if ( isset( $old[ $k ] ) && is_numeric( $old[ $k ] ) ) {
				$meta[ $k ] = (int) $old[ $k ];
			}
		}
		foreach ( array( 'site_url', 'restored_from' ) as $k ) {
			if ( isset( $old[ $k ] ) && is_string( $old[ $k ] ) ) {
				$meta[ $k ] = esc_url_raw( $old[ $k ] );
			}
		}
		if ( isset( $old['store_id'] ) && is_string( $old['store_id'] ) ) {
			$meta['store_id'] = preg_replace( '/[^A-Za-z0-9]/', '', $old['store_id'] );
		}
		$meta['schema']              = Settings::SCHEMA;
		$meta['migrated_from_files'] = time();
		$meta['files_dir']           = Legacy_Files::dir();
		$counts                      = $valid['counts'];

		return Db::tx(
			static function () use ( $valid, $meta, $counts, $cache ) {
				if ( Settings::initialized() ) {
					return new \WP_Error( 'bmlt_lit_initialized', 'Already initialized.' );
				}
				Import_Export::write_documents( $valid['documents'] );
				foreach ( Bmlt_Client::cache_sections() as $section ) {
					if ( isset( $cache[ $section ] ) && is_array( $cache[ $section ] ) ) {
						Db::set_option( Bmlt_Client::cache_option( $section ), $cache[ $section ], false );
					}
				}
				Settings::save_meta( $meta );
				Audit::log( 'settings_changed', 'import', 0, null, array( 'moved_to_database' => $counts ) );
				return $counts;
			}
		);
	}

	/**
	 * Delete the version 6 data folder (after its data was moved into the database).
	 *
	 * @return true|\WP_Error
	 */
	public static function remove_files() {
		if ( empty( Settings::meta()['migrated_from_files'] ) ) {
			return new \WP_Error( 'bmlt_lit_not_migrated', __( 'The old data folder can only be removed after its data was moved into the database.', 'bmlt-literature-manager' ) );
		}
		if ( ! Legacy_Files::remove() ) {
			/* translators: %s: folder. */
			return new \WP_Error( 'bmlt_lit_remove_files', sprintf( __( 'The old data folder could not be removed completely: %s. Remove it with your hosting file manager.', 'bmlt-literature-manager' ), Legacy_Files::dir() ) );
		}
		return true;
	}

	/**
	 * Copy all v1.0 database data into the plugin's tables, replacing what they hold (one transaction).
	 *
	 * @return array|\WP_Error Summary counts.
	 */
	public static function migrate() {
		global $wpdb;
		if ( ! self::legacy_present() ) {
			return new \WP_Error( 'bmlt_lit_no_legacy', __( 'No version 1.0 database data was found.', 'bmlt-literature-manager' ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$summary = array(
			'items'     => 0,
			'inventory' => 0,
			'orders'    => 0,
			'audit'     => 0,
			'users'     => 0,
		);

		// Settings.
		$raw = array(
			'general'   => get_option( 'bmlt_lit_settings', array() ),
			'bmlt'      => get_option( 'bmlt_lit_bmlt', array() ),
			'areas'     => get_option( 'bmlt_lit_areas', array() ),
			'smtp'      => get_option( 'bmlt_lit_smtp', array() ),
			'templates' => get_option( 'bmlt_lit_templates', array() ),
		);
		foreach ( $raw as $k => $v ) {
			$raw[ $k ] = is_array( $v ) ? $v : array();
		}
		$settings = Schema::settings( $raw );

		// Catalog.
		$catalog = array(
			'next_id' => 1,
			'items'   => array(),
		);
		if ( self::table_exists( 'items' ) ) {
			$t = self::table( 'items' );
			foreach ( (array) $wpdb->get_results( "SELECT * FROM {$t} ORDER BY id ASC", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$item = Schema::item( $row );
				if ( $item ) {
					$catalog['items'][ $item['id'] ] = $item;
					$catalog['next_id']              = max( $catalog['next_id'], $item['id'] + 1 );
					++$summary['items'];
				}
			}
		}

		// Inventory.
		$inventory = array( 'areas' => array() );
		if ( self::table_exists( 'inventory' ) ) {
			$t = self::table( 'inventory' );
			foreach ( (array) $wpdb->get_results( "SELECT * FROM {$t}", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$area = absint( $row['area_id'] );
				$item = absint( $row['item_id'] );
				if ( ! $area || ! isset( $catalog['items'][ $item ] ) ) {
					continue;
				}
				$inventory['areas'][ $area ][ $item ] = Schema::inventory_row(
					array(
						'on_hand'    => $row['quantity_on_hand'],
						'reserved'   => $row['quantity_reserved'],
						'low'        => $row['low_stock_threshold'],
						'reorder'    => $row['reorder_quantity'],
						'notes'      => $row['notes'],
						'updated_at' => $row['updated_at'],
						'updated_by' => $row['updated_by'],
					)
				);
				++$summary['inventory'];
			}
		}

		// Orders (batched), with items and history.
		$months = array();
		if ( self::table_exists( 'orders' ) ) {
			$ot     = self::table( 'orders' );
			$it     = self::table( 'order_items' );
			$nt     = self::table( 'order_notes' );
			$has_it = self::table_exists( 'order_items' );
			$has_nt = self::table_exists( 'order_notes' );
			$offset = 0;
			do {
				$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$ot} ORDER BY id ASC LIMIT %d OFFSET %d", 500, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$offset += 500;
				if ( empty( $rows ) ) {
					break;
				}
				$ids   = array_map( 'absint', wp_list_pluck( $rows, 'id' ) );
				$ph    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$items = array();
				$notes = array();
				if ( $has_it ) {
					foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$it} WHERE order_id IN ({$ph}) ORDER BY id ASC", $ids ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $ph is a list of %d placeholders.
						$items[ (int) $r['order_id'] ][] = $r;
					}
				}
				if ( $has_nt ) {
					foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$nt} WHERE order_id IN ({$ph}) ORDER BY id ASC", $ids ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $ph is a list of %d placeholders.
						$notes[ (int) $r['order_id'] ][] = $r;
					}
				}
				foreach ( $rows as $row ) {
					$row['items']     = isset( $items[ (int) $row['id'] ] ) ? $items[ (int) $row['id'] ] : array();
					$row['notes_log'] = isset( $notes[ (int) $row['id'] ] ) ? $notes[ (int) $row['id'] ] : array();
					$rec              = Schema::order( $row );
					if ( ! $rec ) {
						continue;
					}
					$months[ Orders::month_doc( $rec['created_at'] ) ]['orders'][ $rec['id'] ] = $rec;
					++$summary['orders'];
				}
			} while ( count( $rows ) === 500 );
		}

		// Audit.
		$audit = array();
		if ( self::table_exists( 'audit' ) ) {
			$t      = self::table( 'audit' );
			$offset = 0;
			do {
				$rows    = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} ORDER BY id ASC LIMIT %d OFFSET %d", 2000, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$offset += 2000;
				foreach ( $rows as $row ) {
					$e = Schema::audit( $row );
					if ( $e ) {
						$audit[ 'audit/' . substr( $e['created_at'], 0, 7 ) ]['entries'][] = $e;
						++$summary['audit'];
					}
				}
			} while ( count( $rows ) === 2000 );
		}

		// Permissions: v1.0 stored direct user capabilities and Area restrictions in user meta.
		$grants = array( 'users' => array() );
		$caps   = Permissions::caps();
		$users  = get_users(
			array(
				'meta_key' => Permissions::AREA_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'fields'   => 'all',
			)
		);
		foreach ( get_users( array( 'role__not_in' => array( 'administrator' ) ) ) as $u ) {
			if ( array_intersect( array_keys( array_filter( (array) $u->caps ) ), $caps ) ) {
				$users[] = $u;
			}
		}
		foreach ( $users as $u ) {
			$direct = array_values( array_intersect( $caps, array_keys( array_filter( (array) $u->caps ) ) ) );
			$areas  = array_values( array_filter( array_map( 'absint', (array) get_user_meta( $u->ID, Permissions::AREA_META, true ) ) ) );
			if ( $direct || $areas ) {
				$grants['users'][ $u->ID ] = array(
					'caps'  => $direct,
					'areas' => $areas,
				);
			}
		}
		$summary['users'] = count( $grants['users'] );

		$docs = array(
			Settings::DOC    => $settings,
			Catalog::DOC     => $catalog,
			Inventory::DOC   => $inventory,
			Permissions::DOC => $grants,
		) + $months + $audit;

		$written = Db::tx(
			static function () use ( $docs, $summary ) {
				Import_Export::write_documents( $docs );
				$meta                     = Settings::complete_meta( Settings::meta() );
				$meta['migrated_from_db'] = time();
				Settings::save_meta( $meta );
				Audit::log( 'settings_changed', 'import', 0, null, array( 'migrated_from_database' => $summary ) );
				return true;
			}
		);
		if ( is_wp_error( $written ) ) {
			return $written;
		}
		Settings::flush();
		delete_transient( 'bmlt_lit_init_attempt' );
		return $summary;
	}

	/**
	 * Whether legacy tables or options still exist.
	 *
	 * @return bool
	 */
	public static function legacy_leftovers() {
		foreach ( self::LEGACY_TABLES as $t ) {
			if ( self::table_exists( $t ) ) {
				return true;
			}
		}
		return false !== get_option( 'bmlt_lit_settings', false );
	}

	/**
	 * Remove v1.0 tables and options (only after a successful migration).
	 *
	 * @return true|\WP_Error
	 */
	public static function drop_legacy() {
		global $wpdb;
		$meta = Settings::meta();
		if ( empty( $meta['migrated_from_db'] ) ) {
			return new \WP_Error( 'bmlt_lit_not_migrated', __( 'Legacy data can only be removed after it has been migrated.', 'bmlt-literature-manager' ) );
		}
		foreach ( self::LEGACY_TABLES as $t ) {
			$name = self::table( $t );
			$wpdb->query( "DROP TABLE IF EXISTS `{$name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
		foreach ( self::LEGACY_OPTIONS as $o ) {
			delete_option( $o );
		}
		delete_transient( 'bmlt_lit_bmlt_fresh' );
		return true;
	}
}
