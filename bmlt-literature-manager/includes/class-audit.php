<?php
/**
 * Audit log (the audit table).
 *
 * @package BMLT_Lit
 * @version 7.0.0
 */

namespace BMLT_Lit;

defined( 'ABSPATH' ) || exit;

/**
 * Records important administrative actions.
 */
class Audit {

	/**
	 * Name of the audit documents in backups ("audit/YYYY-MM").
	 */
	const DOC_PREFIX = 'audit/';

	/**
	 * Write an audit entry. Inside a transaction it is saved (or rolled back) together with the change it records.
	 *
	 * @param string $action      Action key (e.g. inventory_adjusted).
	 * @param string $object_type Object type (item|inventory|order|settings|import|user).
	 * @param int    $object_id   Object ID.
	 * @param mixed  $old         Old value.
	 * @param mixed  $new         New value.
	 * @param int    $area_id     Area ID (0 = global).
	 * @param string $note        Optional note.
	 * @return true|\WP_Error
	 */
	public static function log( $action, $object_type, $object_id = 0, $old = null, $new = null, $area_id = 0, $note = '' ) {
		$row = self::row(
			array(
				'area_id'     => absint( $area_id ),
				'user_id'     => get_current_user_id(),
				'action'      => substr( sanitize_key( $action ), 0, 64 ),
				'object_type' => substr( sanitize_key( $object_type ), 0, 32 ),
				'object_id'   => absint( $object_id ),
				'old_value'   => $old,
				'new_value'   => $new,
				'note'        => Helpers::limit( sanitize_textarea_field( (string) $note ), 2000 ),
				'created_at'  => Helpers::now_gmt(),
			)
		);
		return Db::tx(
			static function () use ( $row ) {
				Db::insert( 'audit', $row );
				return true;
			}
		);
	}

	/**
	 * Table row from an entry (values as JSON).
	 *
	 * @param array $e Entry (Schema::audit() shape).
	 * @return array
	 */
	public static function row( array $e ) {
		return array(
			'area_id'     => (int) $e['area_id'],
			'user_id'     => (int) $e['user_id'],
			'action'      => (string) $e['action'],
			'object_type' => (string) $e['object_type'],
			'object_id'   => (int) $e['object_id'],
			'old_value'   => null === $e['old_value'] ? null : wp_json_encode( $e['old_value'] ),
			'new_value'   => null === $e['new_value'] ? null : wp_json_encode( $e['new_value'] ),
			'note'        => (string) $e['note'],
			'created_at'  => (string) $e['created_at'],
		);
	}

	/**
	 * Fetch recent entries (newest first).
	 *
	 * @param array $args area_ids, object_type, object_id, actions, limit.
	 * @return object[] Values as JSON strings.
	 */
	public static function recent( array $args = array() ) {
		global $wpdb;
		$args    = wp_parse_args(
			$args,
			array(
				'area_ids'    => null,
				'object_type' => '',
				'object_id'   => 0,
				'actions'     => array(),
				'limit'       => 25,
			)
		);
		$where   = array( '1=1' );
		$params  = array();
		if ( is_array( $args['area_ids'] ) ) {
			$areas   = array_values( array_unique( array_merge( array_map( 'absint', $args['area_ids'] ), array( 0 ) ) ) );
			$where[] = 'area_id IN (' . implode( ',', array_fill( 0, count( $areas ), '%d' ) ) . ')';
			$params  = array_merge( $params, $areas );
		}
		if ( '' !== $args['object_type'] ) {
			$where[]  = 'object_type = %s';
			$params[] = sanitize_key( $args['object_type'] );
		}
		if ( $args['object_id'] ) {
			$where[]  = 'object_id = %d';
			$params[] = absint( $args['object_id'] );
		}
		$actions = array_values( array_filter( array_map( 'sanitize_key', (array) $args['actions'] ) ) );
		if ( $actions ) {
			$where[] = 'action IN (' . implode( ',', array_fill( 0, count( $actions ), '%s' ) ) . ')';
			$params  = array_merge( $params, $actions );
		}
		$params[] = max( 1, min( 500, (int) $args['limit'] ) );
		$t        = Db::table( 'audit' );
		$sql      = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d';
		$out      = array();
		foreach ( Db::rows( $wpdb->prepare( $sql, $params ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
			foreach ( array( 'id', 'area_id', 'user_id', 'object_id' ) as $k ) {
				$r[ $k ] = (int) $r[ $k ];
			}
			$out[] = (object) $r;
		}
		return $out;
	}

	/**
	 * Human-readable label for an action.
	 *
	 * @param string $action Action.
	 * @return string
	 */
	public static function action_label( $action ) {
		$labels = array(
			'inventory_adjusted'   => __( 'Inventory adjusted', 'bmlt-literature-manager' ),
			'inventory_imported'   => __( 'Inventory imported', 'bmlt-literature-manager' ),
			'item_created'         => __( 'Item created', 'bmlt-literature-manager' ),
			'item_updated'         => __( 'Item updated', 'bmlt-literature-manager' ),
			'item_price_changed'   => __( 'Price changed', 'bmlt-literature-manager' ),
			'item_deleted'         => __( 'Item deleted', 'bmlt-literature-manager' ),
			'item_activated'       => __( 'Item activated', 'bmlt-literature-manager' ),
			'item_deactivated'     => __( 'Item deactivated', 'bmlt-literature-manager' ),
			'order_status_changed' => __( 'Order status changed', 'bmlt-literature-manager' ),
			'catalog_imported'     => __( 'Catalog imported', 'bmlt-literature-manager' ),
			'settings_changed'     => __( 'Settings changed', 'bmlt-literature-manager' ),
			'permissions_changed'  => __( 'Permissions changed', 'bmlt-literature-manager' ),
		);
		return isset( $labels[ $action ] ) ? $labels[ $action ] : $action;
	}

	/**
	 * Display name for a user ID.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function user_name( $user_id ) {
		if ( ! $user_id ) {
			return __( 'System / public', 'bmlt-literature-manager' );
		}
		$u = get_userdata( (int) $user_id );
		return $u ? $u->display_name : __( '(deleted user)', 'bmlt-literature-manager' );
	}
}
