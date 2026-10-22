<?php
/**
 * Orders (JSON storage).
 *
 * Layout:
 *   orders/index.json.php    { next_id, orders: { id: summary }, hashes: { submission_hash: id }, refs: { item_id: count } }
 *   orders/YYYY-MM.json.php  { orders: { id: full order incl. items + notes_log } }   (month of creation, UTC)
 *
 * The index holds only the fields needed for listing, filtering, sorting and dashboard counts, so list
 * views never have to open every month file. Every change updates the index and month file together
 * inside one locked transaction.
 *
 * @package BMLT_Lit
 * @version 6.2.0
 */

namespace BMLT_Lit;

defined( 'ABSPATH' ) || exit;

/**
 * Order creation, retrieval, status workflow and inventory effects.
 */
class Orders {

	const INDEX = 'orders/index';

	/**
	 * Fields copied into the index.
	 */
	const INDEX_FIELDS = array( 'id', 'order_number', 'created_at', 'area_id', 'area_name', 'meeting_id', 'meeting_name', 'contact_name', 'contact_email', 'contact_phone', 'item_count', 'total', 'status', 'has_special_order' );

	/**
	 * Index document.
	 *
	 * @return array
	 */
	public static function index() {
		$d = Store::get(
			self::INDEX,
			array(
				'next_id' => 1,
				'orders'  => array(),
				'hashes'  => array(),
				'refs'    => array(),
			)
		);
		foreach ( array( 'orders', 'hashes', 'refs' ) as $k ) {
			if ( ! isset( $d[ $k ] ) || ! is_array( $d[ $k ] ) ) {
				$d[ $k ] = array();
			}
		}
		if ( empty( $d['next_id'] ) ) {
			$d['next_id'] = $d['orders'] ? max( array_map( 'intval', array_keys( $d['orders'] ) ) ) + 1 : 1;
		}
		return $d;
	}

	/**
	 * Month document name for a UTC datetime.
	 *
	 * @param string $created_at UTC datetime.
	 * @return string
	 */
	public static function month_doc( $created_at ) {
		return 'orders/' . substr( (string) $created_at, 0, 7 );
	}

	/**
	 * Build an index entry from a full record.
	 *
	 * @param array $rec Full record.
	 * @return array
	 */
	private static function index_entry( array $rec ) {
		$e = array();
		foreach ( self::INDEX_FIELDS as $f ) {
			$e[ $f ] = isset( $rec[ $f ] ) ? $rec[ $f ] : '';
		}
		$e['month'] = substr( (string) $rec['created_at'], 0, 7 );
		return $e;
	}

	/**
	 * Full record array for an order ID (or null).
	 *
	 * @param int $id Order ID.
	 * @return array|null
	 */
	private static function record( $id ) {
		$id  = absint( $id );
		$idx = self::index();
		if ( ! isset( $idx['orders'][ $id ] ) ) {
			return null;
		}
		$month = Store::get( 'orders/' . $idx['orders'][ $id ]['month'], array( 'orders' => array() ) );
		return isset( $month['orders'][ $id ] ) && is_array( $month['orders'][ $id ] ) ? $month['orders'][ $id ] : null;
	}

	/**
	 * Header object (without items/history) from a record.
	 *
	 * @param array $rec Record.
	 * @return object
	 */
	private static function header_obj( array $rec ) {
		unset( $rec['items'], $rec['notes_log'] );
		foreach ( array( 'id', 'area_id', 'meeting_id', 'meeting_day', 'item_count', 'has_special_order', 'created_by', 'updated_by', 'fulfilled_by' ) as $k ) {
			$rec[ $k ] = isset( $rec[ $k ] ) ? (int) $rec[ $k ] : 0;
		}
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
		$rec = self::record( $id );
		return $rec ? self::header_obj( $rec ) : null;
	}

	/**
	 * Get by submission hash (duplicate protection).
	 *
	 * @param string $hash Hash.
	 * @return object|null
	 */
	public static function get_by_submission_hash( $hash ) {
		$idx = self::index();
		return isset( $idx['hashes'][ (string) $hash ] ) ? self::get( (int) $idx['hashes'][ (string) $hash ] ) : null;
	}

