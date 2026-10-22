<?php
/**
 * SMTP settings and password storage; the protected worker shortcodes; pages that must not be cached.
 */

namespace BmltLitTest;

use BMLT_Lit\Settings;
use BMLT_Lit\Smtp;

class SmtpAndShortcodeTest extends TestCase {

	public function test_smtp_password_is_encrypted_never_shown_and_redacted_from_errors() {
		wp_set_current_user( self::$admin );
		Smtp::save( array( 'enabled' => 1, 'host' => 'smtp.example.org;rm -rf', 'port' => '70000', 'encryption' => 'weird', 'auth' => 1, 'username' => 'lit-user', 'password' => "Pa\$\$w0rd\r\nX", 'from_email' => 'lit@example.org' ) );
		$o = Settings::smtp();
		$this->assertSame( array( 'smtp.example.orgrm-rf', 65535, 'tls' ), array( $o['host'], $o['port'], $o['encryption'] ) );
		$this->assertStringStartsWith( 'v1:', $o['password'] );
		$this->assertSame( 'Pa$$w0rdX', Smtp::password() );
		$this->assertStringNotContainsString( 'Pa$$w0rd', self::everything_stored() );
		$this->assertSame( 'Login as [redacted] with [redacted] failed', Smtp::redact( 'Login as lit-user with Pa$$w0rdX failed' ) );

		// A blank password field keeps the stored one; "clear" removes it.
		Smtp::save( array( 'enabled' => 1, 'host' => 'smtp.example.org', 'auth' => 1, 'username' => 'lit-user', 'password' => '', 'from_email' => 'lit@example.org' ) );
		$this->assertSame( 'Pa$$w0rdX', Smtp::password() );

		// Tampered or foreign ciphertext decrypts to nothing.
		$this->assertSame( '', Smtp::decrypt( 'v1:' . base64_encode( str_repeat( 'x', 64 ) ) ) );
		$this->assertTrue( Smtp::password_unreadable() === false );

		$mailer = new \PHPMailer\PHPMailer\PHPMailer();
		$mailer->From = 'wordpress@example.org';
		Smtp::configure( $mailer );
		$this->assertSame( array( 'smtp', 'smtp.example.org', 587, 'tls', true, 'lit-user', 'Pa$$w0rdX', 'lit@example.org' ), array( $mailer->Mailer, $mailer->Host, $mailer->Port, $mailer->SMTPSecure, $mailer->SMTPAuth, $mailer->Username, $mailer->Password, $mailer->From ) );

		Smtp::save( array( 'clear_password' => 1 ) );
		$this->assertSame( 'none', Smtp::password_source() );

		$audit = \BMLT_Lit\Audit::recent( array( 'object_type' => 'settings' ) );
		$this->assertStringNotContainsString( 'Pa$$w0rd', wp_json_encode( $audit ) );
	}

	public function test_worker_pages_are_protected() {
		$this->assertStringContainsString( 'Please log in to access the literature tools.', do_shortcode( '[bmlt_literature_manager]' ) );
		wp_set_current_user( self::$subscriber );
		$this->assertStringContainsString( 'You do not have permission', do_shortcode( '[bmlt_literature_orders]' ) );
		wp_set_current_user( self::$settings_mgr );
		$this->assertStringContainsString( 'You do not have permission', do_shortcode( '[bmlt_literature_manager]' ), 'settings alone give no worker tools' );

		wp_set_current_user( self::$restricted );
		self::reset_plugin_state();
		$html = do_shortcode( '[bmlt_literature_manager default_tab="inventory" area="102" per_page="50"]' );
		$this->assertStringContainsString( 'data-bmlt-lit-worker', $html );
		$scripts = wp_scripts();
		$config  = $scripts->get_data( 'bmlt-lit-worker', 'before' );
		$config  = json_decode( preg_replace( '/^window\.bmltLitWorker = |;$/', '', trim( implode( "\n", (array) $config ) ) ), true );
		$this->assertSame( array( self::RIVERSIDE => 'Riverside Area' ), $config['areas'] );
		$this->assertSame( 0, $config['defaultArea'], 'an Area the worker may not use is not preselected' );
		$this->assertSame( 'inventory', $config['defaultTab'] );
		$this->assertSame( 50, $config['perPage'] );
		$this->assertFalse( $config['caps']['catalog'] );
		$this->assertFalse( $config['shared'] );
	}

	public function test_restricted_worker_without_areas_is_told_so() {
		\BMLT_Lit\Permissions::save_grants( array( self::$restricted => array( 'caps' => array(), 'areas' => array( 105 ) ) ) );
		wp_set_current_user( self::$restricted );
		self::reset_plugin_state();
		$this->assertStringContainsString( 'No Areas are assigned to your account yet.', do_shortcode( '[bmlt_literature_orders]' ) );
	}

	/**
	 * Page caches would give every visitor the same form token (and, for anonymous visitors, the same valid
	 * nonce), so pages with the order form tell caching plugins and proxies not to store them.
	 */
	public function test_order_form_pages_are_not_cached() {
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => '[bmlt_literature_order]' ) );
		$this->go_to( get_permalink( $page ) );
		do_action( 'template_redirect' );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
		$this->assertContains( 'Cache-Control', array_keys( wp_get_nocache_headers() ) );
	}
}
