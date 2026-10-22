<?php
/**
 * Literature catalog.
 *
 * @package BMLT_Lit
 * @version 7.0.0
 */

namespace BMLT_Lit;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for literature items.
 *
 * Stored in the items table. Items with area_id = 0 form the shared catalog available to every managed Area.
 * Items with a specific area_id belong only to that Area's catalog, allowing each Area to keep its own
 * catalog and pricing when desired.
 */
class Catalog {

	/**
	 * Name of the catalog document in backups.
	 */
	const DOC = 'catalog';

	/**
	 * Request cache: blog id => items keyed by id.
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Drop the request cache.
	 */
	public static function flush() {
		self::$cache = array();
	}

	/**
	 * Item array from a table row.
	 *
	 * @param array $r Row.
	 * @return array
	 */
	private static function from_row( array $r ) {
		$r['id']         = (int) $r['id'];
		$r['area_id']    = (int) $r['area_id'];
		$r['is_active']  = (int) $r['is_active'];
		$r['sort_order'] = (int) $r['sort_order'];
		$r['price']      = Helpers::sql_money( $r['price'] );
		return $r;
	}

	/**
	 * Every item, keyed by id (inside a transaction: as the transaction sees them, never cached).
	 *
	 * @return array { next_id: int, items: array }
	 */
	public static function doc() {
		$blog = get_current_blog_id();
		if ( Db::in_tx() || ! isset( self::$cache[ $blog ] ) ) {
			$t     = Db::table( 'items' );
			$items = array();
			foreach ( Db::rows( "SELECT * FROM {$t} ORDER BY id" ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
				$items[ (int) $r['id'] ] = self::from_row( $r );
			}
			if ( Db::in_tx() ) {
				return array(
					'next_id' => Db::counter( 'item' ),
					'items'   => $items,
				);
			}
			self::$cache[ $blog ] = $items;
		}
		return array(
			'next_id' => Db::counter( 'item' ),
			'items'   => self::$cache[ $blog ],
		);
	}

	/**
	 * Item array as object with typed properties.
	 *
	 * @param array $i Item.
	 * @return object
	 */
	private static function obj( array $i ) {
		$i['id']         = (int) $i['id'];
		$i['area_id']    = (int) $i['area_id'];
		$i['is_active']  = (int) $i['is_active'];
		$i['sort_order'] = (int) $i['sort_order'];
		return (object) $i;
	}

	/**
	 * One item as an array (inside a transaction: read from the table).
	 *
	 * @param int $id Item ID.
	 * @return array|null
	 */
	private static function find( $id ) {
		global $wpdb;
		$id = absint( $id );
		if ( Db::in_tx() ) {
			$t = Db::table( 'items' );
			$r = Db::row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
			return $r ? self::from_row( $r ) : null;
		}
		$d = self::doc();
		return isset( $d['items'][ $id ] ) ? $d['items'][ $id ] : null;
	}

	/**
	 * Get one item.
	 *
	 * @param int $id Item ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		$i = self::find( $id );
		return $i ? self::obj( $i ) : null;
	}

	/**
	 * Get several items.
	 *
	 * @param int[] $ids IDs.
	 * @return object[] Keyed by ID.
	 */
	public static function get_many( array $ids ) {
		$out = array();
		foreach ( self::rows_for( $ids ) as $id => $i ) {
			$out[ $id ] = self::obj( $i );
		}
		return $out;
	}

	/**
	 * Several items as arrays keyed by id (inside a transaction: read from the table).
	 *
	 * @param int[] $ids IDs.
	 * @return array
	 */
	public static function rows_for( array $ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		$out = array();
		if ( ! $ids ) {
			return $out;
		}
		if ( Db::in_tx() ) {
			$t  = Db::table( 'items' );
			$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table name; $ph is a list of %d placeholders.
			foreach ( Db::rows( $wpdb->prepare( "SELECT * FROM {$t} WHERE id IN ({$ph})", $ids ) ) as $r ) {
				$out[ (int) $r['id'] ] = self::from_row( $r );
			}
			return $out;
		}
		$d = self::doc();
		foreach ( $ids as $id ) {
			if ( isset( $d['items'][ $id ] ) ) {
				$out[ $id ] = $d['items'][ $id ];
			}
		}
		return $out;
	}

	/**
	 * Query items (filter, sort and paginate in memory).
	 *
	 * @param array $args Arguments.
	 * @return array { items: object[], total: int }
	 */
	public static function query( array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'area_id'        => null,
				'include_shared' => true,
				'area_ids'       => null,
				'active'         => null,
				'search'         => '',
				'category'       => '',
				'orderby'        => 'sort_order',
				'order'          => 'ASC',
				'limit'          => 0,
				'offset'         => 0,
			)
		);

