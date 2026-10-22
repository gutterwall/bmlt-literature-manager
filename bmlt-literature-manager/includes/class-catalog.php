<?php
/**
 * Literature catalog.
 *
 * @package BMLT_Lit
 * @version 6.2.0
 */

namespace BMLT_Lit;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for literature items.
 *
 * Stored in catalog.json.php. Items with area_id = 0 form the shared catalog available to every managed Area.
 * Items with a specific area_id belong only to that Area's catalog, allowing each Area to keep its own
 * catalog and pricing when desired.
 */
class Catalog {

	/**
	 * Store document.
	 */
	const DOC = 'catalog';

	/**
	 * Raw catalog document.
	 *
	 * @return array { next_id: int, items: array }
	 */
	public static function doc() {
		$d = Store::get( self::DOC, array( 'next_id' => 1, 'items' => array() ) );
		if ( ! isset( $d['items'] ) || ! is_array( $d['items'] ) ) {
			$d['items'] = array();
		}
		if ( empty( $d['next_id'] ) ) {
			$d['next_id'] = $d['items'] ? max( array_map( 'intval', array_keys( $d['items'] ) ) ) + 1 : 1;
		}
		return $d;
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
	 * Get one item.
	 *
	 * @param int $id Item ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		$d  = self::doc();
		$id = absint( $id );
		return isset( $d['items'][ $id ] ) ? self::obj( $d['items'][ $id ] ) : null;
	}

