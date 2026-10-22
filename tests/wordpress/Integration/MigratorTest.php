<?php
/**
 * Upgrading from earlier versions: the JSON data folder of versions 2 to 6, and the database tables and options of
 * version 1.0. The data is copied into the plugin's tables in one transaction, sanitized, and the old data is left
 * untouched.
 */

namespace BmltLitTest;

use BMLT_Lit\Catalog;
use BMLT_Lit\Inventory;
use BMLT_Lit\Migrator;
use BMLT_Lit\Orders;
use BMLT_Lit\Permissions;
use BMLT_Lit\Settings;

class MigratorTest extends TestCase {

	const TABLES = array( 'items', 'inventory', 'orders', 'order_items', 'order_notes', 'audit' );

	public function tear_down() {
		global $wpdb;
		foreach ( self::TABLES as $t ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bmlt_lit_{$t}" ); // phpcs:ignore WordPress.DB
		}
		delete_option( 'bmlt_lit_settings' );
		delete_option( 'bmlt_lit_bmlt' );
		delete_option( 'bmlt_lit_areas' );
		delete_user_meta( self::$restricted, Permissions::AREA_META );
		// CREATE and DROP TABLE commit the test's transaction: remove what this test left behind.
		self::reset_committed_data();
		parent::tear_down();
	}

