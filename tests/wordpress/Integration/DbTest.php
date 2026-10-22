<?php
/**
 * Storage in the WordPress database: every change is saved completely or not at all — when the code says no, when
 * it fails, and when the database fails — and changes wait for each other.
 */

namespace BmltLitTest;

use BMLT_Lit\Catalog;
use BMLT_Lit\Db;
use BMLT_Lit\Inventory;
use BMLT_Lit\Orders;
use BMLT_Lit\Settings;

class DbTest extends TestCase {

	/**
	 * Make one kind of statement fail, as a database error would.
	 *
	 * @param string $prefix Start of the statement, e.g. "INSERT INTO `<table>`".
	 * @return callable The filter (remove it with remove_filter( 'query', ... )).
	 */
	private static function fail_statement( $prefix ) {
		$fail = static function ( $sql ) use ( $prefix ) {
			return 0 === strpos( ltrim( $sql ), $prefix ) ? 'SELECT broken FROM nowhere' : $sql;
		};
		add_filter( 'query', $fail );
		$GLOBALS['wpdb']->suppress_errors( true ); // The failure is the point; TestCase::tear_down() restores this.
		return $fail;
	}

	public function test_a_change_the_code_refuses_or_that_throws_leaves_nothing_behind() {
		wp_set_current_user( self::$admin );
		$before = self::everything_stored();
		$res    = Db::tx(
			function () {
				$this->assertIsInt( Catalog::create( array( 'sku' => 'TX1', 'name' => 'In a transaction', 'price' => '1' ) ) );
				$this->assertIsArray( Inventory::adjust( self::RIVERSIDE, $this->items['BT'], array( 'set' => 99 ) ) );
				return new \WP_Error( 'stop', 'stop' );
			}
		);
		$this->assertSame( 'stop', $res->get_error_code() );
		$res = Db::tx(
			static function () {
				Catalog::create( array( 'sku' => 'TX2', 'name' => 'In a transaction', 'price' => '1' ) );
				throw new \LogicException( 'boom' );
			}
		);
		$this->assertSame( 'The literature data could not be saved because of a system problem. Please try again.', $res->get_error_message() );
		self::reset_plugin_state();
		$this->assertSame( $before, self::everything_stored() );
		$this->assertSame( 5, Inventory::stock_map( array( self::RIVERSIDE ) )[ self::RIVERSIDE ][ $this->items['BT'] ]['on_hand'] );
	}

	public function test_a_failed_audit_entry_rolls_back_the_change_it_records() {
		wp_set_current_user( self::$admin );
		$before = self::everything_stored();
		$fail   = self::fail_statement( 'INSERT INTO `' . Db::table( 'audit' ) . '`' );
		$id     = Catalog::create( array( 'sku' => 'NEW', 'name' => 'New item', 'price' => '1' ) );
		$price  = Catalog::update( $this->items['BT'], array( 'price' => '99.00' ) );
		$stock  = Inventory::adjust( self::RIVERSIDE, $this->items['BT'], array( 'set' => 1 ) );
		remove_filter( 'query', $fail );
		foreach ( array( $id, $price, $stock ) as $res ) {
			$this->assertWPError( $res );
			$this->assertSame( 'bmlt_lit_storage', $res->get_error_code() );
			$this->assertStringContainsString( 'Nothing was changed', $res->get_error_message() );
		}
		self::reset_plugin_state();
		$this->assertSame( $before, self::everything_stored(), 'no item, no price change, no stock change, no used id' );
		$this->assertSame( '15.65', Catalog::get( $this->items['BT'] )->price );
	}

	public function test_an_order_whose_history_cannot_be_written_is_not_placed_and_reserves_nothing() {
		$before = self::everything_stored();
		$post   = $this->order_post( array( 'BT' => 2 ) );
		$review = $this->submit( $post );
		$fail   = self::fail_statement( 'INSERT INTO ' . Db::table( 'order_notes' ) );
		$post['bmlt_lit_step']       = 'confirm';
		$post['bmlt_lit_review_sig'] = $review['state']['review']['sig'];
		$result                      = $this->submit( $post );
		remove_filter( 'query', $fail );
		$this->assertNull( $result['redirect'], 'no confirmation page' );
		$this->assertNotEmpty( $result['state']['errors'] );
		self::reset_plugin_state();
		$this->assertSame( 0, Orders::count_all() );
		$this->assertSame( 0, Inventory::stock_map( array( self::RIVERSIDE ) )[ self::RIVERSIDE ][ $this->items['BT'] ]['reserved'] );
		$this->assertSame( $before, self::everything_stored() );
		$this->assertSame( array(), $this->mails(), 'nobody is told about an order that does not exist' );

		// The same submission goes through once the database works again.
		$this->assertNotNull( $this->submit( $post )['redirect'] );
		$this->assertSame( 1, Orders::count_all() );
	}

