<?php
/**
 * SMTP view. The stored password is never output.
 *
 * @package BMLT_Lit
 * @version 6.2.0
 *
 * @var array  $opts
 * @var string $pw_source
 * @var bool   $pw_broken
 * @var bool   $can_crypt
 */

defined( 'ABSPATH' ) || exit;

$post_url = admin_url( 'admin-post.php' );
?>
<div class="wrap bmlt-lit-admin">
	<h1><?php esc_html_e( 'SMTP', 'bmlt-literature-manager' ); ?></h1>
	<p><?php esc_html_e( 'Choose how literature emails are delivered. When custom SMTP is off, this plugin does not change how WordPress sends any email.', 'bmlt-literature-manager' ); ?></p>

	<?php if ( ! $can_crypt ) : ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'The PHP OpenSSL extension is not available, so the SMTP password can only be obfuscated in the database. Defining BMLT_LIT_SMTP_PASSWORD in wp-config.php is strongly recommended.', 'bmlt-literature-manager' ); ?></p></div>
	<?php endif; ?>
	<?php if ( $pw_broken ) : ?>
		<div class="notice notice-error inline"><p><?php esc_html_e( 'The saved SMTP password can no longer be decrypted (WordPress security keys were probably changed). Please enter the password again.', 'bmlt-literature-manager' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( $post_url ); ?>" autocomplete="off">
		<?php wp_nonce_field( 'bmlt_lit_save_smtp' ); ?>
		<input type="hidden" name="action" value="bmlt_lit_save_smtp">
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Delivery method', 'bmlt-literature-manager' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Delivery method', 'bmlt-literature-manager' ); ?></legend>
						<label><input type="radio" name="enabled" value="0" <?php checked( (int) $opts['enabled'], 0 ); ?>> <?php esc_html_e( 'Use WordPress default mail', 'bmlt-literature-manager' ); ?></label><br>
						<label><input type="radio" name="enabled" value="1" <?php checked( (int) $opts['enabled'], 1 ); ?>> <?php esc_html_e( 'Use custom SMTP (applies to all WordPress email)', 'bmlt-literature-manager' ); ?></label>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bmlt-lit-host"><?php esc_html_e( 'SMTP hostname', 'bmlt-literature-manager' ); ?></label></th>
				<td><input type="text" id="bmlt-lit-host" name="host" class="regular-text" value="<?php echo esc_attr( $opts['host'] ); ?>" placeholder="smtp.example.org"></td>
			</tr>
			<tr>
				<th scope="row"><label for="bmlt-lit-port"><?php esc_html_e( 'SMTP port', 'bmlt-literature-manager' ); ?></label></th>
				<td><input type="number" id="bmlt-lit-port" name="port" min="1" max="65535" class="small-text" value="<?php echo esc_attr( $opts['port'] ); ?>"> <span class="description"><?php esc_html_e( 'Usually 587 for STARTTLS, 465 for SSL, 25 for none.', 'bmlt-literature-manager' ); ?></span></td>
			</tr>
			<tr>
				<th scope="row"><label for="bmlt-lit-enc"><?php esc_html_e( 'Encryption', 'bmlt-literature-manager' ); ?></label></th>
				<td>
					<select id="bmlt-lit-enc" name="encryption">
						<option value="none" <?php selected( $opts['encryption'], 'none' ); ?>><?php esc_html_e( 'None', 'bmlt-literature-manager' ); ?></option>
						<option value="tls" <?php selected( $opts['encryption'], 'tls' ); ?>><?php esc_html_e( 'STARTTLS / TLS', 'bmlt-literature-manager' ); ?></option>
						<option value="ssl" <?php selected( $opts['encryption'], 'ssl' ); ?>><?php esc_html_e( 'SSL', 'bmlt-literature-manager' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Authentication', 'bmlt-literature-manager' ); ?></th>
				<td><label><input type="checkbox" name="auth" value="1" <?php checked( (int) $opts['auth'], 1 ); ?>> <?php esc_html_e( 'Server requires a username and password', 'bmlt-literature-manager' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><label for="bmlt-lit-user"><?php esc_html_e( 'SMTP username', 'bmlt-literature-manager' ); ?></label></th>
				<td><input type="text" id="bmlt-lit-user" name="username" class="regular-text" autocomplete="off" value="<?php echo esc_attr( $opts['username'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="bmlt-lit-pass"><?php esc_html_e( 'SMTP password', 'bmlt-literature-manager' ); ?></label></th>
				<td>
					<?php if ( 'constant' === $pw_source ) : ?>
						<p><strong><?php esc_html_e( 'The password is defined in wp-config.php (BMLT_LIT_SMTP_PASSWORD) and is not stored in the database.', 'bmlt-literature-manager' ); ?></strong></p>
					<?php else : ?>
						<input type="password" id="bmlt-lit-pass" name="password" class="regular-text" autocomplete="new-password" value="" placeholder="<?php echo 'database' === $pw_source ? esc_attr__( '•••••••• (saved — leave blank to keep)', 'bmlt-literature-manager' ) : ''; ?>">
						<?php if ( 'database' === $pw_source ) : ?>
							<br><label><input type="checkbox" name="clear_password" value="1"> <?php esc_html_e( 'Remove the saved password', 'bmlt-literature-manager' ); ?></label>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'The saved password is encrypted and is never displayed. For best security add define( \'BMLT_LIT_SMTP_PASSWORD\', \'…\' ); to wp-config.php instead.', 'bmlt-literature-manager' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bmlt-lit-from"><?php esc_html_e( 'From email', 'bmlt-literature-manager' ); ?></label></th>
				<td><input type="email" id="bmlt-lit-from" name="from_email" class="regular-text" value="<?php echo esc_attr( $opts['from_email'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="bmlt-lit-fromname"><?php esc_html_e( 'From name', 'bmlt-literature-manager' ); ?></label></th>
				<td><input type="text" id="bmlt-lit-fromname" name="from_name" class="regular-text" value="<?php echo esc_attr( $opts['from_name'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Force From address', 'bmlt-literature-manager' ); ?></th>
				<td><label><input type="checkbox" name="force_from" value="1" <?php checked( (int) $opts['force_from'], 1 ); ?>> <?php esc_html_e( 'Always send from the address above (recommended when your SMTP provider rejects other senders)', 'bmlt-literature-manager' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><label for="bmlt-lit-reply"><?php esc_html_e( 'Default reply-to', 'bmlt-literature-manager' ); ?></label></th>
				<td><input type="email" id="bmlt-lit-reply" name="reply_to" class="regular-text" value="<?php echo esc_attr( $opts['reply_to'] ); ?>"> <span class="description"><?php esc_html_e( 'Used only when a message has no reply-to of its own.', 'bmlt-literature-manager' ); ?></span></td>
			</tr>
		</table>
		<?php submit_button( __( 'Save SMTP settings', 'bmlt-literature-manager' ) ); ?>
	</form>

	<div class="postbox bmlt-lit-box">
		<h2 class="hndle"><?php esc_html_e( 'Send Test Email', 'bmlt-literature-manager' ); ?></h2>
		<div class="inside">
			<form method="post" action="<?php echo esc_url( $post_url ); ?>">
				<?php wp_nonce_field( 'bmlt_lit_test_email' ); ?>
				<input type="hidden" name="action" value="bmlt_lit_test_email">
				<p>
					<label for="bmlt-lit-test-to"><?php esc_html_e( 'Send a test message to', 'bmlt-literature-manager' ); ?></label>
					<input type="email" id="bmlt-lit-test-to" name="test_to" class="regular-text" required value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>">
					<?php submit_button( __( 'Send Test Email', 'bmlt-literature-manager' ), 'secondary', 'submit', false ); ?>
				</p>
				<p class="description"><?php esc_html_e( 'Save your settings first. Errors are shown without revealing credentials.', 'bmlt-literature-manager' ); ?></p>
			</form>
		</div>
	</div>
</div>
