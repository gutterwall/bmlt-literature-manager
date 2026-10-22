<?php
/**
 * Roles, capabilities and per-Area access.
 *
 * @package BMLT_Lit
 * @version 6.2.0
 */

namespace BMLT_Lit;

defined( 'ABSPATH' ) || exit;

/**
 * Capability management.
 */
class Permissions {

	const MANAGE_INVENTORY = 'bmlt_lit_manage_inventory';
	const MANAGE_CATALOG   = 'bmlt_lit_manage_catalog';
	const VIEW_ORDERS      = 'bmlt_lit_view_orders';
	const MANAGE_ORDERS    = 'bmlt_lit_manage_orders';
	const MANAGE_SETTINGS  = 'bmlt_lit_manage_settings';

	/**
	 * Legacy (v1.0) user meta key for Area restrictions; read only during migration.
	 */
	const AREA_META = 'bmlt_lit_restrict_areas';

	/**
	 * Store document with per-user grants: { users: { user_id: { caps: [], areas: [] } } }.
	 */
	const DOC = 'permissions';

	/**
	 * Register the capability filter.
	 */
	public static function init() {
		add_filter( 'user_has_cap', array( __CLASS__, 'filter_caps' ), 10, 4 );
	}

	/**
	 * Grant literature capabilities from the JSON store (and all of them to administrators).
	 *
	 * Capabilities live in the data store rather than in user meta, so a database push from staging
	 * cannot remove or add literature permissions on production.
	 *
	 * @param array    $allcaps All capabilities.
	 * @param string[] $caps    Required primitive capabilities.
	 * @param array    $args    Arguments.
	 * @param \WP_User $user    User.
	 * @return array
	 */
	public static function filter_caps( $allcaps, $caps, $args, $user ) {
		$ours = self::caps();
		if ( ! array_intersect( (array) $caps, $ours ) || ! ( $user instanceof \WP_User ) || ! $user->ID ) {
			return $allcaps;
		}
		if ( ! empty( $allcaps['manage_options'] ) ) {
			foreach ( $ours as $cap ) {
				$allcaps[ $cap ] = true;
			}
			return $allcaps;
		}
		$grant = self::grants_for( $user->ID );
		foreach ( $grant['caps'] as $cap ) {
			$allcaps[ $cap ] = true;
		}
		return $allcaps;
	}

	/**
	 * Stored grants for a user.
	 *
	 * @param int $user_id User.
	 * @return array { caps: string[], areas: int[] }
	 */
	public static function grants_for( $user_id ) {
		$doc = Store::get( self::DOC, array( 'users' => array() ) );
		$g   = isset( $doc['users'][ (int) $user_id ] ) && is_array( $doc['users'][ (int) $user_id ] ) ? $doc['users'][ (int) $user_id ] : array();
		return array(
			'caps'  => isset( $g['caps'] ) && is_array( $g['caps'] ) ? array_values( array_intersect( self::caps(), $g['caps'] ) ) : array(),
			'areas' => isset( $g['areas'] ) && is_array( $g['areas'] ) ? array_values( array_filter( array_map( 'absint', $g['areas'] ) ) ) : array(),
		);
	}

	/**
	 * Save grants for several users at once.
	 *
	 * @param array $sets user_id => { caps: string[], areas: int[] }.
	 * @return true|\WP_Error
	 */
	public static function save_grants( array $sets ) {
		return Store::tx(
			static function () use ( $sets ) {
				$doc = Store::get( self::DOC, array( 'users' => array() ) );
				if ( ! isset( $doc['users'] ) || ! is_array( $doc['users'] ) ) {
					$doc['users'] = array();
				}
				foreach ( $sets as $uid => $set ) {
					$uid   = absint( $uid );
					$caps  = array_values( array_intersect( self::caps(), (array) $set['caps'] ) );
					$areas = array_values( array_unique( array_filter( array_map( 'absint', (array) $set['areas'] ) ) ) );
					if ( ! $uid ) {
						continue;
					}
					if ( empty( $caps ) && empty( $areas ) ) {
						unset( $doc['users'][ $uid ] );
					} else {
						$doc['users'][ $uid ] = array(
							'caps'  => $caps,
							'areas' => $areas,
						);
					}
				}
				return Store::put( self::DOC, $doc );
			}
		);
	}

	/**
	 * All custom capabilities.
	 *
	 * @return string[]
	 */
	public static function caps() {
		return array( self::MANAGE_INVENTORY, self::MANAGE_CATALOG, self::VIEW_ORDERS, self::MANAGE_ORDERS, self::MANAGE_SETTINGS );
	}

	/**
	 * Capability labels.
	 *
	 * @return array
	 */
	public static function cap_labels() {
		return array(
			self::VIEW_ORDERS      => __( 'View orders', 'bmlt-literature-manager' ),
			self::MANAGE_ORDERS    => __( 'Manage orders', 'bmlt-literature-manager' ),
			self::MANAGE_INVENTORY => __( 'Manage inventory', 'bmlt-literature-manager' ),
			self::MANAGE_CATALOG   => __( 'Manage catalog', 'bmlt-literature-manager' ),
			self::MANAGE_SETTINGS  => __( 'Manage settings', 'bmlt-literature-manager' ),
		);
	}

