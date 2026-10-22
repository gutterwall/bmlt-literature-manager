<?php
/**
 * The public order form, end to end through the plugin's POST handler: review, confirm, confirmation link,
 * stored order, inventory and emails, and every check that protects it.
 */

namespace BmltLitTest;

use BMLT_Lit\Inventory;
use BMLT_Lit\Orders;
use BMLT_Lit\Settings;

class PublicOrderTest extends TestCase {

	public function test_order_is_priced_on_the_server_split_into_stock_and_special_order_and_reserved() {
		// Basic Text: 5 on hand, 7 ordered -> 5 from stock, 2 special order. Keytag: none on hand -> special order.
		$r = $this->place_order( $this->order_post( array( 'BT' => 7, 'IP1' => 10, 'KT-W' => 2 ) ) );
		$this->assertNotNull( $r['redirect'], 'the visitor is sent to the confirmation' );
		$this->assertStringContainsString( '#bmlt-lit-confirmation', $r['redirect'] );
		$order = $r['order'];
		$this->assertNotNull( $order, 'the confirmation link opens the order' );

		$this->assertSame( self::RIVERSIDE, (int) $order->area_id );
		$this->assertSame( 'Riverside Area', $order->area_name );
		$this->assertSame( 'Just for Today', $order->meeting_name );
		$this->assertSame( 19, (int) $order->item_count );
		// 7 x 15.65 + 10 x 0.33 + 2 x 0.62 = 109.55 + 3.30 + 1.24.
		$this->assertSame( '114.09', $order->total );
		$this->assertSame( 1, (int) $order->has_special_order );
		$this->assertSame( 'new', $order->status );
		$this->assertMatchesRegularExpression( '/^LIT-\d{4}-000001$/', $order->order_number );

		$lines = array();
		foreach ( Orders::get_items( $order->id ) as $it ) {
			$lines[ $it->sku ] = array( (int) $it->quantity, (int) $it->quantity_in_stock, (int) $it->quantity_special, $it->unit_price, $it->line_total );
		}
		$this->assertSame(
			array(
				'IP1'  => array( 10, 10, 0, '0.33', '3.30' ),
				'BT'   => array( 7, 5, 2, '15.65', '109.55' ),
				'KT-W' => array( 2, 0, 2, '0.62', '1.24' ),
			),
			$lines,
			'special-order lines come last'
		);

		$stock = Inventory::stock_map( array( self::RIVERSIDE ) )[ self::RIVERSIDE ];
		$this->assertSame( array( 5, 5, 0 ), array( $stock[ $this->items['BT'] ]['on_hand'], $stock[ $this->items['BT'] ]['reserved'], $stock[ $this->items['BT'] ]['available'] ) );
		$this->assertSame( 10, $stock[ $this->items['IP1'] ]['reserved'] );
		$this->assertSame( 0, $stock[ $this->items['KT-W'] ]['reserved'], 'special-order quantities never touch inventory' );
	}

	public function test_prices_and_quantities_sent_by_the_browser_are_not_trusted() {
		$post = $this->order_post(
			array( 'BT' => 1 ),
			array(
				'bmlt_lit_price' => array( $this->items['BT'] => '0.01' ),
				'price'          => '0.01',
				'total'          => '0.01',
			)
		);
		$r = $this->place_order( $post );
		$this->assertSame( '15.65', $r['order']->total );

		$neg = $this->submit( $this->order_post( array( 'BT' => -3 ) ) );
		$this->assertContains( 'Quantities cannot be negative.', $neg['state']['errors'] );
		$big = $this->submit( $this->order_post( array( 'BT' => 501 ) ) );
		$this->assertContains( 'The maximum quantity per item is 500.', $big['state']['errors'] );
		$none = $this->submit( $this->order_post( array( 'BT' => 0 ) ) );
		$this->assertContains( 'Please enter a quantity for at least one literature item.', $none['state']['errors'] );
	}

