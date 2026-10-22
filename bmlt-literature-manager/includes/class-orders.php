<?php
/**
 * Orders (the orders, order_items and order_notes tables).
 *
 * Every change to an order, its lines, its history and the inventory it affects happens in one transaction.
 *
 * @package BMLT_Lit
 * @version 7.0.0
 */

namespace BMLT_Lit;

defined( 'ABSPATH' ) || exit;

/**
 * Order creation, retrieval, status workflow and inventory effects.
 */
class Orders {

	/**
	 * Name prefix of the order documents in backups ("orders/YYYY-MM").
	 */
	const DOC_PREFIX = 'orders/';

	/**
	 * Header columns (everything but the lines and the history).
	 */
	const COLUMNS = array( 'id', 'order_number', 'access_key', 'submission_hash', 'area_id', 'area_name', 'meeting_id', 'meeting_name', 'meeting_day', 'meeting_time', 'meeting_location', 'contact_name', 'contact_phone', 'contact_email', 'notes', 'subtotal', 'total', 'item_count', 'status', 'has_special_order', 'inventory_mode', 'inventory_state', 'ip_hash', 'created_by', 'created_at', 'updated_at', 'updated_by', 'fulfilled_at', 'fulfilled_by' );

	/**
	 * Line columns.
	 */
	const ITEM_COLUMNS = array( 'item_id', 'sku', 'name', 'description', 'quantity', 'quantity_in_stock', 'quantity_special', 'quantity_reserved', 'unit_price', 'line_total', 'stock_status', 'is_special_order' );

	/**
	 * Backup document name for a UTC datetime.
	 *
	 * @param string $created_at UTC datetime.
	 * @return string
	 */
	public static function month_doc( $created_at ) {
		return self::DOC_PREFIX . substr( (string) $created_at, 0, 7 );
	}

	/**
	 * Header row of an order (or null).
	 *
	 * @param int $id Order ID.
	 * @return array|null
	 */
	private static function header_row( $id ) {
		global $wpdb;
		$t = Db::table( 'orders' );
		return Db::row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", absint( $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
	}

	/**
	 * Header object (without items/history) from a row.
	 *
	 * @param array $rec Row.
	 * @return object
	 */
	private static function header_obj( array $rec ) {
		unset( $rec['items'], $rec['notes_log'] );
		foreach ( array( 'id', 'area_id', 'meeting_id', 'meeting_day', 'item_count', 'has_special_order', 'created_by', 'updated_by', 'fulfilled_by' ) as $k ) {
			$rec[ $k ] = isset( $rec[ $k ] ) ? (int) $rec[ $k ] : 0;
		}
		$rec['submission_hash'] = isset( $rec['submission_hash'] ) ? (string) $rec['submission_hash'] : '';
		if ( empty( $rec['fulfilled_at'] ) ) {
			$rec['fulfilled_at'] = null;
		}
		return (object) $rec;
	}

