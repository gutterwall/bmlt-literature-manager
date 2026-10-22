<?php
/**
 * The admin screens and their form handlers: who may open and submit what, and that everything renders.
 */

namespace BmltLitTest;

use BMLT_Lit\Admin\Admin;
use BMLT_Lit\Orders;
use BMLT_Lit\Permissions;
use BMLT_Lit\Settings;

class AdminTest extends TestCase {

	public function set_up() {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		$GLOBALS['hook_suffix'] = 'toplevel_page_bmlt-lit'; // Set by wp-admin/admin.php; list tables need it on WordPress < 6.
		set_current_screen( 'toplevel_page_bmlt-lit' );
	}

	public function tear_down() {
		$_REQUEST = array();
		parent::tear_down();
	}

	/**
	 * Render an admin page as a user.
	 *
	 * @param int    $user   User.
	 * @param string $page   Page method suffix.
	 * @param array  $get    Query.
	 * @return string HTML, or "DIE: message".
	 */
	private function page( $user, $page, array $get = array() ) {
		wp_set_current_user( $user );
		self::reset_plugin_state();
		$_GET     = $get;
		$_REQUEST = $get;
		ob_start();
		try {
			call_user_func( array( Admin::class, 'page_' . $page ) );
		} catch ( \WPDieException $e ) {
			ob_end_clean();
			return 'DIE: ' . $e->getMessage();
		}
		return (string) ob_get_clean();
	}

	/**
	 * Submit an admin-post.php form as a user.
	 *
	 * @param int    $user    User.
	 * @param string $handler Handler suffix.
	 * @param string $nonce   Nonce action.
	 * @param array  $post    Fields.
	 * @return string Redirect location, or "DIE: message".
	 */
	private function post( $user, $handler, $nonce, array $post ) {
		wp_set_current_user( $user );
		self::reset_plugin_state();
		$post['_wpnonce']          = wp_create_nonce( $nonce );
		$_POST                     = wp_slash( $post );
		$_REQUEST                  = $_POST;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		try {
			call_user_func( array( Admin::class, 'handle_' . $handler ) );
		} catch ( Redirected $r ) {
			return $r->location;
		} catch ( \WPDieException $e ) {
			return 'DIE: ' . $e->getMessage();
		} finally {
			$_POST    = array();
			$_REQUEST = array();
		}
		return '';
	}

	public function test_every_page_renders_for_an_administrator() {
		$order = $this->place_order( $this->order_post( array( 'BT' => 7 ), array( 'bmlt_lit_name' => 'Quote" onmouseover="alert(1)' ) ) )['order'];
		$pages = array(
			array( 'dashboard', array(), 'Literature Dashboard' ),
			array( 'orders', array(), 'Literature Orders' ),
			array( 'orders', array( 'order_id' => $order->id ), $order->order_number ),
			array( 'items', array(), 'Literature Items' ),
			array( 'items', array( 'action' => 'new' ), 'Add Literature Item' ),
			array( 'items', array( 'action' => 'edit', 'item_id' => $this->items['BT'] ), 'Basic Text' ),
			array( 'inventory', array( 'area' => self::RIVERSIDE ), 'Riverside Area' ),
			array( 'import', array(), 'Import' ),
			array( 'bmlt', array(), 'Lakeside North Area' ),
			array( 'smtp', array(), 'SMTP' ),
			array( 'status', array(), 'Storage' ),
		);
		foreach ( array( 'general', 'bmlt', 'areas', 'email', 'templates', 'permissions', 'advanced' ) as $tab ) {
			$pages[] = array( 'settings', array( 'tab' => $tab ), 'Settings' );
		}
		foreach ( $pages as $p ) {
			$html = $this->page( self::$admin, $p[0], $p[1] );
			$this->assertStringNotContainsString( 'DIE:', $html, $p[0] . ' ' . wp_json_encode( $p[1] ) );
			$this->assertStringContainsString( $p[2], $html, $p[0] . ' ' . wp_json_encode( $p[1] ) );
			$this->assertStringNotContainsString( 'onmouseover="alert', $html, $p[0] );
		}
		$detail = $this->page( self::$admin, 'orders', array( 'order_id' => $order->id ) );
		$this->assertStringContainsString( 'Quote&quot; onmouseover=&quot;alert(1)', $detail );
		$this->assertStringContainsString( 'THIS ORDER CONTAINS SPECIAL ORDER ITEMS', $detail );
	}