	public function test_items_from_another_areas_catalog_or_inactive_items_cannot_be_ordered() {
		// Riverside's own T-shirt from a Hillcrest meeting.
		$r = $this->submit( $this->order_post( array( 'RS-SHIRT' => 1 ), array( 'bmlt_lit_meeting' => 2001 ) ) );
		$this->assertCount( 1, $r['state']['errors'] );
		$this->assertStringContainsString( 'no longer available for this Area', $r['state']['errors'][0] );
		$r = $this->submit( $this->order_post( array( 'OLD' => 1 ) ) );
		$this->assertStringContainsString( 'no longer available for this Area', $r['state']['errors'][0] );
		// An item id that does not exist.
		$post = $this->order_post( array( 'BT' => 1 ) );
		$post['bmlt_lit_qty'][99999] = '1';
		$r = $this->submit( $post );
		$this->assertStringContainsString( 'no longer available for this Area', $r['state']['errors'][0] );
		// But Riverside's own item from a Riverside meeting is fine.
		$ok = $this->place_order( $this->order_post( array( 'RS-SHIRT' => 1 ) ) );
		$this->assertSame( '12.00', $ok['order']->total );
	}

	public function test_meeting_must_be_a_published_bmlt_meeting_served_by_this_form() {
		$r = $this->submit( $this->order_post( array( 'BT' => 1 ), array( 'bmlt_lit_meeting' => 1005 ) ) );
		$this->assertContains( 'Please select your meeting from the list.', $r['state']['errors'], 'unpublished meeting' );
		$r = $this->submit( $this->order_post( array( 'BT' => 1 ), array( 'bmlt_lit_meeting' => 9001 ) ) );
		$this->assertContains( 'Please select your meeting from the list.', $r['state']['errors'], 'meeting in an Area this site does not serve' );

		// A form for Hillcrest only cannot be used to order for a Riverside meeting.
		$post = $this->order_post(
			array( 'BT' => 1 ),
			array(
				'bmlt_lit_areas'     => (string) self::HILLCREST,
				'bmlt_lit_areas_sig' => self::areas_sig( array( self::HILLCREST ) ),
			)
		);
		$r = $this->submit( $post );
		$this->assertContains( 'The selected meeting is not served by this order form.', $r['state']['errors'] );

		// ... and widening the form's Areas without the signature is refused.
		$post['bmlt_lit_areas'] = self::HILLCREST . ',' . self::RIVERSIDE;
		$r = $this->submit( $post );
		$this->assertContains( 'The order form is invalid. Please reload the page.', $r['state']['errors'] );

		// Sub-Area meetings belong to their parent Area.
		$sub = $this->place_order( $this->order_post( array( 'BT' => 1 ), array( 'bmlt_lit_meeting' => 3002 ) ) );
		$this->assertSame( self::LAKESIDE, (int) $sub['order']->area_id );
		$this->assertSame( 'North Shore Group', $sub['order']->meeting_name );
	}

	public function test_contact_details_are_validated() {
		$r = $this->submit(
			$this->order_post(
				array( 'BT' => 1 ),
				array(
					'bmlt_lit_name'  => '   ',
					'bmlt_lit_phone' => 'call me',
					'bmlt_lit_email' => 'not-an-email',
				)
			)
		);
		$this->assertSame(
			array( 'Please enter your name.', 'Please enter a valid phone number.', 'Please enter a valid email address.' ),
			$r['state']['errors']
		);
		$general                  = Settings::general();
		$general['require_phone'] = 0;
		$general['require_email'] = 0;
		Settings::update( Settings::GENERAL, $general );
		$ok = $this->place_order( $this->order_post( array( 'BT' => 1 ), array( 'bmlt_lit_phone' => '', 'bmlt_lit_email' => '' ) ) );
		$this->assertNotNull( $ok['order'] );
		$this->assertSame( '', $ok['order']->contact_email );
	}