	/**
	 * Get one order (full header).
	 *
	 * @param int $id Order ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		$rec = self::header_row( $id );
		return $rec ? self::header_obj( $rec ) : null;
	}

	/**
	 * Get by submission hash (duplicate protection).
	 *
	 * @param string $hash Hash.
	 * @return object|null
	 */
	public static function get_by_submission_hash( $hash ) {
		global $wpdb;
		if ( '' === (string) $hash ) {
			return null;
		}
		$t  = Db::table( 'orders' );
		$id = Db::var( $wpdb->prepare( "SELECT id FROM {$t} WHERE submission_hash = %s", (string) $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		return null === $id ? null : self::get( (int) $id );
	}

	/**
	 * Requester confirmation lookup: requires order number AND secret access key.
	 *
	 * @param string $number Order number.
	 * @param string $key    Access key.
	 * @return object|null
	 */
	public static function get_by_access( $number, $key ) {
		global $wpdb;
		$number = sanitize_text_field( (string) $number );
		$key    = preg_replace( '/[^A-Za-z0-9]/', '', (string) $key );
		if ( '' === $number || strlen( $key ) < 20 ) {
			return null;
		}
		$t     = Db::table( 'orders' );
		$found = Db::var( $wpdb->prepare( "SELECT id FROM {$t} WHERE order_number = %s ORDER BY id LIMIT 1", $number ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		$order = null === $found ? null : self::get( (int) $found );
		if ( ! $order || ! hash_equals( (string) $order->access_key, $key ) ) {
			return null;
		}
		if ( strtotime( $order->created_at . ' UTC' ) < time() - 30 * DAY_IN_SECONDS ) {
			return null;
		}
		return $order;
	}

	/**
	 * Lines of an order as arrays, in their stored order.
	 *
	 * @param int $order_id Order.
	 * @return array[]
	 */
	private static function item_rows( $order_id ) {
		global $wpdb;
		$t   = Db::table( 'order_items' );
		$out = array();
		foreach ( Db::rows( $wpdb->prepare( "SELECT * FROM {$t} WHERE order_id = %d ORDER BY line_no", absint( $order_id ) ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
			foreach ( array( 'item_id', 'quantity', 'quantity_in_stock', 'quantity_special', 'quantity_reserved', 'is_special_order' ) as $k ) {
				$r[ $k ] = (int) $r[ $k ];
			}
			$line = array();
			foreach ( self::ITEM_COLUMNS as $k ) {
				$line[ $k ] = $r[ $k ];
			}
			$out[] = $line;
		}
		return $out;
	}

	/**
	 * History of an order as arrays, oldest first.
	 *
	 * @param int $order_id Order.
	 * @return array[]
	 */
	private static function note_rows( $order_id ) {
		global $wpdb;
		$t   = Db::table( 'order_notes' );
		$out = array();
		foreach ( Db::rows( $wpdb->prepare( "SELECT user_id, note_type, old_status, new_status, note, created_at FROM {$t} WHERE order_id = %d ORDER BY id", absint( $order_id ) ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
			$r['user_id'] = (int) $r['user_id'];
			$out[]        = $r;
		}
		return $out;
	}

	/**
	 * Items for an order.
	 *
	 * @param int $order_id Order.
	 * @return object[]
	 */
	public static function get_items( $order_id ) {
		$out = array();
		foreach ( self::item_rows( $order_id ) as $i => $it ) {
			$it['id']       = $i + 1;
			$it['order_id'] = absint( $order_id );
			$out[]          = (object) $it;
		}
		return self::special_last_items( $out );
	}

	/**
	 * Status history / notes, newest first.
	 *
	 * @param int $order_id Order.
	 * @return object[]
	 */
	public static function get_notes( $order_id ) {
		$out = array();
		foreach ( array_reverse( self::note_rows( $order_id ), true ) as $i => $n ) {
			$n['id'] = $i + 1;
			$out[]   = (object) $n;
		}
		return $out;
	}

	/**
	 * Build a note entry.
	 *
	 * @param string $type       note|status|email|system.
	 * @param string $note       Text.
	 * @param string $old_status Old status.
	 * @param string $new_status New status.
	 * @return array
	 */
	private static function note_entry( $type, $note, $old_status = '', $new_status = '' ) {
		return array(
			'user_id'    => get_current_user_id(),
			'note_type'  => in_array( $type, array( 'note', 'status', 'email', 'system' ), true ) ? $type : 'note',
			'old_status' => sanitize_key( $old_status ),
			'new_status' => sanitize_key( $new_status ),
			'note'       => Helpers::limit( sanitize_textarea_field( (string) $note ), 5000 ),
			'created_at' => Helpers::now_gmt(),
		);
	}

	/**
	 * Add a note / history entry.
	 *
	 * @param int    $order_id   Order.
	 * @param string $type       Type.
	 * @param string $note       Text.
	 * @param string $old_status Old status.
	 * @param string $new_status New status.
	 * @return true|\WP_Error
	 */
	public static function add_note( $order_id, $type, $note, $old_status = '', $new_status = '' ) {
		$order_id = absint( $order_id );
		$entry    = self::note_entry( $type, $note, $old_status, $new_status );
		return Db::tx(
			static function () use ( $order_id, $entry ) {
				if ( ! self::header_row( $order_id ) ) {
					return new \WP_Error( 'not_found', __( 'Order not found.', 'bmlt-literature-manager' ) );
				}
				Db::insert( 'order_notes', array( 'order_id' => $order_id ) + $entry );
				return true;
			}
		);
	}

	/**
	 * Generate a readable order number from the order ID.
	 *
	 * @param int $id Order ID.
	 * @return string
	 */
	public static function format_number( $id ) {
		$prefix = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) Settings::get( 'order_prefix' ) ) );
		$prefix = '' === $prefix ? 'LIT' : substr( $prefix, 0, 10 );
		return $prefix . '-' . wp_date( 'Y' ) . '-' . str_pad( (string) absint( $id ), 6, '0', STR_PAD_LEFT );
	}

	/**
	 * Price every line and decide stock / special-order status for an Area — the single source of truth
	 * used by both the review step (preview) and order creation.
	 *
	 * @param int   $area_id  Area.
	 * @param array $lines    item_id => quantity.
	 * @param array $catalog  Catalog document.
	 * @param array $inv      Inventory document.
	 * @param array $settings General settings.
	 * @return array|\WP_Error { items, sub_cents, count, has_special, mode, inventory (updated document) }
	 */
	private static function compute( $area_id, array $lines, array $catalog, array $inv, array $settings ) {
		foreach ( array_keys( $lines ) as $item_id ) {
			$item = isset( $catalog['items'][ $item_id ] ) ? $catalog['items'][ $item_id ] : null;
			if ( ! $item || 1 !== (int) $item['is_active'] || ( (int) $item['area_id'] && (int) $item['area_id'] !== $area_id ) ) {
				return new \WP_Error( 'invalid_item', __( 'One or more selected literature items are no longer available for this Area. Please review your order.', 'bmlt-literature-manager' ) );
			}
		}
		$tracked = Settings::area_tracks_inventory( $area_id );
		$mode    = $tracked ? ( in_array( $settings['reservation_mode'], array( 'reserve', 'deduct', 'none' ), true ) ? $settings['reservation_mode'] : 'reserve' ) : 'untracked';
		$excess  = 'whole_line' === $settings['excess_behavior'] ? 'whole_line' : 'split';

		$items       = array();
		$sub_cents   = 0;
		$count       = 0;
		$has_special = false;
		foreach ( $lines as $item_id => $qty ) {
			$item = $catalog['items'][ $item_id ];
			$row  = Inventory::row( $inv, $area_id, $item_id );
			$take = 0;
			if ( ! $tracked ) {
				// No quantities: the Area marks items as special order or not.
				$flag     = $row && ! empty( $row['special'] );
				$in_stock = $flag ? 0 : $qty;
				$special  = $flag ? $qty : 0;
				$status   = $flag ? 'special_order' : 'in_stock';
			} else {
				$on_hand   = $row ? (int) $row['on_hand'] : 0;
				$reserved  = $row ? (int) $row['reserved'] : 0;
				$low       = $row ? (int) $row['low'] : (int) $settings['default_low_stock'];
				$available = max( 0, $on_hand - $reserved );
				$in_stock  = min( $qty, $available );
				$special   = $qty - $in_stock;
				if ( $special > 0 && 'whole_line' === $excess ) {
					$in_stock = 0;
					$special  = $qty;
				}
				$status = Inventory::status( $on_hand, $reserved, $low, true );
				if ( $special > 0 ) {
					$status = $in_stock > 0 ? 'partial' : 'special_order';
				}
				$take = 'none' === $mode ? 0 : $in_stock;
				if ( $take > 0 && $row ) {
					if ( 'reserve' === $mode ) {
						$inv['areas'][ $area_id ][ $item_id ]['reserved'] = $reserved + $take;
					} else {
						$inv['areas'][ $area_id ][ $item_id ]['on_hand'] = max( 0, $on_hand - $take );
					}
				}
			}
			if ( $special > 0 ) {
				$has_special = true;
			}
			$price_cents = Helpers::to_cents( $item['price'] );
			$line_cents  = $price_cents * $qty;
			$sub_cents  += $line_cents;
			$count      += $qty;
			$items[]     = array(
				'item_id'           => (int) $item_id,
				'sku'               => Helpers::limit( (string) $item['sku'], 64 ),
				'name'              => Helpers::limit( (string) $item['name'], 255 ),
				'description'       => Helpers::limit( (string) $item['short_description'], 1000 ),
				'quantity'          => $qty,
				'quantity_in_stock' => $in_stock,
				'quantity_special'  => $special,
				'quantity_reserved' => $take,
				'unit_price'        => Helpers::sql_money( $price_cents / 100 ),
				'line_total'        => Helpers::sql_money( $line_cents / 100 ),
				'stock_status'      => $status,
				'is_special_order'  => $special > 0 ? 1 : 0,
			);
		}
		return array(
			'items'       => self::special_last_items( $items ),
			'sub_cents'   => $sub_cents,
			'count'       => $count,
			'has_special' => $has_special,
			'mode'        => $mode,
			'inventory'   => $inv,
		);
	}

	/**
	 * Order lines with special-order lines last (stable).
	 *
	 * @param array $items Lines (arrays or objects).
	 * @return array
	 */
	public static function special_last_items( array $items ) {
		return Inventory::special_last(
			$items,
			static function ( $i ) {
				$i = (array) $i;
				return (int) $i['quantity_special'] > 0;
			}
		);
	}

	/**
	 * Preview an order for the review step: same pricing and availability as create(), nothing saved.
	 *
	 * @param array $data area_id, lines.
	 * @return array|\WP_Error { items (objects), subtotal, total, item_count, has_special }
	 */
	public static function preview( array $data ) {
		$lines = array();
		foreach ( (array) $data['lines'] as $item_id => $qty ) {
			if ( absint( $item_id ) && (int) $qty > 0 ) {
				$lines[ absint( $item_id ) ] = (int) $qty;
			}
		}
		if ( empty( $lines ) ) {
			return new \WP_Error( 'empty', __( 'Please choose at least one literature item.', 'bmlt-literature-manager' ) );
		}
		$area_id = absint( $data['area_id'] );
		$calc    = self::compute( $area_id, $lines, array( 'items' => Catalog::rows_for( array_keys( $lines ) ) ), Inventory::doc_for( $area_id, array_keys( $lines ) ), Settings::general() );
		if ( is_wp_error( $calc ) ) {
			return $calc;
		}
		return array(
			'items'       => array_map(
				static function ( $i ) {
					return (object) $i;
				},
				$calc['items']
			),
			'subtotal'    => Helpers::sql_money( $calc['sub_cents'] / 100 ),
			'total'       => Helpers::sql_money( $calc['sub_cents'] / 100 ),
			'item_count'  => $calc['count'],
			'has_special' => $calc['has_special'],
		);
	}

	/**
	 * Create an order. All pricing and stock decisions are made here, inside the store lock.
	 *
	 * @param array $data Validated data: area_id, meeting (array), contact_name, contact_phone, contact_email, notes,
	 *                    lines (item_id => qty), submission_hash, ip_hash.
	 * @return array|\WP_Error { order_id: int, duplicate: bool }
	 */
	public static function create( array $data ) {
		$area_id = absint( $data['area_id'] );
		$lines   = array();
		foreach ( (array) $data['lines'] as $item_id => $qty ) {
			$item_id = absint( $item_id );
			$qty     = (int) $qty;
			if ( $item_id && $qty > 0 ) {
				$lines[ $item_id ] = $qty;
			}
		}
		if ( empty( $lines ) ) {
			return new \WP_Error( 'empty', __( 'Please choose at least one literature item.', 'bmlt-literature-manager' ) );
		}
		$hash = (string) $data['submission_hash'];

		$result = Db::tx(
			static function () use ( $data, $area_id, $lines, $hash ) {
				$existing = '' !== $hash ? self::get_by_submission_hash( $hash ) : null;
				if ( $existing ) {
					return array(
						'order_id'  => (int) $existing->id,
						'duplicate' => true,
					);
				}

				$inv_before = Inventory::doc_for( $area_id, array_keys( $lines ) );
				$calc       = self::compute( $area_id, $lines, array( 'items' => Catalog::rows_for( array_keys( $lines ) ) ), $inv_before, Settings::general() );
				if ( is_wp_error( $calc ) ) {
					return $calc;
				}
				$mode    = $calc['mode'];
				$id      = Db::next_id( 'order', 'orders' );
				$meeting = (array) $data['meeting'];
				$now     = Helpers::now_gmt();
				$rec     = array(
					'id'                => $id,
					'order_number'      => self::format_number( $id ),
					'access_key'        => wp_generate_password( 32, false, false ),
					'submission_hash'   => '' !== $hash ? $hash : null,
					'area_id'           => $area_id,
					'area_name'         => Helpers::limit( Settings::area_name( $area_id ), 255 ),
					'meeting_id'        => absint( $meeting['id'] ),
					'meeting_name'      => Helpers::limit( (string) $meeting['name'], 255 ),
					'meeting_day'       => absint( $meeting['weekday'] ),
					'meeting_time'      => Helpers::limit( (string) $meeting['start_time'], 10 ),
					'meeting_location'  => Helpers::limit( trim( (string) $meeting['location'] . ( $meeting['municipality'] ? ', ' . $meeting['municipality'] : '' ), ', ' ), 255 ),
					'contact_name'      => Helpers::limit( (string) $data['contact_name'], 200 ),
					'contact_phone'     => Helpers::limit( (string) $data['contact_phone'], 50 ),
					'contact_email'     => Helpers::limit( (string) $data['contact_email'], 200 ),
					'notes'             => Helpers::limit( (string) $data['notes'], 5000 ),
					'subtotal'          => Helpers::sql_money( $calc['sub_cents'] / 100 ),
					'total'             => Helpers::sql_money( $calc['sub_cents'] / 100 ),
					'item_count'        => $calc['count'],
					'status'            => 'new',
					'has_special_order' => $calc['has_special'] ? 1 : 0,
					'inventory_mode'    => $mode,
					'inventory_state'   => 'untracked' === $mode ? 'untracked' : ( 'none' === $mode ? 'none' : ( 'reserve' === $mode ? 'reserved' : 'deducted' ) ),
					'ip_hash'           => (string) $data['ip_hash'],
					'created_by'        => get_current_user_id(),
					'created_at'        => $now,
					'updated_at'        => $now,
					'updated_by'        => 0,
					'fulfilled_at'      => null,
					'fulfilled_by'      => 0,
				);
				self::insert_record( $rec, $calc['items'], array( self::note_entry( 'system', __( 'Order submitted.', 'bmlt-literature-manager' ), '', 'new' ) ) );
				Inventory::save_changes( $inv_before, $calc['inventory'] );
				return array(
					'order_id'  => $id,
					'duplicate' => false,
				);
			}
		);

		if ( ! is_wp_error( $result ) && ! $result['duplicate'] ) {
			/**
			 * Fires after an order is created.
			 *
			 * @param int $order_id Order ID.
			 */
			do_action( 'bmlt_lit_order_created', $result['order_id'] );
		}
		return $result;
	}

	/**
	 * Change an order's status, applying inventory effects atomically. Cancelled and Fulfilled are final.
	 *
	 * @param int    $order_id Order.
	 * @param string $status   New status.
	 * @param string $note     Optional note.
	 * @return true|\WP_Error
	 */
	public static function update_status( $order_id, $status, $note = '' ) {
		$statuses = Helpers::order_statuses();
		if ( ! isset( $statuses[ $status ] ) ) {
			return new \WP_Error( 'status', __( 'Invalid order status.', 'bmlt-literature-manager' ) );
		}
		$order_id = absint( $order_id );
		$old      = '';
		$area_id  = 0;
		$effects  = array();

		$result = Db::tx(
			static function () use ( $order_id, $status, $note, &$old, &$area_id, &$effects ) {
				$rec = self::header_row( $order_id );
				if ( ! $rec ) {
					return new \WP_Error( 'not_found', __( 'Order not found.', 'bmlt-literature-manager' ) );
				}
				$old     = (string) $rec['status'];
				$area_id = (int) $rec['area_id'];
				if ( $old === $status ) {
					return new \WP_Error( 'same', __( 'The order already has that status.', 'bmlt-literature-manager' ) );
				}
				if ( in_array( $old, array( 'cancelled', 'fulfilled' ), true ) ) {
					return new \WP_Error( 'final', __( 'Fulfilled and cancelled orders are final and cannot be changed. Submit a new order if needed.', 'bmlt-literature-manager' ) );
				}

				$items      = self::item_rows( $order_id );
				$inv_before = Inventory::doc_for( $area_id, wp_list_pluck( $items, 'item_id' ) );
				$inv        = $inv_before;
				$state      = (string) $rec['inventory_state'];
				foreach ( $items as $it ) {
					$item_id = (int) $it['item_id'];
					$qty_res = (int) $it['quantity_reserved'];
					$qty_stk = (int) $it['quantity_in_stock'];
					$row     = Inventory::row( $inv, $area_id, $item_id );
					if ( ! $row ) {
						continue;
					}
					$ref = &$inv['areas'][ $area_id ][ $item_id ];
					if ( 'fulfilled' === $status ) {
						if ( 'reserved' === $state && $qty_res > 0 ) {
							$ref['on_hand']  = max( 0, (int) $ref['on_hand'] - $qty_res );
							$ref['reserved'] = max( 0, (int) $ref['reserved'] - $qty_res );
							$effects[]       = sprintf( '%s: -%d', $it['sku'], $qty_res );
						} elseif ( 'none' === $state && $qty_stk > 0 ) {
							$ref['on_hand'] = max( 0, (int) $ref['on_hand'] - $qty_stk );
							$effects[]      = sprintf( '%s: -%d', $it['sku'], $qty_stk );
						}
					} elseif ( 'cancelled' === $status ) {
						if ( 'reserved' === $state && $qty_res > 0 ) {
							$ref['reserved'] = max( 0, (int) $ref['reserved'] - $qty_res );
							$effects[]       = sprintf( '%s: released %d', $it['sku'], $qty_res );
						} elseif ( 'deducted' === $state && $qty_res > 0 ) {
							$ref['on_hand'] = (int) $ref['on_hand'] + $qty_res;
							$effects[]      = sprintf( '%s: +%d', $it['sku'], $qty_res );
						}
					}
					unset( $ref );
				}

				$now     = Helpers::now_gmt();
				$changes = array(
					'status'     => $status,
					'updated_at' => $now,
					'updated_by' => get_current_user_id(),
				);
				if ( 'fulfilled' === $status ) {
					$changes['inventory_state'] = 'deducted';
					$changes['fulfilled_at']    = $now;
					$changes['fulfilled_by']    = get_current_user_id();
				} elseif ( 'cancelled' === $status ) {
					$changes['inventory_state'] = 'released';
				}
				$text = sprintf(
					/* translators: 1: old status, 2: new status. */
					__( 'Status changed from %1$s to %2$s.', 'bmlt-literature-manager' ),
					Helpers::order_status_label( $old ),
					Helpers::order_status_label( $status )
				);
				if ( $effects ) {
					$text .= ' ' . __( 'Inventory:', 'bmlt-literature-manager' ) . ' ' . implode( ', ', $effects ) . '.';
				}
				if ( '' !== trim( (string) $note ) ) {
					$text .= "\n" . $note;
				}

				Db::update( 'orders', $changes, array( 'id' => $order_id ) );
				Db::insert( 'order_notes', array( 'order_id' => $order_id ) + self::note_entry( 'status', $text, $old, $status ) );
				Inventory::save_changes( $inv_before, $inv );
				Audit::log(
					'order_status_changed',
					'order',
					$order_id,
					array( 'status' => $old ),
					array(
						'status'    => $status,
						'inventory' => $effects,
					),
					$area_id
				);
				return true;
			}
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		/**
		 * Fires after an order status changes.
		 *
		 * @param int    $order_id   Order ID.
		 * @param string $old_status Old status.
		 * @param string $status     New status.
		 */
		do_action( 'bmlt_lit_order_status_changed', $order_id, $old, $status );
		return true;
	}

	/**
	 * Query orders from the index.
	 *
	 * @param array $args Arguments.
	 * @return array { orders: object[], total: int }
	 */
	public static function query( array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'area_ids'      => array(),
				'area_id'       => 0,
				'status'        => '',
				'special'       => '',
				'search'        => '',
				'contact'       => '',
				'email'         => '',
				'order_number'  => '',
				'meeting'       => '',
				'date_from_gmt' => '',
				'date_to_gmt'   => '',
				'sort'          => 'newest',
				'limit'         => 20,
				'offset'        => 0,
				'count'         => true,
			)
		);

		$area_ids = array_values( array_filter( array_map( 'absint', (array) $args['area_ids'] ) ) );
		if ( $args['area_id'] ) {
			$area_ids = in_array( absint( $args['area_id'] ), $area_ids, true ) ? array( absint( $args['area_id'] ) ) : array();
		}
		if ( empty( $area_ids ) ) {
			return array(
				'orders' => array(),
				'total'  => 0,
			);
		}

		global $wpdb;
		$statuses = Helpers::order_statuses();
		$status   = (string) $args['status'];
		$special  = (string) $args['special'];
		$meeting  = (string) $args['meeting'];
		$where    = array( 'area_id IN (' . implode( ',', array_fill( 0, count( $area_ids ), '%d' ) ) . ')' );
		$params   = $area_ids;
		$like     = static function ( $v ) use ( $wpdb ) {
			return '%' . $wpdb->esc_like( (string) $v ) . '%';
		};

		if ( 'open' === $status ) {
			$open    = Helpers::open_statuses();
			$where[] = 'status IN (' . implode( ',', array_fill( 0, count( $open ), '%s' ) ) . ')';
			$params  = array_merge( $params, $open );
		} elseif ( '' !== $status && isset( $statuses[ $status ] ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}
		if ( '1' === $special || '0' === $special ) {
			$where[]  = 'has_special_order = %d';
			$params[] = (int) $special;
		}
		if ( '' !== $args['date_from_gmt'] ) {
			$where[]  = 'created_at >= %s';
			$params[] = (string) $args['date_from_gmt'];
		}
		if ( '' !== $args['date_to_gmt'] ) {
			$where[]  = 'created_at < %s';
			$params[] = (string) $args['date_to_gmt'];
		}
		if ( '' !== $args['search'] ) {
			$where[] = '(order_number LIKE %s OR contact_name LIKE %s OR contact_email LIKE %s OR contact_phone LIKE %s OR meeting_name LIKE %s)';
			$params  = array_merge( $params, array_fill( 0, 5, $like( $args['search'] ) ) );
		}
		foreach ( array( 'contact' => 'contact_name', 'email' => 'contact_email', 'order_number' => 'order_number' ) as $arg => $col ) {
			if ( '' !== (string) $args[ $arg ] ) {
				$where[]  = "{$col} LIKE %s";
				$params[] = $like( $args[ $arg ] );
			}
		}
		if ( '' !== $meeting ) {
			if ( ctype_digit( $meeting ) ) {
				$where[]  = 'meeting_id = %d';
				$params[] = (int) $meeting;
			} else {
				$where[]  = 'meeting_name LIKE %s';
				$params[] = $like( $meeting );
			}
		}

		$orders = array(
			'oldest'  => 'created_at ASC, id ASC',
			'meeting' => 'meeting_name ASC, created_at DESC, id DESC',
			'area'    => 'area_name ASC, created_at DESC, id DESC',
			'status'  => 'status ASC, created_at DESC, id DESC',
			'total'   => 'total DESC, created_at DESC, id DESC',
			'number'  => 'id DESC',
			'contact' => 'contact_name ASC, created_at DESC, id DESC',
		);
		$order_by = isset( $orders[ (string) $args['sort'] ] ) ? $orders[ (string) $args['sort'] ] : 'created_at DESC, id DESC';
		$t        = Db::table( 'orders' );
		$where    = implode( ' AND ', $where );
		$total    = (int) Db::var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE {$where}", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table name; placeholders built above.
		$page     = array_merge( $params, array( max( 1, min( 1000, (int) $args['limit'] ) ), max( 0, (int) $args['offset'] ) ) );
		$rows     = Db::rows( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$where} ORDER BY {$order_by} LIMIT %d OFFSET %d", $page ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table name, placeholders and a fixed ORDER BY built above.
		return array(
			'orders' => array_map( array( __CLASS__, 'header_obj' ), $rows ),
			'total'  => $total,
		);
	}

	/**
	 * Dashboard counts for a set of Areas.
	 *
	 * @param int[] $area_ids Areas.
	 * @return array
	 */
	public static function dashboard_counts( array $area_ids ) {
		$area_ids = array_values( array_filter( array_map( 'absint', $area_ids ) ) );
		$out      = array(
			'new'       => 0,
			'special'   => 0,
			'open'      => 0,
			'ready'     => 0,
			'last_7'    => 0,
			'last_30'   => 0,
			'last_45'   => 0,
			'total_all' => 0,
		);
		if ( empty( $area_ids ) ) {
			return $out;
		}
		global $wpdb;
		$open   = Helpers::open_statuses();
		$now    = time();
		$t      = Db::table( 'orders' );
		$o_ph   = implode( ',', array_fill( 0, count( $open ), '%s' ) );
		$a_ph   = implode( ',', array_fill( 0, count( $area_ids ), '%d' ) );
		$params = array_merge(
			$open,
			$open,
			array(
				gmdate( 'Y-m-d H:i:s', $now - 7 * DAY_IN_SECONDS ),
				gmdate( 'Y-m-d H:i:s', $now - 30 * DAY_IN_SECONDS ),
				gmdate( 'Y-m-d H:i:s', $now - 45 * DAY_IN_SECONDS ),
			),
			$area_ids
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table name; placeholder lists built above.
		$r = Db::row( $wpdb->prepare( "SELECT COUNT(*) AS total_all, SUM(status = 'new') AS new_count, SUM(status = 'ready') AS ready, SUM(status IN ({$o_ph})) AS open_count, SUM(status IN ({$o_ph}) AND has_special_order = 1) AS special, SUM(created_at >= %s) AS last_7, SUM(created_at >= %s) AS last_30, SUM(created_at >= %s) AS last_45 FROM {$t} WHERE area_id IN ({$a_ph})", $params ) );
		if ( $r ) {
			$out = array(
				'new'       => (int) $r['new_count'],
				'special'   => (int) $r['special'],
				'open'      => (int) $r['open_count'],
				'ready'     => (int) $r['ready'],
				'last_7'    => (int) $r['last_7'],
				'last_30'   => (int) $r['last_30'],
				'last_45'   => (int) $r['last_45'],
				'total_all' => (int) $r['total_all'],
			);
		}
		return $out;
	}

	/**
	 * Total number of orders.
	 *
	 * @return int
	 */
	public static function count_all() {
		$t = Db::table( 'orders' );
		return (int) Db::var( "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
	}

	/**
	 * Summary array for list views (staff only).
	 *
	 * @param object $o Order.
	 * @return array
	 */
	public static function summary( $o ) {
		return array(
			'id'            => (int) $o->id,
			'order_number'  => (string) $o->order_number,
			'date'          => Helpers::local_date( $o->created_at ),
			'date_iso'      => mysql2date( 'c', $o->created_at . ' +0000', false ),
			'area_id'       => (int) $o->area_id,
			'area_name'     => (string) $o->area_name,
			'meeting_id'    => (int) $o->meeting_id,
			'meeting_name'  => (string) $o->meeting_name,
			'contact_name'  => (string) $o->contact_name,
			'contact_email' => (string) $o->contact_email,
			'contact_phone' => (string) $o->contact_phone,
			'item_count'    => (int) $o->item_count,
			'total'         => Helpers::format_money( $o->total ),
			'status'        => (string) $o->status,
			'status_label'  => Helpers::order_status_label( $o->status ),
			'has_special'   => (int) $o->has_special_order,
		);
	}

	/**
	 * Full detail array (staff only).
	 *
	 * @param object $o Order.
	 * @return array
	 */
	public static function detail( $o ) {
		$out             = self::summary( $o );
		$out['notes']    = (string) $o->notes;
		$out['subtotal'] = Helpers::format_money( $o->subtotal );
		$out['meeting_when']     = Helpers::meeting_when( (int) $o->meeting_day, (string) $o->meeting_time );
		$out['meeting_location'] = (string) $o->meeting_location;
		$out['updated']          = Helpers::local_date( $o->updated_at );
		$out['fulfilled']        = $o->fulfilled_at ? Helpers::local_date( $o->fulfilled_at ) : '';
		$out['fulfilled_by']     = $o->fulfilled_by ? Audit::user_name( (int) $o->fulfilled_by ) : '';
		$out['inventory_mode']   = (string) $o->inventory_mode;
		$out['is_final']         = in_array( $o->status, array( 'cancelled', 'fulfilled' ), true );
		$out['items']            = array();
		$stock                   = Helpers::stock_statuses();
		foreach ( self::get_items( $o->id ) as $it ) {
			$out['items'][] = array(
				'sku'          => (string) $it->sku,
				'name'         => (string) $it->name,
				'quantity'     => (int) $it->quantity,
				'in_stock'     => (int) $it->quantity_in_stock,
				'special'      => (int) $it->quantity_special,
				'unit_price'   => Helpers::format_money( $it->unit_price ),
				'line_total'   => Helpers::format_money( $it->line_total ),
				'stock_status' => (string) $it->stock_status,
				'stock_label'  => isset( $stock[ $it->stock_status ] ) ? $stock[ $it->stock_status ]['label'] : (string) $it->stock_status,
				'is_special'   => (int) $it->is_special_order,
			);
		}
		$out['history'] = array();
		foreach ( self::get_notes( $o->id ) as $n ) {
			$out['history'][] = array(
				'date' => Helpers::local_date( $n->created_at ),
				'user' => Audit::user_name( (int) $n->user_id ),
				'type' => (string) $n->note_type,
				'note' => (string) $n->note,
			);
		}
		return $out;
	}

	/**
	 * Full order objects for an email address (privacy exporter), paged.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page (1-based).
	 * @param int    $per   Per page.
	 * @return object[]
	 */
	public static function for_email( $email, $page, $per ) {
		global $wpdb;
		if ( '' === (string) $email ) {
			return array();
		}
		$t    = Db::table( 'orders' );
		$rows = Db::rows( $wpdb->prepare( "SELECT * FROM {$t} WHERE LOWER(contact_email) = LOWER(%s) ORDER BY id LIMIT %d OFFSET %d", (string) $email, (int) $per, ( max( 1, (int) $page ) - 1 ) * (int) $per ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		return array_map( array( __CLASS__, 'header_obj' ), $rows );
	}

	/**
	 * Anonymize all orders for an email address (privacy eraser). Items and totals are kept for literature
	 * accounting.
	 *
	 * @param string $email Email.
	 * @return int Orders anonymized.
	 */
	public static function anonymize_by_email( $email ) {
		global $wpdb;
		$email = (string) $email;
		if ( '' === $email ) {
			return 0;
		}
		$result = Db::tx(
			static function () use ( $email, $wpdb ) {
				$t = Db::table( 'orders' );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
				return Db::q( $wpdb->prepare( "UPDATE {$t} SET contact_name = %s, contact_phone = '', contact_email = '', notes = '', ip_hash = '' WHERE LOWER(contact_email) = LOWER(%s)", __( '[removed]', 'bmlt-literature-manager' ), $email ) );
			}
		);
		return is_wp_error( $result ) ? 0 : (int) $result;
	}

	/* ------------------------------------------------------------------
	 * Whole records (backup, restore, migration).
	 * ---------------------------------------------------------------- */

	/**
	 * Insert a full order (header, lines, history) inside a transaction.
	 *
	 * @param array   $rec   Header (COLUMNS).
	 * @param array[] $items Lines (ITEM_COLUMNS).
	 * @param array[] $notes History, oldest first.
	 * @throws Db_Error On failure.
	 */
	public static function insert_record( array $rec, array $items, array $notes ) {
		$header = array();
		foreach ( self::COLUMNS as $k ) {
			$header[ $k ] = array_key_exists( $k, $rec ) ? $rec[ $k ] : null;
		}
		$header['submission_hash'] = '' !== (string) $header['submission_hash'] ? $header['submission_hash'] : null;
		$header['fulfilled_at']    = '' !== (string) $header['fulfilled_at'] ? $header['fulfilled_at'] : null;
		Db::insert( 'orders', $header );
		$lines = array();
		foreach ( array_values( $items ) as $i => $it ) {
			$line = array(
				'order_id' => (int) $rec['id'],
				'line_no'  => $i + 1,
			);
			foreach ( self::ITEM_COLUMNS as $k ) {
				$line[ $k ] = isset( $it[ $k ] ) ? $it[ $k ] : '';
			}
			$lines[] = $line;
		}
		Db::insert_many( 'order_items', $lines );
		$history = array();
		foreach ( $notes as $n ) {
			$history[] = array(
				'order_id'   => (int) $rec['id'],
				'user_id'    => (int) $n['user_id'],
				'note_type'  => (string) $n['note_type'],
				'old_status' => (string) $n['old_status'],
				'new_status' => (string) $n['new_status'],
				'note'       => (string) $n['note'],
				'created_at' => (string) $n['created_at'],
			);
		}
		Db::insert_many( 'order_notes', $history );
	}

	/**
	 * Every order as backup documents ("orders/YYYY-MM" => { orders: { id: full record } }).
	 *
	 * @return array
	 */
	public static function export_documents() {
		global $wpdb;
		$docs = array();
		$t    = Db::table( 'orders' );
		$last = 0;
		do {
			$rows = Db::rows( $wpdb->prepare( "SELECT * FROM {$t} WHERE id > %d ORDER BY id LIMIT 500", $last ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
			foreach ( $rows as $r ) {
				$id                = (int) $r['id'];
				$last              = $id;
				$rec               = (array) self::header_obj( $r );
				$rec['fulfilled_at'] = null === $rec['fulfilled_at'] ? '' : $rec['fulfilled_at'];
				$rec['items']      = self::item_rows( $id );
				$rec['notes_log']  = self::note_rows( $id );
				$docs[ self::month_doc( $rec['created_at'] ) ]['orders'][ $id ] = $rec;
			}
		} while ( count( $rows ) === 500 );
		return $docs;
	}
}
