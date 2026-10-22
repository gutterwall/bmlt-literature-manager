<?php
/**
 * Audit log (monthly JSON files under audit/).
 *
 * @package BMLT_Lit
 * @version 6.3.0
 */

namespace BMLT_Lit;

defined( 'ABSPATH' ) || exit;

/**
 * Records important administrative actions.
 */
class Audit {

	/**
	 * Write an audit entry to audit/YYYY-MM.json.php.
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
		$now   = Helpers::now_gmt();
		$entry = array(
			'id'          => (int) round( microtime( true ) * 1000 ),
			'area_id'     => absint( $area_id ),
			'user_id'     => get_current_user_id(),
			'action'      => substr( sanitize_key( $action ), 0, 64 ),
			'object_type' => substr( sanitize_key( $object_type ), 0, 32 ),
			'object_id'   => absint( $object_id ),
			'old_value'   => $old,
			'new_value'   => $new,
			'note'        => Helpers::limit( sanitize_textarea_field( (string) $note ), 2000 ),
			'created_at'  => $now,
		);
		$doc = 'audit/' . substr( $now, 0, 7 );
		return Store::tx(
			static function () use ( $doc, $entry ) {
				$d = Store::get( $doc, array( 'entries' => array() ) );
				if ( ! isset( $d['entries'] ) || ! is_array( $d['entries'] ) ) {
					$d['entries'] = array();
				}
				$d['entries'][] = $entry;
				return Store::put( $doc, $d );
			}
		);
	}

	/**
	 * Fetch recent entries (newest first), scanning at most the last 24 monthly files.
	 *
	 * @param array $args area_ids, object_type, object_id, actions, limit.
	 * @return object[]
	 */
	public static function recent( array $args = array() ) {
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
		$limit   = max( 1, min( 500, (int) $args['limit'] ) );
		$areas   = is_array( $args['area_ids'] ) ? array_merge( array_map( 'absint', $args['area_ids'] ), array( 0 ) ) : null;
		$actions = array_map( 'sanitize_key', (array) $args['actions'] );
		$out     = array();
		$months  = array_reverse( Store::names( 'audit' ) );
		foreach ( array_slice( $months, 0, 24 ) as $doc ) {
			$d       = Store::get( $doc, array( 'entries' => array() ) );
			$entries = isset( $d['entries'] ) && is_array( $d['entries'] ) ? array_reverse( $d['entries'] ) : array();
			foreach ( $entries as $e ) {
				if ( null !== $areas && ! in_array( (int) $e['area_id'], $areas, true ) ) {
					continue;
				}
				if ( '' !== $args['object_type'] && $e['object_type'] !== sanitize_key( $args['object_type'] ) ) {
					continue;
				}
				if ( $args['object_id'] && (int) $e['object_id'] !== absint( $args['object_id'] ) ) {
					continue;
				}
				if ( $actions && ! in_array( $e['action'], $actions, true ) ) {
					continue;
				}
				// Views expect JSON strings (as stored in v1.0).
				$e['old_value'] = null === $e['old_value'] ? null : wp_json_encode( $e['old_value'] );
				$e['new_value'] = null === $e['new_value'] ? null : wp_json_encode( $e['new_value'] );
				$out[]          = (object) $e;
				if ( count( $out ) >= $limit ) {
					return $out;
				}
			}
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
