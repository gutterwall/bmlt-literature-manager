<?php
/**
 * Base class: a site set up the way the README's "Setup, step by step" describes.
 *
 * - BMLT: the fake root server (fake-bmlt/), Areas 101 Riverside, 102 Hillcrest and 103 Lakeside selected
 *   (Lakeside includes its sub-Area 104), meetings loaded.
 * - Area configuration: worker, chair and special-order emails for each Area.
 * - Catalog: a few shared items, one Riverside-only item and one inactive item.
 * - Riverside inventory: Basic Text 5 on hand, IP #1 100, Keytag 0.
 * - Users: administrator; Literature Worker and Literature Chair (roles); a worker restricted to Riverside;
 *   a subscriber granted only "Manage settings" on the Permissions tab; a plain subscriber.
 *
 * Every test has its own empty data directory, its own fake-BMLT request log and its own mailbox.
 */

namespace BmltLitTest;

use BMLT_Lit\Bmlt_Client;
use BMLT_Lit\Catalog;
use BMLT_Lit\Inventory;
use BMLT_Lit\Migrator;
use BMLT_Lit\Permissions;
use BMLT_Lit\Settings;
use BMLT_Lit\Store;

/**
 * Thrown instead of redirecting (wp_redirect would exit).
 */
class Redirected extends \Exception {
	/**
	 * Location.
	 *
	 * @var string
	 */
	public $location;

	/**
	 * Status.
	 *
	 * @var int
	 */
	public $status;

	public function __construct( $location, $status ) {
		parent::__construct( 'Redirect to ' . $location );
		$this->location = $location;
		$this->status   = $status;
	}
}

abstract class TestCase extends \WP_UnitTestCase {

	const BMLT      = 'http://bmlt.test/main_server';
	const RIVERSIDE = 101;
	const HILLCREST = 102;
	const LAKESIDE  = 103;
	const IP        = '203.0.113.5';

	/** @var int */
	public static $admin;
	/** @var int */
	public static $worker;
	/** @var int */
	public static $chair;
	/** @var int */
	public static $restricted;
	/** @var int */
	public static $settings_mgr;
	/** @var int */
	public static $subscriber;

	/**
	 * Requests the fake BMLT server received in this test (query arrays).
	 *
	 * @var array
	 */
	public static $bmlt_requests = array();

	/**
	 * Item ids by SKU.
	 *
	 * @var int[]
	 */
	protected $items = array();