	public function test_version_1_database_data_is_copied_into_the_tables() {
		global $wpdb;
		// Real tables (the test suite would otherwise make temporary ones, which SHOW TABLES does not list).
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$p = $wpdb->prefix . 'bmlt_lit_';
		$wpdb->query( "CREATE TABLE {$p}items (id bigint PRIMARY KEY, area_id bigint, sku varchar(64), name varchar(255), short_description text, long_description longtext, category varchar(100), price decimal(10,2), is_active tinyint, sort_order int, image_url text, naws_id varchar(64), created_at datetime, updated_at datetime)" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "CREATE TABLE {$p}inventory (id bigint PRIMARY KEY, area_id bigint, item_id bigint, quantity_on_hand int, quantity_reserved int, low_stock_threshold int, reorder_quantity int, notes text, updated_at datetime, updated_by bigint)" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "CREATE TABLE {$p}orders (id bigint PRIMARY KEY, order_number varchar(40), access_key varchar(64), submission_hash varchar(64), area_id bigint, area_name varchar(255), meeting_id bigint, meeting_name varchar(255), meeting_day tinyint, meeting_time varchar(10), meeting_location varchar(255), contact_name varchar(200), contact_phone varchar(50), contact_email varchar(200), notes text, subtotal decimal(10,2), total decimal(10,2), item_count int, status varchar(30), has_special_order tinyint, inventory_mode varchar(20), inventory_state varchar(20), ip_hash varchar(64), created_by bigint, created_at datetime, updated_at datetime, updated_by bigint, fulfilled_at datetime, fulfilled_by bigint)" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "CREATE TABLE {$p}order_items (id bigint PRIMARY KEY, order_id bigint, item_id bigint, sku varchar(64), name varchar(255), description text, quantity int, quantity_in_stock int, quantity_special int, quantity_reserved int, unit_price decimal(10,2), line_total decimal(10,2), stock_status varchar(20), is_special_order tinyint)" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "CREATE TABLE {$p}order_notes (id bigint PRIMARY KEY, order_id bigint, user_id bigint, note_type varchar(20), old_status varchar(30), new_status varchar(30), note text, created_at datetime)" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "CREATE TABLE {$p}audit (id bigint PRIMARY KEY, area_id bigint, user_id bigint, action varchar(64), object_type varchar(32), object_id bigint, old_value longtext, new_value longtext, note text, created_at datetime)" ); // phpcs:ignore WordPress.DB

		$wpdb->insert( "{$p}items", array( 'id' => 7, 'area_id' => 0, 'sku' => 'BT', 'name' => 'Basic Text <script>x</script>', 'price' => '15.65', 'is_active' => 1, 'sort_order' => 1, 'created_at' => '2025-01-01 00:00:00', 'updated_at' => '2025-01-01 00:00:00' ) );
		$wpdb->insert( "{$p}items", array( 'id' => 9, 'area_id' => 101, 'sku' => 'RS', 'name' => 'Riverside item', 'price' => '2.00', 'is_active' => 0, 'sort_order' => 2, 'created_at' => '2025-01-01 00:00:00', 'updated_at' => '2025-01-01 00:00:00' ) );
		$wpdb->insert( "{$p}inventory", array( 'id' => 1, 'area_id' => 101, 'item_id' => 7, 'quantity_on_hand' => 12, 'quantity_reserved' => 2, 'low_stock_threshold' => 3, 'reorder_quantity' => 10, 'notes' => 'shelf A' ) );
		$wpdb->insert( "{$p}inventory", array( 'id' => 2, 'area_id' => 101, 'item_id' => 99, 'quantity_on_hand' => 5 ) );
		$wpdb->insert( "{$p}orders", array( 'id' => 41, 'order_number' => 'LIT-2025-000041', 'access_key' => str_repeat( 'k', 32 ), 'submission_hash' => str_repeat( 'a', 64 ), 'area_id' => 101, 'area_name' => 'Riverside Area', 'meeting_id' => 1001, 'meeting_name' => 'Just for Today', 'contact_name' => 'Old Customer', 'contact_email' => 'old@example.org', 'subtotal' => '31.30', 'total' => '31.30', 'item_count' => 2, 'status' => 'ready', 'inventory_mode' => 'reserve', 'inventory_state' => 'reserved', 'created_at' => '2025-03-04 10:00:00', 'updated_at' => '2025-03-05 10:00:00' ) );
		$wpdb->insert( "{$p}order_items", array( 'id' => 1, 'order_id' => 41, 'item_id' => 7, 'sku' => 'BT', 'name' => 'Basic Text', 'quantity' => 2, 'quantity_in_stock' => 2, 'quantity_reserved' => 2, 'unit_price' => '15.65', 'line_total' => '31.30', 'stock_status' => 'in_stock' ) );
		$wpdb->insert( "{$p}order_notes", array( 'id' => 1, 'order_id' => 41, 'user_id' => 0, 'note_type' => 'system', 'note' => 'Order submitted.', 'created_at' => '2025-03-04 10:00:00' ) );
		$wpdb->insert( "{$p}audit", array( 'id' => 1, 'area_id' => 101, 'user_id' => self::$admin, 'action' => 'inventory_adjusted', 'object_type' => 'inventory', 'object_id' => 7, 'old_value' => '{"on_hand":10}', 'new_value' => '{"on_hand":12}', 'created_at' => '2025-02-01 09:00:00' ) );
		update_option( 'bmlt_lit_settings', array( 'org_name' => 'Legacy Org', 'order_prefix' => 'OLD' ) );
		update_option( 'bmlt_lit_bmlt', array( 'root_url' => self::BMLT, 'selected_areas' => array( 101, 102 ) ) );
		update_option( 'bmlt_lit_areas', array( 101 => array( 'worker_emails' => 'legacy@example.org, not-an-email' ) ) );
		update_user_meta( self::$restricted, Permissions::AREA_META, array( 101 ) );

		$summary = Migrator::migrate();
		$this->assertSame( array( 'items' => 2, 'inventory' => 1, 'orders' => 1, 'audit' => 1, 'users' => 1 ), $summary, 'the orphan inventory row is skipped' );
		self::reset_plugin_state();

		$this->assertSame( 'Basic Text', Catalog::get( 7 )->name );
		$this->assertSame( 10, (int) Catalog::doc()['next_id'] );
		$this->assertSame( 'Legacy Org', Settings::get( 'org_name' ) );
		$this->assertSame( array( 101, 102 ), Settings::selected_areas() );
		$this->assertSame( 'legacy@example.org', Settings::area( 101 )['worker_emails'] );
		$stock = Inventory::stock_map( array( 101 ) )[101][7];
		$this->assertSame( array( 12, 2, 10 ), array( $stock['on_hand'], $stock['reserved'], $stock['available'] ) );
		$order = Orders::get( 41 );
		$this->assertSame( array( 'LIT-2025-000041', 'ready', 'Old Customer' ), array( $order->order_number, $order->status, $order->contact_name ) );
		$this->assertNull( Orders::get_by_access( 'LIT-2025-000041', str_repeat( 'k', 32 ) ), 'old confirmation links have expired' );
		$this->assertSame( array( 101 ), Permissions::grants_for( self::$restricted )['areas'] );
		$this->assertTrue( Catalog::is_referenced( 7 ) );
		$this->assertNotEmpty( Settings::meta()['migrated_from_db'] );
		$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM {$p}orders" ), 'the tables are left untouched' ); // phpcs:ignore WordPress.DB

		// New orders continue after the migrated numbers.
		$next = $this->place_order( $this->order_post( array(), array( 'bmlt_lit_qty' => array( 7 => '1' ) ) ) )['order'];
		$this->assertSame( 42, (int) $next->id );

		$this->assertTrue( Migrator::drop_legacy() );
		$this->assertFalse( Migrator::legacy_leftovers() );
	}

