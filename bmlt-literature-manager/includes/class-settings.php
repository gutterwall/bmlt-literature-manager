<?php
/**
 * Plugin-wide settings stored in the JSON data store (settings.json.php), not in wp_options,
 * so that database pushes between environments never overwrite them.
 *
 * @package BMLT_Lit
 * @version 6.2.0
 */

namespace BMLT_Lit;

defined( 'ABSPATH' ) || exit;

/**
 * Typed access to plugin options with defaults.
 */
class Settings {

	const GENERAL   = 'general';
	const BMLT      = 'bmlt';
	const AREAS     = 'areas';
	const SMTP      = 'smtp';
	const TEMPLATES = 'templates';

	/**
	 * Store document holding all settings.
	 */
	const DOC = 'settings';

	/**
	 * Current storage schema version.
	 */
	const SCHEMA = 2;

	/**
	 * Request-level cache.
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Clear the request cache (after updates).
	 */
	public static function flush() {
		self::$cache = array();
		Store::flush_cache();
	}

	/**
	 * Raw section from the settings document.
	 *
	 * @param string $section Section key.
	 * @return array
	 */
	public static function raw( $section ) {
		$doc = Store::get( self::DOC );
		return ( isset( $doc[ $section ] ) && is_array( $doc[ $section ] ) ) ? $doc[ $section ] : array();
	}

	/**
	 * Replace a settings section (atomic, locked write).
	 *
	 * @param string $section  Section key (GENERAL, BMLT, AREAS, SMTP, TEMPLATES).
	 * @param array  $value    New value.
	 * @param bool   $autoload Ignored (kept for backwards compatibility).
	 * @return true|\WP_Error
	 */
	public static function update( $section, $value, $autoload = true ) {
		unset( $autoload );
		$section = sanitize_key( $section );
		$result  = Store::tx(
			static function () use ( $section, $value ) {
				$doc             = self::with_meta( Store::get( self::DOC ) );
				$doc[ $section ] = is_array( $value ) ? $value : array();
				return Store::put( self::DOC, $doc );
			}
		);
		self::flush();
		if ( is_wp_error( $result ) ) {
			Helpers::log( 'Settings could not be saved.', array( 'error' => $result->get_error_message() ) );
		}
		return $result;
	}

	/**
	 * Ensure the settings document carries identity metadata.
	 *
	 * @param array $doc Document.
	 * @return array
	 */
	public static function with_meta( array $doc ) {
		if ( empty( $doc['meta'] ) || ! is_array( $doc['meta'] ) ) {
			$doc['meta'] = array();
		}
		$doc['meta'] = wp_parse_args(
			$doc['meta'],
			array(
				'schema'   => self::SCHEMA,
				'site_url' => home_url( '/' ),
				'created'  => time(),
				'store_id' => wp_generate_password( 16, false, false ),
			)
		);
		return $doc;
	}

	/**
	 * Identity metadata of the data store.
	 *
	 * @return array
	 */
	public static function meta() {
		$doc = Store::get( self::DOC );
		return ( isset( $doc['meta'] ) && is_array( $doc['meta'] ) ) ? $doc['meta'] : array();
	}

	/**
	 * Whether the data store was created by a different site URL (e.g. staging data on production).
	 *
	 * @return string The other site URL, or '' when it matches.
	 */
	public static function foreign_site() {
		$meta = self::meta();
		if ( empty( $meta['site_url'] ) ) {
			return '';
		}
		$norm = static function ( $u ) {
			return untrailingslashit( strtolower( preg_replace( '#^https?://(www\.)?#i', '', (string) $u ) ) );
		};
		return $norm( $meta['site_url'] ) === $norm( home_url( '/' ) ) ? '' : (string) $meta['site_url'];
	}

	/**
	 * Mark the data store as belonging to this site.
	 *
	 * @return true|\WP_Error
	 */
	public static function adopt_site() {
		$result = Store::tx(
			static function () {
				$doc                     = self::with_meta( Store::get( self::DOC ) );
				$doc['meta']['site_url'] = home_url( '/' );
				$doc['meta']['adopted']  = time();
				return Store::put( self::DOC, $doc );
			}
		);
		self::flush();
		return $result;
	}