	/**
	 * Requester confirmation lookup: requires order number AND secret access key.
	 *
	 * @param string $number Order number.
	 * @param string $key    Access key.
	 * @return object|null
	 */
	public static function get_by_access( $number, $key ) {
		$number = sanitize_text_field( (string) $number );
		$key    = preg_replace( '/[^A-Za-z0-9]/', '', (string) $key );
		if ( '' === $number || strlen( $key ) < 20 ) {
			return null;
		}
		$found = 0;
		foreach ( self::index()['orders'] as $id => $e ) {
			if ( (string) $e['order_number'] === $number ) {
				$found = (int) $id;
				break;
			}
		}
		$order = $found ? self::get( $found ) : null;
		if ( ! $order || ! hash_equals( (string) $order->access_key, $key ) ) {
			return null;
		}
		if ( strtotime( $order->created_at . ' UTC' ) < time() - 30 * DAY_IN_SECONDS ) {
			return null;
		}
		return $order;
	}

	/**
	 * Items for an order.
	 *
	 * @param int $order_id Order.
	 * @return object[]
	 */
	public static function get_items( $order_id ) {
		$rec = self::record( $order_id );
		$out = array();
		foreach ( $rec && isset( $rec['items'] ) && is_array( $rec['items'] ) ? $rec['items'] : array() as $i => $it ) {
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
		$rec   = self::record( $order_id );
		$notes = $rec && isset( $rec['notes_log'] ) && is_array( $rec['notes_log'] ) ? $rec['notes_log'] : array();
		$out   = array();
		foreach ( array_reverse( $notes, true ) as $i => $n ) {
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
		return Store::tx(
			static function () use ( $order_id, $entry ) {
				$idx = self::index();
				if ( ! isset( $idx['orders'][ $order_id ] ) ) {
					return new \WP_Error( 'not_found', __( 'Order not found.', 'bmlt-literature-manager' ) );
				}
				$doc   = 'orders/' . $idx['orders'][ $order_id ]['month'];
				$month = Store::get( $doc, array( 'orders' => array() ) );
				if ( ! isset( $month['orders'][ $order_id ] ) ) {
					return new \WP_Error( 'not_found', __( 'Order not found.', 'bmlt-literature-manager' ) );
				}
				$month['orders'][ $order_id ]['notes_log'][] = $entry;
				Store::put( $doc, $month );
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
	 * Item IDs referenced by any order (from the index; optionally read fresh inside a transaction).
	 *
	 * @param bool $fresh Unused (inside a transaction Store::get() already reads fresh).
	 * @return int[]
	 */
	public static function referenced_item_ids( $fresh = false ) {
		unset( $fresh );
		$refs = self::index()['refs'];
		return array_map( 'intval', array_keys( array_filter( $refs ) ) );
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
		$calc = self::compute( absint( $data['area_id'] ), $lines, Catalog::doc(), Inventory::doc(), Settings::general() );
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

		$result = Store::tx(
			static function () use ( $data, $area_id, $lines, $hash ) {
				$idx = self::index();
				if ( isset( $idx['hashes'][ $hash ] ) ) {
					return array(
						'order_id'  => (int) $idx['hashes'][ $hash ],
						'duplicate' => true,
					);
				}

				$calc = self::compute( $area_id, $lines, Catalog::doc(), Inventory::doc(), Settings::general() );
				if ( is_wp_error( $calc ) ) {
					return $calc;
				}
				$inv         = $calc['inventory'];
				$items       = $calc['items'];
				$sub_cents   = $calc['sub_cents'];
				$count       = $calc['count'];
				$has_special = $calc['has_special'];
				$mode        = $calc['mode'];
				foreach ( $items as $it ) {
					$idx['refs'][ $it['item_id'] ] = ( isset( $idx['refs'][ $it['item_id'] ] ) ? (int) $idx['refs'][ $it['item_id'] ] : 0 ) + 1;
				}

				$id      = (int) $idx['next_id'];
				$meeting = (array) $data['meeting'];
				$now     = Helpers::now_gmt();
				$rec     = array(
					'id'                => $id,
					'order_number'      => self::format_number( $id ),
					'access_key'        => wp_generate_password( 32, false, false ),
					'submission_hash'   => $hash,
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
					'subtotal'          => Helpers::sql_money( $sub_cents / 100 ),
					'total'             => Helpers::sql_money( $sub_cents / 100 ),
					'item_count'        => $count,
					'status'            => 'new',
					'has_special_order' => $has_special ? 1 : 0,
					'inventory_mode'    => $mode,
					'inventory_state'   => 'untracked' === $mode ? 'untracked' : ( 'none' === $mode ? 'none' : ( 'reserve' === $mode ? 'reserved' : 'deducted' ) ),
					'ip_hash'           => (string) $data['ip_hash'],
					'created_by'        => get_current_user_id(),
					'created_at'        => $now,
					'updated_at'        => $now,
					'updated_by'        => 0,
					'fulfilled_at'      => '',
					'fulfilled_by'      => 0,
					'items'             => $items,
					'notes_log'         => array( self::note_entry( 'system', __( 'Order submitted.', 'bmlt-literature-manager' ), '', 'new' ) ),
				);

				$doc                    = self::month_doc( $now );
				$month                  = Store::get( $doc, array( 'orders' => array() ) );
				$month['orders'][ $id ] = $rec;
				$idx['orders'][ $id ]   = self::index_entry( $rec );
				$idx['hashes'][ $hash ] = $id;
				$idx['next_id']         = $id + 1;

				Store::put( $doc, $month );
				Store::put( self::INDEX, $idx );
				Store::put( Inventory::DOC, $inv );
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

		$result = Store::tx(
			static function () use ( $order_id, $status, $note, &$old, &$area_id, &$effects ) {
				$idx = self::index();
				if ( ! isset( $idx['orders'][ $order_id ] ) ) {
					return new \WP_Error( 'not_found', __( 'Order not found.', 'bmlt-literature-manager' ) );
				}
				$doc   = 'orders/' . $idx['orders'][ $order_id ]['month'];
				$month = Store::get( $doc, array( 'orders' => array() ) );
				if ( ! isset( $month['orders'][ $order_id ] ) ) {
					return new \WP_Error( 'not_found', __( 'Order not found.', 'bmlt-literature-manager' ) );
				}
				$rec     = $month['orders'][ $order_id ];
				$old     = (string) $rec['status'];
				$area_id = (int) $rec['area_id'];
				if ( $old === $status ) {
					return new \WP_Error( 'same', __( 'The order already has that status.', 'bmlt-literature-manager' ) );
				}
				if ( in_array( $old, array( 'cancelled', 'fulfilled' ), true ) ) {
					return new \WP_Error( 'final', __( 'Fulfilled and cancelled orders are final and cannot be changed. Submit a new order if needed.', 'bmlt-literature-manager' ) );
				}

				$inv     = Inventory::doc();
				$state   = (string) $rec['inventory_state'];
				$changed = false;
				foreach ( (array) $rec['items'] as $it ) {
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
							$changed         = true;
						} elseif ( 'none' === $state && $qty_stk > 0 ) {
							$ref['on_hand'] = max( 0, (int) $ref['on_hand'] - $qty_stk );
							$effects[]      = sprintf( '%s: -%d', $it['sku'], $qty_stk );
							$changed        = true;
						}
					} elseif ( 'cancelled' === $status ) {
						if ( 'reserved' === $state && $qty_res > 0 ) {
							$ref['reserved'] = max( 0, (int) $ref['reserved'] - $qty_res );
							$effects[]       = sprintf( '%s: released %d', $it['sku'], $qty_res );
							$changed         = true;
						} elseif ( 'deducted' === $state && $qty_res > 0 ) {
							$ref['on_hand'] = (int) $ref['on_hand'] + $qty_res;
							$effects[]      = sprintf( '%s: +%d', $it['sku'], $qty_res );
							$changed        = true;
						}
					}
					unset( $ref );
				}

				$now               = Helpers::now_gmt();
				$rec['status']     = $status;
				$rec['updated_at'] = $now;
				$rec['updated_by'] = get_current_user_id();
				if ( 'fulfilled' === $status ) {
					$rec['inventory_state'] = 'deducted';
					$rec['fulfilled_at']    = $now;
					$rec['fulfilled_by']    = get_current_user_id();
				} elseif ( 'cancelled' === $status ) {
					$rec['inventory_state'] = 'released';
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
				$rec['notes_log'][] = self::note_entry( 'status', $text, $old, $status );

				$month['orders'][ $order_id ] = $rec;
				$idx['orders'][ $order_id ]   = self::index_entry( $rec );
				Store::put( $doc, $month );
				Store::put( self::INDEX, $idx );
				if ( $changed ) {
					Store::put( Inventory::DOC, $inv );
				}
				return true;
			}
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

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

		$statuses = Helpers::order_statuses();
		$open     = Helpers::open_statuses();
		$status   = (string) $args['status'];
		$special  = (string) $args['special'];
		$meeting  = (string) $args['meeting'];
		$rows     = array();

		foreach ( self::index()['orders'] as $e ) {
			if ( ! in_array( (int) $e['area_id'], $area_ids, true ) ) {
				continue;
			}
			if ( 'open' === $status ) {
				if ( ! in_array( $e['status'], $open, true ) ) {
					continue;
				}
			} elseif ( '' !== $status && isset( $statuses[ $status ] ) && $e['status'] !== $status ) {
				continue;
			}
			if ( ( '1' === $special || '0' === $special ) && (int) $e['has_special_order'] !== (int) $special ) {
				continue;
			}
			if ( '' !== $args['date_from_gmt'] && strcmp( (string) $e['created_at'], (string) $args['date_from_gmt'] ) < 0 ) {
				continue;
			}
			if ( '' !== $args['date_to_gmt'] && strcmp( (string) $e['created_at'], (string) $args['date_to_gmt'] ) >= 0 ) {
				continue;
			}
			if ( '' !== $args['search'] ) {
				$hay = $e['order_number'] . ' ' . $e['contact_name'] . ' ' . $e['contact_email'] . ' ' . $e['contact_phone'] . ' ' . $e['meeting_name'];
				if ( false === stripos( $hay, (string) $args['search'] ) ) {
					continue;
				}
			}
			if ( '' !== $args['contact'] && false === stripos( (string) $e['contact_name'], (string) $args['contact'] ) ) {
				continue;
			}
			if ( '' !== $args['email'] && false === stripos( (string) $e['contact_email'], (string) $args['email'] ) ) {
				continue;
			}
			if ( '' !== $args['order_number'] && false === stripos( (string) $e['order_number'], (string) $args['order_number'] ) ) {
				continue;
			}
			if ( '' !== $meeting ) {
				if ( ctype_digit( $meeting ) ) {
					if ( (int) $e['meeting_id'] !== (int) $meeting ) {
						continue;
					}
				} elseif ( false === stripos( (string) $e['meeting_name'], $meeting ) ) {
					continue;
				}
			}
			$rows[] = $e;
		}

		$sort = (string) $args['sort'];
		$cmp  = static function ( $a, $b ) use ( $sort ) {
			$newest = strcmp( (string) $b['created_at'], (string) $a['created_at'] );
			if ( 0 === $newest ) {
				$newest = (int) $b['id'] <=> (int) $a['id'];
			}
			switch ( $sort ) {
				case 'oldest':
					return -$newest;
				case 'meeting':
					$c = strnatcasecmp( (string) $a['meeting_name'], (string) $b['meeting_name'] );
					break;
				case 'area':
					$c = strnatcasecmp( (string) $a['area_name'], (string) $b['area_name'] );
					break;
				case 'status':
					$c = strcmp( (string) $a['status'], (string) $b['status'] );
					break;
				case 'total':
					$c = (float) $b['total'] <=> (float) $a['total'];
					break;
				case 'number':
					$c = (int) $b['id'] <=> (int) $a['id'];
					break;
				case 'contact':
					$c = strnatcasecmp( (string) $a['contact_name'], (string) $b['contact_name'] );
					break;
				default:
					return $newest;
			}
			return 0 !== $c ? $c : $newest;
		};
		usort( $rows, $cmp );

		$total = count( $rows );
		$rows  = array_slice( $rows, max( 0, (int) $args['offset'] ), max( 1, min( 1000, (int) $args['limit'] ) ) );
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
		$open = Helpers::open_statuses();
		$now  = time();
		$d7   = gmdate( 'Y-m-d H:i:s', $now - 7 * DAY_IN_SECONDS );
		$d30  = gmdate( 'Y-m-d H:i:s', $now - 30 * DAY_IN_SECONDS );
		$d45  = gmdate( 'Y-m-d H:i:s', $now - 45 * DAY_IN_SECONDS );
		foreach ( self::index()['orders'] as $e ) {
			if ( ! in_array( (int) $e['area_id'], $area_ids, true ) ) {
				continue;
			}
			++$out['total_all'];
			$is_open = in_array( $e['status'], $open, true );
			$out['new']     += 'new' === $e['status'] ? 1 : 0;
			$out['ready']   += 'ready' === $e['status'] ? 1 : 0;
			$out['open']    += $is_open ? 1 : 0;
			$out['special'] += ( $is_open && (int) $e['has_special_order'] ) ? 1 : 0;
			$out['last_7']  += strcmp( (string) $e['created_at'], $d7 ) >= 0 ? 1 : 0;
			$out['last_30'] += strcmp( (string) $e['created_at'], $d30 ) >= 0 ? 1 : 0;
			$out['last_45'] += strcmp( (string) $e['created_at'], $d45 ) >= 0 ? 1 : 0;
		}
		return $out;
	}

	/**
	 * Total number of orders.
	 *
	 * @return int
	 */
	public static function count_all() {
		return count( self::index()['orders'] );
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
		$ids = array();
		foreach ( self::index()['orders'] as $id => $e ) {
			if ( '' !== (string) $email && 0 === strcasecmp( (string) $e['contact_email'], (string) $email ) ) {
				$ids[] = (int) $id;
			}
		}
		sort( $ids );
		$out = array();
		foreach ( array_slice( $ids, ( max( 1, (int) $page ) - 1 ) * $per, $per ) as $id ) {
			$o = self::get( $id );
			if ( $o ) {
				$out[] = $o;
			}
		}
		return $out;
	}

	/**
	 * Anonymize all orders for an email address (privacy eraser).
	 *
	 * @param string $email Email.
	 * @return int Orders anonymized.
	 */
	public static function anonymize_by_email( $email ) {
		$email = (string) $email;
		if ( '' === $email ) {
			return 0;
		}
		$count  = 0;
		$result = Store::tx(
			static function () use ( $email, &$count ) {
				$idx    = self::index();
				$months = array();
				foreach ( $idx['orders'] as $id => $e ) {
					if ( 0 !== strcasecmp( (string) $e['contact_email'], $email ) ) {
						continue;
					}
					$doc = 'orders/' . $e['month'];
					if ( ! isset( $months[ $doc ] ) ) {
						$months[ $doc ] = Store::get( $doc, array( 'orders' => array() ) );
					}
					foreach ( array( 'contact_phone', 'contact_email', 'notes', 'ip_hash' ) as $f ) {
						if ( isset( $months[ $doc ]['orders'][ $id ] ) ) {
							$months[ $doc ]['orders'][ $id ][ $f ] = '';
						}
					}
					if ( isset( $months[ $doc ]['orders'][ $id ] ) ) {
						$months[ $doc ]['orders'][ $id ]['contact_name'] = __( '[removed]', 'bmlt-literature-manager' );
					}
					$idx['orders'][ $id ]['contact_name']  = __( '[removed]', 'bmlt-literature-manager' );
					$idx['orders'][ $id ]['contact_email'] = '';
					$idx['orders'][ $id ]['contact_phone'] = '';
					++$count;
				}
				if ( ! $count ) {
					return true;
				}
				foreach ( $months as $doc => $data ) {
					Store::put( $doc, $data );
				}
				Store::put( self::INDEX, $idx );
				return true;
			}
		);
		return is_wp_error( $result ) ? 0 : $count;
	}

	/**
	 * Rebuild the index (next_id, hashes, item references) from the month files.
	 *
	 * @return array|\WP_Error Summary { orders: int, months: int }.
	 */
	public static function rebuild_index() {
		return Store::tx(
			static function () {
				$idx = array(
					'next_id' => 1,
					'orders'  => array(),
					'hashes'  => array(),
					'refs'    => array(),
				);
				$months = 0;
				$max    = 0;
				foreach ( Store::names( 'orders' ) as $doc ) {
					if ( self::INDEX === $doc || ! preg_match( '#^orders/\d{4}-\d{2}$#', $doc ) ) {
						continue;
					}
					++$months;
					$m = Store::get( $doc, array( 'orders' => array() ) );
					foreach ( isset( $m['orders'] ) && is_array( $m['orders'] ) ? $m['orders'] : array() as $id => $rec ) {
						if ( ! is_array( $rec ) || empty( $rec['created_at'] ) ) {
							continue;
						}
						$id                   = (int) $id;
						$rec['id']            = $id;
						$idx['orders'][ $id ] = self::index_entry( $rec );
						if ( ! empty( $rec['submission_hash'] ) ) {
							$idx['hashes'][ (string) $rec['submission_hash'] ] = $id;
						}
						foreach ( isset( $rec['items'] ) && is_array( $rec['items'] ) ? $rec['items'] : array() as $it ) {
							$iid = isset( $it['item_id'] ) ? (int) $it['item_id'] : 0;
							if ( $iid ) {
								$idx['refs'][ $iid ] = ( isset( $idx['refs'][ $iid ] ) ? $idx['refs'][ $iid ] : 0 ) + 1;
							}
						}
						$max = max( $max, $id );
					}
				}
				// Never reuse an order number, even if the old index knew of a higher ID.
				$old            = self::index();
				$idx['next_id'] = max( $max + 1, (int) $old['next_id'] );
				Store::put( self::INDEX, $idx );
				return array(
					'orders' => count( $idx['orders'] ),
					'months' => $months,
				);
			}
		);
	}
}