	/**
	 * Create roles and grant administrators all capabilities.
	 */
	public static function install_roles() {
		if ( ! get_role( 'bmlt_lit_worker' ) ) {
			add_role(
				'bmlt_lit_worker',
				__( 'Literature Worker', 'bmlt-literature-manager' ),
				array(
					'read'                 => true,
					self::VIEW_ORDERS      => true,
					self::MANAGE_ORDERS    => true,
					self::MANAGE_INVENTORY => true,
				)
			);
		}
		if ( ! get_role( 'bmlt_lit_chair' ) ) {
			add_role(
				'bmlt_lit_chair',
				__( 'Literature Chair', 'bmlt-literature-manager' ),
				array(
					'read'                 => true,
					self::VIEW_ORDERS      => true,
					self::MANAGE_ORDERS    => true,
					self::MANAGE_INVENTORY => true,
					self::MANAGE_CATALOG   => true,
				)
			);
		}
	}

	/**
	 * Remove roles and capabilities (uninstall only).
	 */
	public static function remove_roles() {
		remove_role( 'bmlt_lit_worker' );
		remove_role( 'bmlt_lit_chair' );
		global $wp_roles;
		if ( $wp_roles instanceof \WP_Roles ) {
			foreach ( array_keys( $wp_roles->roles ) as $role_name ) {
				$role = get_role( $role_name );
				if ( $role ) {
					foreach ( self::caps() as $cap ) {
						$role->remove_cap( $cap );
					}
				}
			}
		}
		// Grants live in the data store and are removed with it.
	}

	/**
	 * Make sure the two literature roles exist (role definitions are WordPress core data).
	 */
	public static function ensure_admin_caps() {
		if ( ! get_role( 'bmlt_lit_worker' ) || ! get_role( 'bmlt_lit_chair' ) ) {
			self::install_roles();
		}
	}

	/**
	 * Resolve a user ID.
	 *
	 * @param int $user_id User ID (0 = current).
	 * @return int
	 */
	private static function uid( $user_id ) {
		return $user_id ? (int) $user_id : get_current_user_id();
	}

	/**
	 * Whether the user has any literature capability.
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function has_any( $user_id = 0 ) {
		$uid = self::uid( $user_id );
		if ( ! $uid ) {
			return false;
		}
		foreach ( self::caps() as $cap ) {
			if ( user_can( $uid, $cap ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the user is restricted to specific Areas.
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function is_area_restricted( $user_id = 0 ) {
		$uid = self::uid( $user_id );
		if ( ! $uid ) {
			return true;
		}
		if ( user_can( $uid, 'manage_options' ) || user_can( $uid, self::MANAGE_SETTINGS ) ) {
			return false;
		}
		return ! empty( self::grants_for( $uid )['areas'] );
	}

	/**
	 * Area IDs the user may work with (intersection with managed Areas).
	 *
	 * @param int $user_id User.
	 * @return int[]
	 */
	public static function allowed_areas( $user_id = 0 ) {
		$uid      = self::uid( $user_id );
		$selected = Settings::selected_areas();
		if ( ! $uid ) {
			return array();
		}
		if ( ! self::is_area_restricted( $uid ) ) {
			return $selected;
		}
		return array_values( array_intersect( $selected, self::grants_for( $uid )['areas'] ) );
	}

	/**
	 * Whether the user may access an Area. Area 0 (shared catalog) requires an unrestricted user.
	 *
	 * @param int $area_id Area ID.
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function can_area( $area_id, $user_id = 0 ) {
		$area_id = absint( $area_id );
		if ( 0 === $area_id ) {
			return self::uid( $user_id ) > 0 && ! self::is_area_restricted( $user_id );
		}
		return in_array( $area_id, self::allowed_areas( $user_id ), true );
	}

	/**
	 * Users who hold any literature capability.
	 *
	 * @return \WP_User[]
	 */
	public static function users_with_caps() {
		$by_role = get_users(
			array(
				'role__in' => array( 'administrator', 'bmlt_lit_worker', 'bmlt_lit_chair' ),
				'orderby'  => 'display_name',
				'number'   => 500,
			)
		);
		$doc     = Store::get( self::DOC, array( 'users' => array() ) );
		$ids     = isset( $doc['users'] ) && is_array( $doc['users'] ) ? array_map( 'absint', array_keys( $doc['users'] ) ) : array();
		$granted = $ids ? get_users(
			array(
				'include' => $ids,
				'orderby' => 'display_name',
			)
		) : array();
		$all     = array();
		foreach ( array_merge( $by_role, $granted ) as $u ) {
			if ( self::has_any( $u->ID ) ) {
				$all[ $u->ID ] = $u;
			}
		}
		uasort(
			$all,
			static function ( $a, $b ) {
				return strnatcasecmp( $a->display_name, $b->display_name );
			}
		);
		return array_values( $all );
	}
}
