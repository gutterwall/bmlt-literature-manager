<?php
/**
 * REST API: who may call what, and per-Area access to every object (no reading or changing another Area's
 * orders, inventory or catalog by guessing ids).
 */

namespace BmltLitTest;

use BMLT_Lit\Orders;
use BMLT_Lit\Permissions;

class RestTest extends TestCase {

	/** @var object */
	private $river;
	/** @var object */
	private $hill;

	public function set_up() {
		parent::set_up();
		$this->river = $this->place_order( $this->order_post( array( 'BT' => 1 ) ) )['order'];
		$this->hill  = $this->place_order( $this->order_post( array( 'IP1' => 2 ), array( 'bmlt_lit_meeting' => 2001, 'bmlt_lit_email' => 'hill@example.org' ) ) )['order'];
		reset_phpmailer_instance();
	}

	private function status( $user, $method, $route, array $params = array() ) {
		return $this->rest( $user, $method, $route, $params )->get_status();
	}

	public function test_order_endpoints_need_the_right_capability() {
		$matrix = array(
			array( 'GET', 'orders', array() ),
			array( 'GET', 'orders/' . $this->river->id, array() ),
			array( 'POST', 'orders/' . $this->river->id . '/notes', array( 'note' => 'x' ) ),
			array( 'POST', 'orders/' . $this->river->id . '/status', array( 'status' => 'reviewed' ) ),
		);
		foreach ( $matrix as $m ) {
			$this->assertSame( 401, $this->status( 0, $m[0], $m[1], $m[2] ), 'visitor ' . $m[1] );
			$this->assertSame( 403, $this->status( self::$subscriber, $m[0], $m[1], $m[2] ), 'subscriber ' . $m[1] );
			$this->assertSame( 403, $this->status( self::$settings_mgr, $m[0], $m[1], $m[2] ), 'settings only ' . $m[1] );
		}
		$this->assertSame( 200, $this->status( self::$worker, 'GET', 'orders/' . $this->river->id ) );
		$this->assertSame( 200, $this->status( self::$worker, 'POST', 'orders/' . $this->river->id . '/status', array( 'status' => 'reviewed' ) ) );
		$this->assertSame( 'reviewed', Orders::get( $this->river->id )->status );
	}

	public function test_a_worker_restricted_to_an_area_never_sees_or_changes_another_areas_orders() {
		$list = $this->rest( self::$restricted, 'GET', 'orders' )->get_data();
		$this->assertSame( array( (int) $this->river->id ), wp_list_pluck( $list['orders'], 'id' ) );
		$this->assertSame( array(), $this->rest( self::$restricted, 'GET', 'orders', array( 'area' => self::HILLCREST ) )->get_data()['orders'] );

		$hill = $this->hill->id;
		foreach ( array(
			array( 'GET', "orders/$hill", array() ),
			array( 'POST', "orders/$hill/status", array( 'status' => 'cancelled' ) ),
			array( 'POST', "orders/$hill/notes", array( 'note' => 'x' ) ),
			array( 'POST', "orders/$hill/resend", array() ),
			array( 'POST', "orders/$hill/email", array( 'message' => 'x' ) ),
			array( 'GET', 'orders/99999', array() ),
		) as $m ) {
			$this->assertSame( 404, $this->status( self::$restricted, $m[0], $m[1], $m[2] ), $m[0] . ' ' . $m[1] . ': same answer as a missing order' );
		}
		$this->assertSame( 'new', Orders::get( $hill )->status );
		$this->assertCount( 0, $this->mails() );

		$all = $this->rest( self::$worker, 'GET', 'orders' )->get_data();
		$this->assertCount( 2, $all['orders'] );
	}

	public function test_order_detail_contains_what_staff_need() {
		$d = $this->rest( self::$worker, 'GET', 'orders/' . $this->river->id )->get_data();
		$this->assertSame( 'Pat Example', $d['contact_name'] );
		$this->assertSame( 'pat@example.org', $d['contact_email'] );
		$this->assertSame( 'Basic Text', $d['items'][0]['name'] );
		$this->assertTrue( $d['can_manage'] );
		$this->assertStringContainsString( 'action=bmlt_lit_print_order', $d['print_url'] );
		$this->assertStringContainsString( '_wpnonce=', $d['print_url'] );
	}