		$allowed_areas = null;
		if ( null !== $args['area_id'] && '' !== $args['area_id'] ) {
			$area          = absint( $args['area_id'] );
			$allowed_areas = ( $args['include_shared'] && $area ) ? array( 0, $area ) : array( $area );
		} elseif ( is_array( $args['area_ids'] ) ) {
			$allowed_areas   = array_map( 'absint', $args['area_ids'] );
			$allowed_areas[] = 0;
		}
		$search = (string) $args['search'];

		$rows = array();
		foreach ( self::doc()['items'] as $i ) {
			if ( null !== $allowed_areas && ! in_array( (int) $i['area_id'], $allowed_areas, true ) ) {
				continue;
			}
			if ( null !== $args['active'] && '' !== $args['active'] && (int) $i['is_active'] !== ( $args['active'] ? 1 : 0 ) ) {
				continue;
			}
			if ( '' !== $args['category'] && (string) $i['category'] !== (string) $args['category'] ) {
				continue;
			}
			if ( '' !== $search ) {
				$hay = $i['name'] . ' ' . $i['sku'] . ' ' . $i['short_description'] . ' ' . $i['category'];
				if ( false === stripos( $hay, $search ) ) {
					continue;
				}
			}
			$rows[] = $i;
		}

		$allowed = array( 'sort_order', 'name', 'sku', 'category', 'price', 'is_active', 'id', 'area_id', 'updated_at' );
		$orderby = in_array( $args['orderby'], $allowed, true ) ? $args['orderby'] : 'sort_order';
		$dir     = 'DESC' === strtoupper( (string) $args['order'] ) ? -1 : 1;
		$numeric = in_array( $orderby, array( 'sort_order', 'price', 'is_active', 'id', 'area_id' ), true );
		usort(
			$rows,
			static function ( $a, $b ) use ( $orderby, $dir, $numeric ) {
				$c = $numeric ? ( (float) $a[ $orderby ] <=> (float) $b[ $orderby ] ) : strnatcasecmp( (string) $a[ $orderby ], (string) $b[ $orderby ] );
				if ( 0 !== $c ) {
					return $c * $dir;
				}
				$c = strnatcasecmp( (string) $a['name'], (string) $b['name'] );
				return 0 !== $c ? $c : ( (int) $a['id'] <=> (int) $b['id'] );
			}
		);

