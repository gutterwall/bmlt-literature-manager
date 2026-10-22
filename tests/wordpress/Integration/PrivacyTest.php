<?php
/**
 * WordPress privacy tools: export and erase a requester's personal data — everywhere the plugin keeps it.
 */

namespace BmltLitTest;

use BMLT_Lit\Orders;
use BMLT_Lit\Privacy;
use BMLT_Lit\Store;

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
		$other = $this->place_order( $this->order_post( array( 'IP1' => 1 ), array( 'bmlt_lit_email' => 'other@example.org', 'bmlt_lit_name' => 'Other Person' ) ) )['order'];
		Store::snapshot( 'before-erase' );

		$r = Privacy::erase( 'pat@example.org' );
		$this->assertTrue( $r['items_removed'] );
		$this->assertTrue( $r['items_retained'] );
		self::reset_plugin_state();
		$o = Orders::get( $pat->id );
		$this->assertSame( array( '[removed]', '', '', '' ), array( $o->contact_name, $o->contact_email, $o->contact_phone, $o->notes ) );
		$this->assertSame( '31.30', $o->total, 'items and totals stay for literature accounting' );
		$this->assertSame( 'Other Person', Orders::get( $other->id )->contact_name );
		$this->assertSame( array(), Privacy::export( 'pat@example.org' )['data'] );

		// Nothing of Pat's is left anywhere in the data directory: not in previous versions, not in snapshots.
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			$raw = file_get_contents( $f->getPathname() );
			foreach ( array( 'pat@example.org', 'Pat Example', '555-0123', 'side door' ) as $needle ) {
				$this->assertStringNotContainsString( $needle, $raw, substr( $f->getPathname(), strlen( $this->dir ) ) . ' still holds ' . $needle );
			}
		}
		$this->assertStringContainsString( 'other@example.org', file_get_contents( Store::path( Orders::INDEX ) ) );
		$this->assertSame( array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true ), Privacy::erase( 'nobody@example.org' ) );
	}

	public function test_ip_addresses_are_never_stored() {
		$this->place_order( $this->order_post( array( 'BT' => 1 ) ) );
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			$this->assertStringNotContainsString( self::IP, file_get_contents( $f->getPathname() ) );
		}
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', Orders::get( 1 )->ip_hash );
	}
}