	public function test_status_change_can_email_the_requester() {
		$r = $this->rest( self::$worker, 'POST', 'orders/' . $this->river->id . '/status', array( 'status' => 'ready', 'note' => 'Ready at the ASC', 'notify' => true ) );
		$this->assertSame( 200, $r->get_status() );
		$this->assertSame( 'Order status updated. Requester notified by email.', $r->get_data()['message'] );
		$mails = $this->mails();
		$this->assertCount( 1, $mails );
		$this->assertSame( array( 'pat@example.org' ), self::to( $mails[0] ) );
		$this->assertStringContainsString( 'is ready', $mails[0]['subject'] );
		$this->assertStringContainsString( 'Ready at the ASC', self::html_part( $mails[0] ) );

		$r = $this->rest( self::$worker, 'POST', 'orders/' . $this->river->id . '/email', array( 'message' => "Hi <b>there</b>\nSee you Sunday" ) );
		$this->assertSame( 200, $r->get_status() );
		$html = self::html_part( $this->mails()[1] );
		$this->assertStringContainsString( 'Hi there<br />', $html );
		$this->assertSame( 400, $this->status( self::$worker, 'POST', 'orders/' . $this->river->id . '/email', array( 'message' => '  ' ) ) );
		$this->assertSame( 200, $this->status( self::$worker, 'POST', 'orders/' . $this->river->id . '/resend' ) );
		$this->assertSame( array( 'riverside-lit@example.org', 'helper@example.org', 'riverside-chair@example.org' ), self::to( $this->mails()[2] ) );
	}

	public function test_inventory_endpoints_respect_areas() {
		$this->assertSame( 403, $this->status( self::$subscriber, 'GET', 'inventory', array( 'area' => self::RIVERSIDE ) ) );
		$this->assertSame( 403, $this->status( self::$restricted, 'GET', 'inventory', array( 'area' => self::HILLCREST ) ) );
		$inv = $this->rest( self::$restricted, 'GET', 'inventory', array( 'area' => self::RIVERSIDE ) )->get_data();
		$this->assertTrue( $inv['tracked'] );
		$rows = array_column( $inv['rows'], null, 'sku' );
		$this->assertSame( array( 5, 1, 4 ), array( $rows['BT']['on_hand'], $rows['BT']['reserved'], $rows['BT']['available'] ) );
		$this->assertSame( array( 'KT-W', 'JFT' ), array_slice( wp_list_pluck( $inv['rows'], 'sku' ), -2 ), 'special orders last' );

		$r = $this->rest( self::$restricted, 'POST', 'inventory', array( 'area' => self::RIVERSIDE, 'item_id' => $this->items['KT-W'], 'delta' => 12, 'reason' => 'NAWS delivery' ) );
		$this->assertSame( 200, $r->get_status() );
		$this->assertSame( 12, $r->get_data()['row']['on_hand'] );
		$this->assertSame( 'in_stock', $r->get_data()['row']['status'] );
		$this->assertSame( 403, $this->status( self::$restricted, 'POST', 'inventory', array( 'area' => self::HILLCREST, 'item_id' => $this->items['BT'], 'set' => 50 ) ) );
		// Riverside's own item cannot be stocked for Hillcrest, even by an unrestricted worker.
		$this->assertSame( 400, $this->status( self::$worker, 'POST', 'inventory', array( 'area' => self::HILLCREST, 'item_id' => $this->items['RS-SHIRT'], 'set' => 5 ) ) );
		$this->assertSame( 400, $this->status( self::$worker, 'POST', 'inventory', array( 'area' => self::RIVERSIDE, 'item_id' => 99999, 'set' => 5 ) ) );
		$neg = $this->rest( self::$worker, 'POST', 'inventory', array( 'area' => self::RIVERSIDE, 'item_id' => $this->items['IP1'], 'set' => -40 ) )->get_data();
		$this->assertSame( 0, $neg['row']['on_hand'], 'stock never goes below zero' );

		$history = \BMLT_Lit\Audit::recent( array( 'object_type' => 'inventory', 'area_ids' => array( self::RIVERSIDE ) ) );
		$this->assertSame( 'NAWS delivery', $history[1]->note );
		$this->assertSame( self::$restricted, (int) $history[1]->user_id );
	}