	public function test_bots_and_forged_requests_are_refused() {
		$cases = array(
			'nonce'    => array( array( 'bmlt_lit_nonce' => 'bad' ), 'Your session expired before the order was sent. Please review the form and submit again.' ),
			'honeypot' => array( array( 'bmlt_lit_website' => 'http://spam.example' ), 'Your order could not be accepted. Please try again.' ),
			'too fast' => array( array( 'bmlt_lit_ts' => self::time_token( 0 ) ), 'The form was submitted too quickly. Please wait a moment and submit again.' ),
			'forged'   => array( array( 'bmlt_lit_ts' => time() - 60 . '.abc' ), 'This form has expired. Please reload the page and try again.' ),
			'old'      => array( array( 'bmlt_lit_ts' => self::time_token( 3 * DAY_IN_SECONDS ) ), 'This form has expired. Please reload the page and try again.' ),
			'token'    => array( array( 'bmlt_lit_token' => 'short' ), 'The order form is invalid. Please reload the page.' ),
		);
		foreach ( $cases as $label => $case ) {
			$r = $this->submit( $this->order_post( array( 'BT' => 1 ), $case[0] ) );
			$this->assertSame( array( $case[1] ), $r['state']['errors'], $label );
			$this->assertTrue( empty( $r['state']['review'] ), $label );
		}
		$this->assertSame( 0, Orders::count_all() );

		add_filter(
			'bmlt_lit_validate_captcha',
			static function ( $valid, $post ) {
				return isset( $post['captcha'] ) && 'ok' === $post['captcha'] ? true : new \WP_Error( 'captcha', 'Please complete the verification.' );
			},
			10,
			2
		);
		$r = $this->submit( $this->order_post( array( 'BT' => 1 ) ) );
		$this->assertSame( array( 'Please complete the verification.' ), $r['state']['errors'] );
		$ok = $this->place_order( $this->order_post( array( 'BT' => 1 ), array( 'captcha' => 'ok' ) ) );
		$this->assertNotNull( $ok['order'] );
	}

	public function test_orders_per_connection_are_rate_limited() {
		$general                     = Settings::general();
		$general['rate_limit_count'] = 2;
		Settings::update( Settings::GENERAL, $general );
		$this->assertNotNull( $this->place_order( $this->order_post( array( 'BT' => 1 ) ) )['order'] );
		$this->assertNotNull( $this->place_order( $this->order_post( array( 'IP1' => 1 ) ) )['order'] );
		$r = $this->submit( $this->order_post( array( 'IP1' => 2 ) ) );
		$this->assertSame( array( 'Too many orders have been submitted from your connection recently. Please wait a few minutes and try again.' ), $r['state']['errors'] );
		// Another connection is not affected.
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$this->assertNotNull( $this->place_order( $this->order_post( array( 'IP1' => 2 ) ) )['order'] );
		$this->assertSame( 3, Orders::count_all() );
	}

	public function test_confirm_reprices_and_shows_the_review_again_when_anything_changed() {
		$post   = $this->order_post( array( 'BT' => 2 ) );
		$review = $this->submit( $post );
		$this->assertSame( '31.30', $review['state']['review']['preview']['total'] );

		// The price changes between review and confirm.
		wp_set_current_user( self::$admin );
		\BMLT_Lit\Catalog::update( $this->items['BT'], array( 'price' => '16.00' ) );
		wp_set_current_user( 0 );

		$confirm                        = $post;
		$confirm['bmlt_lit_step']       = 'confirm';
		$confirm['bmlt_lit_review_sig'] = $review['state']['review']['sig'];
		$again                          = $this->submit( $confirm );
		$this->assertNull( $again['redirect'], 'nothing is submitted' );
		$this->assertTrue( $again['state']['review']['changed'] );
		$this->assertSame( '32.00', $again['state']['review']['preview']['total'] );
		$this->assertSame( 0, Orders::count_all() );

		// Quantities edited on the review page without "Update totals" are not submitted either.
		$edited                         = $confirm;
		$edited['bmlt_lit_qty']         = array( $this->items['BT'] => '3' );
		$edited['bmlt_lit_review_sig']  = $again['state']['review']['sig'];
		$third                          = $this->submit( $edited );
		$this->assertNull( $third['redirect'] );
		$this->assertTrue( $third['state']['review']['changed'] );
		$this->assertSame( '48.00', $third['state']['review']['preview']['total'] );

		$confirm['bmlt_lit_review_sig'] = $again['state']['review']['sig'];
		$done                           = $this->submit( $confirm );
		$this->assertNotNull( $done['redirect'] );
		$this->assertSame( 1, Orders::count_all() );
	}

