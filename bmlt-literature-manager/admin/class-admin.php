<?php
/**
 * WordPress admin controller.
 *
 * @package BMLT_Lit
 * @version 6.2.0
 */

namespace BMLT_Lit\Admin;

use BMLT_Lit\Audit;
use BMLT_Lit\Bmlt_Client;
use BMLT_Lit\Catalog;
use BMLT_Lit\Migrator;
use BMLT_Lit\Email;
use BMLT_Lit\Helpers;
use BMLT_Lit\Import_Export;
use BMLT_Lit\Inventory;
use BMLT_Lit\Orders;
use BMLT_Lit\Permissions;
use BMLT_Lit\Settings;
use BMLT_Lit\Smtp;
use BMLT_Lit\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Admin menus, pages and handlers. Every handler checks a nonce and a capability.
 */
class Admin {

	/**
	 * Page hook suffixes.
	 *
	 * @var string[]
	 */
	private static $hooks = array();

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'plugin_action_links_' . BMLT_LIT_BASENAME, array( __CLASS__, 'action_links' ) );

		$handlers = array(
			'save_item',
			'item_action',
			'save_inventory',
			'order_status',
			'order_note',
			'order_resend',
			'order_email',
			'export',
			'import_preview',
			'import_confirm',
			'save_bmlt',
			'save_area_selection',
			'test_bmlt',
			'refresh_bmlt',
			'save_smtp',
			'test_email',
			'save_settings',
			'save_permissions',
			'clear_cache',
			'repair_store',
			'backup_download',
			'restore_backup',
			'migrate_db',
			'drop_legacy',
			'adopt_site',
		);
		foreach ( $handlers as $h ) {
			add_action( 'admin_post_bmlt_lit_' . $h, array( __CLASS__, 'handle_' . $h ) );
		}
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		if ( current_user_can( Permissions::MANAGE_SETTINGS ) ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=bmlt-lit-settings' ) ) . '">' . esc_html__( 'Settings', 'bmlt-literature-manager' ) . '</a>' );
		}
		return $links;
	}

	/**
	 * Register the Literature menu.
	 */
	public static function menu() {
		$parent_cap = Permissions::VIEW_ORDERS;
		foreach ( array( Permissions::VIEW_ORDERS, Permissions::MANAGE_INVENTORY, Permissions::MANAGE_CATALOG, Permissions::MANAGE_SETTINGS ) as $cap ) {
			if ( current_user_can( $cap ) ) {
				$parent_cap = $cap;
				break;
			}
		}

		self::$hooks['dashboard'] = add_menu_page(
			__( 'Literature', 'bmlt-literature-manager' ),
			__( 'Literature', 'bmlt-literature-manager' ),
			$parent_cap,
			'bmlt-lit',
			array( __CLASS__, 'page_dashboard' ),
			'dashicons-book-alt',
			26
		);
		self::$hooks['dashboard'] = add_submenu_page( 'bmlt-lit', __( 'Literature Dashboard', 'bmlt-literature-manager' ), __( 'Dashboard', 'bmlt-literature-manager' ), $parent_cap, 'bmlt-lit', array( __CLASS__, 'page_dashboard' ) );
		self::$hooks['orders']    = add_submenu_page( 'bmlt-lit', __( 'Literature Orders', 'bmlt-literature-manager' ), __( 'Orders', 'bmlt-literature-manager' ), Permissions::VIEW_ORDERS, 'bmlt-lit-orders', array( __CLASS__, 'page_orders' ) );
		self::$hooks['items']     = add_submenu_page( 'bmlt-lit', __( 'Literature Items', 'bmlt-literature-manager' ), __( 'Items', 'bmlt-literature-manager' ), Permissions::MANAGE_CATALOG, 'bmlt-lit-items', array( __CLASS__, 'page_items' ) );
		self::$hooks['inventory'] = add_submenu_page( 'bmlt-lit', __( 'Area Inventory', 'bmlt-literature-manager' ), __( 'Inventory', 'bmlt-literature-manager' ), Permissions::MANAGE_INVENTORY, 'bmlt-lit-inventory', array( __CLASS__, 'page_inventory' ) );
		self::$hooks['import']    = add_submenu_page( 'bmlt-lit', __( 'Import / Export', 'bmlt-literature-manager' ), __( 'Import / Export', 'bmlt-literature-manager' ), Permissions::MANAGE_CATALOG, 'bmlt-lit-import', array( __CLASS__, 'page_import' ) );
		self::$hooks['bmlt']      = add_submenu_page( 'bmlt-lit', __( 'BMLT / Areas', 'bmlt-literature-manager' ), __( 'BMLT / Areas', 'bmlt-literature-manager' ), Permissions::MANAGE_SETTINGS, 'bmlt-lit-bmlt', array( __CLASS__, 'page_bmlt' ) );
		self::$hooks['smtp']      = add_submenu_page( 'bmlt-lit', __( 'SMTP', 'bmlt-literature-manager' ), __( 'SMTP', 'bmlt-literature-manager' ), Permissions::MANAGE_SETTINGS, 'bmlt-lit-smtp', array( __CLASS__, 'page_smtp' ) );
		self::$hooks['settings']  = add_submenu_page( 'bmlt-lit', __( 'Literature Settings', 'bmlt-literature-manager' ), __( 'Settings', 'bmlt-literature-manager' ), Permissions::MANAGE_SETTINGS, 'bmlt-lit-settings', array( __CLASS__, 'page_settings' ) );
		self::$hooks['status']    = add_submenu_page( 'bmlt-lit', __( 'Tools / Status', 'bmlt-literature-manager' ), __( 'Tools / Status', 'bmlt-literature-manager' ), Permissions::MANAGE_SETTINGS, 'bmlt-lit-status', array( __CLASS__, 'page_status' ) );

		if ( self::$hooks['items'] ) {
			add_action( 'load-' . self::$hooks['items'], array( __CLASS__, 'process_item_bulk' ) );
		}
	}

	/**
	 * Whether the current screen is one of ours.
	 *
	 * @param string $hook Hook suffix.
	 * @return bool
	 */
	private static function is_our_page( $hook ) {
		return in_array( $hook, array_filter( self::$hooks ), true );
	}

	/**
	 * Enqueue admin assets on plugin pages only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function assets( $hook ) {
		if ( ! self::is_our_page( $hook ) ) {
			return;
		}
		wp_enqueue_style( 'bmlt-lit-admin', BMLT_LIT_URL . 'admin/css/admin.css', array(), Helpers::asset_version( 'admin/css/admin.css' ) );
		wp_enqueue_script( 'bmlt-lit-admin', BMLT_LIT_URL . 'admin/js/admin.js', array(), Helpers::asset_version( 'admin/js/admin.js' ), true );
		wp_add_inline_script(
			'bmlt-lit-admin',
			'window.bmltLitAdmin = ' . wp_json_encode(
				array(
					'restUrl' => esc_url_raw( rest_url( 'bmlt-lit/v1/' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'strings' => array(
						'testing'   => __( 'Testing connection…', 'bmlt-literature-manager' ),
						'success'   => __( 'Connection successful.', 'bmlt-literature-manager' ),
						'failed'    => __( 'Connection failed.', 'bmlt-literature-manager' ),
						'version'   => __( 'Server version', 'bmlt-literature-manager' ),
						'bodies'    => __( 'Service bodies detected', 'bmlt-literature-manager' ),
						'meetings'  => __( 'Meetings detected', 'bmlt-literature-manager' ),
						'scopeSel'  => __( '(in selected Areas)', 'bmlt-literature-manager' ),
						'scopeAll'  => __( '(on the whole server)', 'bmlt-literature-manager' ),
						'reloadSb'  => __( 'Reload this page to see the updated list of Areas.', 'bmlt-literature-manager' ),
						'copied'    => __( 'Copied to clipboard.', 'bmlt-literature-manager' ),
						'copyFail'  => __( 'Copy failed — select the text and copy it manually.', 'bmlt-literature-manager' ),
						'validJson' => __( 'The JSON is valid. Use “Preview import” to see exactly what will change.', 'bmlt-literature-manager' ),
						'badJson'   => __( 'Invalid JSON:', 'bmlt-literature-manager' ),
						'confirm'   => __( 'Are you sure?', 'bmlt-literature-manager' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Show flash notices on plugin pages.
	 */
	public static function notices() {
		$screen = get_current_screen();
		if ( ! $screen || ! self::is_our_page( $screen->id ) ) {
			return;
		}
		foreach ( Helpers::take_notices() as $n ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $n['type'] ),
				nl2br( esc_html( $n['message'] ) )
			);
		}
		if ( current_user_can( Permissions::MANAGE_SETTINGS ) ) {
			$status_url = admin_url( 'admin.php?page=bmlt-lit-status' );
			if ( ! Store::exists( Settings::DOC ) ) {
				$ready = Store::ensure_dir();
				echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'BMLT Literature Manager cannot store data yet.', 'bmlt-literature-manager' ) . '</strong> ' . esc_html( is_wp_error( $ready ) ? $ready->get_error_message() : __( 'The data store is being initialized; reload this page.', 'bmlt-literature-manager' ) ) . '</p></div>';
			}
			foreach ( Store::read_errors() as $bad ) {
				/* translators: %s: file name. */
				echo '<div class="notice notice-error"><p>' . esc_html( sprintf( __( 'Literature data file "%s" could not be read. Changes touching it are blocked until it is restored.', 'bmlt-literature-manager' ), $bad ) ) . ' <a href="' . esc_url( $status_url ) . '">' . esc_html__( 'Tools / Status', 'bmlt-literature-manager' ) . '</a></p></div>';
			}
			$foreign = Settings::foreign_site();
			if ( '' !== $foreign ) {
				echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Literature data belongs to another site.', 'bmlt-literature-manager' ) . '</strong> '
					/* translators: 1: other site URL, 2: this site URL. */
					. esc_html( sprintf( __( 'The JSON data directory was created by %1$s, but this site is %2$s. If staging and production share or copied this directory, orders may be going to the wrong place.', 'bmlt-literature-manager' ), $foreign, home_url( '/' ) ) )
					. ' <a href="' . esc_url( $status_url ) . '">' . esc_html__( 'Review on Tools / Status', 'bmlt-literature-manager' ) . '</a></p></div>';
			}
			if ( empty( Settings::selected_areas() ) && ( empty( self::$hooks['bmlt'] ) || self::$hooks['bmlt'] !== $screen->id ) ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'BMLT Literature Manager: no Areas are selected yet.', 'bmlt-literature-manager' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=bmlt-lit-bmlt' ) ) . '">' . esc_html__( 'Configure BMLT / Areas', 'bmlt-literature-manager' ) . '</a></p></div>';
			}
			$m = Bmlt_Client::get_meetings( false );
			if ( ! empty( Settings::selected_areas() ) && $m['stale'] && $m['fetched_at'] && Bmlt_Client::status()['last_error'] ) {
				echo '<div class="notice notice-warning"><p>' . esc_html(
					sprintf(
						/* translators: %s: date/time. */
						__( 'The BMLT server could not be reached recently. Cached meeting information from %s is being used.', 'bmlt-literature-manager' ),
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $m['fetched_at'] )
					)
				) . '</p></div>';
			}
		}
	}

	/* ------------------------------------------------------------------
	 * Utilities.
	 * ---------------------------------------------------------------- */

	/**
	 * Verify nonce + capability or die.
	 *
	 * @param string $cap    Capability.
	 * @param string $action Nonce action.
	 */
	private static function verify( $cap, $action ) {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'bmlt-literature-manager' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirect to a plugin page.
	 *
	 * @param string $page Page slug.
	 * @param array  $args Query args.
	 */
	private static function go( $page, array $args = array() ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Unslashed POST array.
	 *
	 * @return array
	 */
	private static function post() {
		return wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers verify nonces first.
	}

	/**
	 * Render an admin view.
	 *
	 * @param string $view View.
	 * @param array  $vars Variables.
	 */
	private static function view( $view, array $vars = array() ) {
		$file = BMLT_LIT_DIR . 'admin/views/' . sanitize_file_name( $view ) . '.php';
		if ( is_readable( $file ) ) {
			extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			include $file;
		}
	}

	/**
	 * Area selected via GET, limited to the user's Areas.
	 *
	 * @param string $param Param name.
	 * @return int
	 */
	private static function requested_area( $param = 'area' ) {
		$allowed = Permissions::allowed_areas();
		$area    = isset( $_GET[ $param ] ) ? absint( $_GET[ $param ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $area && in_array( $area, $allowed, true ) ) {
			return $area;
		}
		return 0;
	}

	/* ------------------------------------------------------------------
	 * Pages.
	 * ---------------------------------------------------------------- */

	/**
	 * Dashboard.
	 */
	public static function page_dashboard() {
		if ( ! Permissions::has_any() ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'bmlt-literature-manager' ) );
		}
		$area  = self::requested_area();
		$areas = $area ? array( $area ) : Permissions::allowed_areas();
		self::view(
			'dashboard',
			array(
				'area'    => $area,
				'areas'   => $areas,
				'counts'  => Orders::dashboard_counts( $areas ),
				'alerts'  => current_user_can( Permissions::MANAGE_INVENTORY ) || current_user_can( Permissions::VIEW_ORDERS ) ? Inventory::stock_alerts( $areas ) : array(
					'low'       => 0,
					'out'       => 0,
					'low_items' => array(),
				),
				'recent'  => current_user_can( Permissions::VIEW_ORDERS ) ? Orders::query(
					array(
						'area_ids' => $areas,
						'limit'    => 10,
						'count'    => false,
					)
				)['orders'] : array(),
				'adjusts' => Audit::recent(
					array(
						'area_ids'    => $areas,
						'object_type' => 'inventory',
						'limit'       => 10,
					)
				),
			)
		);
	}

	/**
	 * Orders list or single order.
	 */
	public static function page_orders() {
		if ( ! current_user_can( Permissions::VIEW_ORDERS ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'bmlt-literature-manager' ) );
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $order_id ) {
			$order = Orders::get( $order_id );
			if ( ! $order || ! Permissions::can_area( (int) $order->area_id ) ) {
				wp_die( esc_html__( 'Order not found.', 'bmlt-literature-manager' ) );
			}
			self::view(
				'order-detail',
				array(
					'order' => $order,
					'items' => Orders::get_items( $order->id ),
					'notes' => Orders::get_notes( $order->id ),
				)
			);
			return;
		}
		$table = new Orders_List_Table();
		$table->prepare_items();
		self::view( 'orders', array( 'table' => $table ) );
	}

	/**
	 * Items list / edit.
	 */
	public static function page_items() {
		if ( ! current_user_can( Permissions::MANAGE_CATALOG ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'bmlt-literature-manager' ) );
		}
		$action = isset( $_GET['action'] ) && is_scalar( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $action, array( 'edit', 'new' ), true ) ) {
			$item = null;
			if ( 'edit' === $action ) {
				$item = Catalog::get( isset( $_GET['item_id'] ) ? absint( $_GET['item_id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( ! $item || ! Permissions::can_area( (int) $item->area_id ) ) {
					wp_die( esc_html__( 'Item not found.', 'bmlt-literature-manager' ) );
				}
			}
			self::view(
				'item-edit',
				array(
					'item'       => $item,
					'categories' => Catalog::categories(),
					'history'    => $item ? Audit::recent(
						array(
							'object_type' => 'item',
							'object_id'   => (int) $item->id,
							'limit'       => 20,
						)
					) : array(),
				)
			);
			return;
		}
		$table = new Items_List_Table();
		$table->prepare_items();
		self::view( 'items', array( 'table' => $table ) );
	}

	/**
	 * Inventory.
	 */
	public static function page_inventory() {
		if ( ! current_user_can( Permissions::MANAGE_INVENTORY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'bmlt-literature-manager' ) );
		}
		$allowed = Permissions::allowed_areas();
		$area    = self::requested_area();
		if ( ! $area && count( $allowed ) >= 1 ) {
			$area = (int) $allowed[0];
		}
		$search = isset( $_GET['s'] ) && is_scalar( $_GET['s'] ) ? Helpers::limit( sanitize_text_field( wp_unslash( $_GET['s'] ) ), 100 ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter = isset( $_GET['stock'] ) && is_scalar( $_GET['stock'] ) ? sanitize_key( $_GET['stock'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rows   = $area ? Inventory::area_inventory(
			$area,
			array(
				'search'       => $search,
				'special_last' => true,
			)
		) : array();
		if ( in_array( $filter, array( 'low_stock', 'special_order', 'in_stock', 'inactive' ), true ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $r ) use ( $filter ) {
						return $r['status'] === $filter;
					}
				)
			);
		}
		self::view(
			'inventory',
			array(
				'area'    => $area,
				'tracked' => $area ? Settings::area_tracks_inventory( $area ) : true,
				'areas'   => Settings::area_options( $allowed ),
				'rows'    => $rows,
				'search'  => $search,
				'filter'  => $filter,
				'history' => $area ? Audit::recent(
					array(
						'area_ids'    => array( $area ),
						'object_type' => 'inventory',
						'limit'       => 40,
					)
				) : array(),
			)
		);
	}

	/**
	 * Import / Export.
	 */
	public static function page_import() {
		if ( ! current_user_can( Permissions::MANAGE_CATALOG ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'bmlt-literature-manager' ) );
		}
		$preview = null;
		$token   = isset( $_GET['preview'] ) && is_scalar( $_GET['preview'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', sanitize_text_field( wp_unslash( $_GET['preview'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $token ) {
			$stored = get_transient( 'bmlt_lit_import_' . get_current_user_id() . '_' . $token );
			if ( is_array( $stored ) ) {
				$preview = $stored;
			}
		}
		$editor = '';
		$show   = isset( $_GET['editor'] ) && is_scalar( $_GET['editor'] ) ? sanitize_key( $_GET['editor'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $show && isset( $_GET['_wpnonce'] ) && is_scalar( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'bmlt_lit_editor' ) ) {
			$catalog = isset( $_GET['catalog'] ) && is_scalar( $_GET['catalog'] ) ? sanitize_text_field( wp_unslash( $_GET['catalog'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'inventory' === $show ) {
				$area = self::requested_area( 'inv_area' );
				if ( $area && current_user_can( Permissions::MANAGE_INVENTORY ) ) {
					$editor = Import_Export::to_json( Import_Export::export_inventory( $area ) );
				}
			} else {
				$editor = Import_Export::to_json( Import_Export::export_catalog( self::catalog_scope( $catalog ) ) );
			}
		}
		self::view(
			'import-export',
			array(
				'preview' => $preview,
				'token'   => $token,
				'editor'  => $editor,
				'areas'   => Settings::area_options( Permissions::allowed_areas() ),
			)
		);
	}

	/**
	 * Interpret a catalog scope value the current user may export.
	 *
	 * @param string $catalog all|shared|area id.
	 * @return int|null
	 */
	private static function catalog_scope( $catalog ) {
		if ( 'shared' === $catalog ) {
			return 0;
		}
		if ( ctype_digit( (string) $catalog ) && Permissions::can_area( (int) $catalog ) ) {
			return (int) $catalog;
		}
		return Permissions::is_area_restricted() ? 0 : null;
	}

	/**
	 * BMLT / Areas page.
	 */
	public static function page_bmlt() {
		if ( ! current_user_can( Permissions::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'bmlt-literature-manager' ) );
		}
		$test = get_transient( 'bmlt_lit_test_' . get_current_user_id() );
		delete_transient( 'bmlt_lit_test_' . get_current_user_id() );
		self::view(
			'bmlt',
			array(
				'opts'     => Settings::bmlt(),
				'tree'     => Bmlt_Client::service_body_tree(),
				'selected' => Settings::selected_areas(),
				'status'   => Bmlt_Client::status(),
				'meetings' => Bmlt_Client::get_meetings( false ),
				'sb_time'  => Bmlt_Client::service_bodies_fetched_at(),
				'test'     => is_array( $test ) ? $test : null,
			)
		);
	}

	/**
	 * SMTP page.
	 */
	public static function page_smtp() {
		if ( ! current_user_can( Permissions::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'bmlt-literature-manager' ) );
		}
		self::view(
			'smtp',
			array(
				'opts'       => Settings::smtp(),
				'pw_source'  => Smtp::password_source(),
				'pw_broken'  => Smtp::password_unreadable(),
				'can_crypt'  => Smtp::can_encrypt(),
			)
		);
	}

	/**
	 * Settings page (tabs).
	 */
	public static function page_settings() {
		if ( ! current_user_can( Permissions::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'bmlt-literature-manager' ) );
		}
		$tabs = array(
			'general'     => __( 'General', 'bmlt-literature-manager' ),
			'bmlt'        => __( 'BMLT', 'bmlt-literature-manager' ),
			'areas'       => __( 'Area Configuration', 'bmlt-literature-manager' ),
			'email'       => __( 'Email', 'bmlt-literature-manager' ),
			'templates'   => __( 'Email Templates', 'bmlt-literature-manager' ),
			'permissions' => __( 'Permissions', 'bmlt-literature-manager' ),
			'advanced'    => __( 'Advanced', 'bmlt-literature-manager' ),
		);
		$tab  = isset( $_GET['tab'] ) && is_scalar( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'general';
		}
		self::view(
			'settings',
			array(
				'tabs'      => $tabs,
				'tab'       => $tab,
				'general'   => Settings::general(),
				'templates' => Settings::template_labels(),
			)
		);
	}

	/**
	 * Tools / Status page.
	 */
	public static function page_status() {
		if ( ! current_user_can( Permissions::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'bmlt-literature-manager' ) );
		}
		$catalog = Catalog::query( array( 'limit' => 1 ) );
		self::view(
			'status',
			array(
				'bmlt'       => Settings::bmlt(),
				'bmlt_state' => Bmlt_Client::status(),
				'meetings'   => Bmlt_Client::get_meetings( false ),
				'sb_count'   => count( Bmlt_Client::get_service_bodies() ),
				'sb_time'    => Bmlt_Client::service_bodies_fetched_at(),
				'items'      => $catalog['total'],
				'inv'        => Inventory::count_records(),
				'orders'     => Orders::count_all(),
				'smtp'       => Settings::smtp(),
				'pw_source'  => Smtp::password_source(),
				'health'     => Store::health(),
				'meta'       => Settings::meta(),
				'foreign'    => Settings::foreign_site(),
				'legacy'     => Migrator::legacy_leftovers(),
				'restore'    => self::pending_restore(),
				'next_cron'  => wp_next_scheduled( Bmlt_Client::CRON_HOOK ),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Item handlers.
	 * ---------------------------------------------------------------- */

	/**
	 * Save (create/update) an item from the admin form.
	 */
	public static function handle_save_item() {
		self::verify( Permissions::MANAGE_CATALOG, 'bmlt_lit_save_item' );
		$p       = self::post();
		$item_id = isset( $p['item_id'] ) ? absint( $p['item_id'] ) : 0;
		$input   = array();
		foreach ( array( 'area_id', 'sku', 'name', 'short_description', 'long_description', 'category', 'price', 'sort_order', 'image_url', 'reference_url', 'naws_id' ) as $f ) {
			$input[ $f ] = isset( $p[ $f ] ) && is_scalar( $p[ $f ] ) ? (string) $p[ $f ] : '';
		}
		$input['is_active'] = ! empty( $p['is_active'] ) ? 1 : 0;

		if ( ! Permissions::can_area( absint( $input['area_id'] ) ) ) {
			wp_die( esc_html__( 'You may only manage catalogs for your own Areas.', 'bmlt-literature-manager' ), '', array( 'response' => 403 ) );
		}

		if ( $item_id ) {
			$item = Catalog::get( $item_id );
			if ( ! $item || ! Permissions::can_area( (int) $item->area_id ) ) {
				wp_die( esc_html__( 'Item not found.', 'bmlt-literature-manager' ) );
			}
			$res = Catalog::update( $item_id, $input );
		} else {
			$res = Catalog::create( $input );
			if ( ! is_wp_error( $res ) ) {
				$item_id = (int) $res;
			}
		}
		if ( is_wp_error( $res ) ) {
			Helpers::add_notice( implode( "\n", $res->get_error_messages() ), 'error' );
			set_transient( 'bmlt_lit_item_form_' . get_current_user_id(), $input, 5 * MINUTE_IN_SECONDS );
			self::go( 'bmlt-lit-items', $item_id ? array( 'action' => 'edit', 'item_id' => $item_id ) : array( 'action' => 'new' ) );
		}
		// Special-order flags for Areas that don't track quantities.
		$flags = ( isset( $p['special_area'] ) && is_array( $p['special_area'] ) ) ? array_map( 'absint', $p['special_area'] ) : array();
		$shown = ( isset( $p['special_area_shown'] ) && is_array( $p['special_area_shown'] ) ) ? array_map( 'absint', $p['special_area_shown'] ) : array();
		foreach ( $shown as $area ) {
			if ( ! $area || Settings::area_tracks_inventory( $area ) || ! Permissions::can_area( $area ) || ! current_user_can( Permissions::MANAGE_INVENTORY ) ) {
				continue;
			}
			$res = Inventory::adjust( $area, $item_id, array( 'special' => in_array( $area, $flags, true ) ? 1 : 0 ), __( 'Special-order setting changed on item page', 'bmlt-literature-manager' ) );
			if ( is_wp_error( $res ) ) {
				Helpers::add_notice( $res->get_error_message(), 'error' );
			}
		}
		Helpers::add_notice( __( 'Item saved.', 'bmlt-literature-manager' ) );
		self::go( 'bmlt-lit-items', array( 'action' => 'edit', 'item_id' => $item_id ) );
	}

	/**
	 * Single-item row actions (activate/deactivate/delete).
	 */
	public static function handle_item_action() {
		$item_id = isset( $_GET['item_id'] ) ? absint( $_GET['item_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		self::verify( Permissions::MANAGE_CATALOG, 'bmlt_lit_item_action_' . $item_id );
		$do   = isset( $_GET['do'] ) && is_scalar( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$item = Catalog::get( $item_id );
		if ( ! $item || ! Permissions::can_area( (int) $item->area_id ) ) {
			wp_die( esc_html__( 'Item not found.', 'bmlt-literature-manager' ) );
		}
		if ( 'delete' === $do ) {
			$res = Catalog::delete( $item_id );
			Helpers::add_notice( is_wp_error( $res ) ? $res->get_error_message() : __( 'Item deleted.', 'bmlt-literature-manager' ), is_wp_error( $res ) ? 'error' : 'success' );
		} elseif ( 'activate' === $do || 'deactivate' === $do ) {
			Catalog::set_active( $item_id, 'activate' === $do );
			Helpers::add_notice( 'activate' === $do ? __( 'Item activated.', 'bmlt-literature-manager' ) : __( 'Item deactivated.', 'bmlt-literature-manager' ) );
		}
		self::go( 'bmlt-lit-items' );
	}

	/**
	 * Bulk actions on the Items list table.
	 */
	public static function process_item_bulk() {
		$action = '';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce verified below.
		if ( isset( $_REQUEST['action'] ) && is_scalar( $_REQUEST['action'] ) && '-1' !== $_REQUEST['action'] ) {
			$action = sanitize_key( $_REQUEST['action'] );
		} elseif ( isset( $_REQUEST['action2'] ) && is_scalar( $_REQUEST['action2'] ) && '-1' !== $_REQUEST['action2'] ) {
			$action = sanitize_key( $_REQUEST['action2'] );
		}
		if ( ! in_array( $action, array( 'bulk_activate', 'bulk_deactivate', 'bulk_delete' ), true ) || empty( $_REQUEST['item'] ) ) {
			return;
		}
		check_admin_referer( 'bulk-items' );
		if ( ! current_user_can( Permissions::MANAGE_CATALOG ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'bmlt-literature-manager' ) );
		}
		$ids = array_map( 'absint', (array) $_REQUEST['item'] );
		// phpcs:enable
		$done    = 0;
		$skipped = 0;
		foreach ( Catalog::get_many( $ids ) as $item ) {
			if ( ! Permissions::can_area( (int) $item->area_id ) ) {
				++$skipped;
				continue;
			}
			if ( 'bulk_delete' === $action ) {
				$res = Catalog::delete( (int) $item->id );
				if ( is_wp_error( $res ) ) {
					++$skipped;
					continue;
				}
			} else {
				Catalog::set_active( (int) $item->id, 'bulk_activate' === $action );
			}
			++$done;
		}
		/* translators: %d: number of items. */
		$msg = sprintf( _n( '%d item updated.', '%d items updated.', $done, 'bmlt-literature-manager' ), $done );
		if ( $skipped ) {
			/* translators: %d: number of items. */
			$msg .= ' ' . sprintf( _n( '%d item was skipped (items on historical orders cannot be deleted — deactivate them instead).', '%d items were skipped (items on historical orders cannot be deleted — deactivate them instead).', $skipped, 'bmlt-literature-manager' ), $skipped );
		}
		Helpers::add_notice( $msg, $skipped ? 'warning' : 'success' );
		self::go( 'bmlt-lit-items' );
	}

	/* ------------------------------------------------------------------
	 * Inventory.
	 * ---------------------------------------------------------------- */

	/**
	 * Save inventory rows that changed.
	 */
	public static function handle_save_inventory() {
		self::verify( Permissions::MANAGE_INVENTORY, 'bmlt_lit_save_inventory' );
		$p    = self::post();
		$area = isset( $p['area'] ) ? absint( $p['area'] ) : 0;
		if ( ! $area || ! Permissions::can_area( $area ) ) {
			wp_die( esc_html__( 'You do not have access to that Area.', 'bmlt-literature-manager' ), '', array( 'response' => 403 ) );
		}
		$reason  = isset( $p['reason'] ) && is_scalar( $p['reason'] ) ? Helpers::limit( sanitize_text_field( $p['reason'] ), 500 ) : '';
		$rows    = ( isset( $p['inv'] ) && is_array( $p['inv'] ) ) ? $p['inv'] : array();
		$changed = 0;
		$errors  = array();
		foreach ( $rows as $item_id => $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$item_id = absint( $item_id );
			$num     = static function ( $key ) use ( $r ) {
				return ( isset( $r[ $key ] ) && is_scalar( $r[ $key ] ) && '' !== trim( (string) $r[ $key ] ) && is_numeric( $r[ $key ] ) ) ? (int) $r[ $key ] : null;
			};
			$changes = array();
			$on_hand = $num( 'on_hand' );
			$orig    = $num( 'orig_on_hand' );
			if ( null !== $on_hand && $on_hand !== $orig ) {
				$changes['set'] = $on_hand;
			}
			$delta = $num( 'delta' );
			if ( null !== $delta && 0 !== $delta ) {
				$changes['delta'] = $delta;
			}
			$low = $num( 'low' );
			if ( null !== $low && $low !== $num( 'orig_low' ) ) {
				$changes['low_stock_threshold'] = $low;
			}
			$reorder = $num( 'reorder' );
			if ( null !== $reorder && $reorder !== $num( 'orig_reorder' ) ) {
				$changes['reorder_quantity'] = $reorder;
			}
			if ( isset( $r['notes'], $r['orig_notes'] ) && is_scalar( $r['notes'] ) && (string) $r['notes'] !== (string) $r['orig_notes'] ) {
				$changes['notes'] = (string) $r['notes'];
			}
			if ( isset( $r['orig_special'] ) ) {
				$special = ! empty( $r['special'] ) ? 1 : 0;
				if ( $special !== (int) $r['orig_special'] ) {
					$changes['special'] = $special;
				}
			}
			if ( empty( $changes ) ) {
				continue;
			}
			$res = Inventory::adjust( $area, $item_id, $changes, $reason );
			if ( is_wp_error( $res ) ) {
				$errors[] = $res->get_error_message();
			} elseif ( ! empty( $res['changed'] ) ) {
				++$changed;
			}
		}
		if ( $errors ) {
			Helpers::add_notice( implode( "\n", array_unique( $errors ) ), 'error' );
		}
		/* translators: %d: number of rows. */
		Helpers::add_notice( sprintf( _n( '%d inventory record updated.', '%d inventory records updated.', $changed, 'bmlt-literature-manager' ), $changed ) );
		self::go( 'bmlt-lit-inventory', array( 'area' => $area ) );
	}

	/* ------------------------------------------------------------------
	 * Orders.
	 * ---------------------------------------------------------------- */

	/**
	 * Load an order for a handler, enforcing Area access.
	 *
	 * @param string $cap    Capability.
	 * @param string $prefix Nonce prefix.
	 * @return object
	 */
	private static function order_for_handler( $cap, $prefix ) {
		$p        = self::post();
		$order_id = isset( $p['order_id'] ) ? absint( $p['order_id'] ) : 0;
		self::verify( $cap, $prefix . $order_id );
		$order = Orders::get( $order_id );
		if ( ! $order || ! Permissions::can_area( (int) $order->area_id ) ) {
			wp_die( esc_html__( 'Order not found.', 'bmlt-literature-manager' ), '', array( 'response' => 404 ) );
		}
		return $order;
	}

	/**
	 * Change status.
	 */
	public static function handle_order_status() {
		$order  = self::order_for_handler( Permissions::MANAGE_ORDERS, 'bmlt_lit_order_' );
		$p      = self::post();
		$status = isset( $p['status'] ) && is_scalar( $p['status'] ) ? sanitize_key( $p['status'] ) : '';
		$note   = isset( $p['note'] ) && is_scalar( $p['note'] ) ? Helpers::limit( sanitize_textarea_field( $p['note'] ), 2000 ) : '';
		$res    = Orders::update_status( (int) $order->id, $status, $note );
		if ( is_wp_error( $res ) ) {
			Helpers::add_notice( $res->get_error_message(), 'error' );
		} else {
			Helpers::add_notice( __( 'Order status updated.', 'bmlt-literature-manager' ) );
			if ( ! empty( $p['notify'] ) ) {
				$tpl = 'ready' === $status ? 'ready' : ( 'waiting_special' === $status ? 'special' : '' );
				if ( $tpl ) {
					$sent = Email::send_to_requester( (int) $order->id, $tpl, $note );
					Helpers::add_notice( is_wp_error( $sent ) ? $sent->get_error_message() : __( 'Requester notified by email.', 'bmlt-literature-manager' ), is_wp_error( $sent ) ? 'error' : 'success' );
				}
			}
		}
		self::go( 'bmlt-lit-orders', array( 'order_id' => (int) $order->id ) );
	}

	/**
	 * Add a note.
	 */
	public static function handle_order_note() {
		$order = self::order_for_handler( Permissions::MANAGE_ORDERS, 'bmlt_lit_order_' );
		$p     = self::post();
		$note  = isset( $p['note'] ) && is_scalar( $p['note'] ) ? Helpers::limit( sanitize_textarea_field( $p['note'] ), 5000 ) : '';
		if ( '' === trim( $note ) ) {
			Helpers::add_notice( __( 'Please enter a note.', 'bmlt-literature-manager' ), 'error' );
		} else {
			Orders::add_note( (int) $order->id, 'note', $note );
			Helpers::add_notice( __( 'Note added.', 'bmlt-literature-manager' ) );
		}
		self::go( 'bmlt-lit-orders', array( 'order_id' => (int) $order->id ) );
	}

	/**
	 * Resend literature-worker notification.
	 */
	public static function handle_order_resend() {
		$order = self::order_for_handler( Permissions::MANAGE_ORDERS, 'bmlt_lit_order_' );
		$res   = Email::resend_worker_notification( (int) $order->id );
		Helpers::add_notice( is_wp_error( $res ) ? $res->get_error_message() : __( 'Notification resent.', 'bmlt-literature-manager' ), is_wp_error( $res ) ? 'error' : 'success' );
		self::go( 'bmlt-lit-orders', array( 'order_id' => (int) $order->id ) );
	}

	/**
	 * Email the requester.
	 */
	public static function handle_order_email() {
		$order   = self::order_for_handler( Permissions::MANAGE_ORDERS, 'bmlt_lit_order_' );
		$p       = self::post();
		$message = isset( $p['message'] ) && is_scalar( $p['message'] ) ? Helpers::limit( sanitize_textarea_field( $p['message'] ), 5000 ) : '';
		if ( '' === trim( $message ) ) {
			Helpers::add_notice( __( 'Please write a message to send.', 'bmlt-literature-manager' ), 'error' );
		} else {
			$res = Email::send_to_requester( (int) $order->id, 'message', $message );
			Helpers::add_notice( is_wp_error( $res ) ? $res->get_error_message() : __( 'Email sent to requester.', 'bmlt-literature-manager' ), is_wp_error( $res ) ? 'error' : 'success' );
		}
		self::go( 'bmlt-lit-orders', array( 'order_id' => (int) $order->id ) );
	}

	/* ------------------------------------------------------------------
	 * Import / Export.
	 * ---------------------------------------------------------------- */

	/**
	 * Download JSON.
	 */
	public static function handle_export() {
		self::verify( Permissions::MANAGE_CATALOG, 'bmlt_lit_export' );
		$p    = self::post();
		$type = isset( $p['type'] ) && is_scalar( $p['type'] ) ? sanitize_key( $p['type'] ) : 'catalog';
		if ( 'inventory' === $type ) {
			$area = isset( $p['inv_area'] ) ? absint( $p['inv_area'] ) : 0;
			if ( ! $area || ! Permissions::can_area( $area ) || ! current_user_can( Permissions::MANAGE_INVENTORY ) ) {
				wp_die( esc_html__( 'You do not have access to that Area.', 'bmlt-literature-manager' ), '', array( 'response' => 403 ) );
			}
			$data = Import_Export::export_inventory( $area );
			$name = 'bmlt-literature-inventory-' . $area . '-' . gmdate( 'Ymd-His' ) . '.json';
		} else {
			$catalog = isset( $p['catalog'] ) && is_scalar( $p['catalog'] ) ? sanitize_text_field( $p['catalog'] ) : 'all';
			$data    = Import_Export::export_catalog( self::catalog_scope( $catalog ) );
			$name    = 'bmlt-literature-catalog-' . gmdate( 'Ymd-His' ) . '.json';
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo Import_Export::to_json( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
		exit;
	}

	/**
	 * Validate and preview an import (never writes data).
	 */
	public static function handle_import_preview() {
		self::verify( Permissions::MANAGE_CATALOG, 'bmlt_lit_import_preview' );
		$p    = self::post();
		$type = isset( $p['type'] ) && is_scalar( $p['type'] ) ? sanitize_key( $p['type'] ) : 'catalog';
		$json = isset( $p['json'] ) && is_string( $p['json'] ) ? $p['json'] : '';

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.NonceVerification.Missing -- nonce verified above; file array validated below.
		$file = isset( $_FILES['json_file'] ) && is_array( $_FILES['json_file'] ) ? $_FILES['json_file'] : null;
		if ( $file && isset( $file['error'], $file['tmp_name'], $file['size'] ) && UPLOAD_ERR_OK === (int) $file['error'] ) {
			if ( (int) $file['size'] > Import_Export::MAX_BYTES ) {
				Helpers::add_notice( __( 'The uploaded file is too large (maximum 2 MB).', 'bmlt-literature-manager' ), 'error' );
				self::go( 'bmlt-lit-import' );
			}
			$name = isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '';
			if ( '' !== $name && ! preg_match( '/\.json$/i', $name ) ) {
				Helpers::add_notice( __( 'Please upload a .json file.', 'bmlt-literature-manager' ), 'error' );
				self::go( 'bmlt-lit-import' );
			}
			if ( is_uploaded_file( $file['tmp_name'] ) ) {
				$json = (string) file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}
		}

		$data = Import_Export::decode( $json );
		if ( is_wp_error( $data ) ) {
			Helpers::add_notice( $data->get_error_message(), 'error' );
			self::go( 'bmlt-lit-import' );
		}

		if ( 'inventory' === $type ) {
			if ( ! current_user_can( Permissions::MANAGE_INVENTORY ) ) {
				wp_die( esc_html__( 'You do not have permission to import inventory.', 'bmlt-literature-manager' ), '', array( 'response' => 403 ) );
			}
			$area = isset( $p['inv_area'] ) ? absint( $p['inv_area'] ) : 0;
			if ( ! Permissions::can_area( $area ) ) {
				wp_die( esc_html__( 'You do not have access to that Area.', 'bmlt-literature-manager' ), '', array( 'response' => 403 ) );
			}
			$preview = Import_Export::preview_inventory( $data, $area );
		} else {
			$target = isset( $p['target'] ) ? absint( $p['target'] ) : 0;
			if ( ! Permissions::can_area( $target ) || ( $target && ! Settings::is_selected_area( $target ) ) ) {
				wp_die( esc_html__( 'You may only import into catalogs for your own Areas.', 'bmlt-literature-manager' ), '', array( 'response' => 403 ) );
			}
			$preview = Import_Export::preview_catalog( $data, $target, ! empty( $p['update_existing'] ), ! empty( $p['deactivate_missing'] ) );
		}
		if ( is_wp_error( $preview ) ) {
			Helpers::add_notice( $preview->get_error_message(), 'error' );
			self::go( 'bmlt-lit-import' );
		}
		$token = wp_generate_password( 20, false, false );
		set_transient( 'bmlt_lit_import_' . get_current_user_id() . '_' . $token, $preview, 30 * MINUTE_IN_SECONDS );
		self::go( 'bmlt-lit-import', array( 'preview' => $token ) );
	}

	/**
	 * Apply a previewed import after explicit confirmation.
	 */
	public static function handle_import_confirm() {
		self::verify( Permissions::MANAGE_CATALOG, 'bmlt_lit_import_confirm' );
		$p     = self::post();
		$token = isset( $p['token'] ) && is_scalar( $p['token'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) $p['token'] ) : '';
		$key   = 'bmlt_lit_import_' . get_current_user_id() . '_' . $token;
		$prev  = get_transient( $key );
		if ( ! is_array( $prev ) || empty( $p['confirm'] ) ) {
			Helpers::add_notice( __( 'The import preview expired or was not confirmed. Please preview the import again.', 'bmlt-literature-manager' ), 'error' );
			self::go( 'bmlt-lit-import' );
		}
		if ( 'inventory' === $prev['type'] && ( ! current_user_can( Permissions::MANAGE_INVENTORY ) || ! Permissions::can_area( (int) $prev['target'] ) ) ) {
			wp_die( esc_html__( 'You do not have access to that Area.', 'bmlt-literature-manager' ), '', array( 'response' => 403 ) );
		}
		if ( 'catalog' === $prev['type'] && ! Permissions::can_area( (int) $prev['target'] ) ) {
			wp_die( esc_html__( 'You may only import into catalogs for your own Areas.', 'bmlt-literature-manager' ), '', array( 'response' => 403 ) );
		}
		delete_transient( $key );
		$summary = Import_Export::apply( $prev );
		Helpers::add_notice(
			sprintf(
				/* translators: 1: created, 2: updated, 3: deactivated, 4: failed. */
				__( 'Import complete. Created: %1$d. Updated: %2$d. Deactivated: %3$d. Failed: %4$d.', 'bmlt-literature-manager' ),
				$summary['created'],
				$summary['updated'],
				$summary['deactivated'],
				$summary['failed']
			),
			$summary['failed'] ? 'warning' : 'success'
		);
		self::go( 'bmlt-lit-import' );
	}

	/* ------------------------------------------------------------------
	 * BMLT.
	 * ---------------------------------------------------------------- */

	/**
	 * Validate an http(s) URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function clean_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		$url = esc_url_raw( $url, array( 'http', 'https' ) );
		return wp_http_validate_url( $url ) ? $url : '';
	}

	/**
	 * Save BMLT connection settings.
	 */
	public static function handle_save_bmlt() {
		self::verify( Permissions::MANAGE_SETTINGS, 'bmlt_lit_save_bmlt' );
		$p   = self::post();
		$old = Settings::bmlt();
		$new = $old;

		$root = self::clean_url( isset( $p['root_url'] ) ? $p['root_url'] : '' );
		if ( '' !== trim( (string) ( isset( $p['root_url'] ) ? $p['root_url'] : '' ) ) && '' === $root ) {
			Helpers::add_notice( __( 'The Root Server URL was not saved. It must be an http(s) URL of a server on the internet, on port 80, 443 or 8080. (A server on a private network or another port can be allowed with WordPress’s http_request_host_is_external and http_allowed_safe_ports filters.)', 'bmlt-literature-manager' ), 'error' );
		}
		$root = preg_replace( '#/client_interface/.*$#i', '', $root );
		$override = self::clean_url( isset( $p['endpoint_override'] ) ? $p['endpoint_override'] : '' );

		$new['root_url']          = untrailingslashit( $root );
		$new['endpoint_override'] = $override;
		$new['timeout']           = max( 3, min( 120, absint( isset( $p['timeout'] ) ? $p['timeout'] : 20 ) ) );
		$new['cache_hours']       = max( 1, min( 168, absint( isset( $p['cache_hours'] ) ? $p['cache_hours'] : 6 ) ) );
		$new['refresh_hours']     = max( 1, min( 168, absint( isset( $p['refresh_hours'] ) ? $p['refresh_hours'] : 12 ) ) );
		$new['debug']             = ! empty( $p['debug'] ) ? 1 : 0;

		Settings::update( Settings::BMLT, $new );
		Audit::log( 'settings_changed', 'settings', 0, array( 'bmlt' => $old ), array( 'bmlt' => $new ) );

		if ( $old['root_url'] !== $new['root_url'] || $old['endpoint_override'] !== $new['endpoint_override'] ) {
			Bmlt_Client::clear_cache();
		}
		if ( (int) $old['refresh_hours'] !== (int) $new['refresh_hours'] ) {
			Bmlt_Client::schedule_cron( true );
		}
		if ( (int) $old['cache_hours'] !== (int) $new['cache_hours'] ) {
			Bmlt_Client::expire_cache();
		}
		Helpers::add_notice( __( 'BMLT settings saved.', 'bmlt-literature-manager' ) );
		self::go( 'bmlt-lit-bmlt' );
	}

	/**
	 * Save selected Areas.
	 */
	public static function handle_save_area_selection() {
		self::verify( Permissions::MANAGE_SETTINGS, 'bmlt_lit_save_area_selection' );
		$p      = self::post();
		$bodies = Bmlt_Client::get_service_bodies();
		$ids    = array();
		foreach ( ( isset( $p['areas'] ) && is_array( $p['areas'] ) ) ? $p['areas'] : array() as $id ) {
			$id = absint( $id );
			// Only service bodies actually returned by BMLT (or already selected) are accepted.
			if ( $id && ( isset( $bodies[ $id ] ) || Settings::is_selected_area( $id ) ) ) {
				$ids[] = $id;
			}
		}
		$ids = array_values( array_unique( $ids ) );
		$old = Settings::bmlt();
		$new = $old;

		$new['selected_areas'] = $ids;
		Settings::update( Settings::BMLT, $new );
		Bmlt_Client::sync_area_metadata( $bodies );
		Audit::log( 'settings_changed', 'settings', 0, array( 'selected_areas' => $old['selected_areas'] ), array( 'selected_areas' => $ids ) );

		$refresh = Bmlt_Client::refresh_meetings();
		if ( is_wp_error( $refresh ) ) {
			Helpers::add_notice( __( 'Areas saved, but meetings could not be loaded from BMLT:', 'bmlt-literature-manager' ) . ' ' . $refresh->get_error_message(), 'warning' );
		} else {
			/* translators: 1: number of Areas, 2: number of meetings. */
			Helpers::add_notice( sprintf( __( 'Saved %1$d Area(s). %2$d meetings loaded from BMLT.', 'bmlt-literature-manager' ), count( $ids ), count( $refresh ) ) );
		}
		self::go( 'bmlt-lit-bmlt' );
	}

	/**
	 * Non-JS connection test.
	 */
	public static function handle_test_bmlt() {
		self::verify( Permissions::MANAGE_SETTINGS, 'bmlt_lit_test_bmlt' );
		set_transient( 'bmlt_lit_test_' . get_current_user_id(), Bmlt_Client::test_connection(), 5 * MINUTE_IN_SECONDS );
		self::go( 'bmlt-lit-bmlt' );
	}

	/**
	 * Manual refresh.
	 */
	public static function handle_refresh_bmlt() {
		self::verify( Permissions::MANAGE_SETTINGS, 'bmlt_lit_refresh_bmlt' );
		delete_transient( Bmlt_Client::BACKOFF );
		$res = Bmlt_Client::refresh_all();
		Helpers::add_notice( is_wp_error( $res ) ? __( 'Refresh failed — cached BMLT data is still being used:', 'bmlt-literature-manager' ) . ' ' . $res->get_error_message() : __( 'BMLT data refreshed.', 'bmlt-literature-manager' ), is_wp_error( $res ) ? 'error' : 'success' );
		$back = isset( $_POST['back'] ) && is_scalar( $_POST['back'] ) && 'status' === $_POST['back'] ? 'bmlt-lit-status' : 'bmlt-lit-bmlt'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		self::go( $back );
	}

	/* ------------------------------------------------------------------
	 * SMTP.
	 * ---------------------------------------------------------------- */

	/**
	 * Save SMTP settings.
	 */
	public static function handle_save_smtp() {
		self::verify( Permissions::MANAGE_SETTINGS, 'bmlt_lit_save_smtp' );
		Smtp::save( self::post() );
		Helpers::add_notice( __( 'SMTP settings saved.', 'bmlt-literature-manager' ) );
		self::go( 'bmlt-lit-smtp' );
	}

	/**
	 * Send a test email.
	 */
	public static function handle_test_email() {
		self::verify( Permissions::MANAGE_SETTINGS, 'bmlt_lit_test_email' );
		$p   = self::post();
		$res = Smtp::send_test( isset( $p['test_to'] ) ? (string) $p['test_to'] : '' );
		if ( is_wp_error( $res ) ) {
			Helpers::add_notice( __( 'The test email could not be sent:', 'bmlt-literature-manager' ) . ' ' . $res->get_error_message(), 'error' );
		} else {
			Helpers::add_notice( __( 'Test email sent. Check the inbox (and spam folder) of the recipient.', 'bmlt-literature-manager' ) );
		}
		self::go( 'bmlt-lit-smtp' );
	}

	/* ------------------------------------------------------------------
	 * Settings.
	 * ---------------------------------------------------------------- */

	/**
	 * Save a settings tab.
	 */
	public static function handle_save_settings() {
		self::verify( Permissions::MANAGE_SETTINGS, 'bmlt_lit_save_settings' );
		$p   = self::post();
		$tab = isset( $p['tab'] ) && is_scalar( $p['tab'] ) ? sanitize_key( $p['tab'] ) : 'general';

		if ( 'areas' === $tab ) {
			$res = self::save_areas_tab( $p );
		} elseif ( 'templates' === $tab ) {
			$res = self::save_templates_tab( $p );
		} else {
			$res = self::save_general_tab( $p, $tab );
		}
		if ( is_wp_error( $res ) ) {
			Helpers::add_notice( $res->get_error_message(), 'error' );
		} else {
			Helpers::add_notice( __( 'Settings saved.', 'bmlt-literature-manager' ) );
		}
		self::go( 'bmlt-lit-settings', array( 'tab' => $tab ) );
	}

	/**
	 * General / Email / Advanced tabs.
	 *
	 * @param array  $p   POST.
	 * @param string $tab Tab.
	 * @return true|\WP_Error
	 */
	private static function save_general_tab( array $p, $tab ) {
		$old = Settings::general();
		$new = $old;
		$txt = static function ( $key, $max = 500 ) use ( $p ) {
			return isset( $p[ $key ] ) && is_scalar( $p[ $key ] ) ? Helpers::limit( sanitize_text_field( $p[ $key ] ), $max ) : '';
		};
		$area = static function ( $key, $max = 2000 ) use ( $p ) {
			return isset( $p[ $key ] ) && is_scalar( $p[ $key ] ) ? Helpers::limit( sanitize_textarea_field( $p[ $key ] ), $max ) : '';
		};
		$int = static function ( $key, $min, $max, $def ) use ( $p ) {
			return isset( $p[ $key ] ) && is_numeric( $p[ $key ] ) ? max( $min, min( $max, (int) $p[ $key ] ) ) : $def;
		};
		$chk = static function ( $key ) use ( $p ) {
			return ! empty( $p[ $key ] ) ? 1 : 0;
		};

		if ( 'general' === $tab ) {
			$new['org_name']                 = $txt( 'org_name', 200 );
			$new['currency_symbol']          = Helpers::limit( $txt( 'currency_symbol', 8 ), 8 );
			$new['currency_position']        = 'after' === $txt( 'currency_position' ) ? 'after' : 'before';
			$new['decimals']                 = $int( 'decimals', 0, 4, 2 );
			$new['decimal_sep']              = in_array( $txt( 'decimal_sep' ), array( '.', ',' ), true ) ? $txt( 'decimal_sep' ) : '.';
			$ts                              = isset( $p['thousands_sep'] ) && is_scalar( $p['thousands_sep'] ) ? (string) $p['thousands_sep'] : ',';
			$new['thousands_sep']            = in_array( $ts, array( ',', '.', ' ', '' ), true ) ? $ts : ',';
			if ( $new['thousands_sep'] === $new['decimal_sep'] ) {
				$new['thousands_sep'] = '.' === $new['decimal_sep'] ? ',' : '.';
			}
			$new['delivery_message']         = $area( 'delivery_message', 1000 );
			$new['worker_delivery_message']  = $area( 'worker_delivery_message', 1000 );
			$new['special_order_message']    = $area( 'special_order_message', 1000 );
			$new['special_order_turnaround'] = $txt( 'special_order_turnaround', 100 );
			$new['reservation_mode']         = in_array( $txt( 'reservation_mode' ), array( 'reserve', 'deduct', 'none' ), true ) ? $txt( 'reservation_mode' ) : 'reserve';
			$new['excess_behavior']          = 'whole_line' === $txt( 'excess_behavior' ) ? 'whole_line' : 'split';
			$new['order_prefix']             = substr( strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $txt( 'order_prefix', 10 ) ) ), 0, 10 );
			if ( '' === $new['order_prefix'] ) {
				$new['order_prefix'] = 'LIT';
			}
			$new['require_name']      = $chk( 'require_name' );
			$new['require_phone']     = $chk( 'require_phone' );
			$new['require_email']     = $chk( 'require_email' );
			$new['default_low_stock'] = $int( 'default_low_stock', 0, 10000, 2 );
			$new['max_qty_per_line']  = $int( 'max_qty_per_line', 1, 10000, 500 );
			foreach ( array( 'delivery_message', 'special_order_message', 'special_order_turnaround' ) as $k ) {
				if ( '' === trim( $new[ $k ] ) ) {
					$defaults  = Settings::general_defaults();
					$new[ $k ] = $defaults[ $k ];
				}
			}
		} elseif ( 'email' === $tab ) {
			$new['email_from_name']        = Helpers::header_safe( $txt( 'email_from_name', 120 ) );
			$from                          = sanitize_email( isset( $p['email_from_address'] ) ? (string) $p['email_from_address'] : '' );
			$new['email_from_address']     = is_email( $from ) ? $from : '';
			$new['global_chair_email']     = Helpers::sanitize_email_list( isset( $p['global_chair_email'] ) ? (string) $p['global_chair_email'] : '' );
			$new['copy_global_chair']      = $chk( 'copy_global_chair' );
			$new['admin_copy']             = $chk( 'admin_copy' );
			$new['admin_copy_email']       = Helpers::sanitize_email_list( isset( $p['admin_copy_email'] ) ? (string) $p['admin_copy_email'] : '' );
			$new['requester_confirmation'] = $chk( 'requester_confirmation' );
			$new['notify_requester_ready'] = $chk( 'notify_requester_ready' );
		} elseif ( 'advanced' === $tab ) {
			$new['rate_limit_count']         = $int( 'rate_limit_count', 1, 1000, 5 );
			$new['rate_limit_minutes']       = $int( 'rate_limit_minutes', 1, 1440, 15 );
			$new['min_submit_seconds']       = $int( 'min_submit_seconds', 0, 120, 3 );
			$new['delete_data_on_uninstall'] = $chk( 'delete_data_on_uninstall' );
		}

		$res      = Settings::update( Settings::GENERAL, $new );
		$diff_old = array_diff_assoc( array_map( 'strval', $old ), array_map( 'strval', $new ) );
		$diff_new = array_intersect_key( $new, $diff_old );
		if ( $diff_old && ! is_wp_error( $res ) ) {
			Audit::log( 'settings_changed', 'settings', 0, $diff_old, $diff_new );
		}
		return $res;
	}

	/**
	 * Area configuration tab.
	 *
	 * @param array $p POST.
	 */
	private static function save_areas_tab( array $p ) {
		$config = Settings::areas_config();
		$input  = ( isset( $p['area'] ) && is_array( $p['area'] ) ) ? $p['area'] : array();
		foreach ( Settings::selected_areas() as $id ) {
			if ( ! isset( $input[ $id ] ) || ! is_array( $input[ $id ] ) ) {
				continue;
			}
			$r    = $input[ $id ];
			$cur  = isset( $config[ $id ] ) && is_array( $config[ $id ] ) ? $config[ $id ] : array();
			$get  = static function ( $k ) use ( $r ) {
				return isset( $r[ $k ] ) && is_scalar( $r[ $k ] ) ? (string) $r[ $k ] : '';
			};
			$date = $get( 'next_service_meeting' );
			$web  = self::clean_url( $get( 'website' ) );

			$config[ $id ] = array_merge(
				$cur,
				array(
					'display_name'          => Helpers::limit( sanitize_text_field( $get( 'display_name' ) ), 200 ),
					'worker_emails'         => Helpers::sanitize_email_list( $get( 'worker_emails' ) ),
					'chair_email'           => Helpers::sanitize_email_list( $get( 'chair_email' ) ),
					'special_order_emails'  => Helpers::sanitize_email_list( $get( 'special_order_emails' ) ),
					'website'               => $web,
					'phone'                 => Helpers::limit( sanitize_text_field( $get( 'phone' ) ), 50 ),
					'delivery_instructions' => Helpers::limit( sanitize_textarea_field( $get( 'delivery_instructions' ) ), 1000 ),
					'service_meeting_info'  => Helpers::limit( sanitize_text_field( $get( 'service_meeting_info' ) ), 300 ),
					'next_service_meeting'  => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '',
					'track_inventory'       => '0' === $get( 'track_inventory' ) ? 0 : 1,
				)
			);
		}
		$res = Settings::update( Settings::AREAS, $config );
		if ( ! is_wp_error( $res ) ) {
			Audit::log( 'settings_changed', 'settings', 0, null, array( 'areas_config' => array_keys( $config ) ) );
		}
		return $res;
	}

	/**
	 * Email templates tab.
	 *
	 * @param array $p POST.
	 */
	private static function save_templates_tab( array $p ) {
		$saved = Settings::raw( Settings::TEMPLATES );
		$input = ( isset( $p['tpl'] ) && is_array( $p['tpl'] ) ) ? $p['tpl'] : array();
		foreach ( array_keys( Settings::template_labels() ) as $key ) {
			if ( ! empty( $p['reset'][ $key ] ) ) {
				unset( $saved[ $key ] );
				continue;
			}
			if ( ! isset( $input[ $key ] ) || ! is_array( $input[ $key ] ) ) {
				continue;
			}
			$subject       = isset( $input[ $key ]['subject'] ) && is_scalar( $input[ $key ]['subject'] ) ? Helpers::header_safe( sanitize_text_field( (string) $input[ $key ]['subject'] ) ) : '';
			$body          = isset( $input[ $key ]['body'] ) && is_scalar( $input[ $key ]['body'] ) ? wp_kses_post( (string) $input[ $key ]['body'] ) : '';
			$saved[ $key ] = array(
				'subject' => Helpers::limit( $subject, 300 ),
				'body'    => Helpers::limit( $body, 50000 ),
			);
		}
		$res = Settings::update( Settings::TEMPLATES, $saved );
		if ( ! is_wp_error( $res ) ) {
			Audit::log( 'settings_changed', 'settings', 0, null, array( 'templates' => array_keys( $saved ) ) );
		}
		return $res;
	}

	/**
	 * Save per-user capabilities and Area restrictions (administrators only).
	 */
	public static function handle_save_permissions() {
		self::verify( 'manage_options', 'bmlt_lit_save_permissions' );
		$p        = self::post();
		$caps     = Permissions::caps();
		$selected = Settings::selected_areas();
		$users    = array();

		if ( ! empty( $p['add_user'] ) && is_scalar( $p['add_user'] ) ) {
			$login = sanitize_text_field( (string) $p['add_user'] );
			$user  = is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login );
			if ( $user ) {
				$users[ $user->ID ] = array(
					'caps'  => array( Permissions::VIEW_ORDERS, Permissions::MANAGE_ORDERS, Permissions::MANAGE_INVENTORY ),
					'areas' => array(),
				);
			} else {
				Helpers::add_notice( __( 'No user was found with that username or email.', 'bmlt-literature-manager' ), 'error' );
			}
		}

		$rows = ( isset( $p['perm'] ) && is_array( $p['perm'] ) ) ? $p['perm'] : array();
		foreach ( $rows as $uid => $row ) {
			$uid = absint( $uid );
			if ( ! $uid || ! is_array( $row ) || isset( $users[ $uid ] ) ) {
				continue;
			}
			$users[ $uid ] = array(
				'caps'  => array_values( array_intersect( $caps, array_map( 'sanitize_key', isset( $row['caps'] ) && is_array( $row['caps'] ) ? $row['caps'] : array() ) ) ),
				'areas' => array_values( array_intersect( $selected, array_map( 'absint', isset( $row['areas'] ) && is_array( $row['areas'] ) ? $row['areas'] : array() ) ) ),
			);
		}

		$save = array();
		foreach ( $users as $uid => $set ) {
			$user = get_userdata( $uid );
			if ( ! $user || user_can( $user, 'manage_options' ) ) {
				continue; // Administrators always have every capability.
			}
			// Store only what the user's roles do not already grant, so role changes keep working.
			$from_roles = array();
			foreach ( (array) $user->roles as $role_name ) {
				$role = get_role( $role_name );
				if ( $role ) {
					$from_roles = array_merge( $from_roles, array_keys( array_filter( $role->capabilities ) ) );
				}
			}
			$set['caps']  = array_values( array_diff( $set['caps'], $from_roles ) );
			$save[ $uid ] = $set;
			Audit::log( 'permissions_changed', 'user', $uid, Permissions::grants_for( $uid ), $set );
		}
		$res = Permissions::save_grants( $save );
		if ( is_wp_error( $res ) ) {
			Helpers::add_notice( $res->get_error_message(), 'error' );
			self::go( 'bmlt-lit-settings', array( 'tab' => 'permissions' ) );
		}
		Helpers::add_notice( __( 'Permissions saved.', 'bmlt-literature-manager' ) );
		self::go( 'bmlt-lit-settings', array( 'tab' => 'permissions' ) );
	}

	/* ------------------------------------------------------------------
	 * Tools.
	 * ---------------------------------------------------------------- */

	/**
	 * Clear and rebuild BMLT cache.
	 */
	public static function handle_clear_cache() {
		self::verify( Permissions::MANAGE_SETTINGS, 'bmlt_lit_clear_cache' );
		Bmlt_Client::clear_cache();
		$res = Bmlt_Client::refresh_all();
		Helpers::add_notice( is_wp_error( $res ) ? __( 'Cache cleared, but BMLT could not be reached to rebuild it:', 'bmlt-literature-manager' ) . ' ' . $res->get_error_message() : __( 'BMLT cache cleared and rebuilt.', 'bmlt-literature-manager' ), is_wp_error( $res ) ? 'warning' : 'success' );
		self::go( 'bmlt-lit-status' );
	}

	/**
	 * Verify the data directory and rebuild the order index from the month files.
	 */
	public static function handle_repair_store() {
		self::verify( Permissions::MANAGE_SETTINGS, 'bmlt_lit_repair_store' );
		$ready = Store::ensure_dir();
		if ( is_wp_error( $ready ) ) {
			Helpers::add_notice( $ready->get_error_message(), 'error' );
			self::go( 'bmlt-lit-status' );
		}
		Permissions::install_roles();
		Store::upgrade_layout();
		Store::snapshot( 'pre-repair' );
		$res = Orders::rebuild_index();
		if ( is_wp_error( $res ) ) {
			Helpers::add_notice( $res->get_error_message(), 'error' );
		} else {
			/* translators: 1: orders, 2: month files. */
			Helpers::add_notice( sprintf( __( 'Storage verified. Order index rebuilt from %2$d month file(s): %1$d orders. No data was removed.', 'bmlt-literature-manager' ), $res['orders'], $res['months'] ) );
		}
		self::go( 'bmlt-lit-status' );
	}

	/**
	 * Download a full JSON backup.
	 */
	public static function handle_backup_download() {
		self::verify( Permissions::MANAGE_SETTINGS, 'bmlt_lit_backup_download' );
		$json = Import_Export::to_json( Import_Export::export_backup() );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="bmlt-literature-backup-' . gmdate( 'Ymd-His' ) . '.json"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
		exit;
	}

	/**
	 * Pending (validated, unconfirmed) restore for the current user.
	 *
	 * @return array|null
	 */
	private static function pending_restore() {
		$p = get_transient( 'bmlt_lit_restore_' . get_current_user_id() );
		return is_array( $p ) ? array(
			'counts' => $p['counts'],
			'site'   => $p['site'],
		) : null;
	}

	/**
	 * Restore a full backup: step 1 validates and previews, step 2 (confirm) replaces all data.
	 * Restricted to site administrators.
	 */
	public static function handle_restore_backup() {
		self::verify( 'manage_options', 'bmlt_lit_restore_backup' );
		$p   = self::post();
		$key = 'bmlt_lit_restore_' . get_current_user_id();

		if ( ! empty( $p['cancel'] ) ) {
			delete_transient( $key );
			self::go( 'bmlt-lit-status' );
		}

		if ( ! empty( $p['confirm'] ) ) {
			$pending = get_transient( $key );
			$typed   = isset( $p['confirm_text'] ) && is_scalar( $p['confirm_text'] ) ? strtoupper( trim( (string) $p['confirm_text'] ) ) : '';
			if ( ! is_array( $pending ) || 'RESTORE' !== $typed ) {
				Helpers::add_notice( __( 'Restore not confirmed. Type RESTORE to confirm, or upload the backup again.', 'bmlt-literature-manager' ), 'error' );
				self::go( 'bmlt-lit-status' );
			}
			delete_transient( $key );
			$res = Import_Export::restore_backup( $pending );
			Helpers::add_notice( is_wp_error( $res ) ? $res->get_error_message() : __( 'Backup restored. The previous data was saved as a snapshot in the backups folder.', 'bmlt-literature-manager' ), is_wp_error( $res ) ? 'error' : 'success' );
			self::go( 'bmlt-lit-status' );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.NonceVerification.Missing -- nonce verified above; validated below.
		$file = isset( $_FILES['backup_file'] ) && is_array( $_FILES['backup_file'] ) ? $_FILES['backup_file'] : null;
		if ( ! $file || ! isset( $file['error'], $file['tmp_name'], $file['size'] ) || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			Helpers::add_notice( __( 'Please choose a backup file to upload.', 'bmlt-literature-manager' ), 'error' );
			self::go( 'bmlt-lit-status' );
		}
		if ( (int) $file['size'] > Import_Export::MAX_BACKUP_BYTES ) {
			Helpers::add_notice( __( 'The backup file is too large.', 'bmlt-literature-manager' ), 'error' );
			self::go( 'bmlt-lit-status' );
		}
		$raw  = (string) file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = json_decode( preg_replace( '/^\xEF\xBB\xBF/', '', $raw ), true, 512 );
		if ( ! is_array( $data ) ) {
			Helpers::add_notice( __( 'The backup file is not valid JSON.', 'bmlt-literature-manager' ), 'error' );
			self::go( 'bmlt-lit-status' );
		}
		$valid = Import_Export::validate_backup( $data );
		if ( is_wp_error( $valid ) ) {
			Helpers::add_notice( $valid->get_error_message(), 'error' );
			self::go( 'bmlt-lit-status' );
		}
		set_transient( $key, $valid, 30 * MINUTE_IN_SECONDS );
		self::go( 'bmlt-lit-status', array( 'restore' => 'review' ) );
	}

	/**
	 * Re-run migration from the v1.0 database tables.
	 */
	public static function handle_migrate_db() {
		self::verify( 'manage_options', 'bmlt_lit_migrate_db' );
		$res = Migrator::migrate();
		if ( is_wp_error( $res ) ) {
			Helpers::add_notice( $res->get_error_message(), 'error' );
		} else {
			/* translators: 1: items, 2: inventory rows, 3: orders, 4: audit entries, 5: users. */
			Helpers::add_notice( sprintf( __( 'Migration complete: %1$d items, %2$d inventory records, %3$d orders, %4$d audit entries, %5$d user permission sets. The database tables were left untouched.', 'bmlt-literature-manager' ), $res['items'], $res['inventory'], $res['orders'], $res['audit'], $res['users'] ) );
		}
		self::go( 'bmlt-lit-status' );
	}

	/**
	 * Remove v1.0 tables/options after migration.
	 */
	public static function handle_drop_legacy() {
		self::verify( 'manage_options', 'bmlt_lit_drop_legacy' );
		$res = Migrator::drop_legacy();
		Helpers::add_notice( is_wp_error( $res ) ? $res->get_error_message() : __( 'Version 1.0 database tables and options were removed.', 'bmlt-literature-manager' ), is_wp_error( $res ) ? 'error' : 'success' );
		self::go( 'bmlt-lit-status' );
	}

	/**
	 * Mark the data directory as belonging to this site.
	 */
	public static function handle_adopt_site() {
		self::verify( 'manage_options', 'bmlt_lit_adopt_site' );
		$res = Settings::adopt_site();
		Helpers::add_notice( is_wp_error( $res ) ? $res->get_error_message() : __( 'The literature data is now marked as belonging to this site.', 'bmlt-literature-manager' ), is_wp_error( $res ) ? 'error' : 'success' );
		self::go( 'bmlt-lit-status' );
	}
}