	/**
	 * This test's data directory.
	 *
	 * @var string
	 */
	protected $dir = '';

	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin        = $factory->user->create( array( 'role' => 'administrator', 'user_login' => 'lit-admin' ) );
		self::$worker       = $factory->user->create( array( 'role' => 'bmlt_lit_worker', 'user_login' => 'lit-worker' ) );
		self::$chair        = $factory->user->create( array( 'role' => 'bmlt_lit_chair', 'user_login' => 'lit-chair' ) );
		self::$restricted   = $factory->user->create( array( 'role' => 'bmlt_lit_worker', 'user_login' => 'lit-riverside' ) );
		self::$settings_mgr = $factory->user->create( array( 'role' => 'subscriber', 'user_login' => 'lit-settings' ) );
		self::$subscriber   = $factory->user->create( array( 'role' => 'subscriber', 'user_login' => 'lit-subscriber' ) );
		if ( is_multisite() ) {
			grant_super_admin( self::$admin );
		}
	}

	public function set_up() {
		parent::set_up();
		$this->dir                          = sys_get_temp_dir() . '/bmlt-lit-test-' . getmypid() . '-' . wp_generate_password( 8, false, false );
		$GLOBALS['bmlt_lit_test_data_dir'] = $this->dir;
		self::$bmlt_requests                = array();
		self::reset_plugin_state();
		add_filter( 'pre_http_request', array( __CLASS__, 'fake_http' ), 10, 3 );
		add_filter( 'wp_redirect', array( __CLASS__, 'throw_redirect' ), 1, 2 );
		reset_phpmailer_instance();
		$_SERVER['REMOTE_ADDR'] = self::IP;

		delete_transient( 'bmlt_lit_init_attempt' );
		Migrator::maybe_initialize();
		$this->assertTrue( Store::exists( Settings::DOC ), 'the data store was initialized' );
		$this->configure_site();
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( __CLASS__, 'fake_http' ), 10 );
		remove_filter( 'wp_redirect', array( __CLASS__, 'throw_redirect' ), 1 );
		self::rrmdir( $this->dir );
		$GLOBALS['bmlt_lit_test_data_dir'] = '';
		self::reset_plugin_state();
		$_POST = array();
		$_GET  = array();
		unset( $_SERVER['REQUEST_METHOD'] );
		parent::tear_down();
	}

	/**
	 * Forget everything the plugin cached in static properties.
	 */
	public static function reset_plugin_state() {
		Store::flush_cache();
		Settings::flush();
		self::set_static( Store::class, 'read_errors', array() );
		self::set_static( \BMLT_Lit\Front\Front::class, 'state', array( 'errors' => array(), 'values' => array() ) );
	}

	/**
	 * Set a private static property.
	 *
	 * @param string $class Class.
	 * @param string $prop  Property.
	 * @param mixed  $value Value.
	 */
	public static function set_static( $class, $prop, $value ) {
		$r = new \ReflectionProperty( $class, $prop );
		$r->setAccessible( true );
		$r->setValue( null, $value );
	}

	/**
	 * Read a private static property.
	 *
	 * @param string $class Class.
	 * @param string $prop  Property.
	 * @return mixed
	 */
	public static function get_static( $class, $prop ) {
		$r = new \ReflectionProperty( $class, $prop );
		$r->setAccessible( true );
		return $r->getValue();
	}

	/**
	 * The site as the README's setup steps leave it.
	 */
	protected function configure_site() {
		$bmlt                   = Settings::bmlt();
		$bmlt['root_url']       = self::BMLT;
		$bmlt['selected_areas'] = array( self::RIVERSIDE, self::HILLCREST, self::LAKESIDE );
		$this->assertTrue( Settings::update( Settings::BMLT, $bmlt ) );
		$this->assertTrue( Bmlt_Client::refresh_all() );

		$areas = Settings::areas_config();
		$areas[ self::RIVERSIDE ] = array_merge(
			$areas[ self::RIVERSIDE ],
			array(
				'worker_emails'         => 'riverside-lit@example.org, helper@example.org',
				'chair_email'           => 'riverside-chair@example.org',
				'special_order_emails'  => 'riverside-special@example.org',
				'delivery_instructions' => 'Pick up at the Riverside ASC.',
				'service_meeting_info'  => '2nd Sunday, 2 PM',
			)
		);
		$areas[ self::HILLCREST ] = array_merge( $areas[ self::HILLCREST ], array( 'worker_emails' => 'hillcrest-lit@example.org' ) );
		$this->assertTrue( Settings::update( Settings::AREAS, $areas ) );

		$general                       = Settings::general();
		$general['email_from_address'] = 'literature@example.org';
		$general['email_from_name']    = 'Fictional Region Literature';
		$general['org_name']           = 'Fictional Region';
		$this->assertTrue( Settings::update( Settings::GENERAL, $general ) );

		wp_set_current_user( self::$admin );
		$catalog = array(
			array( 'BT', 'Basic Text', 'Books', '15.65', 1, 0 ),
			array( 'IP1', 'Who, What, How, and Why', 'Pamphlets', '0.33', 1, 0 ),
			array( 'KT-W', 'Welcome Keytag', 'Keytags', '0.62', 1, 0 ),
			array( 'JFT', 'Just for Today', 'Books', '14.30', 1, 0 ),
			array( 'OLD', 'Retired Booklet', 'Booklets', '2.00', 0, 0 ),
			array( 'RS-SHIRT', 'Riverside T-shirt', 'Merchandise', '12.00', 1, self::RIVERSIDE ),
		);
		foreach ( $catalog as $i => $c ) {
			$id = Catalog::create(
				array(
					'sku'               => $c[0],
					'name'              => $c[1],
					'category'          => $c[2],
					'price'             => $c[3],
					'is_active'         => $c[4],
					'area_id'           => $c[5],
					'sort_order'        => $i + 1,
					'short_description' => $c[1] . ' description',
				)
			);
			$this->assertIsInt( $id, 'catalog item ' . $c[0] );
			$this->items[ $c[0] ] = $id;
		}
		foreach ( array( 'BT' => 5, 'IP1' => 100, 'KT-W' => 0, 'RS-SHIRT' => 3 ) as $sku => $qty ) {
			$this->assertIsArray( Inventory::adjust( self::RIVERSIDE, $this->items[ $sku ], array( 'set' => $qty, 'low_stock_threshold' => 2 ), 'count' ) );
		}
		$this->assertTrue(
			Permissions::save_grants(
				array(
					self::$restricted   => array(
						'caps'  => array(),
						'areas' => array( self::RIVERSIDE ),
					),
					self::$settings_mgr => array(
						'caps'  => array( Permissions::MANAGE_SETTINGS ),
						'areas' => array(),
					),
				)
			)
		);
		wp_set_current_user( 0 );
		reset_phpmailer_instance();
		self::reset_plugin_state();
	}

	/**
	 * In-process HTTP: requests to bmlt.test go to the fake BMLT server; nothing else may leave the test.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param array  $args Request arguments.
	 * @param string $url  URL.
	 * @return array|\WP_Error
	 */
	public static function fake_http( $pre, $args, $url ) {
		$parts = wp_parse_url( $url );
		if ( ! isset( $parts['host'] ) || ! in_array( $parts['host'], array( 'bmlt.test', '93.184.215.14' ), true ) ) {
			return new \WP_Error( 'http_request_failed', 'Tests do not make network requests: ' . $url );
		}
		$query = array();
		parse_str( isset( $parts['query'] ) ? $parts['query'] : '', $query );
		self::$bmlt_requests[] = $query;
		$path = isset( $parts['path'] ) ? $parts['path'] : '/';
		if ( 0 === strpos( $path, '/unreachable/' ) ) {
			return new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to bmlt.test port 80' );
		}
		if ( 0 === strpos( $path, '/down/' ) ) {
			list( $status, $body ) = array( 503, 'Service unavailable' );
		} elseif ( 0 === strpos( $path, '/html/' ) ) {
			list( $status, $body ) = array( 200, '<!DOCTYPE html><html><body>Welcome</body></html>' );
		} elseif ( ! preg_match( '#/main_server/client_interface/json/$#', $path ) ) {
			list( $status, $body ) = array( 404, 'Not found' );
		} else {
			list( $status, , $body ) = \BmltLitTest\FakeBmlt::handle( $query );
		}
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $status,
				'message' => get_status_header_desc( $status ),
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Throw instead of redirecting.
	 *
	 * @param string $location Location.
	 * @param int    $status   Status.
	 * @throws Redirected Always.
	 */
	public static function throw_redirect( $location, $status ) {
		throw new Redirected( $location, $status );
	}

	/**
	 * Remove a directory tree.
	 *
	 * @param string $dir Directory.
	 */
	public static function rrmdir( $dir ) {
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $f ) {
			if ( '.' === $f || '..' === $f ) {
				continue;
			}
			$p = $dir . '/' . $f;
			if ( is_dir( $p ) && ! is_link( $p ) ) {
				self::rrmdir( $p );
			} else {
				unlink( $p );
			}
		}
		rmdir( $dir );
	}

	/* ------------------------------------------------------------------
	 * The public order form.
	 * ---------------------------------------------------------------- */

	/**
	 * Signed timestamp the order form would carry, rendered $ago seconds ago.
	 *
	 * @param int $ago Seconds.
	 * @return string
	 */
	protected static function time_token( $ago = 60 ) {
		$t = time() - $ago;
		return $t . '.' . hash_hmac( 'sha256', 'ts:' . $t, wp_salt( 'nonce' ) );
	}

	/**
	 * Signature binding a form to its Areas.
	 *
	 * @param int[] $areas Areas.
	 * @return string
	 */
	protected static function areas_sig( array $areas ) {
		sort( $areas );
		return hash_hmac( 'sha256', 'areas:' . implode( ',', $areas ), wp_salt( 'nonce' ) );
	}

	/**
	 * A valid order form submission (step "review"), as a visitor's browser would send it.
	 *
	 * @param array $qty       SKU => quantity.
	 * @param array $overrides Field overrides.
	 * @return array
	 */
	protected function order_post( array $qty, array $overrides = array() ) {
		$areas = array( self::RIVERSIDE, self::HILLCREST, self::LAKESIDE );
		$post  = array(
			'bmlt_lit_action'    => 'submit_order',
			'bmlt_lit_step'      => 'review',
			'bmlt_lit_nonce'     => wp_create_nonce( 'bmlt_lit_submit_order' ),
			'bmlt_lit_token'     => 'tok' . wp_generate_password( 29, false, false ),
			'bmlt_lit_ts'        => self::time_token(),
			'bmlt_lit_areas'     => implode( ',', $areas ),
			'bmlt_lit_areas_sig' => self::areas_sig( $areas ),
			'bmlt_lit_return'    => home_url( '/order-literature/' ),
			'bmlt_lit_meeting'   => 1001,
			'bmlt_lit_name'      => 'Pat Example',
			'bmlt_lit_phone'     => '555-0123',
			'bmlt_lit_email'     => 'pat@example.org',
			'bmlt_lit_notes'     => '',
			'bmlt_lit_website'   => '',
			'bmlt_lit_qty'       => array(),
		);
		foreach ( $qty as $sku => $n ) {
			$post['bmlt_lit_qty'][ $this->items[ $sku ] ] = (string) $n;
		}
		return array_merge( $post, $overrides );
	}

	/**
	 * Send a POST through the plugin's handler.
	 *
	 * @param array $post Unslashed POST data.
	 * @return array { redirect: string|null, state: array }
	 */
	protected function submit( array $post ) {
		self::set_static( \BMLT_Lit\Front\Front::class, 'state', array( 'errors' => array(), 'values' => array() ) );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = wp_slash( $post );
		$redirect                  = null;
		try {
			\BMLT_Lit\Front\Front::handle_submission();
		} catch ( Redirected $r ) {
			$redirect = $r->location;
		}
		$_POST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
		return array(
			'redirect' => $redirect,
			'state'    => self::get_static( \BMLT_Lit\Front\Front::class, 'state' ),
		);
	}

	/**
	 * Review, then confirm, the way a visitor clicks through.
	 *
	 * @param array $post Order form submission.
	 * @return array { review: array, redirect: string|null, state: array, order: object|null }
	 */
	protected function place_order( array $post ) {
		$review = $this->submit( $post );
		$this->assertNull( $review['redirect'], 'review does not redirect' );
		$this->assertSame( array(), $review['state']['errors'], 'review has no errors' );
		$this->assertNotEmpty( $review['state']['review'], 'a review is shown' );
		$confirm                        = $post;
		$confirm['bmlt_lit_step']       = 'confirm';
		$confirm['bmlt_lit_review_sig'] = $review['state']['review']['sig'];
		$result                         = $this->submit( $confirm );
		$order                          = null;
		if ( $result['redirect'] ) {
			$q = array();
			parse_str( (string) wp_parse_url( $result['redirect'], PHP_URL_QUERY ), $q );
			$order = \BMLT_Lit\Orders::get_by_access( $q['bmlt_lit_order'], $q['bmlt_lit_key'] );
		}
		return array(
			'review'   => $review,
			'redirect' => $result['redirect'],
			'state'    => $result['state'],
			'order'    => $order,
		);
	}

	/**
	 * Emails sent so far.
	 *
	 * @return array
	 */
	protected function mails() {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}

	/**
	 * Decoded HTML part of a sent email.
	 *
	 * @param array $mail Mock mail.
	 * @return string
	 */
	protected static function html_part( array $mail ) {
		return self::mime_part( $mail, 'text/html' );
	}

	/**
	 * Decoded plain-text part of a sent email.
	 *
	 * @param array $mail Mock mail.
	 * @return string
	 */
	protected static function text_part( array $mail ) {
		return self::mime_part( $mail, 'text/plain' );
	}

	/**
	 * One part of a multipart/alternative message (or the whole body when it is a single part).
	 *
	 * @param array  $mail Mock mail.
	 * @param string $type MIME type.
	 * @return string
	 */
	private static function mime_part( array $mail, $type ) {
		$body = str_replace( "\r\n", "\n", (string) $mail['body'] );
		if ( ! preg_match( '/^--(\S+)$/m', $body, $b ) ) {
			return $body;
		}
		foreach ( explode( '--' . $b[1], $body ) as $part ) {
			$split = strpos( $part, "\n\n" );
			if ( false === $split ) {
				continue;
			}
			$head = substr( $part, 0, $split );
			if ( false === stripos( $head, 'Content-Type: ' . $type ) ) {
				continue;
			}
			$content = substr( $part, $split + 2 );
			return false !== stripos( $head, 'quoted-printable' ) ? quoted_printable_decode( $content ) : ( false !== stripos( $head, 'base64' ) ? base64_decode( $content ) : $content );
		}
		return '';
	}

	/**
	 * Recipient addresses of one sent email.
	 *
	 * @param array $mail Mock mail.
	 * @return string[]
	 */
	protected static function to( array $mail ) {
		return array_map(
			static function ( $r ) {
				return $r[0];
			},
			$mail['to']
		);
	}

	/**
	 * Run a REST request as a user.
	 *
	 * @param int    $user   User id (0 = visitor).
	 * @param string $method Method.
	 * @param string $route  Route below /bmlt-lit/v1.
	 * @param array  $params Parameters.
	 * @return \WP_REST_Response
	 */
	protected function rest( $user, $method, $route, array $params = array() ) {
		wp_set_current_user( $user );
		self::reset_plugin_state();
		$req = new \WP_REST_Request( $method, '/bmlt-lit/v1/' . ltrim( $route, '/' ) );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return rest_get_server()->dispatch( $req );
	}
}
