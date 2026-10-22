<?php
/**
 * Plugin Name:       BMLT Literature Manager
 * Plugin URI:        https://bmlt.app/
 * Description:       Narcotics Anonymous literature ordering with Area-specific inventory, pricing, special orders, order management, email notifications, JSON import/export and SMTP — integrated with the BMLT. Data is kept in the plugin's own WordPress database tables, and every change is saved completely or not at all.
 * Version: 7.0.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            BMLT Literature Manager Contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bmlt-literature-manager
 * Domain Path:       /languages
 *
 * @package BMLT_Lit
 * @version 7.0.0
 */

defined( 'ABSPATH' ) || exit;

define( 'BMLT_LIT_VERSION', '7.0.0' );
define( 'BMLT_LIT_FILE', __FILE__ );
define( 'BMLT_LIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'BMLT_LIT_URL', plugin_dir_url( __FILE__ ) );
define( 'BMLT_LIT_BASENAME', plugin_basename( __FILE__ ) );

/*
 * PSR-4-style autoloader for the BMLT_Lit namespace.
 *
 * BMLT_Lit\Orders              -> includes/class-orders.php
 * BMLT_Lit\Admin\Admin         -> admin/class-admin.php
 * BMLT_Lit\Front\Front         -> public/class-front.php
 */
spl_autoload_register(
	static function ( $class ) {
		$prefix = 'BMLT_Lit\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$parts    = explode( '\\', $relative );
		$name     = array_pop( $parts );
		$map      = array(
			'admin' => 'admin',
			'front' => 'public',
		);
		$dir      = 'includes';
		if ( ! empty( $parts ) ) {
			$key = strtolower( $parts[0] );
			if ( ! isset( $map[ $key ] ) ) {
				return;
			}
			$dir = $map[ $key ];
		}
		$file = BMLT_LIT_DIR . $dir . '/class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'BMLT_Lit\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BMLT_Lit\\Activator', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'BMLT_Lit\\Plugin', 'boot' ) );