	public function test_a_status_change_moves_stock_and_history_together() {
		$order   = $this->place_order( $this->order_post( array( 'BT' => 2 ) ) )['order'];
		$history = count( Orders::get_notes( $order->id ) );
		wp_set_current_user( self::$worker );
		$fail = self::fail_statement( 'INSERT INTO `' . Db::table( 'order_notes' ) . '`' );
		$res  = Orders::update_status( $order->id, 'fulfilled' );
		remove_filter( 'query', $fail );
		$this->assertWPError( $res );
		self::reset_plugin_state();
		$this->assertSame( 'new', Orders::get( $order->id )->status );
		$stock = Inventory::stock_map( array( self::RIVERSIDE ) )[ self::RIVERSIDE ][ $this->items['BT'] ];
		$this->assertSame( array( 5, 2 ), array( $stock['on_hand'], $stock['reserved'] ), 'still reserved, not deducted' );
		$this->assertCount( $history, Orders::get_notes( $order->id ) );
		$this->assertTrue( Orders::update_status( $order->id, 'fulfilled' ) );
	}

	public function test_a_failed_inner_step_is_undone_while_the_outer_change_continues() {
		wp_set_current_user( self::$admin );
		$res = Db::tx(
			function () {
				$outer = Catalog::create( array( 'sku' => 'OUTER', 'name' => 'Outer', 'price' => '1' ) );
				$inner = Db::tx(
					static function () {
						Db::insert(
							'permissions',
							array(
								'user_id' => 999,
								'caps'    => 'bmlt_lit_view_orders',
								'areas'   => '',
							)
						);
						return new \WP_Error( 'inner', 'inner refused' );
					}
				);
				$this->assertSame( 'inner', $inner->get_error_code() );
				$dup = Catalog::create( array( 'sku' => 'OUTER', 'name' => 'Same SKU', 'price' => '1' ) );
				$this->assertSame( 'sku_dup', $dup->get_error_code(), 'a refused step inside an import is counted, not fatal' );
				return $outer;
			}
		);
		$this->assertIsInt( $res );
		self::reset_plugin_state();
		$this->assertSame( 'OUTER', Catalog::get( $res )->sku );
		$this->assertSame( array( 'caps' => array(), 'areas' => array() ), \BMLT_Lit\Permissions::grants_for( 999 ) );
	}

	public function test_a_failure_inside_an_inner_step_aborts_the_whole_change() {
		wp_set_current_user( self::$admin );
		$GLOBALS['wpdb']->suppress_errors( true );
		$before = self::everything_stored();
		$res    = Db::tx(
			static function () {
				Catalog::create( array( 'sku' => 'OUTER', 'name' => 'Outer', 'price' => '1' ) );
				Db::tx(
					static function () {
						Db::q( 'SELECT broken FROM nowhere' );
					}
				);
				return true;
			}
		);
		$this->assertSame( 'bmlt_lit_storage', $res->get_error_code() );
		self::reset_plugin_state();
		$this->assertSame( $before, self::everything_stored() );
	}

	public function test_an_import_that_fails_half_way_changes_nothing() {
		wp_set_current_user( self::$admin );
		$preview = \BMLT_Lit\Import_Export::preview_catalog(
			array(
				'format'  => 'bmlt-literature-manager',
				'version' => 1,
				'items'   => array(
					array( 'sku' => 'IMP-1', 'name' => 'First', 'price' => '1' ),
					array( 'sku' => 'IMP-2', 'name' => 'Second', 'price' => '1' ),
					array( 'sku' => 'IMP-3', 'name' => 'Third', 'price' => '1' ),
				),
			),
			0,
			false,
			false
		);
		$before = self::everything_stored();
		$count  = 0;
		$fail   = static function ( $sql ) use ( &$count ) {
			if ( 0 === strpos( $sql, 'INSERT INTO `' . Db::table( 'items' ) . '`' ) && ++$count > 2 ) {
				return 'SELECT broken FROM nowhere'; // The third item.
			}
			return $sql;
		};
		add_filter( 'query', $fail );
		$GLOBALS['wpdb']->suppress_errors( true );
		$res = \BMLT_Lit\Import_Export::apply( $preview );
		remove_filter( 'query', $fail );
		$this->assertWPError( $res );
		self::reset_plugin_state();
		$this->assertSame( $before, self::everything_stored(), 'not the first two items either' );
		$this->assertSame( 3, \BMLT_Lit\Import_Export::apply( $preview )['created'] );
	}

