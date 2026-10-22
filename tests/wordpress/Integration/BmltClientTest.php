<?php
/**
 * BMLT: service bodies and meetings from the fake root server, sub-Areas, caching, and what happens when the
 * server is down or the URL is wrong.
 */

namespace BmltLitTest;

use BMLT_Lit\Bmlt_Client;
use BMLT_Lit\Settings;

class BmltClientTest extends TestCase {

	private function set_root( $url ) {
		$b             = Settings::bmlt();
		$b['root_url'] = $url;
		Settings::update( Settings::BMLT, $b );
	}

	public function test_meetings_come_from_the_selected_areas_and_their_sub_areas() {
		$m = Bmlt_Client::get_meetings( false );
		$this->assertFalse( $m['stale'] );
		$this->assertCount( 22, $m['meetings'] );
		$this->assertSame( self::LAKESIDE, $m['meetings'][3002]['area_id'], 'sub-Area meeting counted under its parent' );
		$this->assertSame( 104, $m['meetings'][3002]['sb_id'] );
		$this->assertArrayNotHasKey( 1005, $m['meetings'], 'unpublished' );
		$this->assertArrayNotHasKey( 9001, $m['meetings'], 'another Region' );
		$this->assertSame( '20:30', $m['meetings'][1003]['start_time'] );
		$this->assertSame( 'Recovery & Beyond Group', $m['meetings'][1003]['name'], 'markup removed (escaped on output)' );

		$search = array_values(
			array_filter(
				self::$bmlt_requests,
				static function ( $q ) {
					return 'GetSearchResults' === $q['switcher'];
				}
			)
		)[0];
		$this->assertSame( array( '101', '102', '103' ), $search['services'] );
		$this->assertSame( '1', $search['recursive'] );

		$cfg = Settings::area( self::RIVERSIDE );
		$this->assertSame( 'Riverside Area', $cfg['name'] );
		$this->assertSame( 'Fictional Region', $cfg['parent_name'] );
		$this->assertSame( 'https://riverside.example.org/', $cfg['bmlt_url'] );
	}

	public function test_cached_meetings_are_used_without_asking_bmlt_again() {
		self::$bmlt_requests = array();
		Bmlt_Client::get_meetings( true );
		do_shortcode( '[bmlt_literature_order]' );
		$this->assertSame( array(), self::$bmlt_requests );
	}

	public function test_when_bmlt_is_down_ordering_keeps_working_from_the_last_good_copy() {
		$this->set_root( 'http://bmlt.test/down/main_server' );
		Bmlt_Client::expire_cache();
		$m = Bmlt_Client::get_meetings( true );
		$this->assertTrue( $m['stale'] );
		$this->assertSame( 'The BMLT server responded with HTTP status 503. Please verify the Root Server URL.', $m['error'] );
		$this->assertCount( 22, $m['meetings'] );
		$this->assertNotFalse( get_transient( Bmlt_Client::BACKOFF ), 'no retry on every page view' );

		self::$bmlt_requests = array();
		Bmlt_Client::get_meetings( true );
		$this->assertSame( array(), self::$bmlt_requests, 'backing off' );

		$ok = $this->place_order( $this->order_post( array( 'BT' => 1 ) ) );
		$this->assertNotNull( $ok['order'] );
		$this->assertSame( 'The BMLT server responded with HTTP status 503. Please verify the Root Server URL.', Bmlt_Client::status()['last_error'] );

		wp_set_current_user( self::$admin );
		$this->assertStringContainsString( 'the BMLT server could not be reached', do_shortcode( '[bmlt_literature_order]' ), 'administrators are told' );
		wp_set_current_user( 0 );
		$this->assertStringNotContainsString( 'the BMLT server could not be reached', do_shortcode( '[bmlt_literature_order]' ) );
	}

	public function test_errors_for_wrong_urls() {
		$cases = array(
			'http://bmlt.test/html/main_server'        => 'did not return valid JSON',
			'http://bmlt.test/unreachable/main_server' => 'Could not connect to the BMLT server',
			'http://bmlt.test/wrong'                   => 'HTTP status 404',
		);
		foreach ( $cases as $url => $msg ) {
			$this->set_root( $url );
			$t = Bmlt_Client::test_connection();
			$this->assertFalse( $t['success'], $url );
			$this->assertStringContainsString( $msg, $t['messages'][0], $url );
		}
		$this->set_root( '' );
		$this->assertSame( 'The BMLT Root Server URL has not been configured.', Bmlt_Client::request( 'GetServerInfo' )->get_error_message() );
	}

	public function test_without_any_cache_the_form_says_ordering_is_unavailable() {
		Bmlt_Client::clear_cache();
		$this->set_root( 'http://bmlt.test/down/main_server' );
		$html = do_shortcode( '[bmlt_literature_order]' );
		$this->assertStringContainsString( 'The meeting list is temporarily unavailable', $html );
	}

	public function test_changing_selected_areas_refreshes_meetings() {
		$b                   = Settings::bmlt();
		$b['selected_areas'] = array( self::HILLCREST );
		Settings::update( Settings::BMLT, $b );
		$m = Bmlt_Client::get_meetings( true );
		$this->assertSame( array( 2001, 2002 ), array_keys( $m['meetings'] ) );
	}

	public function test_refresh_runs_on_a_schedule() {
		$this->assertNotFalse( wp_next_scheduled( Bmlt_Client::CRON_HOOK ) );
		$schedules = wp_get_schedules();
		$this->assertSame( 12 * HOUR_IN_SECONDS, $schedules['bmlt_lit_interval']['interval'] );
		self::$bmlt_requests = array();
		do_action( Bmlt_Client::CRON_HOOK );
		$this->assertSame( array( 'GetServiceBodies', 'GetSearchResults' ), wp_list_pluck( self::$bmlt_requests, 'switcher' ) );
	}

	public function test_service_body_tree() {
		$tree = Bmlt_Client::service_body_tree();
		$rows = array();
		foreach ( $tree as $t ) {
			$rows[] = str_repeat( '-', $t['depth'] ) . $t['body']['name'];
		}
		$this->assertSame(
			array( 'Fictional Region', '-Empty Area', '-Hillcrest Area', '-Lakeside Area', '--Lakeside North Area', '-Riverside Area', 'Other Region', '-Faraway Area' ),
			$rows
		);
	}
}
