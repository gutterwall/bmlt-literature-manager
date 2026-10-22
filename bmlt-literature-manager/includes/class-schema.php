<?php
/**
 * Record schemas.
 *
 * Every record written from an external source (v1.0 database migration, backup restore) is passed through
 * these normalizers: only whitelisted keys survive, every value is type-checked, cast and sanitized.
 *
 * @package BMLT_Lit
 * @version 6.3.0
 */

namespace BMLT_Lit;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizers for stored records.
 */
class Schema {

	/**
	 * Single-line string.
	 *
	 * @param mixed $v   Value.
	 * @param int   $max Max length.
	 * @return string
	 */
	public static function str( $v, $max = 255 ) {
		return is_scalar( $v ) ? Helpers::limit( sanitize_text_field( (string) $v ), $max ) : '';
	}

	/**
	 * Multi-line string.
	 *
	 * @param mixed $v   Value.
	 * @param int   $max Max length.
	 * @return string
	 */
	public static function text( $v, $max = 5000 ) {
		return is_scalar( $v ) ? Helpers::limit( sanitize_textarea_field( (string) $v ), $max ) : '';
	}

	/**
	 * Integer.
	 *
	 * @param mixed $v Value.
	 * @return int
	 */
	public static function int( $v ) {
		return is_numeric( $v ) ? (int) $v : 0;
	}

	/**
	 * Non-negative integer.
	 *
	 * @param mixed $v Value.
	 * @return int
	 */
	public static function uint( $v ) {
		return max( 0, self::int( $v ) );
	}

	/**
	 * Money string with two decimals.
	 *
	 * @param mixed $v Value.
	 * @return string
	 */
	public static function money( $v ) {
		return is_numeric( $v ) ? Helpers::sql_money( max( 0, (float) $v ) ) : '0.00';
	}

	/**
	 * UTC datetime "Y-m-d H:i:s" or ''.
	 *
	 * @param mixed $v Value.
	 * @return string
	 */
	public static function datetime( $v ) {
		return ( is_string( $v ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v ) && '0000-00-00 00:00:00' !== $v ) ? $v : '';
	}

	/**
	 * Boolean as 0/1.
	 *
	 * @param mixed $v Value.
	 * @return int
	 */
	public static function bool( $v ) {
		return ( true === $v || 1 === $v || '1' === $v || 'yes' === $v || 'true' === $v ) ? 1 : 0;
	}

	/**
	 * List of positive integer ids (anything else, negative numbers included, is dropped).
	 *
	 * @param mixed $v Value.
	 * @return int[]
	 */
	public static function ids( $v ) {
		$out = array();
		foreach ( is_array( $v ) ? $v : array() as $id ) {
			if ( ( is_int( $id ) && $id > 0 ) || ( is_string( $id ) && ctype_digit( $id ) && (int) $id > 0 ) ) {
				$out[ (int) $id ] = (int) $id;
			}
		}
		return array_values( $out );
	}

	/**
	 * Array value.
	 *
	 * @param array  $r   Record.
	 * @param string $key Key.
	 * @return mixed
	 */
	private static function v( array $r, $key ) {
		return isset( $r[ $key ] ) ? $r[ $key ] : null;
	}

	/**
	 * Literature item.
	 *
	 * @param array $r Raw.
	 * @return array|null
	 */
	public static function item( array $r ) {
		$id = self::uint( self::v( $r, 'id' ) );
		if ( ! $id ) {
			return null;
		}
		$image = is_scalar( self::v( $r, 'image_url' ) ) ? esc_url_raw( (string) $r['image_url'], array( 'http', 'https' ) ) : '';
		$now   = Helpers::now_gmt();
		return array(
			'id'                => $id,
			'area_id'           => self::uint( self::v( $r, 'area_id' ) ),
			'sku'               => self::str( self::v( $r, 'sku' ), 64 ),
			'name'              => self::str( self::v( $r, 'name' ), 255 ),
			'short_description' => self::text( self::v( $r, 'short_description' ), 1000 ),
			'long_description'  => is_scalar( self::v( $r, 'long_description' ) ) ? Helpers::limit( wp_kses_post( (string) $r['long_description'] ), 20000 ) : '',
			'category'          => self::str( self::v( $r, 'category' ), 100 ),
			'price'             => self::money( self::v( $r, 'price' ) ),
			'is_active'         => self::bool( self::v( $r, 'is_active' ) ),
			'sort_order'        => self::int( self::v( $r, 'sort_order' ) ),
			'image_url'         => Helpers::limit( $image, 2048 ),
			'reference_url'     => Helpers::limit( is_scalar( self::v( $r, 'reference_url' ) ) ? esc_url_raw( (string) $r['reference_url'], array( 'http', 'https' ) ) : '', 2048 ),
			'naws_id'           => self::str( self::v( $r, 'naws_id' ), 64 ),
			'created_at'        => self::datetime( self::v( $r, 'created_at' ) ) ? $r['created_at'] : $now,
			'updated_at'        => self::datetime( self::v( $r, 'updated_at' ) ) ? $r['updated_at'] : $now,
		);
	}