	/**
	 * Fetch a section merged with defaults.
	 *
	 * @param string $section  Section.
	 * @param array  $defaults Defaults.
	 * @return array
	 */
	private static function merged( $section, array $defaults ) {
		if ( ! isset( self::$cache[ $section ] ) ) {
			self::$cache[ $section ] = wp_parse_args( self::raw( $section ), $defaults );
		}
		return self::$cache[ $section ];
	}

	/* ------------------------------------------------------------------
	 * General settings.
	 * ---------------------------------------------------------------- */

	/**
	 * General defaults.
	 *
	 * @return array
	 */
	public static function general_defaults() {
		return array(
			'org_name'                 => get_bloginfo( 'name' ),
			'currency_symbol'          => '$',
			'currency_position'        => 'before',
			'decimals'                 => 2,
			'decimal_sep'              => '.',
			'thousands_sep'            => ',',
			'delivery_message'         => __( 'In-stock literature can normally be received at the next Area Service meeting for your Area.', 'bmlt-literature-manager' ),
			'worker_delivery_message'  => __( 'In-stock literature may be delivered at the next Area Service meeting for this Area.', 'bmlt-literature-manager' ),
			'special_order_message'    => __( 'Special Order – Allow approximately one month for ordering and stocking before delivery.', 'bmlt-literature-manager' ),
			'special_order_turnaround' => __( 'approximately one month', 'bmlt-literature-manager' ),
			'reservation_mode'         => 'reserve',
			'excess_behavior'          => 'split',
			'order_prefix'             => 'LIT',
			'requester_confirmation'   => 1,
			'require_name'             => 1,
			'require_phone'            => 1,
			'require_email'            => 1,
			'default_low_stock'        => 2,
			'max_qty_per_line'         => 500,
			'rate_limit_count'         => 5,
			'rate_limit_minutes'       => 15,
			'min_submit_seconds'       => 3,
			'email_from_name'          => get_bloginfo( 'name' ),
			'email_from_address'       => get_option( 'admin_email' ),
			'global_chair_email'       => '',
			'copy_global_chair'        => 0,
			'admin_copy'               => 0,
			'admin_copy_email'         => get_option( 'admin_email' ),
			'notify_requester_ready'   => 1,
			'delete_data_on_uninstall' => 0,
		);
	}

	/**
	 * General settings.
	 *
	 * @return array
	 */
	public static function general() {
		return self::merged( self::GENERAL, self::general_defaults() );
	}

	/**
	 * Single general setting.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$s = self::general();
		return isset( $s[ $key ] ) ? $s[ $key ] : null;
	}

	/* ------------------------------------------------------------------
	 * BMLT settings.
	 * ---------------------------------------------------------------- */

	/**
	 * BMLT defaults.
	 *
	 * @return array
	 */
	public static function bmlt_defaults() {
		return array(
			'root_url'          => '',
			'endpoint_override' => '',
			'timeout'           => 20,
			'cache_hours'       => 6,
			'refresh_hours'     => 12,
			'debug'             => 0,
			'selected_areas'    => array(),
		);
	}

	/**
	 * BMLT settings.
	 *
	 * @return array
	 */
	public static function bmlt() {
		return self::merged( self::BMLT, self::bmlt_defaults() );
	}

