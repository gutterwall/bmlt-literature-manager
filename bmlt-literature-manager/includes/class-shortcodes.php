<?php
/**
 * Shortcodes.
 *
 * @package BMLT_Lit
 * @version 7.0.0
 */

namespace BMLT_Lit;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's shortcodes.
 *
 * [bmlt_literature_order]    Public order form.
 * [bmlt_literature_manager]  Protected literature-worker workspace.
 * [bmlt_literature_orders]   Protected order history.
 */
class Shortcodes {

	/**
	 * Register shortcodes.
	 */
	public static function init() {
		add_shortcode( 'bmlt_literature_order', array( Front\Front::class, 'shortcode_order_form' ) );
		add_shortcode( 'bmlt_literature_manager', array( Front\Front::class, 'shortcode_manager' ) );
		add_shortcode( 'bmlt_literature_orders', array( Front\Front::class, 'shortcode_orders' ) );
	}

	/**
	 * Parse a comma-separated list of IDs from an untrusted attribute.
	 *
	 * @param string $value Attribute value.
	 * @return int[]
	 */
	public static function parse_ids( $value ) {
		$ids = array();
		foreach ( preg_split( '/[\s,]+/', (string) $value ) as $part ) {
			$id = absint( $part );
			if ( $id ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Interpret a yes/no attribute.
	 *
	 * @param string $value   Value.
	 * @param bool   $default Default.
	 * @return bool
	 */
	public static function yes( $value, $default = true ) {
		$value = strtolower( trim( (string) $value ) );
		if ( '' === $value ) {
			return $default;
		}
		return in_array( $value, array( 'yes', '1', 'true', 'on' ), true );
	}
}