	/**
	 * Inventory row.
	 *
	 * @param array $r Raw.
	 * @return array
	 */
	public static function inventory_row( array $r ) {
		return array(
			'on_hand'    => min( 1000000, self::uint( self::v( $r, 'on_hand' ) ) ),
			'reserved'   => min( 1000000, self::uint( self::v( $r, 'reserved' ) ) ),
			'low'        => min( 100000, self::uint( self::v( $r, 'low' ) ) ),
			'reorder'    => min( 100000, self::uint( self::v( $r, 'reorder' ) ) ),
			'notes'      => self::text( self::v( $r, 'notes' ), 2000 ),
			'updated_at' => self::datetime( self::v( $r, 'updated_at' ) ),
			'updated_by' => self::uint( self::v( $r, 'updated_by' ) ),
			'special'    => self::bool( self::v( $r, 'special' ) ),
		);
	}

	/**
	 * Order line.
	 *
	 * @param array $r Raw.
	 * @return array
	 */
	public static function order_item( array $r ) {
		$status = self::str( self::v( $r, 'stock_status' ), 20 );
		return array(
			'item_id'           => self::uint( self::v( $r, 'item_id' ) ),
			'sku'               => self::str( self::v( $r, 'sku' ), 64 ),
			'name'              => self::str( self::v( $r, 'name' ), 255 ),
			'description'       => self::text( self::v( $r, 'description' ), 1000 ),
			'quantity'          => self::uint( self::v( $r, 'quantity' ) ),
			'quantity_in_stock' => self::uint( self::v( $r, 'quantity_in_stock' ) ),
			'quantity_special'  => self::uint( self::v( $r, 'quantity_special' ) ),
			'quantity_reserved' => self::uint( self::v( $r, 'quantity_reserved' ) ),
			'unit_price'        => self::money( self::v( $r, 'unit_price' ) ),
			'line_total'        => self::money( self::v( $r, 'line_total' ) ),
			'stock_status'      => in_array( $status, array( 'in_stock', 'low_stock', 'special_order', 'partial' ), true ) ? $status : 'in_stock',
			'is_special_order'  => self::bool( self::v( $r, 'is_special_order' ) ),
		);
	}

	/**
	 * Order note / history entry.
	 *
	 * @param array $r Raw.
	 * @return array
	 */
	public static function note( array $r ) {
		$type = self::str( self::v( $r, 'note_type' ), 20 );
		return array(
			'user_id'    => self::uint( self::v( $r, 'user_id' ) ),
			'note_type'  => in_array( $type, array( 'note', 'status', 'email', 'system' ), true ) ? $type : 'note',
			'old_status' => sanitize_key( (string) self::str( self::v( $r, 'old_status' ), 30 ) ),
			'new_status' => sanitize_key( (string) self::str( self::v( $r, 'new_status' ), 30 ) ),
			'note'       => self::text( self::v( $r, 'note' ), 5000 ),
			'created_at' => self::datetime( self::v( $r, 'created_at' ) ) ? $r['created_at'] : Helpers::now_gmt(),
		);
	}