	/**
	 * Selected (managed) Area service body IDs.
	 *
	 * @return int[]
	 */
	public static function selected_areas() {
		$b   = self::bmlt();
		$ids = is_array( $b['selected_areas'] ) ? array_map( 'absint', $b['selected_areas'] ) : array();
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Whether an Area is managed by this installation.
	 *
	 * @param int $area_id Area ID.
	 * @return bool
	 */
	public static function is_selected_area( $area_id ) {
		return in_array( absint( $area_id ), self::selected_areas(), true );
	}

	/* ------------------------------------------------------------------
	 * Area configuration.
	 * ---------------------------------------------------------------- */

	/**
	 * Defaults for one Area.
	 *
	 * @return array
	 */
	public static function area_defaults() {
		return array(
			// BMLT metadata (refreshed from BMLT).
			'name'                  => '',
			'parent_id'             => 0,
			'parent_name'           => '',
			'type'                  => '',
			'description'           => '',
			'bmlt_url'              => '',
			'bmlt_helpline'         => '',
			// Administrator configuration.
			'display_name'          => '',
			'worker_emails'         => '',
			'chair_email'           => '',
			'special_order_emails'  => '',
			'website'               => '',
			'phone'                 => '',
			'delivery_instructions' => '',
			'service_meeting_info'  => '',
			'next_service_meeting'  => '',
			'track_inventory'       => 1,
		);
	}

	/**
	 * Whether an Area tracks inventory quantities. When it doesn't, each item is simply marked
	 * "special order" or not for that Area.
	 *
	 * @param int $area_id Area ID.
	 * @return bool
	 */
	public static function area_tracks_inventory( $area_id ) {
		$a = self::area( $area_id );
		return 1 === (int) $a['track_inventory'];
	}

	/**
	 * Raw Area configuration keyed by Area ID.
	 *
	 * @return array
	 */
	public static function areas_config() {
		return self::raw( self::AREAS );
	}

	/**
	 * Configuration for one Area merged with defaults.
	 *
	 * @param int $area_id Area ID.
	 * @return array
	 */
	public static function area( $area_id ) {
		$all  = self::areas_config();
		$area = isset( $all[ $area_id ] ) && is_array( $all[ $area_id ] ) ? $all[ $area_id ] : array();
		return wp_parse_args( $area, self::area_defaults() );
	}

	/**
	 * Display name for an Area.
	 *
	 * @param int $area_id Area ID.
	 * @return string
	 */
	public static function area_name( $area_id ) {
		$a = self::area( $area_id );
		if ( '' !== trim( (string) $a['display_name'] ) ) {
			return (string) $a['display_name'];
		}
		if ( '' !== trim( (string) $a['name'] ) ) {
			return (string) $a['name'];
		}
		/* translators: %d: BMLT service body ID. */
		return sprintf( __( 'Area #%d', 'bmlt-literature-manager' ), absint( $area_id ) );
	}

	/**
	 * Map of selected Area IDs to display names, sorted by name.
	 *
	 * @param int[]|null $limit_to Optional subset of IDs.
	 * @return array
	 */
	public static function area_options( $limit_to = null ) {
		$out = array();
		foreach ( self::selected_areas() as $id ) {
			if ( is_array( $limit_to ) && ! in_array( $id, $limit_to, true ) ) {
				continue;
			}
			$out[ $id ] = self::area_name( $id );
		}
		asort( $out, SORT_NATURAL | SORT_FLAG_CASE );
		return $out;
	}

	/**
	 * Next explicitly configured service meeting date if in the future.
	 *
	 * @param int $area_id Area ID.
	 * @return string Formatted date or empty string.
	 */
	public static function next_service_meeting( $area_id ) {
		$a    = self::area( $area_id );
		$date = (string) $a['next_service_meeting'];
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return '';
		}
		if ( $date < wp_date( 'Y-m-d' ) ) {
			return '';
		}
		$ts = strtotime( $date . ' 12:00:00' );
		return $ts ? date_i18n( get_option( 'date_format' ), $ts ) : '';
	}

	/* ------------------------------------------------------------------
	 * SMTP.
	 * ---------------------------------------------------------------- */

	/**
	 * SMTP defaults.
	 *
	 * @return array
	 */
	public static function smtp_defaults() {
		return array(
			'enabled'    => 0,
			'host'       => '',
			'port'       => 587,
			'encryption' => 'tls',
			'auth'       => 1,
			'username'   => '',
			'password'   => '',
			'from_email' => '',
			'from_name'  => '',
			'force_from' => 0,
			'reply_to'   => '',
		);
	}

	/**
	 * SMTP settings (password remains encrypted here).
	 *
	 * @return array
	 */
	public static function smtp() {
		return self::merged( self::SMTP, self::smtp_defaults() );
	}

	/* ------------------------------------------------------------------
	 * Email templates.
	 * ---------------------------------------------------------------- */