	public function test_edit_returns_to_the_form_with_every_entry_kept() {
		$post                  = $this->order_post( array( 'BT' => 2, 'IP1' => 5 ), array( 'bmlt_lit_notes' => "Two lines\nof notes" ) );
		$post['bmlt_lit_step'] = 'edit';
		$r                     = $this->submit( $post );
		$this->assertTrue( empty( $r['state']['review'] ) );
		$this->assertSame( array(), $r['state']['errors'] );
		$this->assertSame( array( $this->items['BT'] => 2, $this->items['IP1'] => 5 ), $r['state']['values']['qty'] );
		$this->assertSame( "Two lines\nof notes", $r['state']['values']['notes'] );
		$this->assertSame( 1001, $r['state']['values']['meeting_id'] );
	}

	public function test_a_double_submit_creates_one_order() {
		$post   = $this->order_post( array( 'BT' => 1 ) );
		$first  = $this->place_order( $post );
		$second = $this->place_order( $post );
		$this->assertSame( $first['order']->id, $second['order']->id );
		$this->assertSame( 1, Orders::count_all() );
		$this->assertSame( 1, Inventory::stock_map( array( self::RIVERSIDE ) )[ self::RIVERSIDE ][ $this->items['BT'] ]['reserved'] );
		$worker_mails = array_filter(
			$this->mails(),
			static function ( $m ) {
				return in_array( 'riverside-lit@example.org', self::to( $m ), true );
			}
		);
		$this->assertCount( 1, $worker_mails, 'literature workers are emailed once' );
	}

	/**
	 * A page cache serves every visitor the same form, with the same form token. Two different people's orders must
	 * both be saved, and nobody may be shown somebody else's order.
	 */
	public function test_two_visitors_sharing_a_cached_form_token_get_their_own_orders() {
		$token = 'cachedtoken' . str_repeat( 'x', 21 );
		$pat   = $this->place_order( $this->order_post( array( 'BT' => 1 ), array( 'bmlt_lit_token' => $token ) ) );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
		$sam = $this->place_order(
			$this->order_post(
				array( 'IP1' => 4 ),
				array(
					'bmlt_lit_token' => $token,
					'bmlt_lit_name'  => 'Sam Other',
					'bmlt_lit_email' => 'sam@example.org',
					'bmlt_lit_phone' => '555-0188',
				)
			)
		);
		$this->assertSame( 2, Orders::count_all(), 'the second visitor\'s order is saved' );
		$this->assertNotSame( $pat['order']->id, $sam['order']->id );
		$this->assertSame( 'Sam Other', $sam['order']->contact_name, 'the second visitor sees their own order' );
		$this->assertStringNotContainsString( 'pat@example.org', (string) $sam['redirect'] );
	}

