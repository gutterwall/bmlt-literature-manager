<?php
/**
 * Core plugin bootstrap.
 *
 * @package BMLT_Lit
 * @version 6.2.0
 */

namespace BMLT_Lit;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every component together.
 */
final class Plugin {

	/**
	 * Whether boot() has run.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Boot the plugin (runs on plugins_loaded).
	 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'init', array( __CLASS__, 'load_textdomain' ), 1 );

		Permissions::init();
		add_action( 'init', array( Migrator::class, 'maybe_initialize' ), 5 );
		Email::init();
		Smtp::init();
		Bmlt_Client::init();
		Shortcodes::init();
		Rest::init();
		Privacy::init();
		Front\Front::init();

		if ( is_admin() ) {
			Admin\Admin::init();
		}

		add_action( 'admin_init', array( Permissions::class, 'ensure_admin_caps' ) );
	}

	/**
	 * Load translations.
	 */
	public static function load_textdomain() {
		load_plugin_textdomain( 'bmlt-literature-manager', false, dirname( BMLT_LIT_BASENAME ) . '/languages' );
	}
}