	/* ------------------------------------------------------------------
	 * The data folder of version 6.
	 * ---------------------------------------------------------------- */

	/**
	 * Write this site's data as a version 6.3 data folder would hold it, then forget it in the database, the way a
	 * site looks right after updating from 6.3 to 7.0.
	 *
	 * @return array The documents written.
	 */
	private function make_version_6_folder() {
		wp_set_current_user( 0 );
		$order = $this->place_order( $this->order_post( array( 'BT' => 2, 'KT-W' => 1 ), array( 'bmlt_lit_notes' => 'Side door' ) ) )['order'];
		wp_set_current_user( self::$admin );
		\BMLT_Lit\Orders::update_status( $order->id, 'ready', 'On the shelf' );
		\BMLT_Lit\Smtp::save( array( 'enabled' => 1, 'host' => 'smtp.example.org', 'auth' => 1, 'username' => 'lit', 'password' => 'Folder-Pass-1', 'from_email' => 'lit@example.org' ) );
		$docs                                = \BMLT_Lit\Import_Export::documents();
		$docs['settings']['smtp']['password'] = Settings::smtp()['password'];
		$docs['settings']['meta']            = array(
			'schema'   => 3,
			'site_url' => 'https://example.org/',
			'created'  => 1700000000,
			'store_id' => 'AbC123',
		);
		$docs['cache/bmlt'] = array();
		foreach ( \BMLT_Lit\Bmlt_Client::cache_sections() as $section ) {
			$docs['cache/bmlt'][ $section ] = get_option( \BMLT_Lit\Bmlt_Client::cache_option( $section ) );
		}
		// The order index of version 6 (rebuilt from the month files, never read by the move).
		$docs['orders/index'] = array( 'next_id' => 2, 'orders' => array(), 'hashes' => array(), 'refs' => array() );
		foreach ( $docs as $name => $doc ) {
			if ( ! is_dir( dirname( $this->dir . '/' . $name ) ) ) {
				mkdir( dirname( $this->dir . '/' . $name ), 0777, true );
			}
			file_put_contents( $this->dir . '/' . $name . '.json.php', "<?php exit; ?>\n" . wp_json_encode( $doc, JSON_PRETTY_PRINT ) );
			file_put_contents( $this->dir . '/' . $name . '.bak.json.php', "<?php exit; ?>\n" . wp_json_encode( $doc ) );
		}
		$this->forget_database_data();
		return $docs;
	}

	/**
	 * Empty the plugin's tables and options (inside this test's transaction).
	 */
	private function forget_database_data() {
		global $wpdb;
		foreach ( \BMLT_Lit\Db::TABLES as $short ) {
			if ( 'counters' !== $short ) {
				$wpdb->query( 'DELETE FROM ' . \BMLT_Lit\Db::table( $short ) ); // phpcs:ignore WordPress.DB
			}
		}
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'bmlt\\_literature\\_%' AND option_name <> 'bmlt_literature_db_version'" ); // phpcs:ignore WordPress.DB
		wp_cache_flush();
		self::reset_plugin_state();
		delete_transient( 'bmlt_lit_init_attempt' );
		$this->assertFalse( Settings::initialized() );
	}

