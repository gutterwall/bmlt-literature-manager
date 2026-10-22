<?php
/**
 * Upgrading from version 1.0, which kept everything in database tables and options: the data is copied into the
 * JSON store, sanitized, and the tables are left untouched.
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
		// CREATE and DROP TABLE commit the test's transaction: remove what this test left behind (rate-limit counters ...).
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%bmlt\\_lit\\_%'" ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'COMMIT' ); // Otherwise the test's rollback would undo this cleanup.
		wp_cache_flush();
		parent::tear_down();
	}

	public function test_version_1_database_data_is_copied_into_the_json_store() {
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
		$this->assertNotEmpty( glob( $this->dir . '/backups/*-pre-migration' ), 'the JSON data that existed was saved first' );
		$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM {$p}orders" ), 'the tables are left untouched' ); // phpcs:ignore WordPress.DB

		// New orders continue after the migrated numbers.
		$next = $this->place_order( $this->order_post( array(), array( 'bmlt_lit_qty' => array( 7 => '1' ) ) ) )['order'];
		$this->assertSame( 42, (int) $next->id );

		$this->assertTrue( Migrator::drop_legacy() );
		$this->assertFalse( Migrator::legacy_leftovers() );
	}
}
