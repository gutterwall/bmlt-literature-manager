<?php
/**
 * Activation, deactivation, the data-ownership check, and uninstall (single site and multisite).
 */

namespace BmltLitTest;

use BMLT_Lit\Activator;
use BMLT_Lit\Catalog;
use BMLT_Lit\Db;
use BMLT_Lit\Settings;

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
		$this->assertNotEmpty( Catalog::doc()['items'] );
		\BMLT_Lit\Bmlt_Client::schedule_cron();
	}

	public function test_data_created_by_another_site_url_is_flagged_until_adopted() {
		$this->assertSame( '', Settings::foreign_site() );
		$meta             = Settings::meta();
		$meta['site_url'] = 'https://staging.example.org/';
		Settings::save_meta( $meta );
		$this->assertSame( 'https://staging.example.org/', Settings::foreign_site() );
		$this->assertTrue( Settings::adopt_site() );
		$this->assertSame( '', Settings::foreign_site() );
	}

	public function test_tables_exist_and_support_transactions() {
		$this->assertSame( array(), Db::missing_tables() );
		$this->assertSame( Db::VERSION, (int) get_option( Db::DB_VERSION_OPTION ) );
		$h = Db::health();
		$this->assertSame( array(), $h['not_innodb'], 'every table supports transactions' );
		$this->assertSame( array(), $h['missing'] );
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

	/**
	 * Whether a table really exists (not a temporary one).
	 *
	 * @param string $name Table.
	 * @return bool
	 */
	private static function table_exists( $name ) {
		global $wpdb;
		return $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
	}

	public function test_uninstall_keeps_data_unless_the_administrator_opted_in() {
		$this->place_order( $this->order_post( array( 'BT' => 1 ) ) );
		$this->uninstall();
		$this->assertSame( 1, \BMLT_Lit\Orders::count_all() );

		$g                             = Settings::general();
		$g['delete_data_on_uninstall'] = 1;
		Settings::update( Settings::GENERAL, $g );
		// Uninstall drops real tables: no temporary tables in this test (and its transaction ends there).
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		mkdir( $this->dir, 0777, true );
		file_put_contents( $this->dir . '/catalog.json.php', "<?php exit; ?>\n{}" );
		$this->uninstall();
		foreach ( Db::TABLES as $short ) {
			$this->assertFalse( self::table_exists( Db::table( $short ) ), $short );
		}
		global $wpdb;
		$this->assertSame( '0', $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'bmlt\\_literature\\_%'" ) ); // phpcs:ignore WordPress.DB
		$this->assertFileDoesNotExist( $this->dir . '/catalog.json.php', 'the version 6 folder too' );
		$this->assertNull( get_role( 'bmlt_lit_worker' ) );
		self::reset_committed_data();
	}

	public function test_uninstall_on_a_network_removes_only_the_sites_that_opted_in() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$site = self::factory()->blog->create();
		switch_to_blog( $site );
		self::reset_plugin_state();
		delete_transient( 'bmlt_lit_init_attempt' );
		\BMLT_Lit\Migrator::maybe_initialize();
		wp_set_current_user( self::$admin );
		$this->assertIsInt( Catalog::create( array( 'sku' => 'SUBSITE', 'name' => 'Sub-site item', 'price' => '1' ) ) );
		$sub_items = Db::table( 'items' );
		restore_current_blog();
		self::reset_plugin_state();
		$this->assertNotSame( Db::table( 'items' ), $sub_items, 'each site has its own tables' );
		$this->assertNotContains( 'SUBSITE', wp_list_pluck( Catalog::doc()['items'], 'sku' ) );

		// The main site opts in; the other site does not.
		$g                             = Settings::general();
		$g['delete_data_on_uninstall'] = 1;
		Settings::update( Settings::GENERAL, $g );
		$this->uninstall();
		$this->assertFalse( self::table_exists( Db::table( 'items' ) ), 'the main site\'s data is removed' );
		$this->assertTrue( self::table_exists( $sub_items ), 'the other site\'s data is kept' );
		switch_to_blog( $site );
		self::reset_plugin_state();
		$this->assertSame( array( 'SUBSITE' ), array_values( wp_list_pluck( Catalog::doc()['items'], 'sku' ) ) );
		restore_current_blog();

		// Deleting a site from the network deletes its literature tables with it.
		wp_delete_site( $site );
		$this->assertFalse( self::table_exists( $sub_items ) );
		self::reset_committed_data();
	}
}