	public function test_settings_from_a_failed_change_never_reach_the_option_cache() {
		$name = Settings::get( 'org_name' );
		$this->assertSame( 'Fictional Region', $name );
		$res = Db::tx(
			function () {
				$g             = Settings::general();
				$g['org_name'] = 'Changed';
				$this->assertTrue( Settings::update( Settings::GENERAL, $g ) );
				$this->assertSame( 'Changed', Settings::get( 'org_name' ), 'the transaction sees its own change' );
				return new \WP_Error( 'stop', 'stop' );
			}
		);
		$this->assertWPError( $res );
		$this->assertSame( 'Fictional Region', Settings::get( 'org_name' ) );
		$this->assertSame( 'Fictional Region', get_option( Settings::option( Settings::GENERAL ) )['org_name'], 'WordPress\'s cache too' );

		$g             = Settings::general();
		$g['org_name'] = 'Saved';
		$this->assertTrue(
			Db::tx(
				static function () use ( $g ) {
					return Settings::update( Settings::GENERAL, $g );
				}
			)
		);
		$this->assertSame( 'Saved', get_option( Settings::option( Settings::GENERAL ) )['org_name'] );
		$this->assertSame( 'Saved', Settings::get( 'org_name' ) );
	}

	public function test_tables_are_never_created_inside_a_transaction() {
		$res = Db::tx(
			static function () {
				return Db::install(); // CREATE TABLE would commit the open transaction.
			}
		);
		$this->assertSame( 'bmlt_lit_db_install', $res->get_error_code() );
	}

	/* ------------------------------------------------------------------
	 * Changes wait for each other (a second database connection holds the lock).
	 * ---------------------------------------------------------------- */

	/**
	 * Hold the store lock from another process for some seconds.
	 *
	 * @param float $seconds Seconds.
	 * @return resource Process.
	 */
	private function hold_lock_elsewhere( $seconds ) {
		global $wpdb;
		// This test's transaction holds the lock since the set-up's changes: end it (nothing of it is needed here).
		$wpdb->query( 'ROLLBACK' );
		$GLOBALS['bmlt_lit_test_transaction'] = false;
		$ready = $this->dir . '-ready';
		$host  = explode( ':', DB_HOST );
		$code  = sprintf(
			'$db = new mysqli(%s, %s, %s, %s, %d); $db->query("START TRANSACTION"); $db->query("SELECT name FROM %s WHERE name = \'lock\' FOR UPDATE"); touch(%s); usleep(%d); $db->query("COMMIT");',
			var_export( $host[0], true ),
			var_export( DB_USER, true ),
			var_export( DB_PASSWORD, true ),
			var_export( DB_NAME, true ),
			isset( $host[1] ) ? (int) $host[1] : 3306,
			Db::table( 'counters' ),
			var_export( $ready, true ),
			(int) ( $seconds * 1000000 )
		);
		$proc = proc_open( array( PHP_BINARY, '-r', $code ), array(), $pipes );
		$wait = microtime( true );
		while ( ! file_exists( $ready ) && microtime( true ) - $wait < 10 ) {
			usleep( 20000 );
		}
		$this->assertFileExists( $ready, 'the other connection holds the lock' );
		unlink( $ready );
		return $proc;
	}

	public function test_a_change_waits_for_another_one_to_finish() {
		if ( ! function_exists( 'proc_open' ) || ! class_exists( 'mysqli' ) ) {
			$this->markTestSkipped( 'proc_open and mysqli are needed.' );
		}
		$proc  = $this->hold_lock_elsewhere( 1.5 );
		$start = microtime( true );
		$res   = Db::tx(
			static function () {
				return 'done';
			}
		);
		$waited = microtime( true ) - $start;
		proc_close( $proc );
		$this->assertSame( 'done', $res );
		$this->assertGreaterThan( 1.0, $waited, 'it waited for the other change' );
	}

	public function test_a_change_gives_up_when_another_one_takes_too_long() {
		if ( ! function_exists( 'proc_open' ) || ! class_exists( 'mysqli' ) ) {
			$this->markTestSkipped( 'proc_open and mysqli are needed.' );
		}
		$GLOBALS['bmlt_lit_test_lock_wait'] = 1;
		$GLOBALS['wpdb']->suppress_errors( true );
		$proc                               = $this->hold_lock_elsewhere( 4 );
		$ran                                = false;
		$res                                = Db::tx(
			static function () use ( &$ran ) {
				$ran = true;
				return true;
			}
		);
		proc_close( $proc );
		$this->assertSame( 'bmlt_lit_busy', $res->get_error_code() );
		$this->assertSame( 'The literature data is busy. Please try again in a moment.', $res->get_error_message() );
		$this->assertFalse( $ran, 'nothing ran without the lock' );
		$this->assertFalse( Db::in_tx() );
	}
}
