<?php
/**
 * Settings view (tabs).
 *
 * @package BMLT_Lit
 * @version 6.3.0
 *
 * @var array  $tabs
 * @var string $tab
 * @var array  $general
 * @var array  $templates
 */

use BMLT_Lit\Permissions;
use BMLT_Lit\Settings;

defined( 'ABSPATH' ) || exit;

$post_url = admin_url( 'admin-post.php' );
$g        = $general;
?>
<div class="wrap bmlt-lit-admin">
	<h1><?php esc_html_e( 'Literature Settings', 'bmlt-literature-manager' ); ?></h1>
	<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Settings sections', 'bmlt-literature-manager' ); ?>">
		<?php foreach ( $tabs as $key => $label ) : ?>
			<?php $url = 'bmlt' === $key ? admin_url( 'admin.php?page=bmlt-lit-bmlt' ) : admin_url( 'admin.php?page=bmlt-lit-settings&tab=' . $key ); ?>
			<a href="<?php echo esc_url( $url ); ?>" class="nav-tab<?php echo $tab === $key ? ' nav-tab-active' : ''; ?>"<?php echo $tab === $key ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
	</nav>

	<?php if ( 'permissions' === $tab ) : ?>
		<?php
		$users    = Permissions::users_with_caps();
		$labels   = Permissions::cap_labels();
		$areas    = Settings::area_options();
		$is_admin = current_user_can( 'manage_options' );
		$role_names = wp_roles()->role_names;
		?>
		<p><?php esc_html_e( 'Users listed here hold at least one literature capability. Administrators always have every capability. You can also give users the “Literature Worker” or “Literature Chair” role under Users.', 'bmlt-literature-manager' ); ?></p>
		<p class="description"><?php esc_html_e( 'Area restriction: leave all Areas unchecked to allow every Area. Checking one or more Areas limits that user to those Areas’ orders, inventory and catalogs (and hides the shared catalog from editing).', 'bmlt-literature-manager' ); ?></p>
		<form method="post" action="<?php echo esc_url( $post_url ); ?>">
			<?php wp_nonce_field( 'bmlt_lit_save_permissions' ); ?>
			<input type="hidden" name="action" value="bmlt_lit_save_permissions">
			<div class="bmlt-lit-table-scroll">
				<table class="widefat striped">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'User', 'bmlt-literature-manager' ); ?></th>
						<?php foreach ( $labels as $label ) : ?>
							<th scope="col"><?php echo esc_html( $label ); ?></th>
						<?php endforeach; ?>
						<th scope="col"><?php esc_html_e( 'Restrict to Areas', 'bmlt-literature-manager' ); ?></th>
					</tr></thead>
					<tbody>
						<?php if ( empty( $users ) ) : ?>
							<tr><td colspan="<?php echo count( $labels ) + 2; ?>"><?php esc_html_e( 'No users have literature capabilities yet.', 'bmlt-literature-manager' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $users as $u ) : ?>
							<?php
							$admin_user = user_can( $u, 'manage_options' );
							$grant      = Permissions::grants_for( $u->ID );
							$restrict   = $grant['areas'];
							?>
							<tr>
								<th scope="row"><?php echo esc_html( $u->display_name ); ?><br><small><?php echo esc_html( $u->user_login . ' — ' . implode( ', ', array_map( static function ( $r ) use ( $role_names ) { return isset( $role_names[ $r ] ) ? translate_user_role( $role_names[ $r ] ) : $r; }, (array) $u->roles ) ) ); ?></small></th>
								<?php foreach ( $labels as $cap => $label ) : ?>
									<td>
										<?php if ( $admin_user ) : ?>
											<span aria-label="<?php esc_attr_e( 'Yes (administrator)', 'bmlt-literature-manager' ); ?>">✓</span>
										<?php else : ?>
											<label class="screen-reader-text" for="bmlt-lit-p-<?php echo (int) $u->ID . '-' . esc_attr( $cap ); ?>"><?php echo esc_html( $label . ' — ' . $u->display_name ); ?></label>
											<input type="checkbox" id="bmlt-lit-p-<?php echo (int) $u->ID . '-' . esc_attr( $cap ); ?>" name="perm[<?php echo (int) $u->ID; ?>][caps][]" value="<?php echo esc_attr( $cap ); ?>" <?php checked( user_can( $u, $cap ) ); ?> <?php disabled( ! $is_admin || ( Permissions::MANAGE_SETTINGS === $cap && ! $is_admin ) ); ?>>
											<?php if ( user_can( $u, $cap ) && ! in_array( $cap, $grant['caps'], true ) ) : ?><br><small><?php esc_html_e( '(via role)', 'bmlt-literature-manager' ); ?></small><?php endif; ?>
										<?php endif; ?>
									</td>
								<?php endforeach; ?>
								<td>
									<?php if ( $admin_user ) : ?>
										<?php esc_html_e( 'All Areas', 'bmlt-literature-manager' ); ?>
									<?php else : ?>
										<input type="hidden" name="perm[<?php echo (int) $u->ID; ?>][present]" value="1">
										<?php foreach ( $areas as $aid => $aname ) : ?>
											<label class="bmlt-lit-nowrap"><input type="checkbox" name="perm[<?php echo (int) $u->ID; ?>][areas][]" value="<?php echo esc_attr( $aid ); ?>" <?php checked( in_array( (int) $aid, array_map( 'absint', $restrict ), true ) ); ?> <?php disabled( ! $is_admin ); ?>> <?php echo esc_html( $aname ); ?></label><br>
										<?php endforeach; ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php if ( $is_admin ) : ?>
				<p>
					<label for="bmlt-lit-add-user"><strong><?php esc_html_e( 'Add a literature worker (username or email)', 'bmlt-literature-manager' ); ?></strong></label><br>
					<input type="text" id="bmlt-lit-add-user" name="add_user" class="regular-text">
					<span class="description"><?php esc_html_e( 'Grants view orders, manage orders and manage inventory. Adjust checkboxes afterwards.', 'bmlt-literature-manager' ); ?></span>
				</p>
				<p class="description"><?php esc_html_e( 'Capabilities granted by a role can only be removed by changing the user’s role. Permissions set here are stored in the literature data directory, so database pushes do not change them.', 'bmlt-literature-manager' ); ?></p>
				<?php submit_button( __( 'Save permissions', 'bmlt-literature-manager' ) ); ?>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Only site administrators can change permissions.', 'bmlt-literature-manager' ); ?></p>
			<?php endif; ?>
		</form>

	<?php else : ?>
		<form method="post" action="<?php echo esc_url( $post_url ); ?>">
			<?php wp_nonce_field( 'bmlt_lit_save_settings' ); ?>
			<input type="hidden" name="action" value="bmlt_lit_save_settings">
			<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">

			<?php if ( 'general' === $tab ) : ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="s-org"><?php esc_html_e( 'Organization name', 'bmlt-literature-manager' ); ?></label></th>
						<td><input type="text" id="s-org" name="org_name" class="regular-text" value="<?php echo esc_attr( $g['org_name'] ); ?>"></td></tr>
					<tr><th scope="row"><label for="s-cur"><?php esc_html_e( 'Currency', 'bmlt-literature-manager' ); ?></label></th>
						<td>
							<input type="text" id="s-cur" name="currency_symbol" class="small-text" maxlength="8" value="<?php echo esc_attr( $g['currency_symbol'] ); ?>">
							<label for="s-curpos" class="screen-reader-text"><?php esc_html_e( 'Symbol position', 'bmlt-literature-manager' ); ?></label>
							<select id="s-curpos" name="currency_position">
								<option value="before" <?php selected( $g['currency_position'], 'before' ); ?>><?php esc_html_e( 'Before amount ($1.00)', 'bmlt-literature-manager' ); ?></option>
								<option value="after" <?php selected( $g['currency_position'], 'after' ); ?>><?php esc_html_e( 'After amount (1.00€)', 'bmlt-literature-manager' ); ?></option>
							</select>
						</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Number format', 'bmlt-literature-manager' ); ?></th>
						<td>
							<label><?php esc_html_e( 'Decimals', 'bmlt-literature-manager' ); ?> <input type="number" name="decimals" min="0" max="4" class="small-text" value="<?php echo esc_attr( $g['decimals'] ); ?>"></label>
							<label><?php esc_html_e( 'Decimal separator', 'bmlt-literature-manager' ); ?>
								<select name="decimal_sep"><option value="." <?php selected( $g['decimal_sep'], '.' ); ?>>.</option><option value="," <?php selected( $g['decimal_sep'], ',' ); ?>>,</option></select></label>
							<label><?php esc_html_e( 'Thousands separator', 'bmlt-literature-manager' ); ?>
								<select name="thousands_sep">
									<option value="," <?php selected( $g['thousands_sep'], ',' ); ?>>,</option>
									<option value="." <?php selected( $g['thousands_sep'], '.' ); ?>>.</option>
									<option value=" " <?php selected( $g['thousands_sep'], ' ' ); ?>><?php esc_html_e( 'space', 'bmlt-literature-manager' ); ?></option>
									<option value="" <?php selected( $g['thousands_sep'], '' ); ?>><?php esc_html_e( 'none', 'bmlt-literature-manager' ); ?></option>
								</select></label>
						</td></tr>
					<tr><th scope="row"><label for="s-del"><?php esc_html_e( 'Area Service delivery message (public)', 'bmlt-literature-manager' ); ?></label></th>
						<td><textarea id="s-del" name="delivery_message" rows="2" class="large-text"><?php echo esc_textarea( $g['delivery_message'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Shown to requesters for in-stock literature. Do not promise a specific date unless you also set one per Area.', 'bmlt-literature-manager' ); ?></p></td></tr>
					<tr><th scope="row"><label for="s-wdel"><?php esc_html_e( 'Delivery message (literature-worker emails)', 'bmlt-literature-manager' ); ?></label></th>
						<td><textarea id="s-wdel" name="worker_delivery_message" rows="2" class="large-text"><?php echo esc_textarea( $g['worker_delivery_message'] ); ?></textarea></td></tr>
					<tr><th scope="row"><label for="s-so"><?php esc_html_e( 'Special-order message', 'bmlt-literature-manager' ); ?></label></th>
						<td><textarea id="s-so" name="special_order_message" rows="2" class="large-text"><?php echo esc_textarea( $g['special_order_message'] ); ?></textarea></td></tr>
					<tr><th scope="row"><label for="s-turn"><?php esc_html_e( 'Approximate special-order turnaround', 'bmlt-literature-manager' ); ?></label></th>
						<td><input type="text" id="s-turn" name="special_order_turnaround" class="regular-text" value="<?php echo esc_attr( $g['special_order_turnaround'] ); ?>"> <span class="description"><?php esc_html_e( 'e.g. “approximately one month”', 'bmlt-literature-manager' ); ?></span></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Inventory when an order is placed', 'bmlt-literature-manager' ); ?></th>
						<td><fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Inventory reservation behavior', 'bmlt-literature-manager' ); ?></legend>
							<label><input type="radio" name="reservation_mode" value="reserve" <?php checked( $g['reservation_mode'], 'reserve' ); ?>> <?php esc_html_e( 'Reserve stock when ordered, deduct permanently when fulfilled (recommended)', 'bmlt-literature-manager' ); ?></label><br>
							<label><input type="radio" name="reservation_mode" value="deduct" <?php checked( $g['reservation_mode'], 'deduct' ); ?>> <?php esc_html_e( 'Deduct stock immediately when ordered (restored if cancelled)', 'bmlt-literature-manager' ); ?></label><br>
							<label><input type="radio" name="reservation_mode" value="none" <?php checked( $g['reservation_mode'], 'none' ); ?>> <?php esc_html_e( 'Leave inventory unchanged until the order is fulfilled', 'bmlt-literature-manager' ); ?></label>
							<p class="description"><?php esc_html_e( 'Applies to Areas that track quantities. Changing this affects new orders only. Each order remembers the method it was placed under.', 'bmlt-literature-manager' ); ?></p>
						</fieldset></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'When quantity exceeds stock', 'bmlt-literature-manager' ); ?></th>
						<td><fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Excess quantity behavior', 'bmlt-literature-manager' ); ?></legend>
							<label><input type="radio" name="excess_behavior" value="split" <?php checked( $g['excess_behavior'], 'split' ); ?>> <?php esc_html_e( 'Fill what is available from stock; special order only the excess', 'bmlt-literature-manager' ); ?></label><br>
							<label><input type="radio" name="excess_behavior" value="whole_line" <?php checked( $g['excess_behavior'], 'whole_line' ); ?>> <?php esc_html_e( 'Special order the entire line', 'bmlt-literature-manager' ); ?></label>
						</fieldset></td></tr>
					<tr><th scope="row"><label for="s-prefix"><?php esc_html_e( 'Order-number prefix', 'bmlt-literature-manager' ); ?></label></th>
						<td><input type="text" id="s-prefix" name="order_prefix" class="small-text" maxlength="10" value="<?php echo esc_attr( $g['order_prefix'] ); ?>"> <span class="description"><?php echo esc_html( sprintf( /* translators: %s: example. */ __( 'Example: %s', 'bmlt-literature-manager' ), $g['order_prefix'] . '-' . wp_date( 'Y' ) . '-000123' ) ); ?></span></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Required fields', 'bmlt-literature-manager' ); ?></th>
						<td>
							<label><input type="checkbox" name="require_name" value="1" <?php checked( (int) $g['require_name'], 1 ); ?>> <?php esc_html_e( 'Contact name', 'bmlt-literature-manager' ); ?></label><br>
							<label><input type="checkbox" name="require_phone" value="1" <?php checked( (int) $g['require_phone'], 1 ); ?>> <?php esc_html_e( 'Phone number', 'bmlt-literature-manager' ); ?></label><br>
							<label><input type="checkbox" name="require_email" value="1" <?php checked( (int) $g['require_email'], 1 ); ?>> <?php esc_html_e( 'Email address', 'bmlt-literature-manager' ); ?></label>
						</td></tr>
					<tr><th scope="row"><label for="s-low"><?php esc_html_e( 'Default low-stock threshold', 'bmlt-literature-manager' ); ?></label></th>
						<td><input type="number" id="s-low" name="default_low_stock" min="0" class="small-text" value="<?php echo esc_attr( $g['default_low_stock'] ); ?>"></td></tr>
					<tr><th scope="row"><label for="s-max"><?php esc_html_e( 'Maximum quantity per item', 'bmlt-literature-manager' ); ?></label></th>
						<td><input type="number" id="s-max" name="max_qty_per_line" min="1" class="small-text" value="<?php echo esc_attr( $g['max_qty_per_line'] ); ?>"></td></tr>
				</table>

			<?php elseif ( 'areas' === $tab ) : ?>
				<?php $sel = Settings::selected_areas(); ?>
				<?php if ( empty( $sel ) ) : ?>
					<p><?php esc_html_e( 'No Areas selected yet.', 'bmlt-literature-manager' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=bmlt-lit-bmlt' ) ); ?>"><?php esc_html_e( 'Select Areas on the BMLT / Areas page.', 'bmlt-literature-manager' ); ?></a></p>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'Orders are emailed only to the addresses configured for the order’s own Area. Separate multiple addresses with commas.', 'bmlt-literature-manager' ); ?></p>
				<?php foreach ( $sel as $id ) : ?>
					<?php
					$a = Settings::area( $id );
					$f = 'area[' . (int) $id . ']';
					$p = 'a' . (int) $id;
					?>
					<div class="postbox bmlt-lit-box">
						<h2 class="hndle"><?php echo esc_html( Settings::area_name( $id ) ); ?> <small>(<?php echo esc_html( sprintf( /* translators: %d: ID. */ __( 'BMLT ID %d', 'bmlt-literature-manager' ), $id ) ); ?><?php echo $a['parent_name'] ? ' — ' . esc_html( $a['parent_name'] ) : ''; ?>)</small></h2>
						<div class="inside">
							<table class="form-table" role="presentation">
								<tr><th scope="row"><label for="<?php echo esc_attr( $p ); ?>-dn"><?php esc_html_e( 'Display name override', 'bmlt-literature-manager' ); ?></label></th>
									<td><input type="text" id="<?php echo esc_attr( $p ); ?>-dn" name="<?php echo esc_attr( $f ); ?>[display_name]" class="regular-text" value="<?php echo esc_attr( $a['display_name'] ); ?>" placeholder="<?php echo esc_attr( $a['name'] ); ?>"></td></tr>
								<tr><th scope="row"><?php esc_html_e( 'Inventory', 'bmlt-literature-manager' ); ?></th>
									<td><fieldset><legend class="screen-reader-text"><?php esc_html_e( 'Inventory', 'bmlt-literature-manager' ); ?></legend>
										<label><input type="radio" name="<?php echo esc_attr( $f ); ?>[track_inventory]" value="1" <?php checked( (int) $a['track_inventory'], 1 ); ?>> <?php esc_html_e( 'Track quantities on hand (items with no stock become special orders automatically)', 'bmlt-literature-manager' ); ?></label><br>
										<label><input type="radio" name="<?php echo esc_attr( $f ); ?>[track_inventory]" value="0" <?php checked( (int) $a['track_inventory'], 0 ); ?>> <?php esc_html_e( 'Don’t track quantities — mark which items are special orders for this Area', 'bmlt-literature-manager' ); ?></label>
										<p class="description"><?php esc_html_e( 'Without tracking, mark items under Literature → Inventory, on each item’s edit page, or in the worker Inventory tab. Orders for this Area never change stock counts.', 'bmlt-literature-manager' ); ?></p>
									</fieldset></td></tr>
								<tr><th scope="row"><label for="<?php echo esc_attr( $p ); ?>-we"><?php esc_html_e( 'Literature worker email(s)', 'bmlt-literature-manager' ); ?></label></th>
									<td><input type="text" id="<?php echo esc_attr( $p ); ?>-we" name="<?php echo esc_attr( $f ); ?>[worker_emails]" class="large-text" value="<?php echo esc_attr( $a['worker_emails'] ); ?>"></td></tr>
								<tr><th scope="row"><label for="<?php echo esc_attr( $p ); ?>-ce"><?php esc_html_e( 'Literature chair email (optional)', 'bmlt-literature-manager' ); ?></label></th>
									<td><input type="text" id="<?php echo esc_attr( $p ); ?>-ce" name="<?php echo esc_attr( $f ); ?>[chair_email]" class="large-text" value="<?php echo esc_attr( $a['chair_email'] ); ?>"></td></tr>
								<tr><th scope="row"><label for="<?php echo esc_attr( $p ); ?>-se"><?php esc_html_e( 'Special-order recipient(s)', 'bmlt-literature-manager' ); ?></label></th>
									<td><input type="text" id="<?php echo esc_attr( $p ); ?>-se" name="<?php echo esc_attr( $f ); ?>[special_order_emails]" class="large-text" value="<?php echo esc_attr( $a['special_order_emails'] ); ?>">
										<p class="description"><?php esc_html_e( 'Also notified when an order contains special-order items.', 'bmlt-literature-manager' ); ?></p></td></tr>
								<tr><th scope="row"><label for="<?php echo esc_attr( $p ); ?>-web"><?php esc_html_e( 'Area website', 'bmlt-literature-manager' ); ?></label></th>
									<td><input type="url" id="<?php echo esc_attr( $p ); ?>-web" name="<?php echo esc_attr( $f ); ?>[website]" class="regular-text" value="<?php echo esc_attr( $a['website'] ); ?>" placeholder="<?php echo esc_attr( $a['bmlt_url'] ); ?>"></td></tr>
								<tr><th scope="row"><label for="<?php echo esc_attr( $p ); ?>-ph"><?php esc_html_e( 'Area phone', 'bmlt-literature-manager' ); ?></label></th>
									<td><input type="text" id="<?php echo esc_attr( $p ); ?>-ph" name="<?php echo esc_attr( $f ); ?>[phone]" class="regular-text" value="<?php echo esc_attr( $a['phone'] ); ?>" placeholder="<?php echo esc_attr( $a['bmlt_helpline'] ); ?>"></td></tr>
								<tr><th scope="row"><label for="<?php echo esc_attr( $p ); ?>-di"><?php esc_html_e( 'Delivery instructions', 'bmlt-literature-manager' ); ?></label></th>
									<td><textarea id="<?php echo esc_attr( $p ); ?>-di" name="<?php echo esc_attr( $f ); ?>[delivery_instructions]" rows="2" class="large-text"><?php echo esc_textarea( $a['delivery_instructions'] ); ?></textarea></td></tr>
								<tr><th scope="row"><label for="<?php echo esc_attr( $p ); ?>-sm"><?php esc_html_e( 'Area Service meeting details', 'bmlt-literature-manager' ); ?></label></th>
									<td><input type="text" id="<?php echo esc_attr( $p ); ?>-sm" name="<?php echo esc_attr( $f ); ?>[service_meeting_info]" class="large-text" value="<?php echo esc_attr( $a['service_meeting_info'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. 2nd Sunday, 2 PM, Community Center', 'bmlt-literature-manager' ); ?>"></td></tr>
								<tr><th scope="row"><label for="<?php echo esc_attr( $p ); ?>-nm"><?php esc_html_e( 'Next Area Service meeting date (optional)', 'bmlt-literature-manager' ); ?></label></th>
									<td><input type="date" id="<?php echo esc_attr( $p ); ?>-nm" name="<?php echo esc_attr( $f ); ?>[next_service_meeting]" value="<?php echo esc_attr( $a['next_service_meeting'] ); ?>">
										<p class="description"><?php esc_html_e( 'Shown to requesters only while the date is in the future. Leave blank to avoid promising a date.', 'bmlt-literature-manager' ); ?></p></td></tr>
							</table>
						</div>
					</div>
				<?php endforeach; ?>

			<?php elseif ( 'email' === $tab ) : ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="s-fn"><?php esc_html_e( 'Sender name', 'bmlt-literature-manager' ); ?></label></th>
						<td><input type="text" id="s-fn" name="email_from_name" class="regular-text" value="<?php echo esc_attr( $g['email_from_name'] ); ?>"></td></tr>
					<tr><th scope="row"><label for="s-fe"><?php esc_html_e( 'Sender email', 'bmlt-literature-manager' ); ?></label></th>
						<td><input type="email" id="s-fe" name="email_from_address" class="regular-text" value="<?php echo esc_attr( $g['email_from_address'] ); ?>">
							<p class="description"><?php esc_html_e( 'Use an address on your own domain to avoid spam filtering.', 'bmlt-literature-manager' ); ?></p></td></tr>
					<tr><th scope="row"><label for="s-gc"><?php esc_html_e( 'Global literature chair email', 'bmlt-literature-manager' ); ?></label></th>
						<td><input type="text" id="s-gc" name="global_chair_email" class="large-text" value="<?php echo esc_attr( $g['global_chair_email'] ); ?>"><br>
							<label><input type="checkbox" name="copy_global_chair" value="1" <?php checked( (int) $g['copy_global_chair'], 1 ); ?>> <?php esc_html_e( 'Copy every order (all Areas) to this address', 'bmlt-literature-manager' ); ?></label>
							<p class="description"><?php esc_html_e( 'Also used as the fallback recipient if an Area has no literature worker email.', 'bmlt-literature-manager' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Administrator copy', 'bmlt-literature-manager' ); ?></th>
						<td><label><input type="checkbox" name="admin_copy" value="1" <?php checked( (int) $g['admin_copy'], 1 ); ?>> <?php esc_html_e( 'Send an administrator notification for every order to:', 'bmlt-literature-manager' ); ?></label><br>
							<label class="screen-reader-text" for="s-ae"><?php esc_html_e( 'Administrator email', 'bmlt-literature-manager' ); ?></label>
							<input type="text" id="s-ae" name="admin_copy_email" class="large-text" value="<?php echo esc_attr( $g['admin_copy_email'] ); ?>"></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Requester emails', 'bmlt-literature-manager' ); ?></th>
						<td>
							<label><input type="checkbox" name="requester_confirmation" value="1" <?php checked( (int) $g['requester_confirmation'], 1 ); ?>> <?php esc_html_e( 'Send the requester a confirmation email', 'bmlt-literature-manager' ); ?></label><br>
							<label><input type="checkbox" name="notify_requester_ready" value="1" <?php checked( (int) $g['notify_requester_ready'], 1 ); ?>> <?php esc_html_e( 'Pre-check “email the requester” when marking orders Ready / Waiting for Special Order', 'bmlt-literature-manager' ); ?></label>
						</td></tr>
				</table>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=bmlt-lit-smtp' ) ); ?>"><?php esc_html_e( 'Configure SMTP and send a test email →', 'bmlt-literature-manager' ); ?></a></p>

			<?php elseif ( 'templates' === $tab ) : ?>
				<p><?php esc_html_e( 'Templates accept basic HTML. Placeholders are replaced when the email is sent; requester-supplied values are always escaped. Leave a field blank to use the default.', 'bmlt-literature-manager' ); ?></p>
				<details class="bmlt-lit-placeholders"><summary><?php esc_html_e( 'Available placeholders', 'bmlt-literature-manager' ); ?></summary>
					<table class="widefat striped"><tbody>
						<?php foreach ( Settings::placeholders() as $ph => $desc ) : ?>
							<tr><td><code><?php echo esc_html( $ph ); ?></code></td><td><?php echo esc_html( $desc ); ?></td></tr>
						<?php endforeach; ?>
					</tbody></table>
				</details>
				<?php foreach ( $templates as $key => $label ) : ?>
					<?php $t = Settings::template( $key ); ?>
					<div class="postbox bmlt-lit-box">
						<h2 class="hndle"><?php echo esc_html( $label ); ?></h2>
						<div class="inside">
							<p><label for="t-<?php echo esc_attr( $key ); ?>-s"><?php esc_html_e( 'Subject', 'bmlt-literature-manager' ); ?></label><br>
								<input type="text" id="t-<?php echo esc_attr( $key ); ?>-s" name="tpl[<?php echo esc_attr( $key ); ?>][subject]" class="large-text" value="<?php echo esc_attr( $t['subject'] ); ?>"></p>
							<p><label for="t-<?php echo esc_attr( $key ); ?>-b"><?php esc_html_e( 'Body (HTML)', 'bmlt-literature-manager' ); ?></label><br>
								<textarea id="t-<?php echo esc_attr( $key ); ?>-b" name="tpl[<?php echo esc_attr( $key ); ?>][body]" rows="10" class="large-text code"><?php echo esc_textarea( $t['body'] ); ?></textarea></p>
							<p><label><input type="checkbox" name="reset[<?php echo esc_attr( $key ); ?>]" value="1"> <?php esc_html_e( 'Reset this template to the default', 'bmlt-literature-manager' ); ?></label></p>
						</div>
					</div>
				<?php endforeach; ?>

			<?php elseif ( 'advanced' === $tab ) : ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><?php esc_html_e( 'Spam protection', 'bmlt-literature-manager' ); ?></th>
						<td>
							<label><?php esc_html_e( 'Allow at most', 'bmlt-literature-manager' ); ?> <input type="number" name="rate_limit_count" min="1" max="1000" class="small-text" value="<?php echo esc_attr( $g['rate_limit_count'] ); ?>"></label>
							<label><?php esc_html_e( 'orders per connection every', 'bmlt-literature-manager' ); ?> <input type="number" name="rate_limit_minutes" min="1" max="1440" class="small-text" value="<?php echo esc_attr( $g['rate_limit_minutes'] ); ?>"> <?php esc_html_e( 'minutes', 'bmlt-literature-manager' ); ?></label><br>
							<label><?php esc_html_e( 'Reject forms submitted faster than', 'bmlt-literature-manager' ); ?> <input type="number" name="min_submit_seconds" min="0" max="120" class="small-text" value="<?php echo esc_attr( $g['min_submit_seconds'] ); ?>"> <?php esc_html_e( 'seconds', 'bmlt-literature-manager' ); ?></label>
							<p class="description"><?php esc_html_e( 'A hidden honeypot field is always active. Developers can add a CAPTCHA with the bmlt_lit_order_form_captcha action and bmlt_lit_validate_captcha filter.', 'bmlt-literature-manager' ); ?></p>
						</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Uninstall', 'bmlt-literature-manager' ); ?></th>
						<td><label><input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( (int) $g['delete_data_on_uninstall'], 1 ); ?>> <strong><?php esc_html_e( 'Delete plugin data when uninstalling', 'bmlt-literature-manager' ); ?></strong></label>
							<p class="description"><?php esc_html_e( 'Off by default. When on, deleting the plugin removes the JSON data directory (all orders, catalog, inventory, audit history and settings) permanently. Deactivating never deletes data.', 'bmlt-literature-manager' ); ?></p></td></tr>
				</table>
			<?php endif; ?>

			<?php submit_button(); ?>
		</form>
	<?php endif; ?>
</div>
