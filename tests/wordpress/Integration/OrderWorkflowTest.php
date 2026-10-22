<?php
/**
 * Order statuses and their effect on inventory under each "Inventory when an order is placed" setting, history,
 * the order index and the order list filters.
 */

namespace BmltLitTest;

use BMLT_Lit\Inventory;
use BMLT_Lit\Orders;
use BMLT_Lit\Settings;
use BMLT_Lit\Store;

class OrderWorkflowTest extends TestCase {

	private function mode( $mode ) {
		$g                     = Settings::general();
		$g['reservation_mode'] = $mode;
		Settings::update( Settings::GENERAL, $g );
	}

	private function bt() {
		$s = Inventory::stock_map( array( self::RIVERSIDE ) )[ self::RIVERSIDE ][ $this->items['BT'] ];
		return array( $s['on_hand'], $s['reserved'] );
	}

	/**
	 * @return array [mode, after order, after fulfilled, after cancelled (second order)]
	 */
	public function modes() {
		return array(
			'reserve, then deduct' => array( 'reserve', array( 5, 3 ), array( 2, 0 ), array( 5, 0 ) ),
			'deduct immediately'   => array( 'deduct', array( 2, 0 ), array( 2, 0 ), array( 5, 0 ) ),
			'leave unchanged'      => array( 'none', array( 5, 0 ), array( 2, 0 ), array( 5, 0 ) ),
		);
	}

	/**
	 * @dataProvider modes
	 */
	public function test_inventory_follows_the_order_through_its_statuses( $mode, $placed, $fulfilled, $cancelled ) {
		$this->mode( $mode );
		$a = $this->place_order( $this->order_post( array( 'BT' => 3 ) ) )['order'];
		$this->assertSame( $placed, $this->bt(), 'placed' );
		wp_set_current_user( self::$worker );
		foreach ( array( 'reviewed', 'ready', 'partial' ) as $status ) {
			$this->assertTrue( Orders::update_status( $a->id, $status ) );
			$this->assertSame( $placed, $this->bt(), $status . ' changes nothing' );
		}
		$this->assertTrue( Orders::update_status( $a->id, 'fulfilled', 'Handed over at ASC' ) );
		$this->assertSame( $fulfilled, $this->bt(), 'fulfilled' );
		$this->assertNotEmpty( Orders::get( $a->id )->fulfilled_at );

		// Restock, then cancel a second order: everything comes back.
		\BMLT_Lit\Inventory::adjust( self::RIVERSIDE, $this->items['BT'], array( 'set' => 5 ) );
		wp_set_current_user( 0 );
		$b = $this->place_order( $this->order_post( array( 'BT' => 3 ), array( 'bmlt_lit_name' => 'Second' ) ) )['order'];
		wp_set_current_user( self::$worker );
		$this->assertTrue( Orders::update_status( $b->id, 'cancelled' ) );
		$this->assertSame( $cancelled, $this->bt(), 'cancelled' );
		$this->assertSame( $mode, Orders::get( $b->id )->inventory_mode, 'each order remembers its mode' );
	}

	public function test_an_order_keeps_the_mode_it_was_placed_under() {
		$this->mode( 'reserve' );
		$a = $this->place_order( $this->order_post( array( 'BT' => 3 ) ) )['order'];
		$this->mode( 'deduct' );
		wp_set_current_user( self::$worker );
		$this->assertTrue( Orders::update_status( $a->id, 'cancelled' ) );
		$this->assertSame( array( 5, 0 ), $this->bt(), 'the reservation is released, nothing is added' );
	}

	public function test_fulfilled_and_cancelled_are_final() {
		$a = $this->place_order( $this->order_post( array( 'BT' => 1 ) ) )['order'];
		wp_set_current_user( self::$worker );
		$this->assertWPError( Orders::update_status( $a->id, 'new' ), 'same status' );
		$this->assertWPError( Orders::update_status( $a->id, 'shipped' ), 'unknown status' );
		$this->assertTrue( Orders::update_status( $a->id, 'fulfilled' ) );
		$res = Orders::update_status( $a->id, 'cancelled' );
		$this->assertWPError( $res );
		$this->assertSame( 'final', $res->get_error_code() );
		$this->assertSame( array( 4, 0 ), $this->bt(), 'nothing restocked' );
		$this->assertWPError( Orders::update_status( 999, 'ready' ) );
	}

	public function test_history_records_who_did_what() {
		$a = $this->place_order( $this->order_post( array( 'BT' => 2 ) ) )['order'];
		wp_set_current_user( self::$worker );
		Orders::update_status( $a->id, 'ready', 'On the shelf' );
		Orders::add_note( $a->id, 'note', 'Called the group' );
		$notes = Orders::get_notes( $a->id );
		$this->assertSame( array( 'note', 'status', 'email', 'system' ), wp_list_pluck( $notes, 'note_type' ), 'newest first' );
		$this->assertSame( "Status changed from New to Ready.\nOn the shelf", $notes[1]->note );
		$this->assertSame( self::$worker, (int) $notes[0]->user_id );
		$detail = Orders::detail( Orders::get( $a->id ) );
		$this->assertSame( 'lit-worker', $detail['history'][0]['user'] );
		$this->assertSame( 'System / public', $detail['history'][3]['user'] );
	}

