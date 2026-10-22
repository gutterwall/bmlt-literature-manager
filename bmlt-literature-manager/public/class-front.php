<?php
/**
 * Front-end controller.
 *
 * @package BMLT_Lit
 * @version 6.2.0
 */

namespace BMLT_Lit\Front;

use BMLT_Lit\Bmlt_Client;
use BMLT_Lit\Catalog;
use BMLT_Lit\Email;
use BMLT_Lit\Helpers;
use BMLT_Lit\Inventory;
use BMLT_Lit\Orders;
use BMLT_Lit\Permissions;
use BMLT_Lit\Settings;
use BMLT_Lit\Shortcodes;

defined( 'ABSPATH' ) || exit;

/**
 * Public order form, protected worker pages and print view.
 */
class Front {

	/**
	 * Submission state (errors + values) for re-rendering the form.
	 *
	 * @var array
	 */
	private static $state = array(
		'errors' => array(),
		'values' => array(),
	);

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_no_cache' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_submission' ) );
		add_action( 'admin_post_bmlt_lit_print_order', array( __CLASS__, 'print_order' ) );
	}

	/**
	 * Keep pages with the order form out of page caches. A cached copy would hand every visitor the same form token
	 * (and, for visitors who are not logged in, the same valid security token for up to a day).
	 */
	public static function maybe_no_cache() {
		$post = is_singular() ? get_queried_object() : null;
		if ( $post instanceof \WP_Post && has_shortcode( (string) $post->post_content, 'bmlt_literature_order' ) ) {
			self::no_cache();
		}
	}

	/**
	 * Tell caching plugins (DONOTCACHEPAGE) and browsers/proxies (headers, when still possible) not to store this page.
	 */
	public static function no_cache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! headers_sent() ) {
			nocache_headers();
		}
	}

	/**
	 * Register (not enqueue) front-end assets.
	 */
	public static function register_assets() {
		wp_register_style( 'bmlt-lit-public', BMLT_LIT_URL . 'public/css/public.css', array(), Helpers::asset_version( 'public/css/public.css' ) );
		wp_register_script( 'bmlt-lit-pager', BMLT_LIT_URL . 'public/js/pager.js', array(), Helpers::asset_version( 'public/js/pager.js' ), true );
		wp_register_script( 'bmlt-lit-order-form', BMLT_LIT_URL . 'public/js/order-form.js', array( 'bmlt-lit-pager' ), Helpers::asset_version( 'public/js/order-form.js' ), true );
		wp_register_script( 'bmlt-lit-worker', BMLT_LIT_URL . 'public/js/worker.js', array( 'bmlt-lit-pager' ), Helpers::asset_version( 'public/js/worker.js' ), true );
	}

	/**
	 * Ensure assets are registered (e.g. shortcode rendered outside the normal flow).
	 */
	private static function ensure_registered() {
		if ( ! wp_style_is( 'bmlt-lit-public', 'registered' ) ) {
			self::register_assets();
		}
	}

	/**
	 * Resolve which Areas a shortcode instance may use (never trusts attributes beyond selected Areas).
	 *
	 * @param array $atts Attributes.
	 * @return int[]
	 */
	private static function resolve_areas( array $atts ) {
		$selected  = Settings::selected_areas();
		$requested = array_merge( Shortcodes::parse_ids( $atts['area'] ), Shortcodes::parse_ids( $atts['areas'] ) );
		if ( empty( $requested ) ) {
			return $selected;
		}
		return array_values( array_intersect( $selected, $requested ) );
	}

	/**
	 * HMAC signature for an Area list (binds a form to its shortcode's Areas).
	 *
	 * @param int[] $areas Areas.
	 * @return string
	 */
	private static function sign_areas( array $areas ) {
		sort( $areas );
		return hash_hmac( 'sha256', 'areas:' . implode( ',', $areas ), wp_salt( 'nonce' ) );
	}

	/**
	 * Signed timestamp token (bot timing check).
	 *
	 * @return string
	 */
	private static function time_token() {
		$t = time();
		return $t . '.' . hash_hmac( 'sha256', 'ts:' . $t, wp_salt( 'nonce' ) );
	}

	/**
	 * Verify a signed timestamp; returns elapsed seconds or false.
	 *
	 * @param string $token Token.
	 * @return int|false
	 */
	private static function verify_time_token( $token ) {
		$parts = explode( '.', (string) $token );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) ) {
			return false;
		}
		if ( ! hash_equals( hash_hmac( 'sha256', 'ts:' . $parts[0], wp_salt( 'nonce' ) ), $parts[1] ) ) {
			return false;
		}
		$elapsed = time() - (int) $parts[0];
		return ( $elapsed >= 0 && $elapsed < 2 * DAY_IN_SECONDS ) ? $elapsed : false;
	}

	/**
	 * Validate a per_page shortcode attribute: 20, 50, 100 or all (0).
	 *
	 * @param mixed $value Attribute value.
	 * @return int 0 means "All".
	 */
	private static function per_page_attr( $value ) {
		$value = strtolower( trim( (string) $value ) );
		if ( 'all' === $value || '0' === $value ) {
			return 0;
		}
		$n = absint( $value );
		return in_array( $n, array( 20, 50, 100 ), true ) ? $n : 20;
	}

	/**
	 * Strings for the pager component.
	 *
	 * @return array
	 */
	private static function pager_strings() {
		return array(
			'perPage'  => __( 'Items per page', 'bmlt-literature-manager' ),
			'all'      => __( 'All', 'bmlt-literature-manager' ),
			'prev'     => __( 'Previous', 'bmlt-literature-manager' ),
			'next'     => __( 'Next', 'bmlt-literature-manager' ),
			'page'     => __( 'Page', 'bmlt-literature-manager' ),
			/* translators: %s: number of pages. */
			'ofPages'  => __( 'of %s', 'bmlt-literature-manager' ),
			/* translators: 1: first item number, 2: last item number, 3: total items. */
			'showing'  => __( 'Showing %1$s–%2$s of %3$s', 'bmlt-literature-manager' ),
			'pages'    => __( 'Pages', 'bmlt-literature-manager' ),
			'pagesEnd' => __( 'Pages (end of list)', 'bmlt-literature-manager' ),
			/* translators: %s: number of items. */
			'offPage'  => __( 'Items you chose on other pages: %s. They stay in your order.', 'bmlt-literature-manager' ),
		);
	}

	/* ------------------------------------------------------------------
	 * [bmlt_literature_order]
	 * ---------------------------------------------------------------- */

	/**
	 * Public order form shortcode.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode_order_form( $atts ) {
		$atts = shortcode_atts(
			array(
				'area'              => '',
				'areas'             => '',
				'show_prices'       => 'yes',
				'show_inventory'    => 'yes',
				'show_descriptions' => 'yes',
				'show_quantities'   => 'no', // Deprecated in 6.0 (the simplified form no longer shows quantities on hand).
				'title'             => '',
				'per_page'          => '20',
			),
			is_array( $atts ) ? $atts : array(),
			'bmlt_literature_order'
		);

		self::ensure_registered();
		wp_enqueue_style( 'bmlt-lit-public' );
		self::no_cache();

		// Confirmation view after a successful submission.
		if ( isset( $_GET['bmlt_lit_order'], $_GET['bmlt_lit_key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order = Orders::get_by_access(
				sanitize_text_field( wp_unslash( $_GET['bmlt_lit_order'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				sanitize_text_field( wp_unslash( $_GET['bmlt_lit_key'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			);
			if ( $order ) {
				return self::render_view(
					'confirmation',
					array(
						'order'       => $order,
						'items'       => Orders::get_items( $order->id ),
						'show_prices' => Shortcodes::yes( $atts['show_prices'] ),
						'new_url'     => remove_query_arg( array( 'bmlt_lit_order', 'bmlt_lit_key' ) ),
					)
				);
			}
			return '<div class="bmlt-lit bmlt-lit-order-form"><div class="bmlt-lit-notice bmlt-lit-notice--error" role="alert"><p>'
				. esc_html__( 'That order confirmation link is invalid or has expired.', 'bmlt-literature-manager' ) . '</p></div></div>';
		}

		if ( ! empty( self::$state['review'] ) ) {
			wp_enqueue_script( 'bmlt-lit-order-form' );
			return self::render_view(
				'review',
				array_merge(
					self::$state['review'],
					array(
						'show_prices' => Shortcodes::yes( $atts['show_prices'] ),
						'form_id'     => 'bmlt-lit-review',
					)
				)
			);
		}

		$areas = self::resolve_areas( $atts );
		if ( empty( $areas ) ) {
			return '<div class="bmlt-lit bmlt-lit-order-form"><p class="bmlt-lit-notice">' . esc_html__( 'Literature ordering is not available yet. An administrator needs to select the Areas served by this site.', 'bmlt-literature-manager' ) . '</p></div>';
		}

		$meeting_data = Bmlt_Client::get_meetings( true );
		$meetings     = array_filter(
			$meeting_data['meetings'],
			static function ( $m ) use ( $areas ) {
				return in_array( (int) $m['area_id'], $areas, true );
			}
		);
		if ( empty( $meetings ) ) {
			return '<div class="bmlt-lit bmlt-lit-order-form"><p class="bmlt-lit-notice">' . esc_html__( 'The meeting list is temporarily unavailable, so literature orders cannot be placed right now. Please try again later.', 'bmlt-literature-manager' ) . '</p></div>';
		}

		$items = Catalog::items_for_areas( $areas );
		$stock = Inventory::stock_map( $areas );

		wp_enqueue_script( 'bmlt-lit-order-form' );

		$s         = Settings::general();
		$area_info = array();
		foreach ( $areas as $a ) {
			$cfg   = Settings::area( $a );
			$extra = array();
			if ( '' !== trim( (string) $cfg['delivery_instructions'] ) ) {
				$extra[] = (string) $cfg['delivery_instructions'];
			}
			if ( '' !== trim( (string) $cfg['service_meeting_info'] ) ) {
				$extra[] = __( 'Area Service meeting:', 'bmlt-literature-manager' ) . ' ' . $cfg['service_meeting_info'];
			}
			$next = Settings::next_service_meeting( $a );
			if ( '' !== $next ) {
				/* translators: %s: date. */
				$extra[] = sprintf( __( 'Next Area Service meeting: %s.', 'bmlt-literature-manager' ), $next );
			}
			$area_info[ $a ] = array(
				'name'  => Settings::area_name( $a ),
				'extra' => implode( ' ', $extra ),
			);
		}

		$stock_js = array();
		$low_def  = (int) $s['default_low_stock'];
		foreach ( $areas as $a ) {
			$stock_js[ $a ] = array();
			foreach ( $items as $it ) {
				$iid = (int) $it->id;
				if ( (int) $it->area_id && (int) $it->area_id !== $a ) {
					continue;
				}
				$row     = isset( $stock[ $a ][ $iid ] ) ? $stock[ $a ][ $iid ] : null;
				$tracked = Settings::area_tracks_inventory( $a );
				$stock_js[ $a ][ $iid ] = array(
					Inventory::area_status( $a, $row, true, $low_def ),
					$tracked ? ( $row ? (int) $row['available'] : 0 ) : 1000000,
				);
			}
		}

		$meetings_js = array();
		foreach ( $meetings as $m ) {
			$meetings_js[] = array(
				'id'    => (int) $m['id'],
				'area'  => (int) $m['area_id'],
				'label' => Helpers::meeting_label( $m ),
			);
		}

		$form_id = 'bmlt-lit-form-' . wp_generate_password( 6, false, false );
		$js      = array(
			'formId'       => $form_id,
			'currency'     => array(
				'symbol'   => (string) $s['currency_symbol'],
				'position' => (string) $s['currency_position'],
				'decimals' => (int) $s['decimals'],
				'dec'      => (string) $s['decimal_sep'],
				'thou'     => (string) $s['thousands_sep'],
			),
			'excess'       => 'whole_line' === $s['excess_behavior'] ? 'whole_line' : 'split',
			'showPrices'   => Shortcodes::yes( $atts['show_prices'] ),
			'showStock'    => Shortcodes::yes( $atts['show_inventory'] ),
			'showQty'      => false,
			'areas'        => $area_info,
			'stock'        => $stock_js,
			'meetings'     => $meetings_js,
			'areaNames'    => wp_list_pluck( $area_info, 'name' ),
			'perPage'      => self::per_page_attr( $atts['per_page'] ),
			'pager'        => self::pager_strings(),
			'strings'      => array(
				'in_stock'      => __( 'In Stock', 'bmlt-literature-manager' ),
				'low_stock'     => __( 'Low Stock', 'bmlt-literature-manager' ),
				'special_order' => __( 'Special Order', 'bmlt-literature-manager' ),
				'inactive'      => __( 'Inactive', 'bmlt-literature-manager' ),
				'specialMsg'    => (string) $s['special_order_message'],
				/* translators: 1: in-stock quantity, 2: special-order quantity. */
				'split'         => __( '%1$s from stock, %2$s special order', 'bmlt-literature-manager' ),
				'wholeSpecial'  => __( 'This line will be special ordered.', 'bmlt-literature-manager' ),
				/* translators: %s: available quantity. */
				'available'     => __( '%s available', 'bmlt-literature-manager' ),
				'chooseMeeting' => __( '— Select your meeting —', 'bmlt-literature-manager' ),
				'noMeetings'    => __( 'No meetings match your search.', 'bmlt-literature-manager' ),
				'submitting'    => __( 'Submitting…', 'bmlt-literature-manager' ),
				/* translators: %s: Area name. */
				'areaLabel'     => __( 'Your Area: %s', 'bmlt-literature-manager' ),
				'groups'        => __( 'Literature groups', 'bmlt-literature-manager' ),
				'allGroups'     => __( 'All', 'bmlt-literature-manager' ),
				'sortBy'        => __( 'Sort by:', 'bmlt-literature-manager' ),
				'sortAsc'       => __( 'sorted ascending', 'bmlt-literature-manager' ),
				'sortDesc'      => __( 'sorted descending', 'bmlt-literature-manager' ),
				'sortNone'      => __( 'not sorted', 'bmlt-literature-manager' ),
				/* translators: 1: column name, 2: sort direction. */
				'sortedBy'      => __( 'Sorted by %1$s, %2$s.', 'bmlt-literature-manager' ),
				'defaultOrder'  => __( 'Default order: catalog order with special orders last.', 'bmlt-literature-manager' ),
				'other'         => __( 'Other', 'bmlt-literature-manager' ),
				/* translators: 1: special-order quantity, 2: quantity ordered. */
				'partOf'        => __( '%1$s of %2$s', 'bmlt-literature-manager' ),
			),
		);

		$return = get_permalink();
		if ( ! $return ) {
			$return = home_url( '/' );
		}

		return self::render_view(
			'order-form',
			array(
				'form_id'       => $form_id,
				'atts'          => $atts,
				'areas'         => $areas,
				'meetings'      => $meetings,
				'items'         => $items,
				'stock_js'      => $stock_js,
				'js'            => $js,
				'settings'      => $s,
				'single_area'   => 1 === count( $areas ) ? (int) $areas[0] : 0,
				'errors'        => self::$state['errors'],
				'values'        => self::$state['values'],
				'areas_sig'     => self::sign_areas( $areas ),
				'time_token'    => ! empty( self::$state['hidden']['ts'] ) ? self::$state['hidden']['ts'] : self::time_token(),
				'form_token'    => ! empty( self::$state['hidden']['token'] ) ? self::$state['hidden']['token'] : wp_generate_password( 32, false, false ),
				'return_url'    => $return,
				'show_prices'   => Shortcodes::yes( $atts['show_prices'] ),
				'show_stock'    => Shortcodes::yes( $atts['show_inventory'] ),
				'show_desc'     => Shortcodes::yes( $atts['show_descriptions'] ),
				'categories'    => array_values( array_unique( array_map( 'strval', wp_list_pluck( $items, 'category' ) ) ) ),
				'stale_notice'  => $meeting_data['stale'] && current_user_can( Permissions::MANAGE_SETTINGS ),
				'meeting_fetch' => $meeting_data['fetched_at'],
			)
		);
	}

	/**
	 * Process a POSTed order (runs on template_redirect, before output).
	 *
	 * Steps: "review" (default) validates and shows a server-priced review page; "edit" returns to the
	 * form with the entries kept; "confirm" re-validates, re-prices and creates the order. If anything
	 * changed between review and confirm (prices, availability), the review is shown again.
	 */
	public static function handle_submission() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '' ) ) {
			return;
		}
		if ( ! isset( $_POST['bmlt_lit_action'] ) || 'submit_order' !== $_POST['bmlt_lit_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in validate_submission().
		$step = isset( $_POST['bmlt_lit_step'] ) && is_scalar( $_POST['bmlt_lit_step'] ) ? sanitize_key( wp_unslash( $_POST['bmlt_lit_step'] ) ) : 'review';

		$valid = self::validate_submission();
		if ( 'edit' === $step ) {
			// Back to the form with everything kept; validation messages are not needed here.
			return;
		}
		if ( is_wp_error( $valid ) ) {
			self::$state['errors'] = $valid->get_error_messages();
			return;
		}

		$preview = Orders::preview( $valid );
		if ( is_wp_error( $preview ) ) {
			self::$state['errors'] = $preview->get_error_messages();
			return;
		}
		$sig = self::review_signature( $valid, $preview );

		if ( 'confirm' === $step ) {
			$posted = isset( $_POST['bmlt_lit_review_sig'] ) && is_scalar( $_POST['bmlt_lit_review_sig'] ) ? sanitize_text_field( wp_unslash( $_POST['bmlt_lit_review_sig'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( hash_equals( $sig, $posted ) ) {
				$result = Orders::create( $valid );
				if ( is_wp_error( $result ) ) {
					self::$state['errors'] = $result->get_error_messages();
					return;
				}
				if ( ! $result['duplicate'] ) {
					if ( '' !== $valid['ip_hash'] ) {
						set_transient( $valid['_rl_key'], (int) get_transient( $valid['_rl_key'] ) + 1, $valid['_window'] );
					}
					Email::send_order_notifications( $result['order_id'] );
				}
				self::redirect_to_confirmation( (int) $result['order_id'] );
				return;
			}
			$changed = true;
		}

		self::$state['review'] = array(
			'valid'   => $valid,
			'preview' => $preview,
			'sig'     => $sig,
			'changed' => ! empty( $changed ),
			'hidden'  => self::$state['hidden'],
			'values'  => self::$state['values'],
			'return'  => self::return_url(),
			'area_sig'=> self::sign_areas( $valid['_areas'] ),
		);
	}

	/**
	 * Signature of what the requester reviewed (Area, meeting, lines, prices, availability).
	 *
	 * @param array $valid   Validated submission.
	 * @param array $preview Preview.
	 * @return string
	 */
	private static function review_signature( array $valid, array $preview ) {
		$lines = array();
		foreach ( $preview['items'] as $it ) {
			$lines[] = array( (int) $it->item_id, (int) $it->quantity, (string) $it->unit_price, (int) $it->quantity_special );
		}
		$payload = wp_json_encode( array( (int) $valid['area_id'], (int) $valid['meeting']['id'], $lines, (string) $preview['total'] ) );
		return hash_hmac( 'sha256', 'review:' . $payload, wp_salt( 'nonce' ) );
	}

	/**
	 * Validated return URL for this form.
	 *
	 * @return string
	 */
	private static function return_url() {
		$return = isset( $_POST['bmlt_lit_return'] ) && is_scalar( $_POST['bmlt_lit_return'] ) ? esc_url_raw( wp_unslash( $_POST['bmlt_lit_return'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$return = wp_validate_redirect( $return, home_url( '/' ) );
		return remove_query_arg( array( 'bmlt_lit_order', 'bmlt_lit_key' ), $return );
	}

	/**
	 * Redirect to the confirmation view for an order.
	 *
	 * @param int $order_id Order.
	 */
	private static function redirect_to_confirmation( $order_id ) {
		$order = Orders::get( $order_id );
		if ( ! $order ) {
			self::$state['errors'] = array( __( 'Your order could not be saved. Please try again.', 'bmlt-literature-manager' ) );
			return;
		}
		$url = add_query_arg(
			array(
				'bmlt_lit_order' => rawurlencode( $order->order_number ),
				'bmlt_lit_key'   => rawurlencode( $order->access_key ),
			),
			self::return_url()
		);
		wp_safe_redirect( $url . '#bmlt-lit-confirmation', 303 );
		exit;
	}

	/**
	 * Validate everything server-side. Returns the data needed to price and create the order.
	 *
	 * @return array|\WP_Error
	 */
	private static function validate_submission() {
		$s      = Settings::general();
		$errors = new \WP_Error();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified below before any state change.
		$post = wp_unslash( $_POST );
		// phpcs:enable

		// Keep values for re-rendering (sanitized).
		$values = array(
			'meeting_id'    => isset( $post['bmlt_lit_meeting'] ) ? absint( $post['bmlt_lit_meeting'] ) : 0,
			'contact_name'  => isset( $post['bmlt_lit_name'] ) && is_scalar( $post['bmlt_lit_name'] ) ? Helpers::limit( sanitize_text_field( $post['bmlt_lit_name'] ), 200 ) : '',
			'contact_phone' => isset( $post['bmlt_lit_phone'] ) && is_scalar( $post['bmlt_lit_phone'] ) ? Helpers::limit( sanitize_text_field( $post['bmlt_lit_phone'] ), 50 ) : '',
			'contact_email' => isset( $post['bmlt_lit_email'] ) && is_scalar( $post['bmlt_lit_email'] ) ? Helpers::limit( sanitize_email( $post['bmlt_lit_email'] ), 200 ) : '',
			'notes'         => isset( $post['bmlt_lit_notes'] ) && is_scalar( $post['bmlt_lit_notes'] ) ? Helpers::limit( sanitize_textarea_field( $post['bmlt_lit_notes'] ), 2000 ) : '',
			'qty'           => array(),
		);
		$raw_qty = ( isset( $post['bmlt_lit_qty'] ) && is_array( $post['bmlt_lit_qty'] ) ) ? $post['bmlt_lit_qty'] : array();
		foreach ( $raw_qty as $k => $v ) {
			$iid = absint( $k );
			if ( $iid && is_scalar( $v ) && preg_match( '/^\s*-?\d+\s*$/', (string) $v ) ) {
				$values['qty'][ $iid ] = (int) $v;
			}
		}
		self::$state['values'] = $values;
		self::$state['hidden'] = array(
			'token' => isset( $post['bmlt_lit_token'] ) && is_scalar( $post['bmlt_lit_token'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) $post['bmlt_lit_token'] ) : '',
			'ts'    => isset( $post['bmlt_lit_ts'] ) && is_scalar( $post['bmlt_lit_ts'] ) ? sanitize_text_field( (string) $post['bmlt_lit_ts'] ) : '',
		);

		// CSRF.
		if ( ! isset( $post['bmlt_lit_nonce'] ) || ! is_scalar( $post['bmlt_lit_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( $post['bmlt_lit_nonce'] ), 'bmlt_lit_submit_order' ) ) {
			return new \WP_Error( 'nonce', __( 'Your session expired before the order was sent. Please review the form and submit again.', 'bmlt-literature-manager' ) );
		}

		// Honeypot.
		if ( ! empty( $post['bmlt_lit_website'] ) ) {
			return new \WP_Error( 'spam', __( 'Your order could not be accepted. Please try again.', 'bmlt-literature-manager' ) );
		}

		// Timing check.
		$elapsed = self::verify_time_token( isset( $post['bmlt_lit_ts'] ) && is_scalar( $post['bmlt_lit_ts'] ) ? (string) $post['bmlt_lit_ts'] : '' );
		if ( false === $elapsed ) {
			return new \WP_Error( 'expired', __( 'This form has expired. Please reload the page and try again.', 'bmlt-literature-manager' ) );
		}
		if ( $elapsed < max( 0, (int) $s['min_submit_seconds'] ) ) {
			return new \WP_Error( 'too_fast', __( 'The form was submitted too quickly. Please wait a moment and submit again.', 'bmlt-literature-manager' ) );
		}

		// Rate limiting by hashed IP.
		$ip_hash  = Helpers::client_ip_hash();
		$window   = max( 1, (int) $s['rate_limit_minutes'] ) * MINUTE_IN_SECONDS;
		$limit    = max( 1, (int) $s['rate_limit_count'] );
		$rl_key   = 'bmlt_lit_rl_' . substr( $ip_hash, 0, 40 );
		$attempts = 'bmlt_lit_ra_' . substr( $ip_hash, 0, 40 );
		if ( '' !== $ip_hash ) {
			$tries = (int) get_transient( $attempts );
			set_transient( $attempts, $tries + 1, $window );
			if ( (int) get_transient( $rl_key ) >= $limit || $tries >= max( 20, $limit * 4 ) ) {
				return new \WP_Error( 'rate', __( 'Too many orders have been submitted from your connection recently. Please wait a few minutes and try again.', 'bmlt-literature-manager' ) );
			}
		}

		/**
		 * Optional CAPTCHA integration. Return true to allow, or a WP_Error / false to block.
		 *
		 * @param bool|\WP_Error $valid Current result.
		 * @param array          $post  Unslashed POST data.
		 */
		$captcha = apply_filters( 'bmlt_lit_validate_captcha', true, $post );
		if ( is_wp_error( $captcha ) ) {
			return $captcha;
		}
		if ( true !== $captcha ) {
			return new \WP_Error( 'captcha', __( 'Please complete the verification challenge.', 'bmlt-literature-manager' ) );
		}

		// Areas bound to this form instance.
		$form_areas = Shortcodes::parse_ids( isset( $post['bmlt_lit_areas'] ) && is_scalar( $post['bmlt_lit_areas'] ) ? (string) $post['bmlt_lit_areas'] : '' );
		$sig        = isset( $post['bmlt_lit_areas_sig'] ) && is_scalar( $post['bmlt_lit_areas_sig'] ) ? (string) $post['bmlt_lit_areas_sig'] : '';
		if ( empty( $form_areas ) || ! hash_equals( self::sign_areas( $form_areas ), $sig ) ) {
			return new \WP_Error( 'areas', __( 'The order form is invalid. Please reload the page.', 'bmlt-literature-manager' ) );
		}
		$form_areas = array_values( array_intersect( $form_areas, Settings::selected_areas() ) );

		// Meeting must be a known BMLT meeting in one of this form's managed Areas.
		$meeting = $values['meeting_id'] ? Bmlt_Client::get_meeting( $values['meeting_id'] ) : null;
		if ( ! $meeting ) {
			$errors->add( 'meeting', __( 'Please select your meeting from the list.', 'bmlt-literature-manager' ) );
		} elseif ( ! in_array( (int) $meeting['area_id'], $form_areas, true ) ) {
			$errors->add( 'meeting', __( 'The selected meeting is not served by this order form.', 'bmlt-literature-manager' ) );
			$meeting = null;
		}

		// Contact details.
		if ( $s['require_name'] && '' === trim( $values['contact_name'] ) ) {
			$errors->add( 'name', __( 'Please enter your name.', 'bmlt-literature-manager' ) );
		}
		$digits = preg_replace( '/\D/', '', $values['contact_phone'] );
		if ( $s['require_phone'] && '' === trim( $values['contact_phone'] ) ) {
			$errors->add( 'phone', __( 'Please enter a phone number.', 'bmlt-literature-manager' ) );
		} elseif ( '' !== trim( $values['contact_phone'] ) && ( strlen( $digits ) < 7 || strlen( $digits ) > 20 || ! preg_match( '/^[0-9+().\-\s xXext]+$/', $values['contact_phone'] ) ) ) {
			$errors->add( 'phone', __( 'Please enter a valid phone number.', 'bmlt-literature-manager' ) );
		}
		$raw_email = isset( $post['bmlt_lit_email'] ) && is_scalar( $post['bmlt_lit_email'] ) ? trim( (string) $post['bmlt_lit_email'] ) : '';
		if ( $s['require_email'] && '' === $raw_email ) {
			$errors->add( 'email', __( 'Please enter your email address.', 'bmlt-literature-manager' ) );
		} elseif ( '' !== $raw_email && ! is_email( $values['contact_email'] ) ) {
			$errors->add( 'email', __( 'Please enter a valid email address.', 'bmlt-literature-manager' ) );
		}

		// Quantities.
		$max   = max( 1, (int) $s['max_qty_per_line'] );
		$lines = array();
		foreach ( $values['qty'] as $iid => $q ) {
			if ( $q < 0 ) {
				$errors->add( 'qty', __( 'Quantities cannot be negative.', 'bmlt-literature-manager' ) );
				break;
			}
			if ( $q > $max ) {
				/* translators: %d: maximum quantity. */
				$errors->add( 'qty', sprintf( __( 'The maximum quantity per item is %d.', 'bmlt-literature-manager' ), $max ) );
				break;
			}
			if ( $q > 0 ) {
				$lines[ $iid ] = $q;
			}
		}
		if ( count( $lines ) > 300 ) {
			$errors->add( 'qty', __( 'Too many different items in one order.', 'bmlt-literature-manager' ) );
		}
		if ( empty( $lines ) && ! $errors->get_error_message( 'qty' ) ) {
			$errors->add( 'qty', __( 'Please enter a quantity for at least one literature item.', 'bmlt-literature-manager' ) );
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}

		$token = isset( $post['bmlt_lit_token'] ) && is_scalar( $post['bmlt_lit_token'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) $post['bmlt_lit_token'] ) : '';
		if ( strlen( $token ) < 20 ) {
			return new \WP_Error( 'token', __( 'The order form is invalid. Please reload the page.', 'bmlt-literature-manager' ) );
		}

		return array(
			'area_id'         => (int) $meeting['area_id'],
			'meeting'         => $meeting,
			'contact_name'    => $values['contact_name'],
			'contact_phone'   => $values['contact_phone'],
			'contact_email'   => $values['contact_email'],
			'notes'           => $values['notes'],
			'lines'           => $lines,
			'submission_hash' => self::submission_hash( $token, (int) $meeting['id'], $values, $lines ),
			'ip_hash'         => $ip_hash,
			'_rl_key'         => $rl_key,
			'_window'         => $window,
			'_token'          => $token,
			'_areas'          => $form_areas,
		);
	}

	/**
	 * Duplicate-submission key: the form token together with what was ordered and by whom. Pressing Submit twice
	 * gives the same key (one order); two people who were served the same cached form do not.
	 *
	 * @param string $token   Form token.
	 * @param int    $meeting Meeting ID.
	 * @param array  $values  Sanitized contact fields.
	 * @param array  $lines   item_id => quantity.
	 * @return string
	 */
	private static function submission_hash( $token, $meeting, array $values, array $lines ) {
		ksort( $lines );
		$content = array( $meeting, $values['contact_name'], $values['contact_phone'], strtolower( $values['contact_email'] ), $values['notes'], $lines );
		return hash( 'sha256', 'order:' . $token . ':' . wp_json_encode( $content ) );
	}

	/* ------------------------------------------------------------------
	 * Protected shortcodes.
	 * ---------------------------------------------------------------- */

	/**
	 * Gate for protected shortcodes.
	 *
	 * @param string[] $caps Any of these capabilities grants access.
	 * @return string Empty string when allowed, otherwise HTML message.
	 */
	private static function gate( array $caps ) {
		if ( ! is_user_logged_in() ) {
			return '<div class="bmlt-lit bmlt-lit-gate"><p>' . esc_html__( 'Please log in to access the literature tools.', 'bmlt-literature-manager' ) . '</p><p><a class="bmlt-lit-button" href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Log in', 'bmlt-literature-manager' ) . '</a></p></div>';
		}
		foreach ( $caps as $cap ) {
			if ( current_user_can( $cap ) ) {
				if ( empty( Permissions::allowed_areas() ) ) {
					return '<div class="bmlt-lit bmlt-lit-gate"><p>' . esc_html__( 'No Areas are assigned to your account yet. Please contact the site administrator.', 'bmlt-literature-manager' ) . '</p></div>';
				}
				return '';
			}
		}
		return '<div class="bmlt-lit bmlt-lit-gate"><p>' . esc_html__( 'You do not have permission to access the literature tools.', 'bmlt-literature-manager' ) . '</p></div>';
	}

	/**
	 * Config shared by worker/order scripts.
	 *
	 * @return array
	 */
	private static function worker_config() {
		$s = Settings::general();
		return array(
			'restUrl'  => esc_url_raw( rest_url( 'bmlt-lit/v1/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'areas'    => Settings::area_options( Permissions::allowed_areas() ),
			'shared'   => Permissions::can_area( 0 ),
			'caps'     => array(
				'viewOrders'   => current_user_can( Permissions::VIEW_ORDERS ),
				'manageOrders' => current_user_can( Permissions::MANAGE_ORDERS ),
				'inventory'    => current_user_can( Permissions::MANAGE_INVENTORY ),
				'catalog'      => current_user_can( Permissions::MANAGE_CATALOG ),
			),
			'statuses' => Helpers::order_statuses(),
			'perPage'  => 20,
			'pager'    => self::pager_strings(),
			'stock'    => Helpers::stock_statuses(),
			'currency' => array(
				'symbol'   => (string) $s['currency_symbol'],
				'position' => (string) $s['currency_position'],
			),
			'strings'  => array(
				'loading'        => __( 'Loading…', 'bmlt-literature-manager' ),
				'error'          => __( 'Something went wrong. Please try again.', 'bmlt-literature-manager' ),
				'noOrders'       => __( 'No orders found for this period.', 'bmlt-literature-manager' ),
				'loadPrevious'   => __( 'Load Previous 45 Days', 'bmlt-literature-manager' ),
				'loadMore'       => __( 'Load more', 'bmlt-literature-manager' ),
				'noOlder'        => __( 'There are no older orders.', 'bmlt-literature-manager' ),
				'special'        => __( 'Special order', 'bmlt-literature-manager' ),
				'specialBanner'  => __( 'THIS ORDER CONTAINS SPECIAL ORDER ITEMS', 'bmlt-literature-manager' ),
				'specialNote'    => (string) $s['special_order_message'],
				'yes'            => __( 'Yes', 'bmlt-literature-manager' ),
				'no'             => __( 'No', 'bmlt-literature-manager' ),
				'close'          => __( 'Close', 'bmlt-literature-manager' ),
				'save'           => __( 'Save', 'bmlt-literature-manager' ),
				'saved'          => __( 'Saved.', 'bmlt-literature-manager' ),
				'edit'           => __( 'Edit', 'bmlt-literature-manager' ),
				'delete'         => __( 'Delete', 'bmlt-literature-manager' ),
				'activate'       => __( 'Activate', 'bmlt-literature-manager' ),
				'deactivate'     => __( 'Deactivate', 'bmlt-literature-manager' ),
				'confirmDelete'  => __( 'Permanently delete this item? This cannot be undone.', 'bmlt-literature-manager' ),
				'confirmCancel'  => __( 'Cancel this order? Reserved inventory will be released. This cannot be undone.', 'bmlt-literature-manager' ),
				'confirmFulfill' => __( 'Mark this order fulfilled? In-stock quantities will be permanently deducted from inventory.', 'bmlt-literature-manager' ),
				'markReviewed'   => __( 'Mark Reviewed', 'bmlt-literature-manager' ),
				'markWaiting'    => __( 'Waiting for Special Order', 'bmlt-literature-manager' ),
				'markReady'      => __( 'Mark Ready', 'bmlt-literature-manager' ),
				'markPartial'    => __( 'Partially Fulfilled', 'bmlt-literature-manager' ),
				'markFulfilled'  => __( 'Mark Fulfilled', 'bmlt-literature-manager' ),
				'cancelOrder'    => __( 'Cancel Order', 'bmlt-literature-manager' ),
				'resend'         => __( 'Resend Notification', 'bmlt-literature-manager' ),
				'print'          => __( 'Print Order', 'bmlt-literature-manager' ),
				'emailRequester' => __( 'Email Requester', 'bmlt-literature-manager' ),
				'send'           => __( 'Send', 'bmlt-literature-manager' ),
				'addNote'        => __( 'Add Note', 'bmlt-literature-manager' ),
				'notify'         => __( 'Email the requester about this status', 'bmlt-literature-manager' ),
				'statusNote'     => __( 'Optional note', 'bmlt-literature-manager' ),
				'history'        => __( 'History', 'bmlt-literature-manager' ),
				'items'          => __( 'Items', 'bmlt-literature-manager' ),
				'sku'            => __( 'SKU', 'bmlt-literature-manager' ),
				'literature'     => __( 'Literature', 'bmlt-literature-manager' ),
				'qty'            => __( 'Qty', 'bmlt-literature-manager' ),
				'unitPrice'      => __( 'Unit Price', 'bmlt-literature-manager' ),
				'lineTotal'      => __( 'Line Total', 'bmlt-literature-manager' ),
				'stockStatus'    => __( 'Stock', 'bmlt-literature-manager' ),
				'subtotal'       => __( 'Subtotal', 'bmlt-literature-manager' ),
				'total'          => __( 'Total', 'bmlt-literature-manager' ),
				'meeting'        => __( 'Meeting', 'bmlt-literature-manager' ),
				'area'           => __( 'Area', 'bmlt-literature-manager' ),
				'contact'        => __( 'Contact', 'bmlt-literature-manager' ),
				'phone'          => __( 'Phone', 'bmlt-literature-manager' ),
				'email'          => __( 'Email', 'bmlt-literature-manager' ),
				'date'           => __( 'Date', 'bmlt-literature-manager' ),
				'status'         => __( 'Status', 'bmlt-literature-manager' ),
				'notes'          => __( 'Notes', 'bmlt-literature-manager' ),
				'orderNumber'    => __( 'Order #', 'bmlt-literature-manager' ),
				'fromStock'      => __( 'from stock', 'bmlt-literature-manager' ),
				'onHand'         => __( 'On hand', 'bmlt-literature-manager' ),
				'reserved'       => __( 'Reserved', 'bmlt-literature-manager' ),
				'available'      => __( 'Available', 'bmlt-literature-manager' ),
				'lowThreshold'   => __( 'Low-stock threshold', 'bmlt-literature-manager' ),
				'specialForArea' => __( 'Special order for this Area', 'bmlt-literature-manager' ),
				'reason'         => __( 'Reason', 'bmlt-literature-manager' ),
				'price'          => __( 'Price', 'bmlt-literature-manager' ),
				'category'       => __( 'Category', 'bmlt-literature-manager' ),
				'active'         => __( 'Active', 'bmlt-literature-manager' ),
				'inactive'       => __( 'Inactive', 'bmlt-literature-manager' ),
				'sort'           => __( 'Sort', 'bmlt-literature-manager' ),
				'catalog'        => __( 'Catalog', 'bmlt-literature-manager' ),
				'actions'        => __( 'Actions', 'bmlt-literature-manager' ),
				'increase'       => __( 'Increase', 'bmlt-literature-manager' ),
				'decrease'       => __( 'Decrease', 'bmlt-literature-manager' ),
				'noItems'        => __( 'No literature items found.', 'bmlt-literature-manager' ),
				'chooseArea'     => __( 'Choose an Area to see its inventory.', 'bmlt-literature-manager' ),
				'sharedCatalog'  => __( 'Shared (all Areas)', 'bmlt-literature-manager' ),
				'addItem'        => __( 'Add Item', 'bmlt-literature-manager' ),
				'editItem'       => __( 'Edit Item', 'bmlt-literature-manager' ),
				'openAdmin'      => __( 'Open in admin', 'bmlt-literature-manager' ),
				'fulfilledOn'    => __( 'Fulfilled', 'bmlt-literature-manager' ),
				'finalNote'      => __( 'This order is final and can no longer change status.', 'bmlt-literature-manager' ),
				'period'         => __( 'Showing orders from', 'bmlt-literature-manager' ),
				'orders'         => __( 'orders', 'bmlt-literature-manager' ),
			),
		);
	}

	/**
	 * [bmlt_literature_manager] — literature-worker workspace.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode_manager( $atts ) {
		$atts = shortcode_atts(
			array(
				'default_tab' => 'orders',
				'area'        => '',
				'per_page'    => '20',
			),
			is_array( $atts ) ? $atts : array(),
			'bmlt_literature_manager'
		);
		self::ensure_registered();
		wp_enqueue_style( 'bmlt-lit-public' );

		$gate = self::gate( array( Permissions::VIEW_ORDERS, Permissions::MANAGE_INVENTORY, Permissions::MANAGE_CATALOG ) );
		if ( '' !== $gate ) {
			return $gate;
		}

		$config               = self::worker_config();
		$tab                  = sanitize_key( $atts['default_tab'] );
		$config['defaultTab'] = in_array( $tab, array( 'orders', 'inventory', 'catalog' ), true ) ? $tab : 'orders';
		$area                 = absint( $atts['area'] );
		$config['defaultArea'] = isset( $config['areas'][ $area ] ) ? $area : 0;
		$config['perPage']     = self::per_page_attr( $atts['per_page'] );

		wp_enqueue_script( 'bmlt-lit-worker' );
		wp_add_inline_script( 'bmlt-lit-worker', 'window.bmltLitWorker = ' . wp_json_encode( $config ) . ';', 'before' );

		return self::render_view(
			'manager',
			array(
				'config'     => $config,
				'categories' => Catalog::categories(),
			)
		);
	}

	/**
	 * [bmlt_literature_orders] — order history.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode_orders( $atts ) {
		$atts = shortcode_atts(
			array(
				'area' => '',
			),
			is_array( $atts ) ? $atts : array(),
			'bmlt_literature_orders'
		);
		self::ensure_registered();
		wp_enqueue_style( 'bmlt-lit-public' );

		$gate = self::gate( array( Permissions::VIEW_ORDERS ) );
		if ( '' !== $gate ) {
			return $gate;
		}
		$config                = self::worker_config();
		$config['defaultTab']  = 'orders';
		$area                  = absint( $atts['area'] );
		$config['defaultArea'] = isset( $config['areas'][ $area ] ) ? $area : 0;
		$config['caps']['inventory'] = false;
		$config['caps']['catalog']   = false;

		wp_enqueue_script( 'bmlt-lit-worker' );
		wp_add_inline_script( 'bmlt-lit-worker', 'window.bmltLitWorker = ' . wp_json_encode( $config ) . ';', 'before' );

		return self::render_view( 'orders', array( 'config' => $config ) );
	}

	/* ------------------------------------------------------------------
	 * Print view.
	 * ---------------------------------------------------------------- */

	/**
	 * Printer-friendly order (admin-post.php?action=bmlt_lit_print_order).
	 */
	public static function print_order() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $order_id || ! isset( $_GET['_wpnonce'] ) || ! is_scalar( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'bmlt_lit_print_' . $order_id ) ) {
			wp_die( esc_html__( 'This print link has expired. Please reopen the order and try again.', 'bmlt-literature-manager' ), '', array( 'response' => 403 ) );
		}
		if ( ! current_user_can( Permissions::VIEW_ORDERS ) ) {
			wp_die( esc_html__( 'You do not have permission to view this order.', 'bmlt-literature-manager' ), '', array( 'response' => 403 ) );
		}
		$order = Orders::get( $order_id );
		if ( ! $order || ! Permissions::can_area( (int) $order->area_id ) ) {
			wp_die( esc_html__( 'Order not found.', 'bmlt-literature-manager' ), '', array( 'response' => 404 ) );
		}
		$items = Orders::get_items( $order->id );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		include BMLT_LIT_DIR . 'public/views/print-order.php';
		exit;
	}

	/**
	 * Render a view file into a string.
	 *
	 * @param string $view View name.
	 * @param array  $vars Variables.
	 * @return string
	 */
	public static function render_view( $view, array $vars ) {
		$file = BMLT_LIT_DIR . 'public/views/' . sanitize_file_name( $view ) . '.php';
		if ( ! is_readable( $file ) ) {
			return '';
		}
		ob_start();
		extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		include $file;
		return (string) ob_get_clean();
	}
}