	/**
	 * Checksums of every file in the folder.
	 *
	 * @return array
	 */
	private function folder_state() {
		$out = array();
		$it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			$out[ substr( $f->getPathname(), strlen( $this->dir ) ) ] = md5_file( $f->getPathname() );
		}
		ksort( $out );
		return $out;
	}

	public function test_version_6_data_folder_moves_into_the_database_unchanged() {
		$docs   = $this->make_version_6_folder();
		$before = $this->folder_state();

		Migrator::maybe_initialize();
		self::reset_plugin_state();
		$this->assertTrue( Settings::initialized() );
		$this->assertSame( '', Migrator::init_error() );

		// Everything is there, as it was.
		$now = \BMLT_Lit\Import_Export::documents();
		foreach ( array( 'catalog', 'inventory', 'permissions' ) as $name ) {
			$this->assertEquals( $docs[ $name ], $now[ $name ], $name );
		}
		$month = 'orders/' . gmdate( 'Y-m' );
		$this->assertEquals( $docs[ $month ], $now[ $month ], 'orders with their lines and history' );
		foreach ( array( 'general', 'bmlt', 'areas', 'templates' ) as $section ) {
			$this->assertEquals( $docs['settings'][ $section ], Settings::raw( $section ), $section );
		}
		$this->assertSame( 'Folder-Pass-1', \BMLT_Lit\Smtp::password(), 'the saved SMTP password moves too' );
		$this->assertCount( count( $docs[ 'audit/' . gmdate( 'Y-m' ) ]['entries'] ) + 1, $now[ 'audit/' . gmdate( 'Y-m' ) ]['entries'], 'plus one entry for the move' );
		$meta = Settings::meta();
		$this->assertSame( array( 'https://example.org/', 1700000000, 'AbC123', 4 ), array( $meta['site_url'], $meta['created'], $meta['store_id'], $meta['schema'] ) );
		$this->assertNotEmpty( $meta['migrated_from_files'] );
		$this->assertEquals( $docs['cache/bmlt']['meetings'], get_option( \BMLT_Lit\Bmlt_Client::cache_option( 'meetings' ) ), 'the last BMLT answers move too' );
		$requests = count( self::$bmlt_requests );

		// Ordering continues after the moved order numbers; the moved order still works.
		$order = \BMLT_Lit\Orders::get( 1 );
		$this->assertSame( 'ready', $order->status );
		$this->assertTrue( \BMLT_Lit\Catalog::is_referenced( $this->items['BT'] ) );
		$next = $this->place_order( $this->order_post( array( 'IP1' => 1 ) ) )['order'];
		$this->assertSame( 2, (int) $next->id );
		$this->assertCount( $requests, self::$bmlt_requests, 'from the moved cache, without asking BMLT' );

		// The folder was not touched, and is only removed on request.
		$this->assertSame( $before, $this->folder_state() );
		Migrator::maybe_initialize();
		$this->assertSame( 2, \BMLT_Lit\Orders::count_all(), 'moved once' );
		$this->assertTrue( Migrator::remove_files() );
		$this->assertDirectoryDoesNotExist( $this->dir );
	}

	public function test_a_damaged_file_is_read_from_its_previous_version() {
		$this->make_version_6_folder();
		file_put_contents( $this->dir . '/catalog.json.php', "<?php exit; ?>\n{\"items\": {\"1\": trunc" );
		Migrator::maybe_initialize();
		self::reset_plugin_state();
		$this->assertTrue( Settings::initialized() );
		$this->assertSame( 'BT', \BMLT_Lit\Catalog::get( $this->items['BT'] )->sku );
	}

	public function test_a_damaged_file_without_a_readable_copy_stops_the_move_and_changes_nothing() {
		$this->make_version_6_folder();
		file_put_contents( $this->dir . '/inventory.json.php', "<?php exit; ?>\nnot json" );
		unlink( $this->dir . '/inventory.bak.json.php' );
		$before = self::everything_stored();
		$files  = $this->folder_state();

		Migrator::maybe_initialize();
		self::reset_plugin_state();
		$this->assertFalse( Settings::initialized() );
		$this->assertStringContainsString( 'The data file "inventory.json.php" is damaged', Migrator::init_error() );
		$this->assertSame( $before, self::everything_stored(), 'nothing was written' );
		$this->assertSame( $files, $this->folder_state() );

		// Restoring the file lets the next attempt succeed.
		file_put_contents( $this->dir . '/inventory.json.php', "<?php exit; ?>\n{\"areas\": {}}" );
		delete_transient( 'bmlt_lit_init_attempt' );
		Migrator::maybe_initialize();
		$this->assertTrue( Settings::initialized() );
		$this->assertSame( '', Migrator::init_error() );
	}

	public function test_a_move_that_fails_half_way_leaves_nothing_behind() {
		$this->make_version_6_folder();
		$before = self::everything_stored();
		// The orders are written after the settings, the catalog and the inventory: make the first order fail.
		$fail = static function ( $sql ) {
			return false !== strpos( $sql, 'INSERT INTO `' . \BMLT_Lit\Db::table( 'orders' ) . '`' ) ? 'SELECT broken FROM nowhere' : $sql;
		};
		add_filter( 'query', $fail );
		$GLOBALS['wpdb']->suppress_errors( true );
		Migrator::maybe_initialize();
		remove_filter( 'query', $fail );
		self::reset_plugin_state();
		$this->assertFalse( Settings::initialized() );
		$this->assertNotSame( '', Migrator::init_error() );
		$this->assertSame( $before, self::everything_stored(), 'no settings, no catalog, no inventory: nothing' );
	}
}
