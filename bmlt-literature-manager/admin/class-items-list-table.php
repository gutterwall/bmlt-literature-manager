<?php
/**
 * Admin literature items list table.
 *
 * @package BMLT_Lit
 * @version 6.3.0
 */

namespace BMLT_Lit\Admin;

use BMLT_Lit\Catalog;
use BMLT_Lit\Helpers;
use BMLT_Lit\Permissions;
use BMLT_Lit\Settings;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Literature catalog list with search, filters, sorting and bulk actions.
 */
class Items_List_Table extends \WP_List_Table {

	/**
	 * IDs of listed items that appear on orders (loaded in one query).
	 *
	 * @var int[]
	 */
	private $referenced = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'item',
				'plural'   => 'items',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Sanitized GET value.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function filter( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? Helpers::limit( sanitize_text_field( wp_unslash( $_GET[ $key ] ) ), 100 ) : '';
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox">',
			'sku'        => __( 'SKU', 'bmlt-literature-manager' ),
			'name'       => __( 'Title', 'bmlt-literature-manager' ),
			'category'   => __( 'Category', 'bmlt-literature-manager' ),
			'catalog'    => __( 'Catalog', 'bmlt-literature-manager' ),
			'price'      => __( 'Price', 'bmlt-literature-manager' ),
			'is_active'  => __( 'Status', 'bmlt-literature-manager' ),
			'sort_order' => __( 'Sort', 'bmlt-literature-manager' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'sku'        => array( 'sku', false ),
			'name'       => array( 'name', false ),
			'category'   => array( 'category', false ),
			'price'      => array( 'price', false ),
			'is_active'  => array( 'is_active', false ),
			'sort_order' => array( 'sort_order', false ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array(
			'bulk_activate'   => __( 'Activate', 'bmlt-literature-manager' ),
			'bulk_deactivate' => __( 'Deactivate', 'bmlt-literature-manager' ),
			'bulk_delete'     => __( 'Delete (only never-ordered items)', 'bmlt-literature-manager' ),
		);
	}

	/**
	 * Query items.
	 */
	public function prepare_items() {
		$per_page = 50;
		$paged    = max( 1, $this->get_pagenum() );
		$args     = array(
			'search'   => self::filter( 's' ),
			'category' => self::filter( 'category' ),
			'orderby'  => sanitize_key( self::filter( 'orderby' ) ),
			'order'    => self::filter( 'order' ),
			'limit'    => $per_page,
			'offset'   => ( $paged - 1 ) * $per_page,
		);
		$active = self::filter( 'active' );
		if ( '0' === $active || '1' === $active ) {
			$args['active'] = (int) $active;
		}
		$catalog = self::filter( 'catalog' );
		if ( '' !== $catalog && ctype_digit( $catalog ) && ( '0' === $catalog || Permissions::can_area( (int) $catalog ) ) ) {
			$args['area_id']        = (int) $catalog;
			$args['include_shared'] = false;
		} else {
			$args['area_ids'] = Permissions::allowed_areas();
		}
		$r                     = Catalog::query( $args );
		$this->items           = $r['items'];
		$this->referenced      = Catalog::referenced_ids( wp_list_pluck( $r['items'], 'id' ) );
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'name' );
		$this->set_pagination_args(
			array(
				'total_items' => $r['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $r['total'] / $per_page ),
			)
		);
	}

	/**
	 * Empty message.
	 */
	public function no_items() {
		esc_html_e( 'No literature items found.', 'bmlt-literature-manager' );
	}

	/**
	 * Checkbox column.
	 *
	 * @param object $it Item.
	 * @return string
	 */
	protected function column_cb( $it ) {
		if ( ! Permissions::can_area( (int) $it->area_id ) ) {
			return '';
		}
		/* translators: %s: item name. */
		return '<label class="screen-reader-text" for="bmlt-lit-cb-' . (int) $it->id . '">' . esc_html( sprintf( __( 'Select %s', 'bmlt-literature-manager' ), $it->name ) ) . '</label><input type="checkbox" id="bmlt-lit-cb-' . (int) $it->id . '" name="item[]" value="' . (int) $it->id . '">';
	}

	/**
	 * Name column with row actions.
	 *
	 * @param object $it Item.
	 * @return string
	 */
	protected function column_name( $it ) {
		$out = '<strong>' . esc_html( $it->name ) . '</strong>';
		if ( ! Permissions::can_area( (int) $it->area_id ) ) {
			return $out;
		}
		$id      = (int) $it->id;
		$edit    = admin_url( 'admin.php?page=bmlt-lit-items&action=edit&item_id=' . $id );
		$out     = '<strong><a href="' . esc_url( $edit ) . '">' . esc_html( $it->name ) . '</a></strong>';
		$base    = admin_url( 'admin-post.php?action=bmlt_lit_item_action&item_id=' . $id );
		$actions = array(
			'edit' => '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'bmlt-literature-manager' ) . '</a>',
		);
		if ( (int) $it->is_active ) {
			$actions['deactivate'] = '<a href="' . esc_url( wp_nonce_url( $base . '&do=deactivate', 'bmlt_lit_item_action_' . $id ) ) . '">' . esc_html__( 'Deactivate', 'bmlt-literature-manager' ) . '</a>';
		} else {
			$actions['activate'] = '<a href="' . esc_url( wp_nonce_url( $base . '&do=activate', 'bmlt_lit_item_action_' . $id ) ) . '">' . esc_html__( 'Activate', 'bmlt-literature-manager' ) . '</a>';
		}
		if ( ! in_array( $id, $this->referenced, true ) ) {
			$actions['delete'] = '<a class="submitdelete" data-bmlt-confirm="' . esc_attr__( 'Permanently delete this item?', 'bmlt-literature-manager' ) . '" href="' . esc_url( wp_nonce_url( $base . '&do=delete', 'bmlt_lit_item_action_' . $id ) ) . '">' . esc_html__( 'Delete', 'bmlt-literature-manager' ) . '</a>';
		}
		return $out . $this->row_actions( $actions );
	}

	/**
	 * Default column renderer.
	 *
	 * @param object $it     Item.
	 * @param string $column Column.
	 * @return string
	 */
	protected function column_default( $it, $column ) {
		switch ( $column ) {
			case 'sku':
				return esc_html( $it->sku );
			case 'category':
				return esc_html( $it->category );
			case 'catalog':
				return esc_html( Catalog::catalog_label( (int) $it->area_id ) );
			case 'price':
				return esc_html( Helpers::format_money( $it->price ) );
			case 'is_active':
				return (int) $it->is_active ? esc_html__( 'Active', 'bmlt-literature-manager' ) : Helpers::stock_badge( 'inactive' );
			case 'sort_order':
				return (string) (int) $it->sort_order;
		}
		return '';
	}

	/**
	 * Filters.
	 *
	 * @param string $which top|bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="bmlt-lit-i-cat">' . esc_html__( 'Catalog', 'bmlt-literature-manager' ) . '</label>';
		echo '<select name="catalog" id="bmlt-lit-i-cat"><option value="">' . esc_html__( 'All catalogs', 'bmlt-literature-manager' ) . '</option>';
		echo '<option value="0" ' . selected( self::filter( 'catalog' ), '0', false ) . '>' . esc_html__( 'Shared (all Areas)', 'bmlt-literature-manager' ) . '</option>';
		foreach ( Settings::area_options( Permissions::allowed_areas() ) as $id => $name ) {
			echo '<option value="' . esc_attr( $id ) . '" ' . selected( self::filter( 'catalog' ), (string) $id, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select>';
		echo '<label class="screen-reader-text" for="bmlt-lit-i-category">' . esc_html__( 'Category', 'bmlt-literature-manager' ) . '</label>';
		echo '<select name="category" id="bmlt-lit-i-category"><option value="">' . esc_html__( 'All categories', 'bmlt-literature-manager' ) . '</option>';
		foreach ( Catalog::categories() as $c ) {
			echo '<option value="' . esc_attr( $c ) . '" ' . selected( self::filter( 'category' ), $c, false ) . '>' . esc_html( $c ) . '</option>';
		}
		echo '</select>';
		echo '<label class="screen-reader-text" for="bmlt-lit-i-active">' . esc_html__( 'Status', 'bmlt-literature-manager' ) . '</label>';
		echo '<select name="active" id="bmlt-lit-i-active"><option value="">' . esc_html__( 'Active and inactive', 'bmlt-literature-manager' ) . '</option>';
		echo '<option value="1" ' . selected( self::filter( 'active' ), '1', false ) . '>' . esc_html__( 'Active', 'bmlt-literature-manager' ) . '</option>';
		echo '<option value="0" ' . selected( self::filter( 'active' ), '0', false ) . '>' . esc_html__( 'Inactive', 'bmlt-literature-manager' ) . '</option>';
		echo '</select>';
		submit_button( __( 'Filter', 'bmlt-literature-manager' ), '', 'filter_action', false );
		echo '</div>';
	}
}