	public function test_workers_see_their_pages_and_nothing_else() {
		$hill = $this->place_order( $this->order_post( array( 'IP1' => 1 ), array( 'bmlt_lit_meeting' => 2001 ) ) )['order'];
		foreach ( array( 'dashboard', 'orders', 'inventory' ) as $page ) {
			$this->assertStringNotContainsString( 'DIE:', $this->page( self::$worker, $page ), $page );
		}
		foreach ( array( 'items', 'import', 'bmlt', 'smtp', 'settings', 'status' ) as $page ) {
			$this->assertStringStartsWith( 'DIE:', $this->page( self::$worker, $page ), $page );
		}
		$this->assertStringStartsWith( 'DIE: Order not found.', $this->page( self::$restricted, 'orders', array( 'order_id' => $hill->id ) ) );
		$this->assertStringNotContainsString( 'Hilltop Hope', $this->page( self::$restricted, 'orders' ) );
		$this->assertStringNotContainsString( 'Hillcrest', $this->page( self::$restricted, 'inventory', array( 'area' => self::HILLCREST ) ) );
		$this->assertStringStartsWith( 'DIE:', $this->page( self::$subscriber, 'dashboard' ) );
		$this->assertStringNotContainsString( 'DIE:', $this->page( self::$settings_mgr, 'settings' ) );
	}

	public function test_handlers_check_capability_nonce_and_area() {
		$hill = $this->place_order( $this->order_post( array( 'IP1' => 1 ), array( 'bmlt_lit_meeting' => 2001 ) ) )['order'];

		$this->assertStringStartsWith( 'DIE:', $this->post( self::$restricted, 'order_status', 'bmlt_lit_order_' . $hill->id, array( 'order_id' => $hill->id, 'status' => 'cancelled' ) ) );
		$this->assertSame( 'new', Orders::get( $hill->id )->status );
		$this->assertStringContainsString( 'order_id=' . $hill->id, $this->post( self::$worker, 'order_status', 'bmlt_lit_order_' . $hill->id, array( 'order_id' => $hill->id, 'status' => 'reviewed' ) ) );
		$this->assertSame( 'reviewed', Orders::get( $hill->id )->status );

		// A nonce for another order is refused.
		$this->assertStringStartsWith( 'DIE:', $this->post( self::$worker, 'order_note', 'bmlt_lit_order_999', array( 'order_id' => $hill->id, 'note' => 'x' ) ) );

		$this->assertStringStartsWith( 'DIE:', $this->post( self::$restricted, 'save_inventory', 'bmlt_lit_save_inventory', array( 'area' => self::HILLCREST, 'inv' => array( $this->items['BT'] => array( 'on_hand' => 9, 'orig_on_hand' => 0 ) ) ) ) );
		$this->post( self::$restricted, 'save_inventory', 'bmlt_lit_save_inventory', array( 'area' => self::RIVERSIDE, 'reason' => 'count', 'inv' => array( $this->items['BT'] => array( 'on_hand' => 9, 'orig_on_hand' => 5 ) ) ) );
		$this->assertSame( 9, \BMLT_Lit\Inventory::stock_map( array( self::RIVERSIDE ) )[ self::RIVERSIDE ][ $this->items['BT'] ]['on_hand'] );
	}

