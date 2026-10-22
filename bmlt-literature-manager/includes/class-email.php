<?php
/**
 * Email notifications.
 *
 * @package BMLT_Lit
 * @version 7.0.0
 */

namespace BMLT_Lit;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and sends order emails (HTML with plain-text alternative).
 */
class Email {

	/**
	 * Plain-text body to attach as AltBody for the current send.
	 *
	 * @var string|null
	 */
	private static $alt_body = null;

	/**
	 * Last wp_mail error message (redacted).
	 *
	 * @var string
	 */
	public static $last_error = '';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'phpmailer_init', array( __CLASS__, 'apply_alt_body' ), 999 );
	}

	/**
	 * Attach the plain-text alternative only for this plugin's own messages.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer.
	 */
	public static function apply_alt_body( $phpmailer ) {
		if ( null !== self::$alt_body ) {
			$phpmailer->AltBody = self::$alt_body; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
	}

	/**
	 * Capture wp_mail errors.
	 *
	 * @param \WP_Error $error Error.
	 */
	public static function capture_error( $error ) {
		if ( is_wp_error( $error ) ) {
			self::$last_error = Smtp::redact( $error->get_error_message() );
		}
	}

	/**
	 * Error captured from the last wp_mail() call (set by capture_error()).
	 *
	 * @return string
	 */
	public static function last_error() {
		return (string) self::$last_error;
	}

	/**
	 * Send an email.
	 *
	 * @param string|string[] $to       Recipients.
	 * @param string          $subject  Subject.
	 * @param string          $html     HTML body (already safe).
	 * @param string          $text     Plain-text body.
	 * @param string          $reply_to Optional reply-to address.
	 * @return true|\WP_Error
	 */
	public static function send( $to, $subject, $html, $text, $reply_to = '' ) {
		$recipients = Helpers::parse_email_list( (array) $to );
		if ( empty( $recipients ) ) {
			return new \WP_Error( 'no_recipients', __( 'No valid email recipients are configured.', 'bmlt-literature-manager' ) );
		}
		$s       = Settings::general();
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$from    = sanitize_email( (string) $s['email_from_address'] );
		if ( $from && is_email( $from ) ) {
			$name      = Helpers::header_safe( wp_specialchars_decode( (string) $s['email_from_name'], ENT_QUOTES ) );
			$name      = str_replace( array( '<', '>', '"' ), '', $name );
			$headers[] = '' !== $name ? sprintf( 'From: "%s" <%s>', $name, $from ) : 'From: ' . $from;
		}
		$reply_to = sanitize_email( (string) $reply_to );
		if ( $reply_to && is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		self::$alt_body   = $text;
		self::$last_error = '';
		add_action( 'wp_mail_failed', array( __CLASS__, 'capture_error' ) );
		$sent = wp_mail( $recipients, Helpers::header_safe( $subject ), $html, $headers );
		remove_action( 'wp_mail_failed', array( __CLASS__, 'capture_error' ) );
		self::$alt_body = null;

		if ( ! $sent ) {
			$message = '' !== self::last_error() ? self::last_error() : __( 'WordPress could not send the email.', 'bmlt-literature-manager' );
			Helpers::log( 'Email send failed', array( 'error' => $message ) );
			return new \WP_Error( 'mail_failed', $message );
		}
		return true;
	}

	/**
	 * Special-order notice (HTML).
	 *
	 * @param object[] $items Order items.
	 * @return string
	 */
	public static function special_notice_html( array $items ) {
		$special = array_filter(
			$items,
			static function ( $i ) {
				return (int) $i->quantity_special > 0;
			}
		);
		if ( empty( $special ) ) {
			return '';
		}
		$turn  = (string) Settings::get( 'special_order_turnaround' );
		$html  = '<div style="border:3px solid #b32d2e;background:#fcf0f1;padding:14px 16px;margin:0 0 18px;">';
		$html .= '<p style="margin:0 0 6px;font-size:18px;font-weight:bold;color:#8a1f1f;">! ' . esc_html__( 'SPECIAL ORDER NOTICE', 'bmlt-literature-manager' ) . '</p>';
		$html .= '<p style="margin:0 0 6px;font-weight:bold;">' . esc_html__( 'THIS ORDER CONTAINS SPECIAL ORDER ITEMS', 'bmlt-literature-manager' ) . '</p>';
		$html .= '<p style="margin:0 0 8px;"><strong>' . esc_html(
			sprintf(
				/* translators: %s: turnaround, e.g. "approximately one month". */
				__( 'This order contains literature that is not currently in stock. Special-order literature may take %s to order and stock before delivery.', 'bmlt-literature-manager' ),
				$turn
			)
		) . '</strong></p>';
		$html .= '<p style="margin:0 0 4px;">' . esc_html__( 'Special-order items:', 'bmlt-literature-manager' ) . '</p><ul style="margin:0;padding-left:20px;">';
		foreach ( $special as $i ) {
			$html .= '<li><strong>' . esc_html( $i->sku ) . '</strong> – ' . esc_html( $i->name ) . ' – '
				/* translators: %d: quantity. */
				. esc_html( sprintf( __( '%d special order', 'bmlt-literature-manager' ), (int) $i->quantity_special ) )
				. ( (int) $i->quantity_in_stock > 0
					/* translators: %d: quantity. */
					? ' (' . esc_html( sprintf( __( '%d from stock', 'bmlt-literature-manager' ), (int) $i->quantity_in_stock ) ) . ')'
					: '' )
				. '</li>';
		}
		$html .= '</ul></div>';
		return $html;
	}

	/**
	 * Special-order notice (plain text).
	 *
	 * @param object[] $items Order items.
	 * @return string
	 */
	public static function special_notice_text( array $items ) {
		$lines = array();
		foreach ( $items as $i ) {
			if ( (int) $i->quantity_special > 0 ) {
				/* translators: 1: SKU, 2: name, 3: quantity. */
				$lines[] = sprintf( __( '  * %1$s – %2$s – %3$d special order', 'bmlt-literature-manager' ), $i->sku, $i->name, (int) $i->quantity_special );
			}
		}
		if ( empty( $lines ) ) {
			return '';
		}
		$turn = (string) Settings::get( 'special_order_turnaround' );
		return "========================================\n"
			. '! ' . __( 'SPECIAL ORDER NOTICE', 'bmlt-literature-manager' ) . "\n"
			. __( 'THIS ORDER CONTAINS SPECIAL ORDER ITEMS', 'bmlt-literature-manager' ) . "\n"
			/* translators: %s: turnaround. */
			. sprintf( __( 'This order contains literature that is not currently in stock. Special-order literature may take %s to order and stock before delivery.', 'bmlt-literature-manager' ), $turn ) . "\n"
			. __( 'Special-order items:', 'bmlt-literature-manager' ) . "\n" . implode( "\n", $lines ) . "\n"
			. "========================================\n\n";
	}

	/**
	 * Stock wording for a line.
	 *
	 * @param object $i Order item.
	 * @return string
	 */
	private static function line_stock_text( $i ) {
		$special = (int) $i->quantity_special;
		$stock   = (int) $i->quantity_in_stock;
		if ( $special > 0 && $stock > 0 ) {
			/* translators: 1: in-stock quantity, 2: special-order quantity. */
			return sprintf( __( '%1$d in stock / %2$d SPECIAL ORDER', 'bmlt-literature-manager' ), $stock, $special );
		}
		if ( $special > 0 ) {
			return __( 'SPECIAL ORDER', 'bmlt-literature-manager' );
		}
		return 'low_stock' === $i->stock_status ? __( 'In Stock (low)', 'bmlt-literature-manager' ) : __( 'In Stock', 'bmlt-literature-manager' );
	}

	/**
	 * Items table (HTML).
	 *
	 * @param object   $order Order.
	 * @param object[] $items Items.
	 * @return string
	 */
	public static function items_html( $order, array $items ) {
		$th   = 'style="text-align:left;padding:6px 8px;border-bottom:2px solid #333;background:#f0f0f1;"';
		$td   = 'padding:6px 8px;border-bottom:1px solid #ddd;vertical-align:top;';
		$html = '<table cellspacing="0" cellpadding="0" style="border-collapse:collapse;width:100%;max-width:720px;font-size:14px;">';
		$html .= '<thead><tr><th ' . $th . '>' . esc_html__( 'SKU', 'bmlt-literature-manager' ) . '</th><th ' . $th . '>' . esc_html__( 'Literature', 'bmlt-literature-manager' ) . '</th><th ' . $th . '>' . esc_html__( 'Qty', 'bmlt-literature-manager' ) . '</th><th ' . $th . '>' . esc_html__( 'Unit Price', 'bmlt-literature-manager' ) . '</th><th ' . $th . '>' . esc_html__( 'Line Total', 'bmlt-literature-manager' ) . '</th><th ' . $th . '>' . esc_html__( 'Stock Status', 'bmlt-literature-manager' ) . '</th></tr></thead><tbody>';
		foreach ( $items as $i ) {
			$special = (int) $i->quantity_special > 0;
			$bg      = $special ? 'background:#fcf0f1;' : '';
			$stock   = self::line_stock_text( $i );
			$html   .= '<tr>';
			$html   .= '<td style="' . $td . $bg . '">' . esc_html( $i->sku ) . '</td>';
			$html   .= '<td style="' . $td . $bg . '">' . esc_html( $i->name ) . '</td>';
			$html   .= '<td style="' . $td . $bg . '">' . (int) $i->quantity . '</td>';
			$html   .= '<td style="' . $td . $bg . '">' . esc_html( Helpers::format_money( $i->unit_price ) ) . '</td>';
			$html   .= '<td style="' . $td . $bg . '">' . esc_html( Helpers::format_money( $i->line_total ) ) . '</td>';
			$html   .= '<td style="' . $td . $bg . ( $special ? 'color:#8a1f1f;font-weight:bold;' : 'color:#1e6b34;' ) . '">' . ( $special ? '! ' : '✓ ' ) . esc_html( $stock ) . '</td>';
			$html   .= '</tr>';
		}
		$html .= '</tbody><tfoot>';
		$html .= '<tr><td colspan="4" style="padding:6px 8px;text-align:right;">' . esc_html__( 'Items', 'bmlt-literature-manager' ) . '</td><td colspan="2" style="padding:6px 8px;">' . (int) $order->item_count . '</td></tr>';
		$html .= '<tr><td colspan="4" style="padding:6px 8px;text-align:right;">' . esc_html__( 'Subtotal', 'bmlt-literature-manager' ) . '</td><td colspan="2" style="padding:6px 8px;">' . esc_html( Helpers::format_money( $order->subtotal ) ) . '</td></tr>';
		$html .= '<tr><td colspan="4" style="padding:6px 8px;text-align:right;font-weight:bold;">' . esc_html__( 'Total', 'bmlt-literature-manager' ) . '</td><td colspan="2" style="padding:6px 8px;font-weight:bold;">' . esc_html( Helpers::format_money( $order->total ) ) . '</td></tr>';
		$html .= '</tfoot></table>';
		return $html;
	}

	/**
	 * Items table (plain text).
	 *
	 * @param object   $order Order.
	 * @param object[] $items Items.
	 * @return string
	 */
	public static function items_text( $order, array $items ) {
		$out = '';
		foreach ( $items as $i ) {
			$out .= sprintf(
				"- [%s] %s\n    %s: %d  x  %s  =  %s\n    %s: %s%s\n",
				$i->sku,
				$i->name,
				__( 'Qty', 'bmlt-literature-manager' ),
				(int) $i->quantity,
				Helpers::format_money( $i->unit_price ),
				Helpers::format_money( $i->line_total ),
				__( 'Stock', 'bmlt-literature-manager' ),
				(int) $i->quantity_special > 0 ? '!! ' : '',
				self::line_stock_text( $i )
			);
		}
		$out .= "\n" . __( 'Items', 'bmlt-literature-manager' ) . ': ' . (int) $order->item_count . "\n";
		$out .= __( 'Subtotal', 'bmlt-literature-manager' ) . ': ' . Helpers::format_money( $order->subtotal ) . "\n";
		$out .= __( 'Total', 'bmlt-literature-manager' ) . ': ' . Helpers::format_money( $order->total ) . "\n";
		return $out;
	}

	/**
	 * Delivery message for an order (empty when nothing is in stock).
	 *
	 * @param object   $order    Order.
	 * @param object[] $items    Items.
	 * @param bool     $internal Use the literature-worker wording.
	 * @return string Plain text.
	 */
	public static function delivery_message( $order, array $items, $internal ) {
		$any_stock = false;
		foreach ( $items as $i ) {
			if ( (int) $i->quantity_in_stock > 0 ) {
				$any_stock = true;
				break;
			}
		}
		if ( ! $any_stock ) {
			return '';
		}
		$msg  = (string) Settings::get( $internal ? 'worker_delivery_message' : 'delivery_message' );
		$area = Settings::area( (int) $order->area_id );
		if ( '' !== trim( (string) $area['delivery_instructions'] ) ) {
			$msg .= ' ' . $area['delivery_instructions'];
		}
		if ( '' !== trim( (string) $area['service_meeting_info'] ) ) {
			$msg .= ' ' . __( 'Area Service meeting:', 'bmlt-literature-manager' ) . ' ' . $area['service_meeting_info'];
		}
		$next = Settings::next_service_meeting( (int) $order->area_id );
		if ( '' !== $next ) {
			/* translators: %s: date. */
			$msg .= ' ' . sprintf( __( 'Next Area Service meeting: %s.', 'bmlt-literature-manager' ), $next );
		}
		return trim( $msg );
	}

	/**
	 * Placeholder values.
	 *
	 * @param object   $order Order.
	 * @param object[] $items Items.
	 * @param string   $mode  html|text.
	 * @param array    $extra Extra raw values: worker_message, internal (bool).
	 * @return array
	 */
	public static function context( $order, array $items, $mode, array $extra = array() ) {
		$internal = ! empty( $extra['internal'] );
		$html     = 'html' === $mode;
		$e        = static function ( $v ) use ( $html ) {
			return $html ? esc_html( (string) $v ) : (string) $v;
		};
		$nl2br    = static function ( $v ) use ( $html ) {
			return $html ? nl2br( esc_html( (string) $v ) ) : (string) $v;
		};
		$link     = admin_url( 'admin.php?page=bmlt-lit-orders&order_id=' . (int) $order->id );
		$details  = trim( Helpers::meeting_when( (int) $order->meeting_day, (string) $order->meeting_time ) . ( $order->meeting_location ? ' – ' . $order->meeting_location : '' ) );

		return array(
			'{order_number}'         => $e( $order->order_number ),
			'{order_date}'           => $e( Helpers::local_date( $order->created_at ) ),
			'{order_status}'         => $e( Helpers::order_status_label( $order->status ) ),
			'{area_name}'            => $e( $order->area_name ),
			'{meeting_name}'         => $e( $order->meeting_name ),
			'{meeting_details}'      => $e( $details ),
			'{contact_name}'         => $e( $order->contact_name ),
			'{contact_phone}'        => $e( $order->contact_phone ),
			'{contact_email}'        => $e( $order->contact_email ),
			'{notes}'                => $nl2br( '' !== trim( (string) $order->notes ) ? $order->notes : __( '(none)', 'bmlt-literature-manager' ) ),
			'{order_items}'          => $html ? self::items_html( $order, $items ) : self::items_text( $order, $items ),
			'{item_count}'           => (string) (int) $order->item_count,
			'{subtotal}'             => $e( Helpers::format_money( $order->subtotal ) ),
			'{total}'                => $e( Helpers::format_money( $order->total ) ),
			'{special_order_notice}' => $html ? self::special_notice_html( $items ) : self::special_notice_text( $items ),
			'{special_order_flag}'   => (int) $order->has_special_order ? ' – ' . __( 'SPECIAL ORDER', 'bmlt-literature-manager' ) : '',
			'{delivery_message}'     => $e( self::delivery_message( $order, $items, $internal ) ),
			'{worker_message}'       => $nl2br( isset( $extra['worker_message'] ) ? $extra['worker_message'] : '' ),
			'{order_link}'           => $internal ? ( $html ? esc_url( $link ) : $link ) : '',
			'{organization_name}'    => $e( Settings::get( 'org_name' ) ),
			'{site_name}'            => $e( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
		);
	}

	/**
	 * Convert template HTML to plain text (before placeholder substitution).
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function html_to_text( $html ) {
		$html = preg_replace( '/<a\s[^>]*href=["\']\{order_link\}["\'][^>]*>(.*?)<\/a>/is', '$1: {order_link}', $html );
		$html = preg_replace( '/<\s*br\s*\/?>/i', "\n", $html );
		$html = preg_replace( '/<\/(p|div|h[1-6]|tr|li|table|ul|ol)>/i', "\n", $html );
		$html = preg_replace( '/<\/(th|td)>/i', "  ", $html );
		$html = preg_replace( '/<hr[^>]*>/i', "\n----------------------------------------\n", $html );
		$text = wp_strip_all_tags( $html, false );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( "/[ \t]+\n/", "\n", $text );
		return trim( preg_replace( "/\n{3,}/", "\n\n", $text ) );
	}

	/**
	 * Render a template.
	 *
	 * @param string   $key   Template key.
	 * @param object   $order Order.
	 * @param object[] $items Items.
	 * @param array    $extra Extra values.
	 * @return array { subject, html, text }
	 */
	public static function render( $key, $order, array $items, array $extra = array() ) {
		$tpl       = Settings::template( $key );
		$html_ctx  = self::context( $order, $items, 'html', $extra );
		$text_ctx  = self::context( $order, $items, 'text', $extra );
		$subject   = Helpers::header_safe( strtr( wp_strip_all_tags( (string) $tpl['subject'] ), $text_ctx ) );
		$body_html = strtr( (string) $tpl['body'], $html_ctx );
		$body_text = strtr( self::html_to_text( (string) $tpl['body'] ), $text_ctx );
		return array(
			'subject' => $subject,
			'html'    => self::wrap( $body_html, $subject ),
			'text'    => $body_text,
		);
	}

	/**
	 * Wrap a body in a simple, client-friendly HTML layout.
	 *
	 * @param string $body    Body HTML.
	 * @param string $subject Subject.
	 * @return string
	 */
	public static function wrap( $body, $subject ) {
		return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $subject ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:#f4f5f7;">'
			. '<div style="max-width:760px;margin:0 auto;padding:20px;background:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#1d2327;">'
			. $body
			. '<p style="margin-top:24px;font-size:12px;color:#646970;">' . esc_html( (string) Settings::get( 'org_name' ) ) . '</p>'
			. '</div></body></html>';
	}

	/**
	 * Recipients for an order's literature-worker notification (Area-specific routing).
	 *
	 * @param object $order Order.
	 * @return string[]
	 */
	public static function worker_recipients( $order ) {
		$area = Settings::area( (int) $order->area_id );
		$list = Helpers::parse_email_list( $area['worker_emails'] );
		$list = array_merge( $list, Helpers::parse_email_list( $area['chair_email'] ) );
		if ( (int) $order->has_special_order ) {
			$list = array_merge( $list, Helpers::parse_email_list( $area['special_order_emails'] ) );
		}
		if ( Settings::get( 'copy_global_chair' ) ) {
			$list = array_merge( $list, Helpers::parse_email_list( (string) Settings::get( 'global_chair_email' ) ) );
		}
		$list = Helpers::parse_email_list( $list );
		if ( empty( $list ) ) {
			// Never lose an order: fall back to the global chair or site admin.
			$list = Helpers::parse_email_list( (string) Settings::get( 'global_chair_email' ) );
			if ( empty( $list ) ) {
				$list = Helpers::parse_email_list( (string) get_option( 'admin_email' ) );
			}
		}
		/**
		 * Filter literature-worker recipients for an order.
		 *
		 * @param string[] $list  Recipients.
		 * @param object   $order Order.
		 */
		return (array) apply_filters( 'bmlt_lit_worker_recipients', $list, $order );
	}

	/**
	 * Send all notifications for a newly created order.
	 *
	 * @param int $order_id Order.
	 * @return array Results keyed by type.
	 */
	public static function send_order_notifications( $order_id ) {
		$order = Orders::get( $order_id );
		if ( ! $order ) {
			return array();
		}
		$items   = Orders::get_items( $order->id );
		$results = array();
		$log     = array();

		$workers = self::worker_recipients( $order );
		$r       = self::render( 'worker', $order, $items, array( 'internal' => true ) );
		$results['worker'] = self::send( $workers, $r['subject'], $r['html'], $r['text'], $order->contact_email );
		/* translators: %d: number of recipients. */
		$log[] = is_wp_error( $results['worker'] ) ? __( 'Literature-worker notification FAILED:', 'bmlt-literature-manager' ) . ' ' . $results['worker']->get_error_message() : sprintf( __( 'Literature-worker notification sent to %d recipient(s).', 'bmlt-literature-manager' ), count( $workers ) );

		if ( Settings::get( 'admin_copy' ) ) {
			$admin = Helpers::parse_email_list( (string) Settings::get( 'admin_copy_email' ) );
			if ( $admin ) {
				$r                = self::render( 'admin', $order, $items, array( 'internal' => true ) );
				$results['admin'] = self::send( $admin, $r['subject'], $r['html'], $r['text'], $order->contact_email );
				$log[]            = is_wp_error( $results['admin'] ) ? __( 'Administrator copy FAILED.', 'bmlt-literature-manager' ) : __( 'Administrator copy sent.', 'bmlt-literature-manager' );
			}
		}

		if ( Settings::get( 'requester_confirmation' ) && is_email( $order->contact_email ) ) {
			$r                    = self::render( 'requester', $order, $items );
			$results['requester'] = self::send( $order->contact_email, $r['subject'], $r['html'], $r['text'], isset( $workers[0] ) ? $workers[0] : '' );
			$log[]                = is_wp_error( $results['requester'] ) ? __( 'Requester confirmation FAILED.', 'bmlt-literature-manager' ) : __( 'Requester confirmation sent.', 'bmlt-literature-manager' );
		}

		Orders::add_note( $order->id, 'email', implode( "\n", $log ) );
		return $results;
	}

	/**
	 * Resend the literature-worker notification.
	 *
	 * @param int $order_id Order.
	 * @return true|\WP_Error
	 */
	public static function resend_worker_notification( $order_id ) {
		$order = Orders::get( $order_id );
		if ( ! $order ) {
			return new \WP_Error( 'not_found', __( 'Order not found.', 'bmlt-literature-manager' ) );
		}
		$items   = Orders::get_items( $order->id );
		$workers = self::worker_recipients( $order );
		$r       = self::render( 'worker', $order, $items, array( 'internal' => true ) );
		$res     = self::send( $workers, $r['subject'], $r['html'], $r['text'], $order->contact_email );
		Orders::add_note(
			$order->id,
			'email',
			is_wp_error( $res )
				? __( 'Resend of literature-worker notification FAILED:', 'bmlt-literature-manager' ) . ' ' . $res->get_error_message()
				/* translators: %d: number of recipients. */
				: sprintf( __( 'Literature-worker notification resent to %d recipient(s).', 'bmlt-literature-manager' ), count( $workers ) )
		);
		return $res;
	}

	/**
	 * Send a templated email to the requester.
	 *
	 * @param int    $order_id Order.
	 * @param string $template ready|special|message.
	 * @param string $message  Optional worker message.
	 * @return true|\WP_Error
	 */
	public static function send_to_requester( $order_id, $template, $message = '' ) {
		$order = Orders::get( $order_id );
		if ( ! $order ) {
			return new \WP_Error( 'not_found', __( 'Order not found.', 'bmlt-literature-manager' ) );
		}
		if ( ! is_email( $order->contact_email ) ) {
			return new \WP_Error( 'no_email', __( 'This order has no valid requester email address.', 'bmlt-literature-manager' ) );
		}
		if ( ! in_array( $template, array( 'ready', 'special', 'message' ), true ) ) {
			$template = 'message';
		}
		$items   = Orders::get_items( $order->id );
		$area    = Settings::area( (int) $order->area_id );
		$reply   = Helpers::parse_email_list( $area['worker_emails'] );
		$r       = self::render( $template, $order, $items, array( 'worker_message' => $message ) );
		$res     = self::send( $order->contact_email, $r['subject'], $r['html'], $r['text'], isset( $reply[0] ) ? $reply[0] : '' );
		$labels  = Settings::template_labels();
		$summary = isset( $labels[ $template ] ) ? $labels[ $template ] : $template;
		Orders::add_note(
			$order->id,
			'email',
			( is_wp_error( $res ) ? __( 'Email to requester FAILED:', 'bmlt-literature-manager' ) . ' ' : __( 'Email sent to requester:', 'bmlt-literature-manager' ) . ' ' ) . $summary . ( '' !== trim( $message ) ? "\n" . $message : '' )
		);
		return $res;
	}
}
