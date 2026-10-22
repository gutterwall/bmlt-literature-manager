<?php
/**
 * Shared helpers.
 *
 * @package BMLT_Lit
 * @version 7.0.0
 */

namespace BMLT_Lit;

defined( 'ABSPATH' ) || exit;

/**
 * Formatting, validation and misc. utilities.
 */
class Helpers {

	/**
	 * Asset version string: plugin version + file modification time, so browsers and caching
	 * plugins always fetch updated CSS/JS after the plugin is updated.
	 *
	 * @param string $relative Path relative to the plugin directory.
	 * @return string
	 */
	public static function asset_version( $relative ) {
		$file  = BMLT_LIT_DIR . ltrim( (string) $relative, '/' );
		$mtime = is_readable( $file ) ? (int) filemtime( $file ) : 0;
		return BMLT_LIT_VERSION . ( $mtime ? '.' . $mtime : '' );
	}

	/**
	 * Current UTC time in MySQL format.
	 *
	 * @return string
	 */
	public static function now_gmt() {
		return current_time( 'mysql', true );
	}

	/**
	 * Convert a stored UTC datetime to a localized display string.
	 *
	 * @param string $gmt    UTC datetime.
	 * @param string $format Optional PHP date format.
	 * @return string
	 */
	public static function local_date( $gmt, $format = '' ) {
		if ( empty( $gmt ) || '0000-00-00 00:00:00' === $gmt ) {
			return '';
		}
		if ( '' === $format ) {
			$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		}
		return get_date_from_gmt( $gmt, $format );
	}