	public function test_order_index_can_be_rebuilt_and_order_numbers_are_never_reused() {
		$a = $this->place_order( $this->order_post( array( 'BT' => 1 ) ) )['order'];
		$b = $this->place_order( $this->order_post( array( 'IP1' => 1 ) ) )['order'];
		unlink( Store::path( Orders::INDEX ) );
		foreach ( glob( $this->dir . '/orders/index*' ) as $f ) {
			unlink( $f );
		}
		self::reset_plugin_state();
		$this->assertSame( 0, Orders::count_all() );
		$res = Orders::rebuild_index();
		$this->assertSame( 2, $res['orders'] );
		$this->assertSame( $b->order_number, Orders::get( $b->id )->order_number );
		$this->assertTrue( \BMLT_Lit\Catalog::is_referenced( $this->items['BT'] ) );
		$c = $this->place_order( $this->order_post( array( 'IP1' => 2 ) ) )['order'];
		$this->assertSame( 3, (int) $c->id );
		$this->assertNotSame( $a->order_number, $c->order_number );
	}

	public function test_order_list_filters_and_sorts() {
		wp_set_current_user( self::$admin );
		Inventory::adjust( self::HILLCREST, $this->items['IP1'], array( 'set' => 50 ) );
		wp_set_current_user( 0 );
		$ids = array();
		$ids['river'] = $this->place_order( $this->order_post( array( 'BT' => 1 ) ) )['order']->id;
		$ids['special'] = $this->place_order( $this->order_post( array( 'KT-W' => 1 ), array( 'bmlt_lit_name' => 'Kim Keytag' ) ) )['order']->id;
		$ids['hill'] = $this->place_order( $this->order_post( array( 'IP1' => 30 ), array( 'bmlt_lit_meeting' => 2001 ) ) )['order']->id;
		wp_set_current_user( self::$worker );
		Orders::update_status( $ids['river'], 'cancelled' );
		$all  = array( self::RIVERSIDE, self::HILLCREST, self::LAKESIDE );
		$q    = static function ( array $args ) use ( $all ) {
			return array_map( 'intval', wp_list_pluck( Orders::query( $args + array( 'area_ids' => $all ) )['orders'], 'id' ) );
		};
		$this->assertSame( array( $ids['hill'], $ids['special'], $ids['river'] ), $q( array() ), 'newest first' );
		$this->assertSame( array( $ids['hill'], $ids['special'] ), $q( array( 'status' => 'open' ) ) );
		$this->assertSame( array( $ids['special'] ), $q( array( 'special' => '1' ) ) );
		$this->assertSame( array( $ids['special'] ), $q( array( 'search' => 'keytag' ) ) );
		$this->assertSame( array( $ids['hill'] ), $q( array( 'meeting' => '2001' ) ) );
		$this->assertSame( array( $ids['hill'] ), $q( array( 'area_id' => self::HILLCREST ) ) );
		$this->assertSame( array(), $q( array( 'area_id' => 105 ) ), 'an Area outside the allowed list gives nothing' );
		$this->assertSame( array( $ids['river'], $ids['special'], $ids['hill'] ), $q( array( 'sort' => 'oldest' ) ) );
		$this->assertSame( $ids['river'], $q( array( 'sort' => 'total' ) )[0], 'highest total first' );
		$this->assertSame( array(), $q( array( 'date_to_gmt' => '2000-01-01 00:00:00' ) ) );

		$counts = Orders::dashboard_counts( $all );
		$this->assertSame( array( 2, 1, 2, 0, 3, 3 ), array( $counts['new'], $counts['special'], $counts['open'], $counts['ready'], $counts['last_7'], $counts['total_all'] ) );
	}

	public function test_prices_on_old_orders_never_change() {
		$a = $this->place_order( $this->order_post( array( 'BT' => 1 ) ) )['order'];
		wp_set_current_user( self::$admin );
		\BMLT_Lit\Catalog::update( $this->items['BT'], array( 'price' => '99.00', 'name' => 'Renamed' ) );
		$it = Orders::get_items( $a->id )[0];
		$this->assertSame( array( 'Basic Text', '15.65' ), array( $it->name, $it->unit_price ) );
		$this->assertWPError( \BMLT_Lit\Catalog::delete( $this->items['BT'] ), 'ordered items cannot be deleted' );
		$this->assertTrue( \BMLT_Lit\Catalog::delete( $this->items['JFT'] ), 'never-ordered items can' );
	}
}
