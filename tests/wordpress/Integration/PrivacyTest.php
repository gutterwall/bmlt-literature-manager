<?php
/**
 * WordPress privacy tools: export and erase a requester's personal data — everywhere the plugin keeps it.
 */

namespace BmltLitTest;

use BMLT_Lit\Orders;
use BMLT_Lit\Privacy;

class PrivacyTest extends TestCase {

	public function test_tools_are_registered() {
		$this->assertArrayHasKey( 'bmlt-literature-manager', apply_filters( 'wp_privacy_personal_data_exporters', array() ) );
		$this->assertArrayHasKey( 'bmlt-literature-manager', apply_filters( 'wp_privacy_personal_data_erasers', array() ) );
	}

	public function test_export_lists_the_requesters_orders_only() {
		$this->place_order( $this->order_post( array( 'BT' => 2 ), array( 'bmlt_lit_email' => 'PAT@example.org', 'bmlt_lit_notes' => 'side door' ) ) );
		$this->place_order( $this->order_post( array( 'IP1' => 1 ), array( 'bmlt_lit_email' => 'other@example.org' ) ) );
		$e = Privacy::export( 'pat@example.org' );
		$this->assertTrue( $e['done'] );
		$this->assertCount( 1, $e['data'] );
		$values = array_column( $e['data'][0]['data'], 'value', 'name' );
		$this->assertSame( 'Pat Example', $values['Contact Name'] );
		$this->assertSame( 'side door', $values['Notes'] );
		$this->assertSame( '2 × BT Basic Text', $values['Items'] );
	}

	public function test_erase_removes_contact_details_everywhere_and_keeps_accounting() {
		$pat   = $this->place_order( $this->order_post( array( 'BT' => 2 ), array( 'bmlt_lit_notes' => 'Ring 555-0123 at the side door' ) ) )['order'];
		$other = $this->place_order( $this->order_post( array( 'IP1' => 1 ), array( 'bmlt_lit_email' => 'other@example.org', 'bmlt_lit_name' => 'Other Person', 'bmlt_lit_phone' => '555-0177' ) ) )['order'];

		$r = Privacy::erase( 'pat@example.org' );
		$this->assertTrue( $r['items_removed'] );
		$this->assertTrue( $r['items_retained'] );
		self::reset_plugin_state();
		$o = Orders::get( $pat->id );
		$this->assertSame( array( '[removed]', '', '', '' ), array( $o->contact_name, $o->contact_email, $o->contact_phone, $o->notes ) );
		$this->assertSame( '31.30', $o->total, 'items and totals stay for literature accounting' );
		$this->assertSame( 'Other Person', Orders::get( $other->id )->contact_name );
		$this->assertSame( array(), Privacy::export( 'pat@example.org' )['data'] );

		// Nothing of Pat's is left anywhere the plugin stores data.
		$stored = self::everything_stored();
		foreach ( array( 'pat@example.org', 'Pat Example', '555-0123', 'side door' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $stored, 'still stored: ' . $needle );
		}
		$this->assertStringContainsString( 'other@example.org', $stored );
		$this->assertSame( array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true ), Privacy::erase( 'nobody@example.org' ) );
	}

	public function test_ip_addresses_are_never_stored() {
		$order = $this->place_order( $this->order_post( array( 'BT' => 1 ) ) )['order'];
		$this->assertStringNotContainsString( self::IP, self::everything_stored() );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', Orders::get( $order->id )->ip_hash );
	}

	public function test_erasing_points_at_the_old_data_folder_while_it_exists() {
		mkdir( $this->dir, 0777, true );
		file_put_contents( $this->dir . '/settings.json.php', "<?php exit; ?>\n{}" );
		$r = Privacy::erase( 'pat@example.org' );
		$this->assertTrue( $r['items_retained'] );
		$this->assertStringContainsString( $this->dir, implode( ' ', $r['messages'] ) );
	}
}