	public function test_confirmation_link_needs_the_secret_key_and_expires_after_30_days() {
		$r = $this->place_order( $this->order_post( array( 'BT' => 1 ) ) );
		$q = array();
		parse_str( (string) wp_parse_url( $r['redirect'], PHP_URL_QUERY ), $q );
		$this->assertSame( 32, strlen( $q['bmlt_lit_key'] ) );
		$this->assertNull( Orders::get_by_access( $q['bmlt_lit_order'], strrev( $q['bmlt_lit_key'] ) ) );
		$this->assertNull( Orders::get_by_access( $q['bmlt_lit_order'], '' ) );
		$this->assertNull( Orders::get_by_access( 'LIT-2026-000002', $q['bmlt_lit_key'] ) );

		$_GET = array(
			'bmlt_lit_order' => $q['bmlt_lit_order'],
			'bmlt_lit_key'   => $q['bmlt_lit_key'],
		);
		$html = do_shortcode( '[bmlt_literature_order]' );
		$this->assertStringContainsString( 'Thank you — your order has been received', $html );
		$this->assertStringContainsString( esc_html( $q['bmlt_lit_order'] ), $html );
		$this->assertStringContainsString( 'Pick up at the Riverside ASC.', $html, 'the Area\'s delivery instructions' );
		$_GET['bmlt_lit_key'] = 'x' . substr( $q['bmlt_lit_key'], 1 );
		$this->assertStringContainsString( 'That order confirmation link is invalid or has expired.', do_shortcode( '[bmlt_literature_order]' ) );

		// Age the order by 31 days.
		$idx   = \BMLT_Lit\Store::get( Orders::INDEX );
		$month = 'orders/' . $idx['orders'][ $r['order']->id ]['month'];
		$doc   = \BMLT_Lit\Store::get( $month );
		$doc['orders'][ $r['order']->id ]['created_at'] = gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS );
		\BMLT_Lit\Store::put( $month, $doc );
		$this->assertNull( Orders::get_by_access( $q['bmlt_lit_order'], $q['bmlt_lit_key'] ) );
	}

	public function test_untracked_area_uses_special_order_marks_and_never_changes_stock() {
		$areas = Settings::areas_config();
		$areas[ self::RIVERSIDE ]['track_inventory'] = 0;
		Settings::update( Settings::AREAS, $areas );
		wp_set_current_user( self::$admin );
		Inventory::adjust( self::RIVERSIDE, $this->items['JFT'], array( 'special' => 1 ), 'not stocked here' );
		wp_set_current_user( 0 );

		$r     = $this->place_order( $this->order_post( array( 'KT-W' => 3, 'JFT' => 1 ) ) );
		$items = Orders::get_items( $r['order']->id );
		$this->assertSame( array( 'KT-W', 'JFT' ), wp_list_pluck( $items, 'sku' ) );
		$this->assertSame( array( 3, 0 ), array_map( 'intval', wp_list_pluck( $items, 'quantity_in_stock' ) ), 'unmarked items count as available even with nothing on hand' );
		$this->assertSame( array( 0, 1 ), array_map( 'intval', wp_list_pluck( $items, 'quantity_special' ) ) );
		$this->assertSame( 'untracked', $r['order']->inventory_mode );
		$stock = Inventory::stock_map( array( self::RIVERSIDE ) )[ self::RIVERSIDE ];
		$this->assertSame( 0, $stock[ $this->items['KT-W'] ]['reserved'] );

		wp_set_current_user( self::$admin );
		$this->assertTrue( Orders::update_status( $r['order']->id, 'fulfilled' ) );
		$this->assertSame( 0, Inventory::stock_map( array( self::RIVERSIDE ) )[ self::RIVERSIDE ][ $this->items['KT-W'] ]['on_hand'] );
	}

	public function test_whole_line_setting_special_orders_the_entire_line() {
		$general                    = Settings::general();
		$general['excess_behavior'] = 'whole_line';
		Settings::update( Settings::GENERAL, $general );
		$r  = $this->place_order( $this->order_post( array( 'BT' => 7 ) ) );
		$it = Orders::get_items( $r['order']->id )[0];
		$this->assertSame( array( 0, 7, 0 ), array( (int) $it->quantity_in_stock, (int) $it->quantity_special, (int) $it->quantity_reserved ) );
		$this->assertSame( 0, Inventory::stock_map( array( self::RIVERSIDE ) )[ self::RIVERSIDE ][ $this->items['BT'] ]['reserved'] );
	}

	public function test_the_last_copy_cannot_be_taken_twice() {
		// Both visitors reviewed while 5 were available; the second confirm sees the new availability.
		$a  = $this->order_post( array( 'BT' => 5 ) );
		$b  = $this->order_post( array( 'BT' => 5 ), array( 'bmlt_lit_name' => 'Second Person', 'bmlt_lit_email' => 'second@example.org' ) );
		$ra = $this->submit( $a );
		$rb = $this->submit( $b );
		$a['bmlt_lit_step']       = 'confirm';
		$a['bmlt_lit_review_sig'] = $ra['state']['review']['sig'];
		$b['bmlt_lit_step']       = 'confirm';
		$b['bmlt_lit_review_sig'] = $rb['state']['review']['sig'];
		$this->assertNotNull( $this->submit( $a )['redirect'] );
		$second = $this->submit( $b );
		$this->assertNull( $second['redirect'], 'the second visitor is shown the new availability first' );
		$this->assertSame( 5, (int) $second['state']['review']['preview']['items'][0]->quantity_special );
		$b['bmlt_lit_review_sig'] = $second['state']['review']['sig'];
		$this->assertNotNull( $this->submit( $b )['redirect'] );
		$stock = Inventory::stock_map( array( self::RIVERSIDE ) )[ self::RIVERSIDE ][ $this->items['BT'] ];
		$this->assertSame( array( 5, 5, 0 ), array( $stock['on_hand'], $stock['reserved'], $stock['available'] ) );
	}

	public function test_notifications_go_to_the_orders_area_only_and_escape_what_the_requester_typed() {
		$r = $this->place_order(
			$this->order_post(
				array( 'BT' => 7 ),
				array(
					'bmlt_lit_name'  => 'Pat & "Sam" O\'Neil <script>alert(1)</script>',
					'bmlt_lit_notes' => "Please deliver\nto the side door & thanks",
				)
			)
		);
		$mails = $this->mails();
		$this->assertCount( 2, $mails, 'workers and requester' );
		$worker = $mails[0];
		$html   = self::html_part( $worker );
		$this->assertSame( array( 'riverside-lit@example.org', 'helper@example.org', 'riverside-chair@example.org', 'riverside-special@example.org' ), self::to( $worker ) );
		$this->assertStringContainsString( '[Riverside Area] Literature Order ' . $r['order']->order_number . ' – SPECIAL ORDER', $worker['subject'] );
		$this->assertStringContainsString( 'Reply-To: pat@example.org', $worker['header'] );
		$this->assertStringContainsString( 'From: Fictional Region Literature <literature@example.org>', $worker['header'] );
		$this->assertStringContainsString( 'SPECIAL ORDER NOTICE', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringContainsString( 'Pat &amp; &quot;Sam&quot; O&#039;Neil', $html );
		$this->assertStringContainsString( 'Please deliver<br />', $html );
		$this->assertStringContainsString( 'to the side door &amp; thanks', $html );
		$this->assertStringContainsString( 'admin.php?page=bmlt-lit-orders&#038;order_id=' . $r['order']->id, $html );
		$text = self::text_part( $worker );
		$this->assertStringContainsString( 'THIS ORDER CONTAINS SPECIAL ORDER ITEMS', $text, 'plain-text alternative' );
		$this->assertStringContainsString( 'Contact Name  Pat & "Sam" O\'Neil', $text );
		$this->assertStringContainsString( 'Stock: !! 5 in stock / 2 SPECIAL ORDER', $text );

		$requester = $mails[1];
		$this->assertSame( array( 'pat@example.org' ), self::to( $requester ) );
		$this->assertStringNotContainsString( 'bmlt-lit-orders', $requester['body'], 'the requester gets no staff link' );
		$this->assertStringContainsString( 'Reply-To: riverside-lit@example.org', $requester['header'] );

		$notes = Orders::get_notes( $r['order']->id );
		$this->assertStringContainsString( 'Literature-worker notification sent to 4 recipient(s).', $notes[0]->note );
	}

	public function test_order_form_renders_escaped_meetings_and_only_this_forms_items() {
		$html = do_shortcode( '[bmlt_literature_order area="102" title="Order <em>now</em>"]' );
		$this->assertStringContainsString( 'Order &lt;em&gt;now&lt;/em&gt;', $html );
		$this->assertStringContainsString( 'Hilltop Hope', $html );
		$this->assertStringNotContainsString( 'Just for Today —', $html, 'no Riverside meetings on a Hillcrest form' );
		$this->assertStringNotContainsString( 'Riverside T-shirt', $html, 'no Riverside-only items on a Hillcrest form' );
		$this->assertStringNotContainsString( 'Retired Booklet', $html );

		$all = do_shortcode( '[bmlt_literature_order]' );
		$this->assertStringContainsString( 'Recovery &amp; Beyond Group', $all, 'meeting names from BMLT are escaped, markup removed' );
		$this->assertStringNotContainsString( '<b>Group', $all );
		$this->assertStringNotContainsString( 'Hidden Meeting', $all );
		$this->assertStringContainsString( 'Meeting #1006', $all, 'a meeting without a name still gets a label' );
		$this->assertStringContainsString( 'North Shore Group', $all, 'sub-Area meetings are offered' );
		$this->assertStringNotContainsString( 'Faraway Friends', $all );

		$this->assertStringContainsString( 'not available yet', do_shortcode( '[bmlt_literature_order area="105"]' ), 'Areas that are not selected are ignored' );
	}
}
