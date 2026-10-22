<?php
/**
 * Area-specific inventory (the inventory table: one row per Area + item).
 *
 * @package BMLT_Lit
 * @version 7.0.0
 */

namespace BMLT_Lit;

defined( 'ABSPATH' ) || exit;

/**
 * Inventory is always tracked per Area + item.
 *
 * In memory the rows form a document { "areas": { "<area_id>": { "<item_id>": { on_hand, reserved, low, reorder,
 * notes, special, updated_at, updated_by } } } }, the shape backups use.
 */
class Inventory {

	/**
	 * Name of the inventory document in backups.
	 */
	const DOC = 'inventory';

	/**
	 * Request cache: blog id => document.
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
	 * Document row from a table row.
	 *
	 * @param array $r Table row.
	 * @return array
	 */
	private static function from_row( array $r ) {
		return array(
			'on_hand'    => (int) $r['on_hand'],
			'reserved'   => (int) $r['reserved'],
			'low'        => (int) $r['low_stock'],
			'reorder'    => (int) $r['reorder_qty'],
			'notes'      => (string) $r['notes'],
			'special'    => (int) $r['special'],
			'updated_at' => null === $r['updated_at'] ? '' : (string) $r['updated_at'],
			'updated_by' => (int) $r['updated_by'],
		);
	}

	/**
	 * Rows as a document.
	 *
	 * @param array[] $rows Table rows.
	 * @return array
	 */
	private static function to_doc( array $rows ) {
		$doc = array( 'areas' => array() );
		foreach ( $rows as $r ) {
			$doc['areas'][ (int) $r['area_id'] ][ (int) $r['item_id'] ] = self::from_row( $r );
		}
		return $doc;
	}

