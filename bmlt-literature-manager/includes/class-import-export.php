<?php
/**
 * JSON import / export.
 *
 * @package BMLT_Lit
 * @version 7.0.0
 */

namespace BMLT_Lit;

defined( 'ABSPATH' ) || exit;

/**
 * Catalog and inventory JSON exchange. Uses json_decode() into arrays only — no object deserialization.
 */
class Import_Export {

	const CATALOG_FORMAT   = 'bmlt-literature-manager';
	const INVENTORY_FORMAT = 'bmlt-literature-manager-inventory';
	const BACKUP_FORMAT    = 'bmlt-literature-manager-backup';
	const BACKUP_VERSION   = 2;
	const MAX_BACKUP_BYTES = 52428800;
	const VERSION          = 1;
	const MAX_BYTES        = 2097152;
	const MAX_ITEMS        = 5000;

	/**
	 * Build catalog export data.
	 *
	 * @param int|null $area_id null = all catalogs, 0 = shared only, N = that Area's own items.
	 * @return array
	 */
	public static function export_catalog( $area_id = null ) {
		$args = array(
			'orderby' => 'sort_order',
			'order'   => 'ASC',
		);
		if ( null !== $area_id ) {
			$args['area_id']        = absint( $area_id );
			$args['include_shared'] = false;
		}
		$r     = Catalog::query( $args );
		$items = array();
		foreach ( $r['items'] as $it ) {
			$items[] = array(
				'sku'               => (string) $it->sku,
				'name'              => (string) $it->name,
				'short_description' => (string) $it->short_description,
				'long_description'  => (string) $it->long_description,
				'category'          => (string) $it->category,
				'price'             => Helpers::sql_money( $it->price ),
				'active'            => (bool) (int) $it->is_active,
				'sort_order'        => (int) $it->sort_order,
				'image_url'         => (string) $it->image_url,
				'reference_url'     => isset( $it->reference_url ) ? (string) $it->reference_url : '',
				'naws_id'           => (string) $it->naws_id,
				'catalog_area_id'   => (int) $it->area_id,
			);
		}
		return array(
			'format'      => self::CATALOG_FORMAT,
			'version'     => self::VERSION,
			'exported_at' => gmdate( 'c' ),
			'site'        => home_url( '/' ),
			'catalog'     => null === $area_id ? 'all' : ( $area_id ? 'area:' . absint( $area_id ) : 'shared' ),
			'items'       => $items,
		);
	}

	/**
	 * Build inventory export data for an Area.
	 *
	 * @param int $area_id Area.
	 * @return array
	 */
	public static function export_inventory( $area_id ) {
		$rows = array();
		foreach ( Inventory::area_inventory( $area_id ) as $r ) {
			$rows[] = array(
				'sku'                 => $r['sku'],
				'name'                => $r['name'],
				'catalog_area_id'     => $r['item_area_id'],
				'quantity_on_hand'    => $r['on_hand'],
				'quantity_reserved'   => $r['reserved'],
				'low_stock_threshold' => $r['low_stock_threshold'],
				'reorder_quantity'    => $r['reorder_quantity'],
				'special'             => (bool) $r['special'],
			);
		}
		return array(
			'format'      => self::INVENTORY_FORMAT,
			'version'     => self::VERSION,
			'exported_at' => gmdate( 'c' ),
			'area_id'     => absint( $area_id ),
			'area_name'   => Settings::area_name( $area_id ),
			'items'       => $rows,
		);
	}

