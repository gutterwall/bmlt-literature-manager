<?php
/**
 * Activation, deactivation, the data-ownership check, and uninstall (single site and multisite).
 */

namespace BmltLitTest;

use BMLT_Lit\Activator;
use BMLT_Lit\Settings;
use BMLT_Lit\Store;

class LifecycleTest extends TestCase {

	public function test_activation_creates_roles_and_admins_get_every_capability() {
		$this->assertNotNull( get_role( 'bmlt_lit_worker' ) );
		$this->assertTrue( get_role( 'bmlt_lit_chair' )->has_cap( 'bmlt_lit_manage_catalog' ) );
		$this->assertFalse( get_role( 'bmlt_lit_worker' )->has_cap( 'bmlt_lit_manage_catalog' ) );
		foreach ( \BMLT_Lit\Permissions::caps() as $cap ) {
			$this->assertTrue( user_can( self::$admin, $cap ), $cap );
			$this->assertFalse( user_can( self::$subscriber, $cap ), $cap );
		}
		$this->assertTrue( user_can( self::$settings_mgr, 'bmlt_lit_manage_settings' ), 'granted in the data store' );
		$this->assertFalse( user_can( self::$settings_mgr, 'bmlt_lit_view_orders' ) );
	}

	public function test_deactivation_keeps_data_and_stops_the_schedule() {
		Activator::deactivate();
		$this->assertFalse( wp_next_scheduled( \BMLT_Lit\Bmlt_Client::CRON_HOOK ) );
		$this->assertTrue( Store::exists( 'catalog' ) );
		\BMLT_Lit\Bmlt_Client::schedule_cron();
	}

	public function test_data_created_by_another_site_url_is_flagged_until_adopted() {
		$this->assertSame( '', Settings::foreign_site() );
		$doc                     = Store::get( Settings::DOC );
		$doc['meta']['site_url'] = 'https://staging.example.org/';
		Store::put( Settings::DOC, $doc );
		Settings::flush();
		$this->assertSame( 'https://staging.example.org/', Settings::foreign_site() );
		$this->assertTrue( Settings::adopt_site() );
		$this->assertSame( '', Settings::foreign_site() );
	}

	/**
	 * Run uninstall.php.
	 */
	private function uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'bmlt-literature-manager/bmlt-literature-manager.php' );
		}
		self::reset_plugin_state();
		include BMLT_LIT_DIR . 'uninstall.php';
		self::reset_plugin_state();
	}

	public function test_uninstall_keeps_data_unless_the_administrator_opted_in() {
		$this->place_order( $this->order_post( array( 'BT' => 1 ) ) );
		$this->uninstall();
		$this->assertTrue( Store::exists( 'orders/index' ) );

		$g                             = Settings::general();
		$g['delete_data_on_uninstall'] = 1;
		Settings::update( Settings::GENERAL, $g );
		$this->uninstall();
		$this->assertDirectoryDoesNotExist( $this->dir );
		$this->assertNull( get_role( 'bmlt_lit_worker' ) );
		\BMLT_Lit\Permissions::install_roles();
	}

	public function test_uninstall_on_a_network_removes_only_the_sites_that_opted_in() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		$site = self::factory()->blog->create();
		switch_to_blog( $site );
		self::reset_plugin_state();
		delete_transient( 'bmlt_lit_init_attempt' );
		\BMLT_Lit\Migrator::maybe_initialize();
		Store::put( 'catalog', array( 'items' => array( 1 => array( 'sku' => 'SUBSITE' ) ) ) );
		$sub_dir = Store::dir();
		restore_current_blog();
		self::reset_plugin_state();
		$this->assertSame( $this->dir . '/site-' . $site, $sub_dir );

		// The main site opts in; the other site does not.
		$g                             = Settings::general();
		$g['delete_data_on_uninstall'] = 1;
		Settings::update( Settings::GENERAL, $g );
		$this->uninstall();
		$this->assertFalse( file_exists( $this->dir . '/catalog.json.php' ), 'the main site\'s data is removed' );
		$this->assertFileExists( $sub_dir . '/catalog.json.php', 'the other site\'s data is kept' );
		\BMLT_Lit\Permissions::install_roles();
	}
}