	/**
	 * Convert a local Y-m-d date to a UTC datetime boundary.
	 *
	 * @param string $date Y-m-d.
	 * @param bool   $end  Whether to return the end-of-day boundary (start of next day).
	 * @return string Empty if invalid.
	 */
	public static function local_date_to_gmt( $date, $end = false ) {
		$date = (string) $date;
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return '';
		}
		$parts = array_map( 'intval', explode( '-', $date ) );
		if ( ! checkdate( $parts[1], $parts[2], $parts[0] ) ) {
			return '';
		}
		try {
			$dt = new \DateTime( $date . ' 00:00:00', wp_timezone() );
			if ( $end ) {
				$dt->modify( '+1 day' );
			}
			$dt->setTimezone( new \DateTimeZone( 'UTC' ) );
			return $dt->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return '';
		}
	}

	/**
	 * Format a money amount.
	 *
	 * @param float|string $amount Amount.
	 * @return string
	 */
	public static function format_money( $amount ) {
		$s        = Settings::general();
		$decimals = max( 0, min( 4, (int) $s['decimals'] ) );
		$num      = number_format( (float) $amount, $decimals, (string) $s['decimal_sep'], (string) $s['thousands_sep'] );
		return 'after' === $s['currency_position'] ? $num . $s['currency_symbol'] : $s['currency_symbol'] . $num;
	}

	/**
	 * Strictly parse a money value. Returns float rounded to 2 decimals or false.
	 *
	 * @param mixed $value Input.
	 * @return float|false
	 */
	public static function parse_money( $value ) {
		if ( is_int( $value ) ) {
			return $value >= 0 ? (float) $value : false;
		}
		if ( is_float( $value ) ) {
			if ( $value < 0 || $value > 999999999 ) {
				return false;
			}
			$rounded = round( $value, 2 );
			return abs( $rounded - $value ) < 0.000001 ? $rounded : false;
		}
		if ( ! is_string( $value ) ) {
			return false;
		}
		$s      = Settings::general();
		$value  = trim( $value );
		$symbol = (string) $s['currency_symbol'];
		if ( '' !== $symbol ) {
			$value = str_replace( $symbol, '', $value );
		}
		$value = str_replace( array( ' ', "\xc2\xa0" ), '', $value );
		if ( ',' === $s['decimal_sep'] ) {
			$value = str_replace( '.', '', $value );
			$value = str_replace( ',', '.', $value );
		} else {
			$value = str_replace( ',', '', $value );
		}
		if ( ! preg_match( '/^\d{1,9}(\.\d{1,2})?$/', $value ) ) {
			return false;
		}
		return round( (float) $value, 2 );
	}

	/**
	 * Money value formatted for SQL decimal columns.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	public static function sql_money( $amount ) {
		return number_format( (float) $amount, 2, '.', '' );
	}

	/**
	 * Convert to integer cents.
	 *
	 * @param float|string $amount Amount.
	 * @return int
	 */
	public static function to_cents( $amount ) {
		return (int) round( ( (float) $amount ) * 100 );
	}

	/**
	 * Stock status definitions (icon + label so colour is never the only cue).
	 *
	 * @return array
	 */
	public static function stock_statuses() {
		return array(
			'in_stock'      => array(
				'icon'  => '✓',
				'label' => __( 'In Stock', 'bmlt-literature-manager' ),
			),
			'low_stock'     => array(
				'icon'  => '⚠',
				'label' => __( 'Low Stock', 'bmlt-literature-manager' ),
			),
			'special_order' => array(
				'icon'  => '!',
				'label' => __( 'Special Order', 'bmlt-literature-manager' ),
			),
			'partial'       => array(
				'icon'  => '!',
				'label' => __( 'Partly Special Order', 'bmlt-literature-manager' ),
			),
			'inactive'      => array(
				'icon'  => '✕',
				'label' => __( 'Inactive', 'bmlt-literature-manager' ),
			),
		);
	}

	/**
	 * Escaped stock badge HTML.
	 *
	 * @param string $status Status key.
	 * @param string $extra  Optional extra text.
	 * @return string
	 */
	public static function stock_badge( $status, $extra = '' ) {
		$all = self::stock_statuses();
		if ( ! isset( $all[ $status ] ) ) {
			$status = 'special_order';
		}
		$html  = '<span class="bmlt-lit-stock bmlt-lit-stock--' . esc_attr( $status ) . '">';
		$html .= '<span class="bmlt-lit-stock__icon" aria-hidden="true">' . esc_html( $all[ $status ]['icon'] ) . '</span> ';
		$html .= '<span class="bmlt-lit-stock__label">' . esc_html( $all[ $status ]['label'] ) . '</span>';
		if ( '' !== $extra ) {
			$html .= ' <span class="bmlt-lit-stock__extra">' . esc_html( $extra ) . '</span>';
		}
		$html .= '</span>';
		return $html;
	}

	/**
	 * Order statuses.
	 *
	 * @return array
	 */
	public static function order_statuses() {
		return array(
			'new'             => __( 'New', 'bmlt-literature-manager' ),
			'reviewed'        => __( 'Reviewed', 'bmlt-literature-manager' ),
			'waiting_special' => __( 'Waiting for Special Order', 'bmlt-literature-manager' ),
			'ready'           => __( 'Ready', 'bmlt-literature-manager' ),
			'partial'         => __( 'Partially Fulfilled', 'bmlt-literature-manager' ),
			'fulfilled'       => __( 'Fulfilled', 'bmlt-literature-manager' ),
			'cancelled'       => __( 'Cancelled', 'bmlt-literature-manager' ),
		);
	}

	/**
	 * Label for an order status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function order_status_label( $status ) {
		$all = self::order_statuses();
		return isset( $all[ $status ] ) ? $all[ $status ] : (string) $status;
	}

	/**
	 * Statuses that still need work.
	 *
	 * @return string[]
	 */
	public static function open_statuses() {
		return array( 'new', 'reviewed', 'waiting_special', 'ready', 'partial' );
	}

	/**
	 * Localized weekday from BMLT weekday (1 = Sunday … 7 = Saturday).
	 *
	 * @param int $day Weekday.
	 * @return string
	 */
	public static function weekday_name( $day ) {
		global $wp_locale;
		$day = (int) $day;
		if ( $day < 1 || $day > 7 || ! $wp_locale ) {
			return '';
		}
		return $wp_locale->get_weekday( $day - 1 );
	}

	/**
	 * Format HH:MM in the site's time format.
	 *
	 * @param string $time HH:MM or HH:MM:SS.
	 * @return string
	 */
	public static function format_time( $time ) {
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})/', (string) $time, $m ) ) {
			return '';
		}
		$ts = gmmktime( (int) $m[1], (int) $m[2], 0, 1, 1, 2000 );
		return gmdate( get_option( 'time_format' ), $ts );
	}

	/**
	 * Human-readable meeting day/time.
	 *
	 * @param int    $day  BMLT weekday.
	 * @param string $time HH:MM.
	 * @return string
	 */
	public static function meeting_when( $day, $time ) {
		return trim( self::weekday_name( $day ) . ' ' . self::format_time( $time ) );
	}

	/**
	 * Parse a comma/newline separated list of email addresses.
	 *
	 * @param string|array $value Input.
	 * @return string[]
	 */
	public static function parse_email_list( $value ) {
		if ( is_array( $value ) ) {
			$value = implode( ',', $value );
		}
		$parts = preg_split( '/[\s,;]+/', (string) $value );
		$out   = array();
		foreach ( (array) $parts as $p ) {
			$email = sanitize_email( $p );
			if ( '' !== $email && is_email( $email ) ) {
				$out[ strtolower( $email ) ] = $email;
			}
		}
		return array_values( $out );
	}

	/**
	 * Sanitize an email list to a normalized comma-separated string.
	 *
	 * @param string $value Input.
	 * @return string
	 */
	public static function sanitize_email_list( $value ) {
		return implode( ', ', self::parse_email_list( $value ) );
	}

	/**
	 * Privacy-conscious hash of the requester's IP address.
	 *
	 * @return string
	 */
	public static function client_ip_hash() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Filter the client IP used for rate limiting (e.g. behind a trusted proxy).
		 *
		 * @param string $ip IP address.
		 */
		$ip = (string) apply_filters( 'bmlt_lit_client_ip', $ip );
		if ( '' === $ip ) {
			return '';
		}
		return hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) );
	}

	/**
	 * Safe debug logging. Never pass credentials or unnecessary personal data.
	 *
	 * @param string $message Message.
	 * @param array  $context Context (scalars only).
	 */
	public static function log( $message, array $context = array() ) {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ) {
			return;
		}
		foreach ( array( 'password', 'pass', 'smtp_password', 'username' ) as $secret ) {
			unset( $context[ $secret ] );
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[BMLT Literature Manager] ' . $message . ( $context ? ' ' . wp_json_encode( $context ) : '' ) );
	}

	/**
	 * Add a flash notice for the current user (shown on next admin page load).
	 *
	 * @param string $message Plain-text message.
	 * @param string $type    success|error|warning|info.
	 */
	public static function add_notice( $message, $type = 'success' ) {
		$key     = 'bmlt_lit_notices_' . get_current_user_id();
		$notices = get_transient( $key );
		$notices = is_array( $notices ) ? $notices : array();
		$notices[] = array(
			'type'    => in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ? $type : 'info',
			'message' => (string) $message,
		);
		set_transient( $key, $notices, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Retrieve and clear flash notices.
	 *
	 * @return array
	 */
	public static function take_notices() {
		$key     = 'bmlt_lit_notices_' . get_current_user_id();
		$notices = get_transient( $key );
		delete_transient( $key );
		return is_array( $notices ) ? $notices : array();
	}

	/**
	 * Remove line breaks from a value used in an email header.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function header_safe( $value ) {
		return trim( str_replace( array( "\r", "\n", '%0a', '%0d' ), ' ', (string) $value ) );
	}

	/**
	 * Truncate a string to a maximum length (multibyte-safe).
	 *
	 * @param string $value Value.
	 * @param int    $max   Max characters.
	 * @return string
	 */
	public static function limit( $value, $max ) {
		$value = (string) $value;
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max );
		}
		return substr( $value, 0, $max );
	}

	/**
	 * Meeting label for select boxes.
	 *
	 * @param array $m Normalized meeting.
	 * @return string
	 */
	public static function meeting_label( array $m ) {
		$parts = array( $m['name'] );
		if ( ! empty( $m['municipality'] ) ) {
			$parts[] = $m['municipality'];
		}
		$when = self::meeting_when( $m['weekday'], $m['start_time'] );
		if ( '' !== $when ) {
			$parts[] = $when;
		}
		return implode( ' — ', $parts );
	}
}
