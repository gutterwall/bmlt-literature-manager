<?php
/**
 * JSON catalog and inventory import/export, the bundled starter catalog, and full backup and restore.
 */

namespace BmltLitTest;

use BMLT_Lit\Catalog;
use BMLT_Lit\Import_Export;
use BMLT_Lit\Inventory;
use BMLT_Lit\Orders;
use BMLT_Lit\Settings;
use BMLT_Lit\Store;

class ImportExportTest extends TestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::$admin );
	}

	public function test_starter_catalog_imports_as_documented() {
		$data    = Import_Export::decode( file_get_contents( BMLT_LIT_DIR . 'sample-data/naws-catalog-2026.json' ) );
		$preview = Import_Export::preview_catalog( $data, 0, true, false );
		$this->assertSame( array(), $preview['errors'] );
		$this->assertSame( 294, count( $preview['rows'] ), '174 items and 3 x 40 medallions' );
		$summary = Import_Export::apply( $preview );
		$this->assertSame( 0, $summary['failed'] );

		$items    = Catalog::query( array( 'area_id' => 0, 'include_shared' => false ) )['items'];
		$by_sku   = array();
		foreach ( $items as $it ) {
			$by_sku[ $it->sku ] = $it;
		}
		$inactive = array_filter(
			$items,
			static function ( $it ) {
				return ! $it->is_active;
			}
		);
		$this->assertCount( 17 + 1, $inactive, '17 unconfirmed prices, plus the retired test item' );
		foreach ( array( 'BRZ-01', 'BRZ-40', '7501', '7540', 'TRI-01', 'TRI-40' ) as $sku ) {
			$this->assertArrayHasKey( $sku, $by_sku, $sku );
		}
		$refs = array_filter(
			$items,
			static function ( $it ) {
				return '' !== $it->reference_url;
			}
		);
		$this->assertCount( 79, $refs, '79 reference links' );
		foreach ( $refs as $it ) {
			$this->assertStringStartsWith( 'https://na.org/', $it->reference_url );
		}

		// Importing again with "update existing" changes nothing.
		$again = Import_Export::preview_catalog( $data, 0, true, true );
		$this->assertSame( 294, $again['counts']['unchanged'] );
		$this->assertSame( 0, $again['counts']['new'] );
	}

	public function test_preview_reports_every_problem_and_changes_nothing() {
		$before = file_get_contents( Store::path( 'catalog' ) );
		$data   = array(
			'format'  => 'bmlt-literature-manager',
			'version' => 1,
			'items'   => array(
				array( 'sku' => 'BT', 'name' => 'Basic Text', 'price' => '16.00', 'category' => 'Books' ),
				array( 'sku' => 'NEW-1', 'name' => 'New pamphlet', 'price' => 0.5 ),
				array( 'sku' => 'new-1', 'name' => 'Duplicate in file', 'price' => '1' ),
				array( 'sku' => '../x', 'name' => 'Bad SKU', 'price' => '1' ),
				array( 'sku' => 'NEG', 'name' => 'Negative', 'price' => '-1' ),
				array( 'sku' => 'ARR', 'name' => array( 'nested' ), 'price' => '1' ),
				array( 'sku' => 'JS', 'name' => 'Link', 'price' => '1', 'reference_url' => 'javascript:alert(1)' ),
				'not an object',
			),
		);
		$p = Import_Export::preview_catalog( $data, 0, true, true );
		$this->assertSame( array( 'new' => 1, 'update' => 1, 'unchanged' => 0, 'skip' => 0, 'invalid' => 6 ), $p['counts'] );
		$this->assertCount( 6, $p['errors'] );
		$this->assertSame( array( 'IP1', 'KT-W', 'JFT' ), wp_list_pluck( $p['missing'], 'sku' ), 'active shared items missing from the file' );
		$this->assertSame( $before, file_get_contents( Store::path( 'catalog' ) ) );

		foreach ( array(
			array( array( 'format' => 'other', 'version' => 1, 'items' => array() ), 'format' ),
			array( array( 'format' => 'bmlt-literature-manager', 'version' => 2, 'items' => array() ), 'version' ),
			array( array( 'format' => 'bmlt-literature-manager', 'version' => 1, 'items' => array( 'a' => array() ) ), 'items' ),
		) as $bad ) {
			$this->assertSame( $bad[1], Import_Export::preview_catalog( $bad[0], 0, false, false )->get_error_code() );
		}
		$this->assertSame( 'json', Import_Export::decode( '{"a":' )->get_error_code() );
		$this->assertSame( 'json_type', Import_Export::decode( '"string"' )->get_error_code() );
		$this->assertSame( 'too_large', Import_Export::decode( str_repeat( ' ', Import_Export::MAX_BYTES + 1 ) )->get_error_code() );
		$this->assertSame( 'json', Import_Export::decode( str_repeat( '[', 40 ) . str_repeat( ']', 40 ) )->get_error_code(), 'nesting is limited' );

		$sum = Import_Export::apply( $p );
		$this->assertSame( array( 'created' => 1, 'updated' => 1, 'deactivated' => 3, 'failed' => 0 ), $sum );
		$this->assertSame( '16.00', Catalog::get( $this->items['BT'] )->price );
		$this->assertSame( 0, Catalog::get( $this->items['IP1'] )->is_active );
	}

	public function test_catalog_round_trip_into_an_area_catalog() {
		$json = Import_Export::to_json( Import_Export::export_catalog( 0 ) );
		$p    = Import_Export::preview_catalog( Import_Export::decode( $json ), self::HILLCREST, false, false );
		$this->assertSame( 5, $p['counts']['new'] );
		Import_Export::apply( $p );
		$hill = Catalog::query( array( 'area_id' => self::HILLCREST, 'include_shared' => false ) )['items'];
		$this->assertSame( array( 'BT', 'IP1', 'KT-W', 'JFT', 'OLD' ), wp_list_pluck( $hill, 'sku' ) );
		$this->assertSame( array( self::HILLCREST ), array_values( array_unique( array_map( 'intval', wp_list_pluck( $hill, 'area_id' ) ) ) ) );
	}

	public function test_inventory_round_trip_including_special_order_marks() {
		$export = Import_Export::export_inventory( self::RIVERSIDE );
		$rows   = array_column( $export['items'], null, 'sku' );
		$this->assertSame( 5, $rows['BT']['quantity_on_hand'] );
		$this->assertFalse( $rows['BT']['special'] );

		$data = array(
			'format'  => 'bmlt-literature-manager-inventory',
			'version' => 1,
			'items'   => array(
				array( 'sku' => 'bt', 'quantity_on_hand' => 40, 'low_stock_threshold' => '5' ),
				array( 'sku' => 'JFT', 'special' => true ),
				array( 'sku' => 'RS-SHIRT', 'quantity_on_hand' => -1 ),
				array( 'sku' => 'NOPE', 'quantity_on_hand' => 1 ),
				array( 'sku' => 'IP1', 'quantity_on_hand' => 1.5 ),
				array( 'sku' => 'KT-W', 'special' => 'maybe' ),
			),
		);
		$p = Import_Export::preview_inventory( $data, self::RIVERSIDE );
		$this->assertSame( array( 'update' => 2, 'unchanged' => 0, 'invalid' => 4 ), $p['counts'] );
		Import_Export::apply( $p );
		$stock = Inventory::stock_map( array( self::RIVERSIDE ) )[ self::RIVERSIDE ];
		$this->assertSame( 40, $stock[ $this->items['BT'] ]['on_hand'] );
		$this->assertSame( 5, $stock[ $this->items['BT'] ]['low'] );
		$this->assertSame( 1, $stock[ $this->items['JFT'] ]['special'] );
		$this->assertSame( 'area', Import_Export::preview_inventory( $data, 105 )->get_error_code(), 'only managed Areas' );
	}

	public function test_full_backup_round_trip_never_includes_the_smtp_password() {
		wp_set_current_user( 0 );
		$order = $this->place_order( $this->order_post( array( 'BT' => 2 ) ) )['order'];
		wp_set_current_user( self::$admin );
		\BMLT_Lit\Smtp::save( array( 'enabled' => 1, 'host' => 'smtp.example.org', 'username' => 'lit', 'password' => 'Secret-Pass-123', 'auth' => 1 ) );

		$backup = Import_Export::export_backup();
		$json   = Import_Export::to_json( $backup );
		$this->assertStringNotContainsString( 'Secret-Pass-123', $json );
		$this->assertSame( '', $backup['documents']['settings']['smtp']['password'] );
		$this->assertArrayNotHasKey( Orders::INDEX, $backup['documents'] );

		// Everything changes after the backup ...
		Catalog::update( $this->items['BT'], array( 'price' => '1.00' ) );
		Orders::update_status( $order->id, 'cancelled' );
		$this->place_order( $this->order_post( array( 'IP1' => 1 ) ) );

		// ... and restoring brings back exactly the backed-up data, keeping this site's SMTP password.
		$valid = Import_Export::validate_backup( Import_Export::decode( $json ) );
		$this->assertSame( 1, $valid['counts']['orders'] );
		$this->assertTrue( Import_Export::restore_backup( $valid ) );
		self::reset_plugin_state();
		$this->assertSame( '15.65', Catalog::get( $this->items['BT'] )->price );
		$this->assertSame( 1, Orders::count_all() );
		$this->assertSame( 'new', Orders::get( $order->id )->status );
		$this->assertSame( 'Secret-Pass-123', \BMLT_Lit\Smtp::password() );
		$this->assertNotEmpty( glob( $this->dir . '/backups/*-pre-restore' ), 'a snapshot was taken first' );
		$this->assertSame( 1, Orders::rebuild_index()['orders'], 'the index matches the restored month files' );
	}

	public function test_restore_sanitizes_everything_in_the_file() {
		$evil = array(
			'format'    => 'bmlt-literature-manager-backup',
			'version'   => 2,
			'documents' => array(
				'settings'          => array(
					'general'   => array( 'org_name' => '<script>x</script>Org', 'unknown_key' => 'x' ),
					'bmlt'      => array( 'root_url' => 'javascript:alert(1)', 'selected_areas' => array( '101', 'x', -5 ) ),
					'templates' => array( 'worker' => array( 'subject' => "Subj\r\nBcc: evil@example.org", 'body' => '<p onclick="x()">Hi</p><script>bad()</script>' ) ),
				),
				'catalog'           => array( 'items' => array( '7' => array( 'sku' => 'A', 'name' => '<img src=x onerror=alert(1)>Book', 'price' => '-3', 'image_url' => 'javascript:x' ) ) ),
				'permissions'       => array( 'users' => array( '5' => array( 'caps' => array( 'administrator', 'bmlt_lit_view_orders' ) ) ) ),
				'orders/2026-01'    => array( 'orders' => array( '3' => array( 'created_at' => '2026-01-05 10:00:00', 'status' => 'hacked', 'contact_name' => '<b>x</b>', 'items' => array( array( 'quantity' => -4, 'stock_status' => 'evil' ) ) ) ) ),
				'../../wp-config'   => array( 'x' => 1 ),
				'orders/2026-01/..' => array(),
			),
		);
		$v = Import_Export::validate_backup( $evil );
		$d = $v['documents'];
		$this->assertSame( array( 'settings', 'catalog', 'permissions', 'orders/2026-01' ), array_keys( $d ) );
		$this->assertSame( 'Org', $d['settings']['general']['org_name'] );
		$this->assertArrayNotHasKey( 'unknown_key', $d['settings']['general'] );
		$this->assertSame( '', $d['settings']['bmlt']['root_url'] );
		$this->assertSame( array( 101 ), $d['settings']['bmlt']['selected_areas'] );
		$this->assertStringNotContainsString( "\n", $d['settings']['templates']['worker']['subject'] );
		$this->assertSame( '<p>Hi</p>bad()', $d['settings']['templates']['worker']['body'] );
		$this->assertSame( 'Book', $d['catalog']['items'][7]['name'] );
		$this->assertSame( '0.00', $d['catalog']['items'][7]['price'] );
		$this->assertSame( '', $d['catalog']['items'][7]['image_url'] );
		$this->assertSame( array( 'bmlt_lit_view_orders' ), $d['permissions']['users'][5]['caps'] );
		$o = $d['orders/2026-01']['orders'][3];
		$this->assertSame( array( 'new', 'x', 0, 'in_stock' ), array( $o['status'], $o['contact_name'], $o['items'][0]['quantity'], $o['items'][0]['stock_status'] ) );
		$this->assertSame( 32, strlen( $o['access_key'] ), 'a fresh secret key' );

		$this->assertSame( 'format', Import_Export::validate_backup( array( 'format' => 'x' ) )->get_error_code() );
		$this->assertSame( 'empty', Import_Export::validate_backup( array( 'format' => 'bmlt-literature-manager-backup', 'version' => 2, 'documents' => array() ) )->get_error_code() );
	}
}