		$total = count( $rows );
		if ( $args['limit'] > 0 ) {
			$rows = array_slice( $rows, max( 0, (int) $args['offset'] ), (int) $args['limit'] );
		}
		return array(
			'items' => array_map( array( __CLASS__, 'obj' ), $rows ),
			'total' => $total,
		);
	}

	/**
	 * Active items available to any of the given Areas (shared + Area-specific).
	 *
	 * @param int[] $area_ids Areas.
	 * @return object[]
	 */
	public static function items_for_areas( array $area_ids ) {
		$r = self::query(
			array(
				'area_ids' => $area_ids,
				'active'   => 1,
			)
		);
		return $r['items'];
	}

	/**
	 * Distinct categories.
	 *
	 * @return string[]
	 */
	public static function categories() {
		$cats = array();
		foreach ( self::doc()['items'] as $i ) {
			if ( '' !== (string) $i['category'] ) {
				$cats[ (string) $i['category'] ] = true;
			}
		}
		$cats = array_keys( $cats );
		natcasesort( $cats );
		return array_values( $cats );
	}

	/**
	 * Whether a SKU already exists in a catalog (Area). Case-insensitive.
	 *
	 * @param string $sku        SKU.
	 * @param int    $area_id    Area.
	 * @param int    $exclude_id Item to ignore.
	 * @return bool
	 */
	public static function sku_exists( $sku, $area_id, $exclude_id = 0 ) {
		global $wpdb;
		$t = Db::table( 'items' );
		return null !== Db::var( $wpdb->prepare( "SELECT id FROM {$t} WHERE area_id = %d AND LOWER(sku) = LOWER(%s) AND id <> %d LIMIT 1", absint( $area_id ), (string) $sku, absint( $exclude_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
	}

	/**
	 * Find an item by SKU in a catalog.
	 *
	 * @param string $sku     SKU.
	 * @param int    $area_id Area.
	 * @return object|null
	 */
	public static function get_by_sku( $sku, $area_id ) {
		global $wpdb;
		$t  = Db::table( 'items' );
		$id = Db::var( $wpdb->prepare( "SELECT id FROM {$t} WHERE area_id = %d AND LOWER(sku) = LOWER(%s) ORDER BY id LIMIT 1", absint( $area_id ), (string) $sku ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		return null === $id ? null : self::get( (int) $id );
	}

	/**
	 * Validate and sanitize item input. Only whitelisted fields are accepted (no mass assignment).
	 *
	 * @param array       $raw      Raw input.
	 * @param object|null $existing Existing item for updates.
	 * @return array|\WP_Error
	 */
	public static function sanitize( array $raw, $existing = null ) {
		$errors = new \WP_Error();
		$get    = static function ( $key, $default = '' ) use ( $raw, $existing ) {
			if ( array_key_exists( $key, $raw ) ) {
				return is_scalar( $raw[ $key ] ) || null === $raw[ $key ] ? $raw[ $key ] : $default;
			}
			return ( $existing && isset( $existing->$key ) ) ? $existing->$key : $default;
		};

		$data = array();

		$data['area_id'] = absint( $get( 'area_id', 0 ) );
		if ( $data['area_id'] && ! Settings::is_selected_area( $data['area_id'] ) && ! ( $existing && (int) $existing->area_id === $data['area_id'] ) ) {
			$errors->add( 'area', __( 'The selected Area is not managed by this site.', 'bmlt-literature-manager' ) );
		}

		$data['sku'] = Helpers::limit( trim( sanitize_text_field( (string) $get( 'sku' ) ) ), 64 );
		if ( '' === $data['sku'] ) {
			$errors->add( 'sku', __( 'SKU / item number is required.', 'bmlt-literature-manager' ) );
		} elseif ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9 ._\-\/#]*$/', $data['sku'] ) ) {
			$errors->add( 'sku', __( 'SKU may contain only letters, numbers, spaces and . _ - / # characters.', 'bmlt-literature-manager' ) );
		}

		$data['name'] = Helpers::limit( trim( sanitize_text_field( (string) $get( 'name' ) ) ), 255 );
		if ( '' === $data['name'] ) {
			$errors->add( 'name', __( 'Literature name is required.', 'bmlt-literature-manager' ) );
		}

		$data['short_description'] = Helpers::limit( sanitize_textarea_field( (string) $get( 'short_description' ) ), 1000 );
		$data['long_description']  = Helpers::limit( wp_kses_post( (string) $get( 'long_description' ) ), 20000 );
		$data['category']          = Helpers::limit( trim( sanitize_text_field( (string) $get( 'category' ) ) ), 100 );

		$price = Helpers::parse_money( $get( 'price', '0' ) );
		if ( false === $price ) {
			$errors->add( 'price', __( 'Price must be a non-negative amount with at most two decimal places.', 'bmlt-literature-manager' ) );
			$price = 0;
		}
		$data['price'] = $price;

		$active            = $get( 'is_active', 1 );
		$data['is_active'] = ( true === $active || 1 === $active || '1' === $active || 'yes' === $active || 'true' === $active || 'on' === $active ) ? 1 : 0;

		$sort = $get( 'sort_order', 0 );
		if ( ! is_numeric( $sort ) ) {
			$sort = 0;
		}
		$data['sort_order'] = max( -999999, min( 999999, (int) $sort ) );

		$image = trim( (string) $get( 'image_url' ) );
		if ( '' !== $image ) {
			$image = esc_url_raw( $image, array( 'http', 'https' ) );
			if ( '' === $image ) {
				$errors->add( 'image_url', __( 'Image URL must be a valid http(s) URL.', 'bmlt-literature-manager' ) );
			}
		}
		$data['image_url'] = Helpers::limit( $image, 2048 );

		$ref = trim( (string) $get( 'reference_url' ) );
		if ( '' !== $ref ) {
			$ref = esc_url_raw( $ref, array( 'http', 'https' ) );
			if ( '' === $ref ) {
				$errors->add( 'reference_url', __( 'Reference link must be a valid http(s) URL.', 'bmlt-literature-manager' ) );
			}
		}
		$data['reference_url'] = Helpers::limit( $ref, 2048 );
		$data['naws_id']   = Helpers::limit( trim( sanitize_text_field( (string) $get( 'naws_id' ) ) ), 64 );

		if ( '' !== $data['sku'] && self::sku_exists( $data['sku'], $data['area_id'], $existing ? (int) $existing->id : 0 ) ) {
			$errors->add( 'sku_dup', __( 'Another item in this catalog already uses that SKU.', 'bmlt-literature-manager' ) );
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}
		return $data;
	}

	/**
	 * Table row from sanitized item data.
	 *
	 * @param array $data Data from sanitize().
	 * @return array
	 */
	private static function to_row( array $data ) {
		$row = array();
		foreach ( array( 'area_id', 'sku', 'name', 'short_description', 'long_description', 'category', 'price', 'is_active', 'sort_order', 'image_url', 'reference_url', 'naws_id' ) as $k ) {
			$row[ $k ] = isset( $data[ $k ] ) ? $data[ $k ] : '';
		}
		$row['price'] = Helpers::sql_money( $row['price'] );
		return $row;
	}

	/**
	 * Create an item.
	 *
	 * @param array $raw Raw input.
	 * @return int|\WP_Error
	 */
	public static function create( array $raw ) {
		return Db::tx(
			static function () use ( $raw ) {
				$data = self::sanitize( $raw ); // Runs under the store lock, so SKU uniqueness is race-free.
				if ( is_wp_error( $data ) ) {
					return $data;
				}
				$id                = Db::next_id( 'item', 'items' );
				$now               = Helpers::now_gmt();
				$row               = self::to_row( $data );
				$row['id']         = $id;
				$row['created_at'] = $now;
				$row['updated_at'] = $now;
				Db::insert( 'items', $row );
				$logged = Audit::log( 'item_created', 'item', $id, null, self::audit_view( $row ), (int) $row['area_id'] );
				return is_wp_error( $logged ) ? $logged : $id;
			}
		);
	}

	/**
	 * Update an item.
	 *
	 * @param int   $id  Item ID.
	 * @param array $raw Raw input.
	 * @return true|\WP_Error
	 */
	public static function update( $id, array $raw ) {
		$id = absint( $id );
		return Db::tx(
			static function () use ( $id, $raw ) {
				$existing = self::get( $id );
				if ( ! $existing ) {
					return new \WP_Error( 'not_found', __( 'Item not found.', 'bmlt-literature-manager' ) );
				}
				$data = self::sanitize( $raw, $existing );
				if ( is_wp_error( $data ) ) {
					return $data;
				}
				$row               = self::to_row( $data );
				$row['updated_at'] = Helpers::now_gmt();
				Db::update( 'items', $row, array( 'id' => $id ) );
				$old = self::audit_view( (array) $existing );
				$new = self::audit_view( $row );
				if ( Helpers::to_cents( $existing->price ) !== Helpers::to_cents( $row['price'] ) ) {
					Audit::log( 'item_price_changed', 'item', $id, array( 'price' => $existing->price ), array( 'price' => $row['price'] ), (int) $row['area_id'] );
				}
				if ( $old !== $new ) {
					Audit::log( 'item_updated', 'item', $id, $old, $new, (int) $row['area_id'] );
				}
				return true;
			}
		);
	}

	/**
	 * Compact representation for audit logs.
	 *
	 * @param array $d Data.
	 * @return array
	 */
	private static function audit_view( array $d ) {
		return array(
			'sku'        => isset( $d['sku'] ) ? (string) $d['sku'] : '',
			'name'       => isset( $d['name'] ) ? (string) $d['name'] : '',
			'category'   => isset( $d['category'] ) ? (string) $d['category'] : '',
			'price'      => isset( $d['price'] ) ? Helpers::sql_money( $d['price'] ) : '',
			'is_active'  => isset( $d['is_active'] ) ? (int) $d['is_active'] : 0,
			'sort_order' => isset( $d['sort_order'] ) ? (int) $d['sort_order'] : 0,
			'area_id'    => isset( $d['area_id'] ) ? (int) $d['area_id'] : 0,
		);
	}

	/**
	 * Activate/deactivate an item.
	 *
	 * @param int  $id     Item ID.
	 * @param bool $active Active.
	 * @return true|\WP_Error
	 */
	public static function set_active( $id, $active ) {
		$id     = absint( $id );
		$active = $active ? 1 : 0;
		return Db::tx(
			static function () use ( $id, $active ) {
				$before = self::find( $id );
				if ( ! $before ) {
					return new \WP_Error( 'not_found', __( 'Item not found.', 'bmlt-literature-manager' ) );
				}
				if ( (int) $before['is_active'] === $active ) {
					return true;
				}
				Db::update(
					'items',
					array(
						'is_active'  => $active,
						'updated_at' => Helpers::now_gmt(),
					),
					array( 'id' => $id )
				);
				Audit::log( $active ? 'item_activated' : 'item_deactivated', 'item', $id, array( 'is_active' => (int) $before['is_active'] ), array( 'is_active' => $active ), (int) $before['area_id'] );
				return true;
			}
		);
	}

	/**
	 * Whether any order references the item.
	 *
	 * @param int $id Item ID.
	 * @return bool
	 */
	public static function is_referenced( $id ) {
		return array() !== self::referenced_ids( array( $id ) );
	}

	/**
	 * IDs (from the given list) that appear on at least one order.
	 *
	 * @param int[] $ids Item IDs.
	 * @return int[]
	 */
	public static function referenced_ids( array $ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$t  = Db::table( 'order_items' );
		$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table name; $ph is a list of %d placeholders.
		$found = array_map( 'intval', Db::col( $wpdb->prepare( "SELECT DISTINCT item_id FROM {$t} WHERE item_id IN ({$ph})", $ids ) ) );
		return array_values( array_intersect( $ids, $found ) );
	}

	/**
	 * Permanently delete an item (and its inventory rows) when it has never been ordered.
	 *
	 * @param int $id Item ID.
	 * @return true|\WP_Error
	 */
	public static function delete( $id ) {
		$id = absint( $id );
		return Db::tx(
			static function () use ( $id ) {
				$removed = self::find( $id );
				if ( ! $removed ) {
					return new \WP_Error( 'not_found', __( 'Item not found.', 'bmlt-literature-manager' ) );
				}
				if ( self::is_referenced( $id ) ) {
					return new \WP_Error( 'referenced', __( 'This item appears on historical orders and cannot be deleted. Deactivate it instead.', 'bmlt-literature-manager' ) );
				}
				Db::delete( 'items', array( 'id' => $id ) );
				Db::delete( 'inventory', array( 'item_id' => $id ) ); // Never leave inventory rows pointing at a missing item.
				Audit::log( 'item_deleted', 'item', $id, self::audit_view( $removed ), null, (int) $removed['area_id'] );
				return true;
			}
		);
	}

	/**
	 * Catalog label for an item's area_id.
	 *
	 * @param int $area_id Area.
	 * @return string
	 */
	public static function catalog_label( $area_id ) {
		return $area_id ? Settings::area_name( $area_id ) : __( 'Shared (all Areas)', 'bmlt-literature-manager' );
	}

	/**
	 * Public array representation.
	 *
	 * @param object $item Item.
	 * @return array
	 */
	public static function to_array( $item ) {
		return array(
			'id'                => (int) $item->id,
			'area_id'           => (int) $item->area_id,
			'catalog'           => self::catalog_label( (int) $item->area_id ),
			'sku'               => (string) $item->sku,
			'name'              => (string) $item->name,
			'short_description' => (string) $item->short_description,
			'long_description'  => (string) $item->long_description,
			'category'          => (string) $item->category,
			'price'             => Helpers::sql_money( $item->price ),
			'price_formatted'   => Helpers::format_money( $item->price ),
			'is_active'         => (int) $item->is_active,
			'sort_order'        => (int) $item->sort_order,
			'image_url'         => (string) $item->image_url,
			'reference_url'     => isset( $item->reference_url ) ? (string) $item->reference_url : '',
			'naws_id'           => (string) $item->naws_id,
		);
	}
}
