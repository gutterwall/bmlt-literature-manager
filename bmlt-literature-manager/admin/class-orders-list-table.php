<?php
/**
 * Admin orders list table.
 *
 * @package BMLT_Lit
 * @version 7.0.0
 */

namespace BMLT_Lit\Admin;

use BMLT_Lit\Helpers;
use BMLT_Lit\Orders;
use BMLT_Lit\Permissions;
use BMLT_Lit\Settings;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Orders list: newest first, 20 per page by default (20/50/100 selectable).
 */
class Orders_List_Table extends \WP_List_Table {

	/**
	 * Allowed per-page values.
	 */
	const PER_PAGE_CHOICES = array( 20, 50, 100 );

	/**
	 * User meta for remembered per-page value.
	 */
	const PER_PAGE_META = 'bmlt_lit_orders_per_page';

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'order',
				'plural'   => 'orders',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Read a sanitized GET filter.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function filter( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? Helpers::limit( sanitize_text_field( wp_unslash( $_GET[ $key ] ) ), 100 ) : '';
	}

	/**
	 * Per-page value (validated against the allowed choices).
	 *
	 * @return int
	 */
	public static function per_page() {
		$req = absint( self::filter( 'per_page' ) );
		if ( in_array( $req, self::PER_PAGE_CHOICES, true ) ) {
			update_user_meta( get_current_user_id(), self::PER_PAGE_META, $req );
			return $req;
		}
		$saved = absint( get_user_meta( get_current_user_id(), self::PER_PAGE_META, true ) );
		return in_array( $saved, self::PER_PAGE_CHOICES, true ) ? $saved : 20;
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'order_number' => __( 'Order #', 'bmlt-literature-manager' ),
			'created_at'   => __( 'Date', 'bmlt-literature-manager' ),
			'area_name'    => __( 'Area', 'bmlt-literature-manager' ),
			'meeting_name' => __( 'Meeting', 'bmlt-literature-manager' ),
			'contact_name' => __( 'Contact', 'bmlt-literature-manager' ),
			'email'        => __( 'Email', 'bmlt-literature-manager' ),
			'phone'        => __( 'Phone', 'bmlt-literature-manager' ),
			'items'        => __( 'Items', 'bmlt-literature-manager' ),
			'total'        => __( 'Total', 'bmlt-literature-manager' ),
			'special'      => __( 'Special Order', 'bmlt-literature-manager' ),
			'status'       => __( 'Status', 'bmlt-literature-manager' ),
		);
	}

	/**
	 * Sortable columns => query sort keys.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'order_number' => array( 'number', true ),
			'created_at'   => array( 'date', true ),
			'area_name'    => array( 'area', false ),
			'meeting_name' => array( 'meeting', false ),
			'contact_name' => array( 'contact', false ),
			'total'        => array( 'total', true ),
			'status'       => array( 'status', false ),
		);
	}

	/**
	 * Build the query.
	 */
	public function prepare_items() {
		$per_page = self::per_page();
		$paged    = max( 1, $this->get_pagenum() );
		$orderby  = sanitize_key( self::filter( 'orderby' ) );
		$order    = 'asc' === strtolower( self::filter( 'order' ) ) ? 'asc' : 'desc';

		$sort = 'newest';
		if ( 'date' === $orderby ) {
			$sort = 'asc' === $order ? 'oldest' : 'newest';
		} elseif ( in_array( $orderby, array( 'area', 'meeting', 'status', 'total', 'number', 'contact' ), true ) ) {
			$sort = $orderby;
		}

		$result = Orders::query(
			array(
				'area_ids'      => Permissions::allowed_areas(),
				'area_id'       => absint( self::filter( 'area' ) ),
				'status'        => sanitize_key( self::filter( 'status' ) ),
				'special'       => in_array( self::filter( 'special' ), array( '0', '1' ), true ) ? self::filter( 'special' ) : '',
				'search'        => self::filter( 's' ),
				'date_from_gmt' => Helpers::local_date_to_gmt( self::filter( 'date_from' ) ),
				'date_to_gmt'   => Helpers::local_date_to_gmt( self::filter( 'date_to' ), true ),
				'sort'          => $sort,
				'limit'         => $per_page,
				'offset'        => ( $paged - 1 ) * $per_page,
			)
		);

		$this->items           = $result['orders'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'order_number' );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $result['total'] / $per_page ),
			)
		);
	}

	/**
	 * Empty message.
	 */
	public function no_items() {
		esc_html_e( 'No orders found.', 'bmlt-literature-manager' );
	}

	/**
	 * Order number column with link.
	 *
	 * @param object $o Order.
	 * @return string
	 */
	protected function column_order_number( $o ) {
		$url = admin_url( 'admin.php?page=bmlt-lit-orders&order_id=' . (int) $o->id );
		return '<strong><a href="' . esc_url( $url ) . '">' . esc_html( $o->order_number ) . '</a></strong>'
			. $this->row_actions( array( 'view' => '<a href="' . esc_url( $url ) . '">' . esc_html__( 'View / manage', 'bmlt-literature-manager' ) . '</a>' ) );
	}

	/**
	 * Default column renderer.
	 *
	 * @param object $o      Order.
	 * @param string $column Column.
	 * @return string
	 */
	protected function column_default( $o, $column ) {
		switch ( $column ) {
			case 'created_at':
				return esc_html( Helpers::local_date( $o->created_at ) );
			case 'area_name':
				return esc_html( $o->area_name );
			case 'meeting_name':
				return esc_html( $o->meeting_name );
			case 'contact_name':
				return esc_html( $o->contact_name );
			case 'email':
				return $o->contact_email ? '<a href="' . esc_url( 'mailto:' . $o->contact_email ) . '">' . esc_html( $o->contact_email ) . '</a>' : '';
			case 'phone':
				return esc_html( $o->contact_phone );
			case 'items':
				return (string) (int) $o->item_count;
			case 'total':
				return esc_html( Helpers::format_money( $o->total ) );
			case 'special':
				return (int) $o->has_special_order ? Helpers::stock_badge( 'special_order' ) : esc_html__( 'No', 'bmlt-literature-manager' );
			case 'status':
				return '<span class="bmlt-lit-status bmlt-lit-status--' . esc_attr( $o->status ) . '">' . esc_html( Helpers::order_status_label( $o->status ) ) . '</span>';
		}
		return '';
	}

	/**
	 * Highlight special-order rows.
	 *
	 * @param object $o Order.
	 */
	public function single_row( $o ) {
		echo '<tr class="' . ( (int) $o->has_special_order ? 'bmlt-lit-row--special' : '' ) . '">';
		$this->single_row_columns( $o );
		echo '</tr>';
	}

	/**
	 * Filters above the table.
	 *
	 * @param string $which top|bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$areas = Settings::area_options( Permissions::allowed_areas() );
		echo '<div class="alignleft actions bmlt-lit-filterbar">';
		if ( count( $areas ) > 1 ) {
			echo '<label class="screen-reader-text" for="bmlt-lit-f-area">' . esc_html__( 'Filter by Area', 'bmlt-literature-manager' ) . '</label>';
			echo '<select name="area" id="bmlt-lit-f-area"><option value="">' . esc_html__( 'All Areas', 'bmlt-literature-manager' ) . '</option>';
			foreach ( $areas as $id => $name ) {
				echo '<option value="' . esc_attr( $id ) . '" ' . selected( absint( self::filter( 'area' ) ), $id, false ) . '>' . esc_html( $name ) . '</option>';
			}
			echo '</select>';
		}
		echo '<label class="screen-reader-text" for="bmlt-lit-f-status">' . esc_html__( 'Filter by status', 'bmlt-literature-manager' ) . '</label>';
		echo '<select name="status" id="bmlt-lit-f-status"><option value="">' . esc_html__( 'Any status', 'bmlt-literature-manager' ) . '</option>';
		echo '<option value="open" ' . selected( self::filter( 'status' ), 'open', false ) . '>' . esc_html__( 'Open', 'bmlt-literature-manager' ) . '</option>';
		foreach ( Helpers::order_statuses() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( self::filter( 'status' ), $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<label class="screen-reader-text" for="bmlt-lit-f-special">' . esc_html__( 'Special orders', 'bmlt-literature-manager' ) . '</label>';
		echo '<select name="special" id="bmlt-lit-f-special"><option value="">' . esc_html__( 'All orders', 'bmlt-literature-manager' ) . '</option>';
		echo '<option value="1" ' . selected( self::filter( 'special' ), '1', false ) . '>' . esc_html__( 'Special orders only', 'bmlt-literature-manager' ) . '</option>';
		echo '<option value="0" ' . selected( self::filter( 'special' ), '0', false ) . '>' . esc_html__( 'No special orders', 'bmlt-literature-manager' ) . '</option>';
		echo '</select>';
		echo '<label for="bmlt-lit-f-from">' . esc_html__( 'From', 'bmlt-literature-manager' ) . '</label> <input type="date" id="bmlt-lit-f-from" name="date_from" value="' . esc_attr( self::filter( 'date_from' ) ) . '"> ';
		echo '<label for="bmlt-lit-f-to">' . esc_html__( 'To', 'bmlt-literature-manager' ) . '</label> <input type="date" id="bmlt-lit-f-to" name="date_to" value="' . esc_attr( self::filter( 'date_to' ) ) . '"> ';
		echo '<label for="bmlt-lit-f-pp">' . esc_html__( 'Per page', 'bmlt-literature-manager' ) . '</label> <select name="per_page" id="bmlt-lit-f-pp">';
		foreach ( self::PER_PAGE_CHOICES as $n ) {
			echo '<option value="' . esc_attr( (string) $n ) . '" ' . selected( self::per_page(), $n, false ) . '>' . esc_html( (string) $n ) . '</option>';
		}
		echo '</select>';
		submit_button( __( 'Filter', 'bmlt-literature-manager' ), '', 'filter_action', false );
		echo '</div>';
	}
}