	/**
	 * Encode as pretty JSON.
	 *
	 * @param array $data Data.
	 * @return string
	 */
	public static function to_json( array $data ) {
		return (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Safely decode JSON into arrays.
	 *
	 * @param string $json JSON text.
	 * @return array|\WP_Error
	 */
	public static function decode( $json ) {
		$json = (string) $json;
		if ( strlen( $json ) > self::MAX_BYTES ) {
			return new \WP_Error( 'too_large', __( 'The JSON is too large (maximum 2 MB).', 'bmlt-literature-manager' ) );
		}
		$json = preg_replace( '/^\xEF\xBB\xBF/', '', trim( $json ) );
		if ( '' === $json ) {
			return new \WP_Error( 'empty', __( 'No JSON was provided.', 'bmlt-literature-manager' ) );
		}
		$data = json_decode( $json, true, 16 );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			/* translators: %s: JSON error. */
			return new \WP_Error( 'json', sprintf( __( 'The JSON could not be read: %s', 'bmlt-literature-manager' ), json_last_error_msg() ) );
		}
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'json_type', __( 'The JSON must be an object.', 'bmlt-literature-manager' ) );
		}
		return $data;
	}

	/**
	 * Validate a catalog payload and build a preview.
	 *
	 * @param array $data            Decoded data.
	 * @param int   $target_area     Target catalog (0 = shared).
	 * @param bool  $update_existing Update items whose SKU already exists.
	 * @param bool  $deactivate      Deactivate items missing from the file.
	 * @return array|\WP_Error Preview.
	 */
	public static function preview_catalog( array $data, $target_area, $update_existing, $deactivate ) {
		if ( ! isset( $data['format'] ) || self::CATALOG_FORMAT !== $data['format'] ) {
			return new \WP_Error( 'format', __( 'This is not a BMLT Literature Manager catalog file (format field missing or wrong).', 'bmlt-literature-manager' ) );
		}
		if ( ! isset( $data['version'] ) || ! is_int( $data['version'] ) || $data['version'] < 1 || $data['version'] > self::VERSION ) {
			return new \WP_Error( 'version', __( 'Unsupported catalog file version. Please update the plugin.', 'bmlt-literature-manager' ) );
		}
		if ( ! isset( $data['items'] ) || ! is_array( $data['items'] ) || array_values( $data['items'] ) !== $data['items'] ) {
			return new \WP_Error( 'items', __( 'The file must contain an "items" list.', 'bmlt-literature-manager' ) );
		}
		if ( count( $data['items'] ) > self::MAX_ITEMS ) {
			return new \WP_Error( 'too_many', __( 'Too many items in one file.', 'bmlt-literature-manager' ) );
		}

		$target_area = absint( $target_area );
		$allowed     = array( 'sku', 'name', 'short_description', 'long_description', 'category', 'price', 'active', 'sort_order', 'image_url', 'reference_url', 'naws_id', 'catalog_area_id' );
		$preview     = array(
			'type'       => 'catalog',
			'target'     => $target_area,
			'update'     => (bool) $update_existing,
			'deactivate' => (bool) $deactivate,
			'rows'       => array(),
			'errors'     => array(),
			'missing'    => array(),
			'counts'     => array(
				'new'       => 0,
				'update'    => 0,
				'unchanged' => 0,
				'skip'      => 0,
				'invalid'   => 0,
			),
		);
		$seen = array();

		foreach ( $data['items'] as $index => $raw ) {
			/* translators: %d: row number. */
			$label = sprintf( __( 'Item %d', 'bmlt-literature-manager' ), $index + 1 );
			if ( ! is_array( $raw ) ) {
				$preview['errors'][] = $label . ': ' . __( 'must be an object.', 'bmlt-literature-manager' );
				++$preview['counts']['invalid'];
				continue;
			}
			$clean = array();
			foreach ( $allowed as $field ) {
				if ( ! array_key_exists( $field, $raw ) ) {
					continue;
				}
				$v = $raw[ $field ];
				if ( ! ( is_scalar( $v ) || null === $v ) ) {
					$preview['errors'][] = $label . ': ' . sprintf( /* translators: %s: field. */ __( 'field "%s" must be a simple value.', 'bmlt-literature-manager' ), $field );
					++$preview['counts']['invalid'];
					continue 2;
				}
				$clean[ $field ] = $v;
			}
			$input = array(
				'area_id'           => $target_area,
				'sku'               => isset( $clean['sku'] ) ? (string) $clean['sku'] : '',
				'name'              => isset( $clean['name'] ) ? (string) $clean['name'] : '',
				'short_description' => isset( $clean['short_description'] ) ? (string) $clean['short_description'] : '',
				'long_description'  => isset( $clean['long_description'] ) ? (string) $clean['long_description'] : '',
				'category'          => isset( $clean['category'] ) ? (string) $clean['category'] : '',
				'price'             => isset( $clean['price'] ) ? $clean['price'] : '0',
				'is_active'         => array_key_exists( 'active', $clean ) ? ( $clean['active'] ? 1 : 0 ) : 1,
				'sort_order'        => isset( $clean['sort_order'] ) ? $clean['sort_order'] : 0,
				'image_url'         => isset( $clean['image_url'] ) ? (string) $clean['image_url'] : '',
				'reference_url'     => isset( $clean['reference_url'] ) ? (string) $clean['reference_url'] : '',
				'naws_id'           => isset( $clean['naws_id'] ) ? (string) $clean['naws_id'] : '',
			);
			if ( is_string( $input['price'] ) && is_numeric( $input['price'] ) ) {
				$input['price'] = (string) $input['price'];
			}

			$sku_key = strtolower( trim( $input['sku'] ) );
			if ( '' !== $sku_key && isset( $seen[ $sku_key ] ) ) {
				$preview['errors'][] = $label . ': ' . __( 'duplicate SKU in file.', 'bmlt-literature-manager' );
				++$preview['counts']['invalid'];
				continue;
			}
			$seen[ $sku_key ] = true;

			$existing = '' !== trim( $input['sku'] ) ? Catalog::get_by_sku( trim( $input['sku'] ), $target_area ) : null;
			$valid    = Catalog::sanitize( $input, $existing );
			if ( is_wp_error( $valid ) ) {
				$preview['errors'][] = $label . ' (' . ( '' !== $input['sku'] ? $input['sku'] : '?' ) . '): ' . implode( ' ', $valid->get_error_messages() );
				++$preview['counts']['invalid'];
				continue;
			}

			if ( ! $existing ) {
				$action = 'new';
			} elseif ( ! $update_existing ) {
				$action = 'skip';
			} else {
				$changed = (string) $existing->name !== $valid['name']
					|| (string) $existing->short_description !== $valid['short_description']
					|| (string) $existing->long_description !== $valid['long_description']
					|| (string) $existing->category !== $valid['category']
					|| Helpers::to_cents( $existing->price ) !== Helpers::to_cents( $valid['price'] )
					|| (int) $existing->is_active !== (int) $valid['is_active']
					|| (int) $existing->sort_order !== (int) $valid['sort_order']
					|| (string) $existing->image_url !== $valid['image_url']
					|| ( isset( $existing->reference_url ) ? (string) $existing->reference_url : '' ) !== $valid['reference_url']
					|| (string) $existing->naws_id !== $valid['naws_id'];
				$action  = $changed ? 'update' : 'unchanged';
			}
			++$preview['counts'][ $action ];
			$valid['price']     = Helpers::sql_money( $valid['price'] );
			$preview['rows'][]  = array(
				'action'      => $action,
				'existing_id' => $existing ? (int) $existing->id : 0,
				'old_price'   => $existing ? Helpers::sql_money( $existing->price ) : '',
				'data'        => $valid,
			);
		}

		if ( $deactivate ) {
			$r = Catalog::query(
				array(
					'area_id'        => $target_area,
					'include_shared' => false,
					'active'         => 1,
				)
			);
			foreach ( $r['items'] as $it ) {
				if ( ! isset( $seen[ strtolower( $it->sku ) ] ) ) {
					$preview['missing'][] = array(
						'id'   => (int) $it->id,
						'sku'  => (string) $it->sku,
						'name' => (string) $it->name,
					);
				}
			}
		}

		return $preview;
	}

	/**
	 * Validate an inventory payload and build a preview.
	 *
	 * @param array $data    Decoded data.
	 * @param int   $area_id Target Area.
	 * @return array|\WP_Error
	 */
	public static function preview_inventory( array $data, $area_id ) {
		if ( ! isset( $data['format'] ) || self::INVENTORY_FORMAT !== $data['format'] ) {
			return new \WP_Error( 'format', __( 'This is not a BMLT Literature Manager inventory file.', 'bmlt-literature-manager' ) );
		}
		if ( ! isset( $data['version'] ) || ! is_int( $data['version'] ) || $data['version'] < 1 || $data['version'] > self::VERSION ) {
			return new \WP_Error( 'version', __( 'Unsupported inventory file version.', 'bmlt-literature-manager' ) );
		}
		if ( ! isset( $data['items'] ) || ! is_array( $data['items'] ) || array_values( $data['items'] ) !== $data['items'] || count( $data['items'] ) > self::MAX_ITEMS ) {
			return new \WP_Error( 'items', __( 'The file must contain an "items" list.', 'bmlt-literature-manager' ) );
		}
		$area_id = absint( $area_id );
		if ( ! Settings::is_selected_area( $area_id ) ) {
			return new \WP_Error( 'area', __( 'Choose a managed Area to import inventory into.', 'bmlt-literature-manager' ) );
		}

		$current = array();
		foreach ( Inventory::area_inventory( $area_id ) as $row ) {
			$current[ strtolower( $row['sku'] ) . '|' . $row['item_area_id'] ] = $row;
		}

		$preview = array(
			'type'    => 'inventory',
			'target'  => $area_id,
			'rows'    => array(),
			'errors'  => array(),
			'missing' => array(),
			'counts'  => array(
				'update'    => 0,
				'unchanged' => 0,
				'invalid'   => 0,
			),
		);

		foreach ( $data['items'] as $index => $raw ) {
			/* translators: %d: row number. */
			$label = sprintf( __( 'Row %d', 'bmlt-literature-manager' ), $index + 1 );
			if ( ! is_array( $raw ) || ! isset( $raw['sku'] ) || ! is_scalar( $raw['sku'] ) ) {
				$preview['errors'][] = $label . ': ' . __( 'missing SKU.', 'bmlt-literature-manager' );
				++$preview['counts']['invalid'];
				continue;
			}
			$sku   = trim( sanitize_text_field( (string) $raw['sku'] ) );
			$cat   = isset( $raw['catalog_area_id'] ) && is_scalar( $raw['catalog_area_id'] ) ? absint( $raw['catalog_area_id'] ) : null;
			$match = null;
			if ( null !== $cat && isset( $current[ strtolower( $sku ) . '|' . $cat ] ) ) {
				$match = $current[ strtolower( $sku ) . '|' . $cat ];
			} elseif ( isset( $current[ strtolower( $sku ) . '|' . $area_id ] ) ) {
				$match = $current[ strtolower( $sku ) . '|' . $area_id ];
			} elseif ( isset( $current[ strtolower( $sku ) . '|0' ] ) ) {
				$match = $current[ strtolower( $sku ) . '|0' ];
			}
			if ( ! $match ) {
				/* translators: %s: SKU. */
				$preview['errors'][] = $label . ': ' . sprintf( __( 'no catalog item with SKU "%s" is available to this Area.', 'bmlt-literature-manager' ), $sku );
				++$preview['counts']['invalid'];
				continue;
			}
			$ints = array();
			foreach ( array( 'quantity_on_hand', 'low_stock_threshold', 'reorder_quantity' ) as $f ) {
				if ( ! array_key_exists( $f, $raw ) ) {
					$ints[ $f ] = null;
					continue;
				}
				if ( ! is_int( $raw[ $f ] ) && ! ( is_string( $raw[ $f ] ) && ctype_digit( $raw[ $f ] ) ) ) {
					/* translators: %s: field. */
					$preview['errors'][] = $label . ': ' . sprintf( __( '"%s" must be a whole number of zero or more.', 'bmlt-literature-manager' ), $f );
					++$preview['counts']['invalid'];
					continue 2;
				}
				$val = (int) $raw[ $f ];
				if ( $val < 0 || $val > 1000000 ) {
					/* translators: %s: field. */
					$preview['errors'][] = $label . ': ' . sprintf( __( '"%s" is out of range.', 'bmlt-literature-manager' ), $f );
					++$preview['counts']['invalid'];
					continue 2;
				}
				$ints[ $f ] = $val;
			}
			// Optional special-order mark (used by Areas that don't track quantities).
			$ints['special'] = null;
			if ( array_key_exists( 'special', $raw ) ) {
				$sv = $raw['special'];
				if ( ! is_bool( $sv ) && ! in_array( $sv, array( 0, 1, '0', '1', 'yes', 'no', 'true', 'false' ), true ) ) {
					/* translators: %s: field. */
					$preview['errors'][] = $label . ': ' . sprintf( __( '"%s" must be true or false.', 'bmlt-literature-manager' ), 'special' );
					++$preview['counts']['invalid'];
					continue;
				}
				$ints['special'] = in_array( $sv, array( true, 1, '1', 'yes', 'true' ), true ) ? 1 : 0;
			}
			$changed = ( null !== $ints['special'] && $ints['special'] !== (int) $match['special'] )
				|| ( null !== $ints['quantity_on_hand'] && $ints['quantity_on_hand'] !== $match['on_hand'] )
				|| ( null !== $ints['low_stock_threshold'] && $ints['low_stock_threshold'] !== $match['low_stock_threshold'] )
				|| ( null !== $ints['reorder_quantity'] && $ints['reorder_quantity'] !== $match['reorder_quantity'] );
			$action  = $changed ? 'update' : 'unchanged';
			++$preview['counts'][ $action ];
			$preview['rows'][] = array(
				'action'  => $action,
				'item_id' => $match['item_id'],
				'sku'     => $match['sku'],
				'name'    => $match['name'],
				'old'     => $match['on_hand'],
				'data'    => $ints,
			);
		}
		return $preview;
	}

	/**
	 * Apply a validated preview.
	 *
	 * @param array $preview Preview from preview_catalog()/preview_inventory().
	 * @return array Summary counts.
	 */
	public static function apply( array $preview ) {
		// One transaction: rows the checks refuse are counted as failed, but a system failure (a database error)
		// leaves the catalog or inventory exactly as it was.
		return Db::tx(
			static function () use ( $preview ) {
				return self::apply_rows( $preview );
			}
		);
	}

	/**
	 * Apply a preview's rows (inside the transaction of apply()).
	 *
	 * @param array $preview Preview.
	 * @return array Summary counts.
	 */
	private static function apply_rows( array $preview ) {
		$summary = array(
			'created'     => 0,
			'updated'     => 0,
			'deactivated' => 0,
			'failed'      => 0,
		);
		if ( 'inventory' === $preview['type'] ) {
			foreach ( $preview['rows'] as $row ) {
				if ( 'update' !== $row['action'] ) {
					continue;
				}
				$res = Inventory::adjust(
					$preview['target'],
					$row['item_id'],
					array(
						'set'                 => $row['data']['quantity_on_hand'],
						'low_stock_threshold' => $row['data']['low_stock_threshold'],
						'reorder_quantity'    => $row['data']['reorder_quantity'],
						'special'             => isset( $row['data']['special'] ) ? $row['data']['special'] : null,
					),
					__( 'JSON inventory import', 'bmlt-literature-manager' ),
					'inventory_imported'
				);
				if ( is_wp_error( $res ) ) {
					++$summary['failed'];
				} else {
					++$summary['updated'];
				}
			}
			Audit::log( 'inventory_imported', 'import', 0, null, $summary, $preview['target'] );
			return $summary;
		}

		foreach ( $preview['rows'] as $row ) {
			if ( 'new' === $row['action'] ) {
				$res = Catalog::create( $row['data'] );
				if ( is_wp_error( $res ) ) {
					++$summary['failed'];
				} else {
					++$summary['created'];
				}
			} elseif ( 'update' === $row['action'] && $row['existing_id'] ) {
				$res = Catalog::update( $row['existing_id'], $row['data'] );
				if ( is_wp_error( $res ) ) {
					++$summary['failed'];
				} else {
					++$summary['updated'];
				}
			}
		}
		if ( ! empty( $preview['deactivate'] ) ) {
			foreach ( $preview['missing'] as $m ) {
				$res = Catalog::set_active( $m['id'], false );
				if ( ! is_wp_error( $res ) ) {
					++$summary['deactivated'];
				}
			}
		}
		Audit::log( 'catalog_imported', 'import', 0, null, $summary, $preview['target'] );
		return $summary;
	}

	/* ------------------------------------------------------------------
	 * Full backup / restore.
	 * ---------------------------------------------------------------- */

	/**
	 * Every stored record as backup documents (the format of version 6, so backups move freely between 6.x and 7.x):
	 * settings, catalog, inventory, permissions, orders/YYYY-MM and audit/YYYY-MM. The SMTP password is never
	 * included.
	 *
	 * @return array name => document
	 */
	public static function documents() {
		$settings         = Settings::all_raw();
		$settings['meta'] = Settings::meta();
		unset( $settings['smtp']['password'] );
		$catalog = Catalog::doc();
		$docs    = array(
			Settings::DOC    => $settings,
			Catalog::DOC     => array(
				'next_id' => max( (int) $catalog['next_id'], $catalog['items'] ? max( array_keys( $catalog['items'] ) ) + 1 : 1 ),
				'items'   => $catalog['items'],
			),
			Inventory::DOC   => Inventory::doc(),
			Permissions::DOC => array( 'users' => Permissions::all_grants() ),
		);
		return $docs + Orders::export_documents() + self::audit_documents();
	}

	/**
	 * Audit entries as backup documents ("audit/YYYY-MM" => { entries: [] }).
	 *
	 * @return array
	 */
	private static function audit_documents() {
		global $wpdb;
		$docs = array();
		$t    = Db::table( 'audit' );
		$last = 0;
		do {
			$rows = Db::rows( $wpdb->prepare( "SELECT * FROM {$t} WHERE id > %d ORDER BY id LIMIT 2000", $last ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
			foreach ( $rows as $r ) {
				$last = (int) $r['id'];
				$e    = Schema::audit( $r );
				if ( $e ) {
					$docs[ Audit::DOC_PREFIX . substr( $e['created_at'], 0, 7 ) ]['entries'][] = $e;
				}
			}
		} while ( count( $rows ) === 2000 );
		return $docs;
	}

	/**
	 * Build a full backup.
	 *
	 * @return array
	 */
	public static function export_backup() {
		$docs = self::documents();
		if ( isset( $docs[ Settings::DOC ]['smtp'] ) ) {
			$docs[ Settings::DOC ]['smtp']['password'] = '';
		}
		return array(
			'format'      => self::BACKUP_FORMAT,
			'version'     => self::BACKUP_VERSION,
			'exported_at' => gmdate( 'c' ),
			'site'        => home_url( '/' ),
			'plugin'      => BMLT_LIT_VERSION,
			'documents'   => $docs,
		);
	}

	/**
	 * Whether a backup document name is valid ("settings", "orders/2026-10", ...).
	 *
	 * @param mixed $name Name.
	 * @return bool
	 */
	public static function valid_name( $name ) {
		return is_string( $name ) && (bool) preg_match( '#^[a-z0-9_-]+(/[a-z0-9_-]+)?$#', $name );
	}

	/**
	 * Validate a backup and return normalized documents ready to write.
	 *
	 * @param array $data Decoded backup.
	 * @return array|\WP_Error { documents: array, counts: array, site: string }
	 */
	public static function validate_backup( array $data ) {
		if ( ! isset( $data['format'] ) || self::BACKUP_FORMAT !== $data['format'] ) {
			return new \WP_Error( 'format', __( 'This is not a BMLT Literature Manager full backup file.', 'bmlt-literature-manager' ) );
		}
		if ( ! isset( $data['version'] ) || ! is_int( $data['version'] ) || $data['version'] < 2 || $data['version'] > self::BACKUP_VERSION ) {
			return new \WP_Error( 'version', __( 'Unsupported backup version.', 'bmlt-literature-manager' ) );
		}
		if ( ! isset( $data['documents'] ) || ! is_array( $data['documents'] ) ) {
			return new \WP_Error( 'documents', __( 'The backup contains no documents.', 'bmlt-literature-manager' ) );
		}
		$out    = array();
		$counts = array(
			'items'     => 0,
			'inventory' => 0,
			'orders'    => 0,
			'audit'     => 0,
			'users'     => 0,
		);
		foreach ( $data['documents'] as $name => $doc ) {
			if ( ! self::valid_name( $name ) || ! is_array( $doc ) ) {
				continue;
			}
			if ( Settings::DOC === $name ) {
				$out[ $name ] = Schema::settings( $doc );
			} elseif ( Catalog::DOC === $name ) {
				$items = array();
				$next  = 1;
				foreach ( isset( $doc['items'] ) && is_array( $doc['items'] ) ? $doc['items'] : array() as $id => $it ) {
					if ( ! is_array( $it ) ) {
						continue;
					}
					$it['id'] = absint( $id );
					$item     = Schema::item( $it );
					if ( $item ) {
						$items[ $item['id'] ] = $item;
						$next                 = max( $next, $item['id'] + 1 );
					}
				}
				$out[ $name ]    = array(
					'next_id' => max( $next, isset( $doc['next_id'] ) ? absint( $doc['next_id'] ) : 1 ),
					'items'   => $items,
				);
				$counts['items'] = count( $items );
			} elseif ( Inventory::DOC === $name ) {
				$areas = array();
				foreach ( isset( $doc['areas'] ) && is_array( $doc['areas'] ) ? $doc['areas'] : array() as $area => $rows ) {
					if ( ! absint( $area ) || ! is_array( $rows ) ) {
						continue;
					}
					foreach ( $rows as $item_id => $row ) {
						if ( absint( $item_id ) && is_array( $row ) ) {
							$areas[ absint( $area ) ][ absint( $item_id ) ] = Schema::inventory_row( $row );
							++$counts['inventory'];
						}
					}
				}
				$out[ $name ] = array( 'areas' => $areas );
			} elseif ( Permissions::DOC === $name ) {
				$out[ $name ]    = Schema::permissions( $doc );
				$counts['users'] = count( $out[ $name ]['users'] );
			} elseif ( preg_match( '#^orders/\d{4}-\d{2}$#', $name ) ) {
				$orders = array();
				foreach ( isset( $doc['orders'] ) && is_array( $doc['orders'] ) ? $doc['orders'] : array() as $id => $rec ) {
					if ( ! is_array( $rec ) ) {
						continue;
					}
					$rec['id'] = absint( $id );
					$o         = Schema::order( $rec );
					if ( $o ) {
						$orders[ $o['id'] ] = $o;
					}
				}
				$out[ $name ]      = array( 'orders' => $orders );
				$counts['orders'] += count( $orders );
			} elseif ( preg_match( '#^audit/\d{4}-\d{2}$#', $name ) ) {
				$entries = array();
				foreach ( isset( $doc['entries'] ) && is_array( $doc['entries'] ) ? $doc['entries'] : array() as $e ) {
					$n = is_array( $e ) ? Schema::audit( $e ) : null;
					if ( $n ) {
						$entries[] = $n;
					}
				}
				$out[ $name ]     = array( 'entries' => $entries );
				$counts['audit'] += count( $entries );
			}
		}
		if ( ! isset( $out[ Settings::DOC ] ) && ! isset( $out[ Catalog::DOC ] ) ) {
			return new \WP_Error( 'empty', __( 'The backup does not contain settings or a catalog.', 'bmlt-literature-manager' ) );
		}
		return array(
			'documents' => $out,
			'counts'    => $counts,
			'site'      => isset( $data['site'] ) && is_string( $data['site'] ) ? esc_url_raw( $data['site'] ) : '',
		);
	}

	/**
	 * Replace all stored data with a validated backup, in one transaction: if anything fails, nothing changes.
	 * This site's identity and its SMTP password are kept.
	 *
	 * @param array $valid Result of validate_backup().
	 * @return true|\WP_Error
	 */
	public static function restore_backup( array $valid ) {
		$result = Db::tx(
			static function () use ( $valid ) {
				$docs = $valid['documents'];
				$meta = Settings::meta();
				if ( isset( $docs[ Settings::DOC ] ) ) {
					$current                                   = Settings::raw( Settings::SMTP );
					$docs[ Settings::DOC ]['smtp']['password'] = isset( $current['password'] ) ? (string) $current['password'] : '';
					$meta['restored_at']                       = time();
					$meta['restored_from']                     = $valid['site'];
				}
				self::write_documents( $docs );
				Settings::save_meta( $meta );
				return true;
			}
		);
		Settings::flush();
		return $result;
	}

	/**
	 * Write normalized documents (from validate_backup(), a migration or the files of version 6) to the database.
	 * Must run inside a transaction. Settings, catalog, inventory and permissions are replaced when the documents
	 * include them; orders and audit entries are always replaced by the documents' (a restore, not a merge).
	 *
	 * @param array $docs Normalized documents.
	 * @throws Db_Error On failure.
	 */
	public static function write_documents( array $docs ) {
		if ( ! Db::in_tx() ) {
			throw new Db_Error( 'write_documents() must run inside a transaction.' );
		}
		if ( isset( $docs[ Settings::DOC ] ) ) {
			foreach ( Settings::sections() as $section ) {
				$value = isset( $docs[ Settings::DOC ][ $section ] ) && is_array( $docs[ Settings::DOC ][ $section ] ) ? $docs[ Settings::DOC ][ $section ] : array();
				Db::set_option( Settings::option( $section ), $value, in_array( $section, Settings::AUTOLOAD, true ) );
			}
		}
		if ( isset( $docs[ Catalog::DOC ] ) ) {
			Db::q( 'DELETE FROM ' . Db::table( 'items' ) );
			$rows = array();
			$max  = 0;
			foreach ( $docs[ Catalog::DOC ]['items'] as $item ) {
				$row = array();
				foreach ( array( 'id', 'area_id', 'sku', 'name', 'short_description', 'long_description', 'category', 'price', 'is_active', 'sort_order', 'image_url', 'reference_url', 'naws_id', 'created_at', 'updated_at' ) as $k ) {
					$row[ $k ] = isset( $item[ $k ] ) ? $item[ $k ] : '';
				}
				$rows[] = $row;
				$max    = max( $max, (int) $item['id'] );
			}
			Db::insert_many( 'items', $rows );
			Db::raise_counter( 'item', max( $max + 1, isset( $docs[ Catalog::DOC ]['next_id'] ) ? (int) $docs[ Catalog::DOC ]['next_id'] : 1 ) );
		}
		if ( isset( $docs[ Inventory::DOC ] ) ) {
			Db::q( 'DELETE FROM ' . Db::table( 'inventory' ) );
			$rows = array();
			foreach ( $docs[ Inventory::DOC ]['areas'] as $area_id => $items ) {
				foreach ( $items as $item_id => $r ) {
					$rows[] = array(
						'area_id'     => (int) $area_id,
						'item_id'     => (int) $item_id,
						'on_hand'     => (int) $r['on_hand'],
						'reserved'    => (int) $r['reserved'],
						'low_stock'   => (int) $r['low'],
						'reorder_qty' => (int) $r['reorder'],
						'notes'       => (string) $r['notes'],
						'special'     => (int) $r['special'],
						'updated_at'  => '' !== (string) $r['updated_at'] ? $r['updated_at'] : null,
						'updated_by'  => (int) $r['updated_by'],
					);
				}
			}
			Db::insert_many( 'inventory', $rows );
		}
		if ( isset( $docs[ Permissions::DOC ] ) ) {
			Db::q( 'DELETE FROM ' . Db::table( 'permissions' ) );
			foreach ( $docs[ Permissions::DOC ]['users'] as $uid => $g ) {
				Permissions::put_grant( (int) $uid, $g['caps'], $g['areas'] );
			}
		}

		foreach ( array( 'order_notes', 'order_items', 'orders' ) as $t ) {
			Db::q( 'DELETE FROM ' . Db::table( $t ) );
		}
		Db::q( 'DELETE FROM ' . Db::table( 'audit' ) );
		$max_order = 0;
		$audit     = array();
		$hashes    = array();
		foreach ( $docs as $name => $doc ) {
			if ( 0 === strpos( $name, Orders::DOC_PREFIX ) ) {
				foreach ( $doc['orders'] as $rec ) {
					// A submission hash only guards against double submits; it must be unique, so a repeated one is dropped.
					if ( '' !== (string) $rec['submission_hash'] && isset( $hashes[ $rec['submission_hash'] ] ) ) {
						$rec['submission_hash'] = '';
					}
					$hashes[ (string) $rec['submission_hash'] ] = true;
					Orders::insert_record( $rec, $rec['items'], $rec['notes_log'] );
					$max_order = max( $max_order, (int) $rec['id'] );
				}
			} elseif ( 0 === strpos( $name, Audit::DOC_PREFIX ) ) {
				foreach ( $doc['entries'] as $e ) {
					$audit[] = Audit::row( $e );
				}
			}
		}
		usort(
			$audit,
			static function ( $a, $b ) {
				return strcmp( $a['created_at'], $b['created_at'] );
			}
		);
		Db::insert_many( 'audit', $audit );
		if ( $max_order ) {
			Db::raise_counter( 'order', $max_order + 1 );
		}
	}
}