	/**
	 * Template keys and labels.
	 *
	 * @return array
	 */
	public static function template_labels() {
		return array(
			'worker'    => __( 'Literature-worker order notification', 'bmlt-literature-manager' ),
			'admin'     => __( 'Administrator notification', 'bmlt-literature-manager' ),
			'requester' => __( 'Requester confirmation', 'bmlt-literature-manager' ),
			'ready'     => __( 'Order-ready notification (to requester)', 'bmlt-literature-manager' ),
			'special'   => __( 'Special-order notification (to requester)', 'bmlt-literature-manager' ),
			'message'   => __( 'Message to requester (from order screen)', 'bmlt-literature-manager' ),
		);
	}

	/**
	 * Default templates.
	 *
	 * @return array
	 */
	public static function template_defaults() {
		$details = '<table cellpadding="6" cellspacing="0" border="0">'
			. '<tr><th align="left">' . esc_html__( 'Order Number', 'bmlt-literature-manager' ) . '</th><td>{order_number}</td></tr>'
			. '<tr><th align="left">' . esc_html__( 'Area', 'bmlt-literature-manager' ) . '</th><td>{area_name}</td></tr>'
			. '<tr><th align="left">' . esc_html__( 'Meeting Name', 'bmlt-literature-manager' ) . '</th><td>{meeting_name}<br>{meeting_details}</td></tr>'
			. '<tr><th align="left">' . esc_html__( 'Contact Name', 'bmlt-literature-manager' ) . '</th><td>{contact_name}</td></tr>'
			. '<tr><th align="left">' . esc_html__( 'Phone Number', 'bmlt-literature-manager' ) . '</th><td>{contact_phone}</td></tr>'
			. '<tr><th align="left">' . esc_html__( 'Email Address', 'bmlt-literature-manager' ) . '</th><td>{contact_email}</td></tr>'
			. '<tr><th align="left">' . esc_html__( 'Order Date', 'bmlt-literature-manager' ) . '</th><td>{order_date}</td></tr>'
			. '<tr><th align="left">' . esc_html__( 'Notes', 'bmlt-literature-manager' ) . '</th><td>{notes}</td></tr>'
			. '</table>';

		return array(
			'worker'    => array(
				/* translators: Do not translate placeholders in braces. */
				'subject' => __( '[{area_name}] Literature Order {order_number}{special_order_flag}', 'bmlt-literature-manager' ),
				'body'    => '{special_order_notice}<h2>' . esc_html__( 'New Literature Order', 'bmlt-literature-manager' ) . ' {order_number}</h2>' . $details
					. '<h3>' . esc_html__( 'Literature Ordered', 'bmlt-literature-manager' ) . '</h3>{order_items}'
					. '<p>{delivery_message}</p><p><a href="{order_link}">' . esc_html__( 'Open this order', 'bmlt-literature-manager' ) . '</a></p>',
			),
			'admin'     => array(
				'subject' => __( '[Admin copy] Literature Order {order_number} – {area_name}{special_order_flag}', 'bmlt-literature-manager' ),
				'body'    => '{special_order_notice}<h2>' . esc_html__( 'Literature Order (administrator copy)', 'bmlt-literature-manager' ) . ' {order_number}</h2>' . $details
					. '{order_items}<p>{delivery_message}</p><p><a href="{order_link}">' . esc_html__( 'Open this order', 'bmlt-literature-manager' ) . '</a></p>',
			),
			'requester' => array(
				'subject' => __( 'Your literature order {order_number} has been received', 'bmlt-literature-manager' ),
				'body'    => '<p>' . esc_html__( 'Hello', 'bmlt-literature-manager' ) . ' {contact_name},</p><p>'
					. esc_html__( 'Thank you. Your literature order has been received by the literature team for', 'bmlt-literature-manager' ) . ' {area_name}.</p>'
					. '{special_order_notice}' . $details . '{order_items}<p>{delivery_message}</p><p>{organization_name}</p>',
			),
			'ready'     => array(
				'subject' => __( 'Your literature order {order_number} is ready', 'bmlt-literature-manager' ),
				'body'    => '<p>' . esc_html__( 'Hello', 'bmlt-literature-manager' ) . ' {contact_name},</p><p>'
					. esc_html__( 'Your literature order is ready.', 'bmlt-literature-manager' ) . '</p><p>{delivery_message}</p>{order_items}<p>{worker_message}</p><p>{organization_name}</p>',
			),
			'special'   => array(
				'subject' => __( 'Special-order update for literature order {order_number}', 'bmlt-literature-manager' ),
				'body'    => '<p>' . esc_html__( 'Hello', 'bmlt-literature-manager' ) . ' {contact_name},</p>{special_order_notice}<p>'
					. esc_html__( 'Your order includes special-order literature that is being obtained for you.', 'bmlt-literature-manager' ) . '</p>{order_items}<p>{worker_message}</p><p>{organization_name}</p>',
			),
			'message'   => array(
				'subject' => __( 'About your literature order {order_number}', 'bmlt-literature-manager' ),
				'body'    => '<p>' . esc_html__( 'Hello', 'bmlt-literature-manager' ) . ' {contact_name},</p><p>{worker_message}</p><hr>' . $details . '{order_items}<p>{organization_name}</p>',
			),
		);
	}

