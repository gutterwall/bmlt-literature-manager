<?php
/**
 * Optional SMTP transport.
 *
 * @package BMLT_Lit
 * @version 6.2.0
 */

namespace BMLT_Lit;

defined( 'ABSPATH' ) || exit;

/**
 * Configures PHPMailer through the phpmailer_init hook. Does nothing when disabled.
 */
class Smtp {

	/**
	 * Register hooks only when the override is enabled.
	 */
	public static function init() {
		$o = Settings::smtp();
		if ( ! empty( $o['enabled'] ) && '' !== trim( (string) $o['host'] ) ) {
			add_action( 'phpmailer_init', array( __CLASS__, 'configure' ), 20 );
		}
	}

	/**
	 * Apply SMTP settings to PHPMailer.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer.
	 */
	public static function configure( $phpmailer ) {
		$o = Settings::smtp();
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$phpmailer->isSMTP();
		$phpmailer->Host    = (string) $o['host'];
		$phpmailer->Port    = max( 1, min( 65535, (int) $o['port'] ) );
		$phpmailer->Timeout = 20;

		if ( 'ssl' === $o['encryption'] ) {
			$phpmailer->SMTPSecure = 'ssl';
		} elseif ( 'tls' === $o['encryption'] ) {
			$phpmailer->SMTPSecure = 'tls';
		} else {
			$phpmailer->SMTPSecure  = '';
			$phpmailer->SMTPAutoTLS = false;
		}

		$phpmailer->SMTPAuth = ! empty( $o['auth'] );
		if ( $phpmailer->SMTPAuth ) {
			$phpmailer->Username = (string) $o['username'];
			$phpmailer->Password = self::password();
		}

		$from = sanitize_email( (string) $o['from_email'] );
		if ( $from && is_email( $from ) && ( ! empty( $o['force_from'] ) || self::is_default_from( $phpmailer->From ) ) ) {
			try {
				$name = '' !== trim( (string) $o['from_name'] ) ? (string) $o['from_name'] : $phpmailer->FromName;
				$phpmailer->setFrom( $from, Helpers::header_safe( $name ), false );
			} catch ( \Exception $e ) {
				Helpers::log( 'Could not set SMTP From address.' );
			}
		}

		$reply = sanitize_email( (string) $o['reply_to'] );
		if ( $reply && is_email( $reply ) && empty( $phpmailer->getReplyToAddresses() ) ) {
			try {
				$phpmailer->addReplyTo( $reply );
			} catch ( \Exception $e ) {
				Helpers::log( 'Could not set SMTP Reply-To address.' );
			}
		}
		// phpcs:enable
	}

	/**
	 * Whether the From address is the WordPress default.
	 *
	 * @param string $from From address.
	 * @return bool
	 */
	private static function is_default_from( $from ) {
		return '' === (string) $from || 0 === strpos( strtolower( (string) $from ), 'wordpress@' );
	}

	/**
	 * Where the password comes from.
	 *
	 * @return string constant|database|none
	 */
	public static function password_source() {
		if ( defined( 'BMLT_LIT_SMTP_PASSWORD' ) && '' !== (string) BMLT_LIT_SMTP_PASSWORD ) {
			return 'constant';
		}
		$o = Settings::smtp();
		return '' !== (string) $o['password'] ? 'database' : 'none';
	}

	/**
	 * Decrypted SMTP password. Never output this value.
	 *
	 * @return string
	 */
	public static function password() {
		if ( defined( 'BMLT_LIT_SMTP_PASSWORD' ) && '' !== (string) BMLT_LIT_SMTP_PASSWORD ) {
			return (string) BMLT_LIT_SMTP_PASSWORD;
		}
		$o = Settings::smtp();
		return self::decrypt( (string) $o['password'] );
	}

	/**
	 * Derive the encryption key from WordPress salts.
	 *
	 * @return string Binary key.
	 */
	private static function key() {
		return hash( 'sha256', wp_salt( 'auth' ) . '|bmlt_lit_smtp', true );
	}