	/**
	 * Full order record (header + items + notes).
	 *
	 * @param array $r Raw.
	 * @return array|null
	 */
	public static function order( array $r ) {
		$id = self::uint( self::v( $r, 'id' ) );
		if ( ! $id || ! self::datetime( self::v( $r, 'created_at' ) ) ) {
			return null;
		}
		$statuses = array_keys( Helpers::order_statuses() );
		$status   = sanitize_key( (string) self::str( self::v( $r, 'status' ), 30 ) );
		$mode     = self::str( self::v( $r, 'inventory_mode' ), 20 );
		$state    = self::str( self::v( $r, 'inventory_state' ), 20 );
		$items    = array();
		foreach ( is_array( self::v( $r, 'items' ) ) ? $r['items'] : array() as $it ) {
			if ( is_array( $it ) ) {
				$items[] = self::order_item( $it );
			}
		}
		$notes = array();
		foreach ( is_array( self::v( $r, 'notes_log' ) ) ? $r['notes_log'] : array() as $n ) {
			if ( is_array( $n ) ) {
				$notes[] = self::note( $n );
			}
		}
		$key = preg_replace( '/[^A-Za-z0-9]/', '', (string) self::str( self::v( $r, 'access_key' ), 64 ) );
		return array(
			'id'                => $id,
			'order_number'      => self::str( self::v( $r, 'order_number' ), 40 ),
			'access_key'        => strlen( $key ) >= 20 ? $key : wp_generate_password( 32, false, false ),
			'submission_hash'   => preg_replace( '/[^a-f0-9]/', '', strtolower( (string) self::str( self::v( $r, 'submission_hash' ), 64 ) ) ),
			'area_id'           => self::uint( self::v( $r, 'area_id' ) ),
			'area_name'         => self::str( self::v( $r, 'area_name' ), 255 ),
			'meeting_id'        => self::uint( self::v( $r, 'meeting_id' ) ),
			'meeting_name'      => self::str( self::v( $r, 'meeting_name' ), 255 ),
			'meeting_day'       => min( 7, self::uint( self::v( $r, 'meeting_day' ) ) ),
			'meeting_time'      => self::str( self::v( $r, 'meeting_time' ), 10 ),
			'meeting_location'  => self::str( self::v( $r, 'meeting_location' ), 255 ),
			'contact_name'      => self::str( self::v( $r, 'contact_name' ), 200 ),
			'contact_phone'     => self::str( self::v( $r, 'contact_phone' ), 50 ),
			'contact_email'     => sanitize_email( (string) self::str( self::v( $r, 'contact_email' ), 200 ) ),
			'notes'             => self::text( self::v( $r, 'notes' ), 5000 ),
			'subtotal'          => self::money( self::v( $r, 'subtotal' ) ),
			'total'             => self::money( self::v( $r, 'total' ) ),
			'item_count'        => self::uint( self::v( $r, 'item_count' ) ),
			'status'            => in_array( $status, $statuses, true ) ? $status : 'new',
			'has_special_order' => self::bool( self::v( $r, 'has_special_order' ) ),
			'inventory_mode'    => in_array( $mode, array( 'reserve', 'deduct', 'none', 'untracked' ), true ) ? $mode : 'none',
			'inventory_state'   => in_array( $state, array( 'none', 'reserved', 'deducted', 'released', 'untracked' ), true ) ? $state : 'none',
			'ip_hash'           => preg_replace( '/[^a-f0-9]/', '', strtolower( (string) self::str( self::v( $r, 'ip_hash' ), 64 ) ) ),
			'created_by'        => self::uint( self::v( $r, 'created_by' ) ),
			'created_at'        => $r['created_at'],
			'updated_at'        => self::datetime( self::v( $r, 'updated_at' ) ) ? $r['updated_at'] : $r['created_at'],
			'updated_by'        => self::uint( self::v( $r, 'updated_by' ) ),
			'fulfilled_at'      => self::datetime( self::v( $r, 'fulfilled_at' ) ),
			'fulfilled_by'      => self::uint( self::v( $r, 'fulfilled_by' ) ),
			'items'             => $items,
			'notes_log'         => $notes,
		);
	}

	/**
	 * Audit entry.
	 *
	 * @param array $r Raw.
	 * @return array|null
	 */
	public static function audit( array $r ) {
		if ( ! self::datetime( self::v( $r, 'created_at' ) ) ) {
			return null;
		}
		$decode = static function ( $v ) {
			if ( is_array( $v ) ) {
				return $v;
			}
			if ( is_string( $v ) && '' !== $v ) {
				$d = json_decode( $v, true );
				return is_array( $d ) || is_scalar( $d ) ? $d : null;
			}
			return null;
		};
		return array(
			'id'          => self::uint( self::v( $r, 'id' ) ),
			'area_id'     => self::uint( self::v( $r, 'area_id' ) ),
			'user_id'     => self::uint( self::v( $r, 'user_id' ) ),
			'action'      => substr( sanitize_key( (string) self::str( self::v( $r, 'action' ), 64 ) ), 0, 64 ),
			'object_type' => substr( sanitize_key( (string) self::str( self::v( $r, 'object_type' ), 32 ) ), 0, 32 ),
			'object_id'   => self::uint( self::v( $r, 'object_id' ) ),
			'old_value'   => $decode( self::v( $r, 'old_value' ) ),
			'new_value'   => $decode( self::v( $r, 'new_value' ) ),
			'note'        => self::text( self::v( $r, 'note' ), 2000 ),
			'created_at'  => $r['created_at'],
		);
	}