	/**
	 * One template (subject/body).
	 *
	 * @param string $key Template key.
	 * @return array
	 */
	public static function template( $key ) {
		$defaults = self::template_defaults();
		$saved    = self::raw( self::TEMPLATES );
		$base     = isset( $defaults[ $key ] ) ? $defaults[ $key ] : array(
			'subject' => '',
			'body'    => '',
		);
		if ( isset( $saved[ $key ] ) && is_array( $saved[ $key ] ) ) {
			if ( isset( $saved[ $key ]['subject'] ) && '' !== trim( $saved[ $key ]['subject'] ) ) {
				$base['subject'] = $saved[ $key ]['subject'];
			}
			if ( isset( $saved[ $key ]['body'] ) && '' !== trim( $saved[ $key ]['body'] ) ) {
				$base['body'] = $saved[ $key ]['body'];
			}
		}
		return $base;
	}

	/**
	 * Supported placeholders with descriptions.
	 *
	 * @return array
	 */
	public static function placeholders() {
		return array(
			'{order_number}'         => __( 'Order number', 'bmlt-literature-manager' ),
			'{order_date}'           => __( 'Order date/time', 'bmlt-literature-manager' ),
			'{order_status}'         => __( 'Current order status', 'bmlt-literature-manager' ),
			'{area_name}'            => __( 'Area name', 'bmlt-literature-manager' ),
			'{meeting_name}'         => __( 'Meeting name', 'bmlt-literature-manager' ),
			'{meeting_details}'      => __( 'Meeting day, time and location', 'bmlt-literature-manager' ),
			'{contact_name}'         => __( 'Requester name', 'bmlt-literature-manager' ),
			'{contact_phone}'        => __( 'Requester phone', 'bmlt-literature-manager' ),
			'{contact_email}'        => __( 'Requester email', 'bmlt-literature-manager' ),
			'{notes}'                => __( 'Requester notes', 'bmlt-literature-manager' ),
			'{order_items}'          => __( 'Table of ordered literature', 'bmlt-literature-manager' ),
			'{item_count}'           => __( 'Total number of items', 'bmlt-literature-manager' ),
			'{subtotal}'             => __( 'Order subtotal', 'bmlt-literature-manager' ),
			'{total}'                => __( 'Order total', 'bmlt-literature-manager' ),
			'{special_order_notice}' => __( 'Special-order warning block (empty if none)', 'bmlt-literature-manager' ),
			'{special_order_flag}'   => __( 'Short “SPECIAL ORDER” subject flag (empty if none)', 'bmlt-literature-manager' ),
			'{delivery_message}'     => __( 'Area Service delivery message', 'bmlt-literature-manager' ),
			'{worker_message}'       => __( 'Message typed by the literature worker', 'bmlt-literature-manager' ),
			'{order_link}'           => __( 'Link to the order (staff only)', 'bmlt-literature-manager' ),
			'{organization_name}'    => __( 'Organization name', 'bmlt-literature-manager' ),
			'{site_name}'            => __( 'Website name', 'bmlt-literature-manager' ),
		);
	}
}