	/**
	 * Whether real encryption is available.
	 *
	 * @return bool
	 */
	public static function can_encrypt() {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'random_bytes' );
	}

	/**
	 * Encrypt a secret for storage (AES-256-CBC + HMAC-SHA256).
	 *
	 * @param string $plain Plain text.
	 * @return string
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}
		if ( ! self::can_encrypt() ) {
			return 'b64:' . base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		$key    = self::key();
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return '';
		}
		$mac = hash_hmac( 'sha256', $iv . $cipher, $key, true );
		return 'v1:' . base64_encode( $iv . $mac . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a stored secret.
	 *
	 * @param string $stored Stored value.
	 * @return string Empty if invalid (e.g. salts changed).
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return '';
		}
		if ( 0 === strpos( $stored, 'b64:' ) ) {
			$raw = base64_decode( substr( $stored, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			return false === $raw ? '' : $raw;
		}
		if ( 0 !== strpos( $stored, 'v1:' ) || ! self::can_encrypt() ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) < 49 ) {
			return '';
		}
		$key    = self::key();
		$iv     = substr( $raw, 0, 16 );
		$mac    = substr( $raw, 16, 32 );
		$cipher = substr( $raw, 48 );
		if ( ! hash_equals( hash_hmac( 'sha256', $iv . $cipher, $key, true ), $mac ) ) {
			return '';
		}
		$plain = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return false === $plain ? '' : $plain;
	}

	/**
	 * Whether a stored password can no longer be decrypted (e.g. salts rotated).
	 *
	 * @return bool
	 */
	public static function password_unreadable() {
		$o = Settings::smtp();
		return 'database' === self::password_source() && '' === self::decrypt( (string) $o['password'] );
	}

	/**
	 * Sanitize and save SMTP settings from a form submission.
	 *
	 * @param array $raw Raw (unslashed) input.
	 * @return array Saved settings.
	 */
	public static function save( array $raw ) {
		$current = Settings::smtp();
		$enc     = isset( $raw['encryption'] ) && is_scalar( $raw['encryption'] ) ? sanitize_key( $raw['encryption'] ) : 'tls';
		$new     = array(
			'enabled'    => ! empty( $raw['enabled'] ) ? 1 : 0,
			'host'       => Helpers::limit( preg_replace( '/[^A-Za-z0-9.\-:\[\]]/', '', (string) ( isset( $raw['host'] ) ? $raw['host'] : '' ) ), 255 ),
			'port'       => max( 1, min( 65535, absint( isset( $raw['port'] ) ? $raw['port'] : 587 ) ) ),
			'encryption' => in_array( $enc, array( 'none', 'tls', 'ssl' ), true ) ? $enc : 'tls',
			'auth'       => ! empty( $raw['auth'] ) ? 1 : 0,
			'username'   => Helpers::limit( sanitize_text_field( (string) ( isset( $raw['username'] ) ? $raw['username'] : '' ) ), 255 ),
			'password'   => (string) $current['password'],
			'from_email' => sanitize_email( (string) ( isset( $raw['from_email'] ) ? $raw['from_email'] : '' ) ),
			'from_name'  => Helpers::limit( Helpers::header_safe( sanitize_text_field( (string) ( isset( $raw['from_name'] ) ? $raw['from_name'] : '' ) ) ), 120 ),
			'force_from' => ! empty( $raw['force_from'] ) ? 1 : 0,
			'reply_to'   => sanitize_email( (string) ( isset( $raw['reply_to'] ) ? $raw['reply_to'] : '' ) ),
		);

		if ( ! empty( $raw['clear_password'] ) ) {
			$new['password'] = '';
		} elseif ( isset( $raw['password'] ) && '' !== (string) $raw['password'] ) {
			// Passwords are stored as entered (not sanitized beyond removing line breaks) and encrypted.
			$new['password'] = self::encrypt( str_replace( array( "\r", "\n" ), '', (string) $raw['password'] ) );
		}

		Settings::update( Settings::SMTP, $new, false );

		$log_old             = $current;
		$log_new             = $new;
		$log_old['password'] = '' !== (string) $current['password'] ? '(set)' : '';
		$log_new['password'] = '' !== (string) $new['password'] ? '(set)' : '';
		Audit::log( 'settings_changed', 'settings', 0, array( 'smtp' => $log_old ), array( 'smtp' => $log_new ) );
		return $new;
	}

	/**
	 * Remove credentials from any message before display/logging.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	public static function redact( $message ) {
		$message = (string) $message;
		$secrets = array();
		$pw      = self::password();
		if ( '' !== $pw ) {
			$secrets[] = $pw;
			$secrets[] = base64_encode( $pw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		$o = Settings::smtp();
		if ( '' !== (string) $o['username'] ) {
			$secrets[] = (string) $o['username'];
			$secrets[] = base64_encode( (string) $o['username'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		foreach ( $secrets as $s ) {
			if ( strlen( $s ) >= 2 ) {
				$message = str_replace( $s, '[redacted]', $message );
			}
		}
		return wp_strip_all_tags( $message );
	}

	/**
	 * Send a test email.
	 *
	 * @param string $to Recipient.
	 * @return true|\WP_Error
	 */
	public static function send_test( $to ) {
		$to = sanitize_email( $to );
		if ( ! is_email( $to ) ) {
			return new \WP_Error( 'invalid', __( 'Please enter a valid email address for the test.', 'bmlt-literature-manager' ) );
		}
		$o       = Settings::smtp();
		$mode    = ! empty( $o['enabled'] ) ? __( 'Custom SMTP', 'bmlt-literature-manager' ) : __( 'WordPress default mail', 'bmlt-literature-manager' );
		$subject = __( 'BMLT Literature Manager test email', 'bmlt-literature-manager' );
		/* translators: 1: delivery method, 2: date/time. */
		$line = sprintf( __( 'This is a test email from BMLT Literature Manager sent using: %1$s at %2$s.', 'bmlt-literature-manager' ), $mode, wp_date( 'Y-m-d H:i:s' ) );
		$html = Email::wrap( '<h2>' . esc_html( $subject ) . '</h2><p>' . esc_html( $line ) . '</p>', $subject );
		return Email::send( $to, $subject, $html, $line );
	}
}