	public function test_catalog_endpoints_respect_areas() {
		$this->assertSame( 403, $this->status( self::$worker, 'GET', 'items' ), 'workers do not manage the catalog' );
		$list = $this->rest( self::$chair, 'GET', 'items' )->get_data();
		$this->assertCount( 6, $list['items'] );

		// A chair restricted to Riverside.
		Permissions::save_grants(
			array(
				self::$restricted => array(
					'caps'  => array( Permissions::MANAGE_CATALOG ),
					'areas' => array( self::RIVERSIDE ),
				),
			)
		);
		$this->assertSame( 403, $this->status( self::$restricted, 'POST', 'items', array( 'sku' => 'NEW', 'name' => 'New', 'price' => '1.00', 'area_id' => 0 ) ), 'no shared-catalog changes' );
		$this->assertSame( 403, $this->status( self::$restricted, 'POST', 'items', array( 'sku' => 'NEW', 'name' => 'New', 'price' => '1.00', 'area_id' => self::HILLCREST ) ) );
		$new = $this->rest( self::$restricted, 'POST', 'items', array( 'sku' => 'RS-MUG', 'name' => 'Riverside Mug', 'price' => '8.50', 'area_id' => self::RIVERSIDE ) );
		$this->assertSame( 200, $new->get_status() );
		$id = $new->get_data()['item']['id'];
		$this->assertSame( 404, $this->status( self::$restricted, 'POST', 'items/' . $this->items['BT'], array( 'price' => '0.01' ) ), 'shared items are read-only' );
		$this->assertSame( 404, $this->status( self::$restricted, 'DELETE', 'items/' . $this->items['JFT'] ) );
		$this->assertSame( 404, $this->status( self::$restricted, 'POST', 'items/' . $this->items['JFT'] . '/active', array( 'active' => false ) ) );
		$this->assertSame( 403, $this->status( self::$restricted, 'POST', "items/$id", array( 'area_id' => 0 ) ), 'cannot move an item into the shared catalog' );
		$this->assertSame( '15.65', \BMLT_Lit\Catalog::get( $this->items['BT'] )->price );

		$listed = $this->rest( self::$restricted, 'GET', 'items' )->get_data()['items'];
		$byid   = array_column( $listed, null, 'id' );
		$this->assertFalse( $byid[ $this->items['BT'] ]['can_edit'] );
		$this->assertTrue( $byid[ $id ]['can_edit'] );
		$this->assertTrue( $byid[ $id ]['can_delete'] );
		$this->assertSame( 200, $this->status( self::$restricted, 'DELETE', "items/$id" ) );

		$this->assertSame( 409, $this->status( self::$chair, 'DELETE', 'items/' . $this->items['BT'] ), 'items on orders cannot be deleted' );
		$bad = $this->rest( self::$chair, 'POST', 'items', array( 'sku' => 'bt', 'name' => 'Dup', 'price' => '1.999' ) );
		$this->assertSame( 400, $bad->get_status() );
		$this->assertStringContainsString( 'at most two decimal places', $bad->get_data()['message'] );
		$this->assertStringContainsString( 'already uses that SKU', $bad->get_data()['message'] );
	}

	public function test_bmlt_test_needs_manage_settings() {
		$this->assertSame( 403, $this->status( self::$worker, 'POST', 'admin/bmlt-test' ) );
		$d = $this->rest( self::$settings_mgr, 'POST', 'admin/bmlt-test' )->get_data();
		$this->assertTrue( $d['success'] );
		$this->assertSame( '3.4.1', $d['version'] );
		$this->assertSame( 8, $d['service_bodies'] );
		$this->assertSame( 22, $d['meetings'], 'published meetings in the selected Areas and their sub-Areas' );
	}

	public function test_public_stock_has_no_personal_data() {
		$r = $this->rest( 0, 'GET', 'public/stock', array( 'area' => self::RIVERSIDE ) );
		$this->assertSame( 200, $r->get_status() );
		$json = wp_json_encode( $r->get_data() );
		$this->assertStringNotContainsString( 'pat@example.org', $json );
		$this->assertSame( array( 'item_id', 'status', 'available' ), array_keys( $r->get_data()['items'][0] ) );
		$this->assertSame( 404, $this->status( 0, 'GET', 'public/stock', array( 'area' => 105 ) ) );
	}
}
