<?php
/**
 * REST API.
 *
 * @package BMLT_Lit
 * @version 6.3.0
 */

namespace BMLT_Lit;

defined( 'ABSPATH' ) || exit;

/**
 * REST routes under /wp-json/bmlt-lit/v1/.
 *
 * Every staff endpoint requires an authenticated user (cookie + X-WP-Nonce) with the relevant
 * capability, and every object is additionally checked against the user's permitted Areas (IDOR protection).
 */
class Rest {

	const NS = 'bmlt-lit/v1';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Build a capability permission callback.
	 *
	 * @param string $cap Capability.
	 * @return callable
	 */
	private static function cap( $cap ) {
		return static function () use ( $cap ) {
			if ( ! is_user_logged_in() ) {
				return new \WP_Error( 'rest_forbidden', __( 'You must be logged in.', 'bmlt-literature-manager' ), array( 'status' => 401 ) );
			}
			if ( ! current_user_can( $cap ) ) {
				return new \WP_Error( 'rest_forbidden', __( 'You do not have permission to do that.', 'bmlt-literature-manager' ), array( 'status' => 403 ) );
			}
			return true;
		};
	}

	/**
	 * Register routes.
	 */
	public static function routes() {
		$id_arg = array(
			'id' => array(
				'validate_callback' => static function ( $v ) {
					return is_numeric( $v ) && (int) $v > 0;
				},
				'sanitize_callback' => 'absint',
			),
		);

		register_rest_route(
			self::NS,
			'/orders',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_orders' ),
				'permission_callback' => self::cap( Permissions::VIEW_ORDERS ),
			)
		);
		register_rest_route(
			self::NS,
			'/orders/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_order' ),
				'permission_callback' => self::cap( Permissions::VIEW_ORDERS ),
				'args'                => $id_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/orders/(?P<id>\d+)/status',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'order_status' ),
				'permission_callback' => self::cap( Permissions::MANAGE_ORDERS ),
				'args'                => $id_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/orders/(?P<id>\d+)/notes',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'order_note' ),
				'permission_callback' => self::cap( Permissions::MANAGE_ORDERS ),
				'args'                => $id_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/orders/(?P<id>\d+)/resend',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'order_resend' ),
				'permission_callback' => self::cap( Permissions::MANAGE_ORDERS ),
				'args'                => $id_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/orders/(?P<id>\d+)/email',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'order_email' ),
				'permission_callback' => self::cap( Permissions::MANAGE_ORDERS ),
				'args'                => $id_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/inventory',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_inventory' ),
					'permission_callback' => self::cap( Permissions::MANAGE_INVENTORY ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'adjust_inventory' ),
					'permission_callback' => self::cap( Permissions::MANAGE_INVENTORY ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/items',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_items' ),
					'permission_callback' => self::cap( Permissions::MANAGE_CATALOG ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_item' ),
					'permission_callback' => self::cap( Permissions::MANAGE_CATALOG ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/items/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'update_item' ),
					'permission_callback' => self::cap( Permissions::MANAGE_CATALOG ),
					'args'                => $id_arg,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_item' ),
					'permission_callback' => self::cap( Permissions::MANAGE_CATALOG ),
					'args'                => $id_arg,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/items/(?P<id>\d+)/active',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'item_active' ),
				'permission_callback' => self::cap( Permissions::MANAGE_CATALOG ),
				'args'                => $id_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/admin/bmlt-test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'bmlt_test' ),
				'permission_callback' => self::cap( Permissions::MANAGE_SETTINGS ),
			)
		);
		// Public, non-personal stock status for one managed Area.
		register_rest_route(
			self::NS,
			'/public/stock',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'public_stock' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'area' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Standard error response.
	 *
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @return \WP_Error
	 */
	private static function error( $message, $status = 400 ) {
		return new \WP_Error( 'bmlt_lit_error', $message, array( 'status' => $status ) );
	}

	/**
	 * Load an order the current user may access, or return an error.
	 *
	 * @param int $id Order ID.
	 * @return object|\WP_Error
	 */
	private static function accessible_order( $id ) {
		$order = Orders::get( $id );
		if ( ! $order || ! Permissions::can_area( (int) $order->area_id ) ) {
			// Same response for missing and forbidden to avoid leaking existence.
			return self::error( __( 'Order not found.', 'bmlt-literature-manager' ), 404 );
		}
		return $order;
	}

	/**
	 * GET /orders — 45-day periods ("Load previous 45 days") or explicit date range with paging.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public static function list_orders( \WP_REST_Request $req ) {
		$allowed = Permissions::allowed_areas();
		$days    = 45;
		$page    = max( 1, absint( $req->get_param( 'page' ) ) );
		$per     = 200;

		$args = array(
			'area_ids'     => $allowed,
			'area_id'      => absint( $req->get_param( 'area' ) ),
			'status'       => sanitize_key( (string) $req->get_param( 'status' ) ),
			'special'      => in_array( (string) $req->get_param( 'special' ), array( '0', '1' ), true ) ? (string) $req->get_param( 'special' ) : '',
			'search'       => Helpers::limit( sanitize_text_field( (string) $req->get_param( 'search' ) ), 100 ),
			'contact'      => Helpers::limit( sanitize_text_field( (string) $req->get_param( 'contact' ) ), 100 ),
			'email'        => Helpers::limit( sanitize_text_field( (string) $req->get_param( 'email' ) ), 100 ),
			'order_number' => Helpers::limit( sanitize_text_field( (string) $req->get_param( 'order_number' ) ), 40 ),
			'meeting'      => Helpers::limit( sanitize_text_field( (string) $req->get_param( 'meeting' ) ), 100 ),
			'sort'         => sanitize_key( (string) $req->get_param( 'sort' ) ),
			'limit'        => $per,
			'offset'       => ( $page - 1 ) * $per,
		);

		$from = Helpers::local_date_to_gmt( (string) $req->get_param( 'date_from' ) );
		$to   = Helpers::local_date_to_gmt( (string) $req->get_param( 'date_to' ), true );
		$mode = ( '' !== $from || '' !== $to ) ? 'range' : 'period';

		if ( 'range' === $mode ) {
			$args['date_from_gmt'] = $from;
			$args['date_to_gmt']   = $to;
			$result                = Orders::query( $args );
			$data                  = array(
				'mode'      => 'range',
				'orders'    => array_map( array( Orders::class, 'summary' ), $result['orders'] ),
				'total'     => $result['total'],
				'page'      => $page,
				'has_more'  => $page * $per < $result['total'],
				'has_older' => false,
			);
			return rest_ensure_response( $data );
		}

		$before = absint( $req->get_param( 'before' ) );
		$end    = $before ? min( $before, time() + DAY_IN_SECONDS ) : time() + 60;
		$start  = $end - $days * DAY_IN_SECONDS;

		$args['date_from_gmt'] = gmdate( 'Y-m-d H:i:s', $start );
		$args['date_to_gmt']   = gmdate( 'Y-m-d H:i:s', $end );
		$result                = Orders::query( $args );

		// Is there anything older than this period (for the "Load Previous 45 Days" button)?
		$older_args                  = $args;
		$older_args['date_from_gmt'] = '';
		$older_args['date_to_gmt']   = gmdate( 'Y-m-d H:i:s', $start );
		$older_args['limit']         = 1;
		$older_args['offset']        = 0;
		$older_args['count']         = false;
		$older                       = Orders::query( $older_args );

		return rest_ensure_response(
			array(
				'mode'         => 'period',
				'orders'       => array_map( array( Orders::class, 'summary' ), $result['orders'] ),
				'total'        => $result['total'],
				'page'         => $page,
				'has_more'     => $page * $per < $result['total'],
				'period_start' => $start,
				'period_end'   => $end,
				'period_label' => sprintf(
					/* translators: 1: start date, 2: end date. */
					__( '%1$s – %2$s', 'bmlt-literature-manager' ),
					wp_date( get_option( 'date_format' ), $start ),
					wp_date( get_option( 'date_format' ), $end )
				),
				'has_older'    => ! empty( $older['orders'] ),
			)
		);
	}

	/**
	 * GET /orders/{id}.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_order( \WP_REST_Request $req ) {
		$order = self::accessible_order( (int) $req['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$data              = Orders::detail( $order );
		$data['print_url'] = wp_nonce_url( admin_url( 'admin-post.php?action=bmlt_lit_print_order&order_id=' . (int) $order->id ), 'bmlt_lit_print_' . (int) $order->id );
		$data['admin_url'] = current_user_can( Permissions::VIEW_ORDERS ) ? admin_url( 'admin.php?page=bmlt-lit-orders&order_id=' . (int) $order->id ) : '';
		$data['can_manage'] = current_user_can( Permissions::MANAGE_ORDERS );
		return rest_ensure_response( $data );
	}

	/**
	 * POST /orders/{id}/status.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function order_status( \WP_REST_Request $req ) {
		$order = self::accessible_order( (int) $req['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$status = sanitize_key( (string) $req->get_param( 'status' ) );
		$note   = Helpers::limit( sanitize_textarea_field( (string) $req->get_param( 'note' ) ), 2000 );
		$res    = Orders::update_status( (int) $order->id, $status, $note );
		if ( is_wp_error( $res ) ) {
			return self::error( $res->get_error_message() );
		}
		$messages = array( __( 'Order status updated.', 'bmlt-literature-manager' ) );
		if ( rest_sanitize_boolean( $req->get_param( 'notify' ) ) ) {
			$tpl = 'ready' === $status ? 'ready' : ( 'waiting_special' === $status ? 'special' : '' );
			if ( $tpl ) {
				$sent       = Email::send_to_requester( (int) $order->id, $tpl, $note );
				$messages[] = is_wp_error( $sent ) ? $sent->get_error_message() : __( 'Requester notified by email.', 'bmlt-literature-manager' );
			}
		}
		$fresh = Orders::get( (int) $order->id );
		return rest_ensure_response(
			array(
				'message' => implode( ' ', $messages ),
				'order'   => Orders::detail( $fresh ),
			)
		);
	}

	/**
	 * POST /orders/{id}/notes.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function order_note( \WP_REST_Request $req ) {
		$order = self::accessible_order( (int) $req['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$note = Helpers::limit( sanitize_textarea_field( (string) $req->get_param( 'note' ) ), 5000 );
		if ( '' === trim( $note ) ) {
			return self::error( __( 'Please enter a note.', 'bmlt-literature-manager' ) );
		}
		Orders::add_note( (int) $order->id, 'note', $note );
		return rest_ensure_response(
			array(
				'message' => __( 'Note added.', 'bmlt-literature-manager' ),
				'order'   => Orders::detail( $order ),
			)
		);
	}

	/**
	 * POST /orders/{id}/resend.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function order_resend( \WP_REST_Request $req ) {
		$order = self::accessible_order( (int) $req['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$res = Email::resend_worker_notification( (int) $order->id );
		if ( is_wp_error( $res ) ) {
			return self::error( $res->get_error_message(), 500 );
		}
		return rest_ensure_response(
			array(
				'message' => __( 'Notification resent.', 'bmlt-literature-manager' ),
				'order'   => Orders::detail( $order ),
			)
		);
	}

	/**
	 * POST /orders/{id}/email.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function order_email( \WP_REST_Request $req ) {
		$order = self::accessible_order( (int) $req['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$message = Helpers::limit( sanitize_textarea_field( (string) $req->get_param( 'message' ) ), 5000 );
		if ( '' === trim( $message ) ) {
			return self::error( __( 'Please write a message to send.', 'bmlt-literature-manager' ) );
		}
		$res = Email::send_to_requester( (int) $order->id, 'message', $message );
		if ( is_wp_error( $res ) ) {
			return self::error( $res->get_error_message(), 500 );
		}
		return rest_ensure_response(
			array(
				'message' => __( 'Email sent to requester.', 'bmlt-literature-manager' ),
				'order'   => Orders::detail( $order ),
			)
		);
	}

	/**
	 * GET /inventory?area=.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_inventory( \WP_REST_Request $req ) {
		$area = absint( $req->get_param( 'area' ) );
		if ( ! $area || ! Permissions::can_area( $area ) ) {
			return self::error( __( 'You do not have access to that Area.', 'bmlt-literature-manager' ), 403 );
		}
		$rows = Inventory::area_inventory(
			$area,
			array(
				'search'       => Helpers::limit( sanitize_text_field( (string) $req->get_param( 'search' ) ), 100 ),
				'category'     => Helpers::limit( sanitize_text_field( (string) $req->get_param( 'category' ) ), 100 ),
				'special_last' => true,
			)
		);
		return rest_ensure_response(
			array(
				'area_id'   => $area,
				'area_name' => Settings::area_name( $area ),
				'tracked'   => Settings::area_tracks_inventory( $area ),
				'rows'      => $rows,
			)
		);
	}

	/**
	 * POST /inventory — adjust one item for one Area.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function adjust_inventory( \WP_REST_Request $req ) {
		$area = absint( $req->get_param( 'area' ) );
		$item = absint( $req->get_param( 'item_id' ) );
		if ( ! $area || ! Permissions::can_area( $area ) ) {
			return self::error( __( 'You do not have access to that Area.', 'bmlt-literature-manager' ), 403 );
		}
		$int_or_null = static function ( $v ) {
			if ( null === $v || '' === $v ) {
				return null;
			}
			return is_numeric( $v ) ? (int) $v : null;
		};
		$changes = array(
			'set'                 => $int_or_null( $req->get_param( 'set' ) ),
			'delta'               => $int_or_null( $req->get_param( 'delta' ) ),
			'low_stock_threshold' => $int_or_null( $req->get_param( 'low_stock_threshold' ) ),
			'reorder_quantity'    => $int_or_null( $req->get_param( 'reorder_quantity' ) ),
		);
		if ( null !== $req->get_param( 'notes' ) ) {
			$changes['notes'] = (string) $req->get_param( 'notes' );
		}
		if ( null !== $req->get_param( 'special' ) ) {
			$changes['special'] = rest_sanitize_boolean( $req->get_param( 'special' ) );
		}
		$reason = Helpers::limit( sanitize_text_field( (string) $req->get_param( 'reason' ) ), 500 );
		$res    = Inventory::adjust( $area, $item, $changes, $reason );
		if ( is_wp_error( $res ) ) {
			return self::error( $res->get_error_message() );
		}
		return rest_ensure_response(
			array(
				'message' => $res['changed'] ? __( 'Inventory saved.', 'bmlt-literature-manager' ) : __( 'No changes.', 'bmlt-literature-manager' ),
				'row'     => $res,
			)
		);
	}

	/**
	 * GET /items.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public static function list_items( \WP_REST_Request $req ) {
		$allowed = Permissions::allowed_areas();
		$catalog = (string) $req->get_param( 'catalog' );
		$args    = array(
			'search'   => Helpers::limit( sanitize_text_field( (string) $req->get_param( 'search' ) ), 100 ),
			'category' => Helpers::limit( sanitize_text_field( (string) $req->get_param( 'category' ) ), 100 ),
			'orderby'  => sanitize_key( (string) $req->get_param( 'orderby' ) ),
		);
		if ( '' !== $catalog && ctype_digit( $catalog ) ) {
			$cat = absint( $catalog );
			if ( ! Permissions::can_area( $cat ) && 0 !== $cat ) {
				return rest_ensure_response( array( 'items' => array() ) );
			}
			$args['area_id']        = $cat;
			$args['include_shared'] = false;
		} else {
			$args['area_ids'] = $allowed;
		}
		$r     = Catalog::query( $args );
		$items = array();
		$used  = Catalog::referenced_ids( wp_list_pluck( $r['items'], 'id' ) );
		foreach ( $r['items'] as $it ) {
			$row             = Catalog::to_array( $it );
			$row['can_edit'] = Permissions::can_area( (int) $it->area_id );
			$row['can_delete'] = $row['can_edit'] && ! in_array( (int) $it->id, $used, true );
			$items[]         = $row;
		}
		return rest_ensure_response(
			array(
				'items'      => $items,
				'categories' => array_values( Catalog::categories() ),
			)
		);
	}

	/**
	 * Whitelisted item fields from a request.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return array
	 */
	private static function item_input( \WP_REST_Request $req ) {
		$fields = array( 'area_id', 'sku', 'name', 'short_description', 'long_description', 'category', 'price', 'is_active', 'sort_order', 'image_url', 'reference_url', 'naws_id' );
		$input  = array();
		foreach ( $fields as $f ) {
			$v = $req->get_param( $f );
			if ( null !== $v && is_scalar( $v ) ) {
				$input[ $f ] = $v;
			}
		}
		return $input;
	}

	/**
	 * POST /items.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create_item( \WP_REST_Request $req ) {
		$input = self::item_input( $req );
		$area  = isset( $input['area_id'] ) ? absint( $input['area_id'] ) : 0;
		if ( ! Permissions::can_area( $area ) ) {
			return self::error( __( 'You may only add items to catalogs for your own Areas.', 'bmlt-literature-manager' ), 403 );
		}
		$id = Catalog::create( $input );
		if ( is_wp_error( $id ) ) {
			return self::error( implode( ' ', $id->get_error_messages() ) );
		}
		return rest_ensure_response(
			array(
				'message' => __( 'Item added.', 'bmlt-literature-manager' ),
				'item'    => Catalog::to_array( Catalog::get( $id ) ),
			)
		);
	}

	/**
	 * POST /items/{id}.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_item( \WP_REST_Request $req ) {
		$item = Catalog::get( (int) $req['id'] );
		if ( ! $item || ! Permissions::can_area( (int) $item->area_id ) ) {
			return self::error( __( 'Item not found.', 'bmlt-literature-manager' ), 404 );
		}
		$input = self::item_input( $req );
		if ( isset( $input['area_id'] ) && ! Permissions::can_area( absint( $input['area_id'] ) ) ) {
			return self::error( __( 'You may only move items into catalogs for your own Areas.', 'bmlt-literature-manager' ), 403 );
		}
		$res = Catalog::update( (int) $item->id, $input );
		if ( is_wp_error( $res ) ) {
			return self::error( implode( ' ', $res->get_error_messages() ) );
		}
		return rest_ensure_response(
			array(
				'message' => __( 'Item saved.', 'bmlt-literature-manager' ),
				'item'    => Catalog::to_array( Catalog::get( (int) $item->id ) ),
			)
		);
	}

	/**
	 * POST /items/{id}/active.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function item_active( \WP_REST_Request $req ) {
		$item = Catalog::get( (int) $req['id'] );
		if ( ! $item || ! Permissions::can_area( (int) $item->area_id ) ) {
			return self::error( __( 'Item not found.', 'bmlt-literature-manager' ), 404 );
		}
		$active = rest_sanitize_boolean( $req->get_param( 'active' ) );
		Catalog::set_active( (int) $item->id, $active );
		return rest_ensure_response(
			array(
				'message' => $active ? __( 'Item activated.', 'bmlt-literature-manager' ) : __( 'Item deactivated.', 'bmlt-literature-manager' ),
				'item'    => Catalog::to_array( Catalog::get( (int) $item->id ) ),
			)
		);
	}

	/**
	 * DELETE /items/{id}.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function delete_item( \WP_REST_Request $req ) {
		$item = Catalog::get( (int) $req['id'] );
		if ( ! $item || ! Permissions::can_area( (int) $item->area_id ) ) {
			return self::error( __( 'Item not found.', 'bmlt-literature-manager' ), 404 );
		}
		$res = Catalog::delete( (int) $item->id );
		if ( is_wp_error( $res ) ) {
			return self::error( $res->get_error_message(), 409 );
		}
		return rest_ensure_response( array( 'message' => __( 'Item deleted.', 'bmlt-literature-manager' ) ) );
	}

	/**
	 * POST /admin/bmlt-test.
	 *
	 * @return \WP_REST_Response
	 */
	public static function bmlt_test() {
		return rest_ensure_response( Bmlt_Client::test_connection() );
	}

	/**
	 * GET /public/stock?area= — non-personal stock indicators only.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function public_stock( \WP_REST_Request $req ) {
		$area = absint( $req->get_param( 'area' ) );
		if ( ! Settings::is_selected_area( $area ) ) {
			return self::error( __( 'Unknown Area.', 'bmlt-literature-manager' ), 404 );
		}
		$out = array();
		foreach ( Inventory::area_inventory( $area, array( 'active' => 1 ) ) as $row ) {
			$out[] = array(
				'item_id'   => $row['item_id'],
				'status'    => $row['status'],
				'available' => $row['available'],
			);
		}
		return rest_ensure_response(
			array(
				'area_id' => $area,
				'items'   => $out,
			)
		);
	}
}