	/**
	 * Get several items.
	 *
	 * @param int[] $ids IDs.
	 * @return object[] Keyed by ID.
	 */
	public static function get_many( array $ids ) {
		$d   = self::doc();
		$out = array();
		foreach ( array_unique( array_filter( array_map( 'absint', $ids ) ) ) as $id ) {
			if ( isset( $d['items'][ $id ] ) ) {
				$out[ $id ] = self::obj( $d['items'][ $id ] );
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
		foreach ( self::doc()['items'] as $i ) {
			if ( (int) $i['area_id'] === absint( $area_id ) && (int) $i['id'] !== absint( $exclude_id ) && 0 === strcasecmp( (string) $i['sku'], (string) $sku ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Find an item by SKU in a catalog.
	 *
	 * @param string $sku     SKU.
	 * @param int    $area_id Area.
	 * @return object|null
	 */
	public static function get_by_sku( $sku, $area_id ) {
		foreach ( self::doc()['items'] as $i ) {
			if ( (int) $i['area_id'] === absint( $area_id ) && 0 === strcasecmp( (string) $i['sku'], (string) $sku ) ) {
				return self::obj( $i );
			}
		}
		return null;
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
	 * Create an item.
	 *
	 * @param array $raw Raw input.
	 * @return int|\WP_Error
	 */
	public static function create( array $raw ) {
		$result = Store::tx(
			static function () use ( $raw ) {
				$data = self::sanitize( $raw ); // Runs inside the lock, so SKU uniqueness is race-free.
				if ( is_wp_error( $data ) ) {
					return $data;
				}
				$doc                = self::doc();
				$id                 = (int) $doc['next_id'];
				$now                = Helpers::now_gmt();
				$data['id']         = $id;
				$data['price']      = Helpers::sql_money( $data['price'] );
				$data['created_at'] = $now;
				$data['updated_at'] = $now;
				$doc['items'][ $id ] = $data;
				$doc['next_id']      = $id + 1;
				Store::put( self::DOC, $doc );
				return $id;
			}
		);
		if ( ! is_wp_error( $result ) ) {
			$item = self::get( $result );
			Audit::log( 'item_created', 'item', $result, null, self::audit_view( (array) $item ), (int) $item->area_id );
		}
		return $result;
	}

	/**
	 * Update an item.
	 *
	 * @param int   $id  Item ID.
	 * @param array $raw Raw input.
	 * @return true|\WP_Error
	 */
	public static function update( $id, array $raw ) {
		$id       = absint( $id );
		$existing = null;
		$result   = Store::tx(
			static function () use ( $id, $raw, &$existing ) {
				$doc = self::doc();
				if ( ! isset( $doc['items'][ $id ] ) ) {
					return new \WP_Error( 'not_found', __( 'Item not found.', 'bmlt-literature-manager' ) );
				}
				$existing = self::obj( $doc['items'][ $id ] );
				$data     = self::sanitize( $raw, $existing );
				if ( is_wp_error( $data ) ) {
					return $data;
				}
				$data['price']       = Helpers::sql_money( $data['price'] );
				$data['updated_at']  = Helpers::now_gmt();
				$doc['items'][ $id ] = array_merge( $doc['items'][ $id ], $data );
				Store::put( self::DOC, $doc );
				return true;
			}
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$new_item = self::get( $id );
		$old      = self::audit_view( (array) $existing );
		$new      = self::audit_view( (array) $new_item );
		if ( Helpers::to_cents( $existing->price ) !== Helpers::to_cents( $new_item->price ) ) {
			Audit::log( 'item_price_changed', 'item', $id, array( 'price' => $existing->price ), array( 'price' => $new_item->price ), (int) $new_item->area_id );
		}
		if ( $old !== $new ) {
			Audit::log( 'item_updated', 'item', $id, $old, $new, (int) $new_item->area_id );
		}
		return true;
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
		$before = null;
		$result = Store::tx(
			static function () use ( $id, $active, &$before ) {
				$doc = self::doc();
				if ( ! isset( $doc['items'][ $id ] ) ) {
					return new \WP_Error( 'not_found', __( 'Item not found.', 'bmlt-literature-manager' ) );
				}
				$before = $doc['items'][ $id ];
				if ( (int) $before['is_active'] === $active ) {
					return true;
				}
				$doc['items'][ $id ]['is_active']  = $active;
				$doc['items'][ $id ]['updated_at'] = Helpers::now_gmt();
				Store::put( self::DOC, $doc );
				return true;
			}
		);
		if ( ! is_wp_error( $result ) && $before && (int) $before['is_active'] !== $active ) {
			Audit::log( $active ? 'item_activated' : 'item_deactivated', 'item', $id, array( 'is_active' => (int) $before['is_active'] ), array( 'is_active' => $active ), (int) $before['area_id'] );
		}
		return $result;
	}

	/**
	 * Whether any order references the item.
	 *
	 * @param int $id Item ID.
	 * @return bool
	 */
	public static function is_referenced( $id ) {
		return in_array( absint( $id ), Orders::referenced_item_ids(), true );
	}

	/**
	 * IDs (from the given list) that appear on at least one order.
	 *
	 * @param int[] $ids Item IDs.
	 * @return int[]
	 */
	public static function referenced_ids( array $ids ) {
		return array_values( array_intersect( array_map( 'absint', $ids ), Orders::referenced_item_ids() ) );
	}

	/**
	 * Permanently delete an item (and its inventory rows) when it has never been ordered.
	 *
	 * @param int $id Item ID.
	 * @return true|\WP_Error
	 */
	public static function delete( $id ) {
		$id      = absint( $id );
		$removed = null;
		$result  = Store::tx(
			static function () use ( $id, &$removed ) {
				$doc = self::doc();
				if ( ! isset( $doc['items'][ $id ] ) ) {
					return new \WP_Error( 'not_found', __( 'Item not found.', 'bmlt-literature-manager' ) );
				}
				if ( in_array( $id, Orders::referenced_item_ids( true ), true ) ) {
					return new \WP_Error( 'referenced', __( 'This item appears on historical orders and cannot be deleted. Deactivate it instead.', 'bmlt-literature-manager' ) );
				}
				$removed = $doc['items'][ $id ];
				unset( $doc['items'][ $id ] );
				Store::put( self::DOC, $doc );

				// Never leave inventory rows pointing at a missing item.
				$inv = Store::get( Inventory::DOC, array( 'areas' => array() ) );
				foreach ( isset( $inv['areas'] ) && is_array( $inv['areas'] ) ? array_keys( $inv['areas'] ) : array() as $area ) {
					unset( $inv['areas'][ $area ][ $id ] );
				}
				Store::put( Inventory::DOC, $inv );
				return true;
			}
		);
		if ( ! is_wp_error( $result ) && $removed ) {
			Audit::log( 'item_deleted', 'item', $id, self::audit_view( $removed ), null, (int) $removed['area_id'] );
		}
		return $result;
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