	/**
	 * Sanitize a settings document (used for restore / migration).
	 *
	 * @param array $s Raw settings.
	 * @return array
	 */
	public static function settings( array $s ) {
		$out = array();

		$general = array();
		foreach ( Settings::general_defaults() as $k => $def ) {
			if ( isset( $s['general'][ $k ] ) && is_scalar( $s['general'][ $k ] ) ) {
				$general[ $k ] = is_int( $def ) ? (int) $s['general'][ $k ] : ( in_array( $k, array( 'delivery_message', 'worker_delivery_message', 'special_order_message' ), true ) ? self::text( $s['general'][ $k ], 1000 ) : self::str( $s['general'][ $k ], 500 ) );
			}
		}
		$out['general'] = $general;

		$bmlt = array();
		foreach ( Settings::bmlt_defaults() as $k => $def ) {
			if ( ! isset( $s['bmlt'][ $k ] ) ) {
				continue;
			}
			$v = $s['bmlt'][ $k ];
			if ( 'selected_areas' === $k ) {
				$bmlt[ $k ] = self::ids( $v );
			} elseif ( in_array( $k, array( 'root_url', 'endpoint_override' ), true ) ) {
				$bmlt[ $k ] = is_scalar( $v ) ? esc_url_raw( (string) $v, array( 'http', 'https' ) ) : '';
			} elseif ( is_scalar( $v ) ) {
				$bmlt[ $k ] = (int) $v;
			}
		}
		$out['bmlt'] = $bmlt;

		$areas = array();
		foreach ( isset( $s['areas'] ) && is_array( $s['areas'] ) ? $s['areas'] : array() as $id => $a ) {
			$id = absint( $id );
			if ( ! $id || ! is_array( $a ) ) {
				continue;
			}
			$row = array();
			foreach ( Settings::area_defaults() as $k => $def ) {
				if ( ! isset( $a[ $k ] ) || ! is_scalar( $a[ $k ] ) ) {
					continue;
				}
				if ( is_int( $def ) ) {
					$row[ $k ] = absint( $a[ $k ] );
				} elseif ( in_array( $k, array( 'worker_emails', 'chair_email', 'special_order_emails' ), true ) ) {
					$row[ $k ] = Helpers::sanitize_email_list( (string) $a[ $k ] );
				} elseif ( in_array( $k, array( 'website', 'bmlt_url' ), true ) ) {
					$row[ $k ] = esc_url_raw( (string) $a[ $k ], array( 'http', 'https' ) );
				} elseif ( 'delivery_instructions' === $k || 'description' === $k ) {
					$row[ $k ] = self::text( $a[ $k ], 1000 );
				} else {
					$row[ $k ] = self::str( $a[ $k ], 300 );
				}
			}
			$areas[ $id ] = $row;
		}
		$out['areas'] = $areas;

		$smtp = array();
		foreach ( Settings::smtp_defaults() as $k => $def ) {
			if ( isset( $s['smtp'][ $k ] ) && is_scalar( $s['smtp'][ $k ] ) ) {
				$smtp[ $k ] = is_int( $def ) ? (int) $s['smtp'][ $k ] : ( 'password' === $k ? (string) $s['smtp'][ $k ] : self::str( $s['smtp'][ $k ], 255 ) );
			}
		}
		$out['smtp'] = $smtp;

		$templates = array();
		foreach ( array_keys( Settings::template_labels() ) as $k ) {
			if ( isset( $s['templates'][ $k ] ) && is_array( $s['templates'][ $k ] ) ) {
				$templates[ $k ] = array(
					'subject' => isset( $s['templates'][ $k ]['subject'] ) ? self::str( $s['templates'][ $k ]['subject'], 300 ) : '',
					'body'    => isset( $s['templates'][ $k ]['body'] ) && is_scalar( $s['templates'][ $k ]['body'] ) ? Helpers::limit( wp_kses_post( (string) $s['templates'][ $k ]['body'] ), 50000 ) : '',
				);
			}
		}
		$out['templates'] = $templates;
		return $out;
	}

	/**
	 * Sanitize the permissions document.
	 *
	 * @param array $p Raw.
	 * @return array
	 */
	public static function permissions( array $p ) {
		$users = array();
		foreach ( isset( $p['users'] ) && is_array( $p['users'] ) ? $p['users'] : array() as $uid => $g ) {
			$uid = absint( $uid );
			if ( ! $uid || ! is_array( $g ) ) {
				continue;
			}
			$caps  = isset( $g['caps'] ) && is_array( $g['caps'] ) ? array_values( array_intersect( Permissions::caps(), array_map( 'strval', $g['caps'] ) ) ) : array();
			$areas = isset( $g['areas'] ) ? self::ids( $g['areas'] ) : array();
			if ( $caps || $areas ) {
				$users[ $uid ] = array(
					'caps'  => $caps,
					'areas' => $areas,
				);
			}
		}
		return array( 'users' => $users );
	}
}