	public function test_settings_and_permissions() {
		$loc = $this->post(
			self::$settings_mgr,
			'save_settings',
			'bmlt_lit_save_settings',
			array(
				'tab'               => 'general',
				'org_name'          => 'New <b>Name</b>',
				'order_prefix'      => 'ab-c!',
				'decimal_sep'       => ',',
				'thousands_sep'     => ',',
				'reservation_mode'  => 'deduct',
				'max_qty_per_line'  => '99999',
				'require_name'      => '1',
				'delivery_message'  => '',
			)
		);
		$this->assertStringContainsString( 'tab=general', $loc );
		$g = Settings::general();
		$this->assertSame( array( 'New Name', 'ABC', ',', '.', 'deduct', 10000, 0 ), array( $g['org_name'], $g['order_prefix'], $g['decimal_sep'], $g['thousands_sep'], $g['reservation_mode'], $g['max_qty_per_line'], $g['require_phone'] ) );
		$this->assertSame( Settings::general_defaults()['delivery_message'], $g['delivery_message'], 'empty messages fall back to the default' );

		$this->post(
			self::$settings_mgr,
			'save_settings',
			'bmlt_lit_save_settings',
			array(
				'tab'  => 'templates',
				'tpl'  => array( 'worker' => array( 'subject' => "Order {order_number}\nBcc: x@example.org", 'body' => '<p>{order_items}</p><script>alert(1)</script>' ) ),
			)
		);
		$t = Settings::template( 'worker' );
		$this->assertSame( 'Order {order_number} Bcc: x@example.org', $t['subject'] );
		$this->assertSame( '<p>{order_items}</p>alert(1)', $t['body'] );

		// Only site administrators change permissions.
		$this->assertStringStartsWith( 'DIE:', $this->post( self::$settings_mgr, 'save_permissions', 'bmlt_lit_save_permissions', array( 'perm' => array( self::$settings_mgr => array( 'caps' => Permissions::caps() ) ) ) ) );
		$this->assertFalse( user_can( self::$settings_mgr, Permissions::VIEW_ORDERS ) );
		$this->post( self::$admin, 'save_permissions', 'bmlt_lit_save_permissions', array( 'add_user' => 'lit-subscriber' ) );
		self::reset_plugin_state();
		$this->assertTrue( user_can( self::$subscriber, Permissions::VIEW_ORDERS ) );
		$this->post( self::$admin, 'save_permissions', 'bmlt_lit_save_permissions', array( 'perm' => array( self::$subscriber => array( 'caps' => array( Permissions::VIEW_ORDERS, 'manage_options' ), 'areas' => array( self::HILLCREST, 105 ) ) ) ) );
		self::reset_plugin_state();
		$this->assertSame( array( self::HILLCREST ), Permissions::allowed_areas( self::$subscriber ) );
		$this->assertFalse( user_can( self::$subscriber, 'manage_options' ) );

		$this->assertStringStartsWith( 'DIE:', $this->post( self::$settings_mgr, 'restore_backup', 'bmlt_lit_restore_backup', array( 'confirm' => 1, 'confirm_text' => 'RESTORE' ) ), 'restore is for administrators only' );
	}

	public function test_bmlt_settings_and_area_selection() {
		// A public address (answered by the fake BMLT server like bmlt.test).
		$public = 'http://93.184.215.14/main_server';
		$this->post( self::$admin, 'save_bmlt', 'bmlt_lit_save_bmlt', array( 'root_url' => $public . '/client_interface/json/?switcher=GetServerInfo', 'timeout' => '500', 'cache_hours' => '0' ) );
		$b = Settings::bmlt();
		$this->assertSame( array( $public, 120, 1 ), array( $b['root_url'], $b['timeout'], $b['cache_hours'] ) );
		foreach ( array( 'javascript:alert(1)', 'http://127.0.0.1/main_server', 'http://10.0.0.5/main_server', 'http://93.184.215.14:2222/main_server', 'ftp://93.184.215.14/' ) as $refused ) {
			$this->post( self::$admin, 'save_bmlt', 'bmlt_lit_save_bmlt', array( 'root_url' => $refused ) );
			$this->assertSame( '', Settings::bmlt()['root_url'], $refused . ' is not saved' );
		}
		// Private addresses and other ports need the site owner's say-so (WordPress's own filters).
		add_filter( 'http_request_host_is_external', '__return_true' );
		$this->post( self::$admin, 'save_bmlt', 'bmlt_lit_save_bmlt', array( 'root_url' => 'http://10.0.0.5/main_server' ) );
		remove_filter( 'http_request_host_is_external', '__return_true' );
		$this->assertSame( 'http://10.0.0.5/main_server', Settings::bmlt()['root_url'] );
		$this->post( self::$admin, 'save_bmlt', 'bmlt_lit_save_bmlt', array( 'root_url' => $public ) );

		// Only service bodies BMLT knows about can be selected.
		$this->post( self::$admin, 'save_area_selection', 'bmlt_lit_save_area_selection', array( 'areas' => array( self::HILLCREST, 9999, 'x' ) ) );
		$this->assertSame( array( self::HILLCREST ), Settings::selected_areas() );
		$this->assertSame( array( 2001, 2002 ), array_keys( \BMLT_Lit\Bmlt_Client::get_meetings( false )['meetings'] ) );
	}

	public function test_item_form_validates_and_parses_prices() {
		$loc = $this->post( self::$chair, 'save_item', 'bmlt_lit_save_item', array( 'sku' => 'NEW', 'name' => 'New Book', 'price' => '$1,234.50', 'area_id' => 0, 'is_active' => '1', 'category' => 'Books' ) );
		$this->assertStringContainsString( 'action=edit', $loc );
		$this->assertSame( '1234.50', \BMLT_Lit\Catalog::get_by_sku( 'new', 0 )->price );
		$bad = $this->post( self::$chair, 'save_item', 'bmlt_lit_save_item', array( 'sku' => 'NEW', 'name' => '', 'price' => 'abc', 'area_id' => 0 ) );
		$this->assertStringContainsString( 'action=new', $bad, 'errors send the chair back to the form' );
	}
}