	/**
	 * Every inventory row (inside a transaction: as the transaction sees them, never cached).
	 *
	 * @return array
	 */
	public static function doc() {
		$blog = get_current_blog_id();
		if ( ! Db::in_tx() && isset( self::$cache[ $blog ] ) ) {
			return self::$cache[ $blog ];
		}
		$t   = Db::table( 'inventory' );
		$doc = self::to_doc( Db::rows( "SELECT * FROM {$t} ORDER BY area_id, item_id" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		if ( ! Db::in_tx() ) {
			self::$cache[ $blog ] = $doc;
		}
		return $doc;
	}

	/**
	 * The rows of one Area for some items, as a document.
	 *
	 * @param int   $area_id  Area.
	 * @param int[] $item_ids Items.
	 * @return array
	 */
	public static function doc_for( $area_id, array $item_ids ) {
		global $wpdb;
		$item_ids = array_values( array_unique( array_filter( array_map( 'absint', $item_ids ) ) ) );
		if ( ! $item_ids ) {
			return array( 'areas' => array() );
		}
		$t    = Db::table( 'inventory' );
		$ph   = implode( ',', array_fill( 0, count( $item_ids ), '%d' ) );
		$args = array_merge( array( absint( $area_id ) ), $item_ids );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table name; $ph is a list of %d placeholders.
		return self::to_doc( Db::rows( $wpdb->prepare( "SELECT * FROM {$t} WHERE area_id = %d AND item_id IN ({$ph})", $args ) ) );
	}

	/**
	 * Save one row (inside a transaction).
	 *
	 * @param int   $area_id Area.
	 * @param int   $item_id Item.
	 * @param array $row     Document row.
	 * @throws Db_Error On failure.
	 */
	public static function put_row( $area_id, $item_id, array $row ) {
		Db::upsert(
			'inventory',
			array(
				'area_id'     => absint( $area_id ),
				'item_id'     => absint( $item_id ),
				'on_hand'     => max( 0, (int) $row['on_hand'] ),
				'reserved'    => max( 0, (int) $row['reserved'] ),
				'low_stock'   => max( 0, (int) $row['low'] ),
				'reorder_qty' => max( 0, (int) $row['reorder'] ),
				'notes'       => isset( $row['notes'] ) ? (string) $row['notes'] : '',
				'special'     => ! empty( $row['special'] ) ? 1 : 0,
				'updated_at'  => ! empty( $row['updated_at'] ) ? (string) $row['updated_at'] : null,
				'updated_by'  => isset( $row['updated_by'] ) ? absint( $row['updated_by'] ) : 0,
			),
			array( 'area_id', 'item_id' )
		);
	}

	/**
	 * Save the rows of $new that differ from $old (both documents; inside a transaction).
	 *
	 * @param array $old Document before.
	 * @param array $new Document after.
	 * @return int Rows saved.
	 * @throws Db_Error On failure.
	 */
	public static function save_changes( array $old, array $new ) {
		$n = 0;
		foreach ( isset( $new['areas'] ) ? $new['areas'] : array() as $area_id => $rows ) {
			foreach ( $rows as $item_id => $row ) {
				if ( self::row( $old, $area_id, $item_id ) !== $row ) {
					self::put_row( $area_id, $item_id, $row );
					++$n;
				}
			}
		}
		return $n;
	}

	/**
	 * One row (or null).
	 *
	 * @param array $doc     Document.
	 * @param int   $area_id Area.
	 * @param int   $item_id Item.
	 * @return array|null
	 */
	public static function row( array $doc, $area_id, $item_id ) {
		return isset( $doc['areas'][ $area_id ][ $item_id ] ) && is_array( $doc['areas'][ $area_id ][ $item_id ] ) ? $doc['areas'][ $area_id ][ $item_id ] : null;
	}

	/**
	 * Compute a stock status.
	 *
	 * @param int  $on_hand  On hand.
	 * @param int  $reserved Reserved.
	 * @param int  $low      Low-stock threshold.
	 * @param bool $active   Item active.
	 * @return string
	 */
	public static function status( $on_hand, $reserved, $low, $active = true ) {
		if ( ! $active ) {
			return 'inactive';
		}
		$available = (int) $on_hand - (int) $reserved;
		if ( $available <= 0 ) {
			return 'special_order';
		}
		if ( (int) $low > 0 && $available <= (int) $low ) {
			return 'low_stock';
		}
		return 'in_stock';
	}

	/**
	 * Status for an item in a specific Area, honouring the Area's tracking setting.
	 *
	 * @param int        $area_id     Area.
	 * @param array|null $row         Inventory row (or null).
	 * @param bool       $active      Item active.
	 * @param int        $default_low Default low-stock threshold.
	 * @return string
	 */
	public static function area_status( $area_id, $row, $active, $default_low ) {
		if ( ! $active ) {
			return 'inactive';
		}
		if ( ! Settings::area_tracks_inventory( $area_id ) ) {
			return ( $row && ! empty( $row['special'] ) ) ? 'special_order' : 'in_stock';
		}
		return self::status( $row ? (int) $row['on_hand'] : 0, $row ? (int) $row['reserved'] : 0, $row ? (int) $row['low'] : (int) $default_low, true );
	}

	/**
	 * Sort rows so special-order items come last (stable, keeps the existing order otherwise).
	 *
	 * @param array    $rows   Rows.
	 * @param callable $is_sp  Returns true when a row is a special order.
	 * @return array
	 */
	public static function special_last( array $rows, callable $is_sp ) {
		$keyed = array();
		foreach ( array_values( $rows ) as $i => $r ) {
			$keyed[] = array( $is_sp( $r ) ? 1 : 0, $i, $r );
		}
		usort(
			$keyed,
			static function ( $a, $b ) {
				return ( $a[0] <=> $b[0] ) ?: ( $a[1] <=> $b[1] );
			}
		);
		return array_map(
			static function ( $k ) {
				return $k[2];
			},
			$keyed
		);
	}

	/**
	 * Stock for several Areas.
	 *
	 * @param int[] $area_ids Areas.
	 * @return array [area_id][item_id] => array(on_hand, reserved, available, low).
	 */
	public static function stock_map( array $area_ids ) {
		$doc = self::doc();
		$map = array();
		foreach ( array_filter( array_map( 'absint', $area_ids ) ) as $area ) {
			foreach ( isset( $doc['areas'][ $area ] ) ? $doc['areas'][ $area ] : array() as $item_id => $r ) {
				$map[ $area ][ (int) $item_id ] = array(
					'on_hand'   => (int) $r['on_hand'],
					'reserved'  => (int) $r['reserved'],
					'available' => max( 0, (int) $r['on_hand'] - (int) $r['reserved'] ),
					'low'       => (int) $r['low'],
					'special'   => ! empty( $r['special'] ) ? 1 : 0,
				);
			}
		}
		return $map;
	}

	/**
	 * All catalog items for an Area joined with that Area's inventory.
	 *
	 * @param int   $area_id Area.
	 * @param array $args    search, category, active (null|0|1).
	 * @return array Rows (arrays).
	 */
	public static function area_inventory( $area_id, array $args = array() ) {
		$args    = wp_parse_args(
			$args,
			array(
				'search'       => '',
				'category'     => '',
				'active'       => null,
				'special_last' => false,
			)
		);
		$area_id = absint( $area_id );
		$items   = Catalog::query(
			array(
				'area_id'  => $area_id,
				'active'   => $args['active'],
				'category' => $args['category'],
				'orderby'  => 'sort_order',
			)
		)['items'];
		$doc     = self::doc();
		$default = max( 0, (int) Settings::get( 'default_low_stock' ) );
		$search  = (string) $args['search'];
		$out     = array();
		foreach ( $items as $it ) {
			if ( '' !== $search && false === stripos( $it->name . ' ' . $it->sku, $search ) ) {
				continue;
			}
			$r         = self::row( $doc, $area_id, (int) $it->id );
			$on_hand   = $r ? (int) $r['on_hand'] : 0;
			$reserved  = $r ? (int) $r['reserved'] : 0;
			$low       = $r ? (int) $r['low'] : $default;
			$out[]     = array(
				'item_id'             => (int) $it->id,
				'item_area_id'        => (int) $it->area_id,
				'sku'                 => (string) $it->sku,
				'name'                => (string) $it->name,
				'category'            => (string) $it->category,
				'price'               => Helpers::sql_money( $it->price ),
				'price_formatted'     => Helpers::format_money( $it->price ),
				'is_active'           => (int) $it->is_active,
				'on_hand'             => $on_hand,
				'reserved'            => $reserved,
				'available'           => max( 0, $on_hand - $reserved ),
				'low_stock_threshold' => $low,
				'reorder_quantity'    => $r ? (int) $r['reorder'] : 0,
				'notes'               => $r ? (string) $r['notes'] : '',
				'updated_at'          => ( $r && $r['updated_at'] ) ? Helpers::local_date( $r['updated_at'] ) : '',
				'updated_by'          => ( $r && $r['updated_by'] ) ? Audit::user_name( (int) $r['updated_by'] ) : '',
				'has_record'          => null !== $r,
				'special'             => ( $r && ! empty( $r['special'] ) ) ? 1 : 0,
				'status'              => self::area_status( $area_id, $r, (bool) (int) $it->is_active, $default ),
			);
		}
		if ( ! empty( $args['special_last'] ) ) {
			$out = self::special_last(
				$out,
				static function ( $r ) {
					return 'special_order' === $r['status'];
				}
			);
		}
		return $out;
	}

	/**
	 * Adjust inventory for one Area + item (one transaction, audit entry included).
	 *
	 * @param int    $area_id Area.
	 * @param int    $item_id Item.
	 * @param array  $changes Keys: set, delta, low_stock_threshold, reorder_quantity, notes.
	 * @param string $reason  Adjustment reason.
	 * @param string $action  Audit action key.
	 * @return array|\WP_Error New row values.
	 */
	public static function adjust( $area_id, $item_id, array $changes, $reason = '', $action = 'inventory_adjusted' ) {
		$area_id = absint( $area_id );
		$item_id = absint( $item_id );
		if ( ! Settings::is_selected_area( $area_id ) ) {
			return new \WP_Error( 'area', __( 'That Area is not managed by this site.', 'bmlt-literature-manager' ) );
		}
		$old    = null;
		$active = true;
		$result = Db::tx(
			static function () use ( $area_id, $item_id, $changes, $reason, $action, &$old, &$active ) {
				$item = Catalog::get( $item_id );
				if ( ! $item ) {
					return new \WP_Error( 'item', __( 'That literature item does not exist.', 'bmlt-literature-manager' ) );
				}
				if ( (int) $item->area_id && (int) $item->area_id !== $area_id ) {
					return new \WP_Error( 'item_area', __( 'That item belongs to a different Area’s catalog.', 'bmlt-literature-manager' ) );
				}
				$active  = (bool) (int) $item->is_active;
				$doc     = self::doc_for( $area_id, array( $item_id ) );
				$current = self::row( $doc, $area_id, $item_id );
				$old     = array(
					'on_hand'             => $current ? (int) $current['on_hand'] : 0,
					'reserved'            => $current ? (int) $current['reserved'] : 0,
					'low_stock_threshold' => $current ? (int) $current['low'] : max( 0, (int) Settings::get( 'default_low_stock' ) ),
					'reorder_quantity'    => $current ? (int) $current['reorder'] : 0,
					'notes'               => $current ? (string) $current['notes'] : '',
					'special'             => ( $current && ! empty( $current['special'] ) ) ? 1 : 0,
				);
				$new     = $old;
				if ( isset( $changes['set'] ) ) {
					$new['on_hand'] = max( 0, min( 1000000, (int) $changes['set'] ) );
				}
				if ( isset( $changes['delta'] ) && 0 !== (int) $changes['delta'] ) {
					$new['on_hand'] = max( 0, min( 1000000, $new['on_hand'] + (int) $changes['delta'] ) );
				}
				if ( isset( $changes['low_stock_threshold'] ) ) {
					$new['low_stock_threshold'] = max( 0, min( 100000, (int) $changes['low_stock_threshold'] ) );
				}
				if ( isset( $changes['reorder_quantity'] ) ) {
					$new['reorder_quantity'] = max( 0, min( 100000, (int) $changes['reorder_quantity'] ) );
				}
				if ( isset( $changes['notes'] ) ) {
					$new['notes'] = Helpers::limit( sanitize_textarea_field( (string) $changes['notes'] ), 2000 );
				}
				if ( isset( $changes['special'] ) ) {
					$new['special'] = $changes['special'] ? 1 : 0;
				}
				if ( $new === $old && $current ) {
					return $new + array( 'changed' => false );
				}
				self::put_row(
					$area_id,
					$item_id,
					array(
						'on_hand'    => $new['on_hand'],
						'reserved'   => $old['reserved'],
						'low'        => $new['low_stock_threshold'],
						'reorder'    => $new['reorder_quantity'],
						'notes'      => $new['notes'],
						'special'    => $new['special'],
						'updated_at' => Helpers::now_gmt(),
						'updated_by' => get_current_user_id(),
					)
				);
				Audit::log( $action, 'inventory', $item_id, $old, $new, $area_id, Helpers::limit( sanitize_text_field( (string) $reason ), 500 ) );
				return $new + array( 'changed' => true );
			}
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result['available'] = max( 0, $result['on_hand'] - $result['reserved'] );
		$result['status']    = self::area_status(
			$area_id,
			array(
				'on_hand'  => $result['on_hand'],
				'reserved' => $result['reserved'],
				'low'      => $result['low_stock_threshold'],
				'special'  => $result['special'],
			),
			$active,
			$result['low_stock_threshold']
		);
		return $result;
	}

	/**
	 * Low/out-of-stock counts for active items in the given Areas.
	 *
	 * @param int[] $area_ids Areas.
	 * @return array { low: int, out: int, low_items: array }
	 */
	public static function stock_alerts( array $area_ids ) {
		$out = array(
			'low'       => 0,
			'out'       => 0,
			'low_items' => array(),
		);
		foreach ( $area_ids as $area_id ) {
			if ( ! Settings::area_tracks_inventory( $area_id ) ) {
				continue; // Special-order flags in untracked Areas are intentional, not stock problems.
			}
			foreach ( self::area_inventory( $area_id, array( 'active' => 1 ) ) as $row ) {
				if ( 'low_stock' === $row['status'] ) {
					++$out['low'];
				} elseif ( 'special_order' === $row['status'] ) {
					++$out['out'];
				} else {
					continue;
				}
				if ( count( $out['low_items'] ) < 25 ) {
					$out['low_items'][] = array(
						'area'      => Settings::area_name( $area_id ),
						'sku'       => $row['sku'],
						'name'      => $row['name'],
						'available' => $row['available'],
						'status'    => $row['status'],
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Count inventory records.
	 *
	 * @return int
	 */
	public static function count_records() {
		$t = Db::table( 'inventory' );
		return (int) Db::var( "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
	}
}
