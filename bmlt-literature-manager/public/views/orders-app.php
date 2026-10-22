<?php
/**
 * Shared order-history application markup (used by both protected shortcodes).
 *
 * @package BMLT_Lit
 * @version 6.2.0
 *
 * @var array $config
 */

use BMLT_Lit\Helpers;

defined( 'ABSPATH' ) || exit;

$uid = 'bmlt-lit-o-' . wp_generate_password( 5, false, false );
?>
<div class="bmlt-lit-orders-app" data-bmlt-lit-orders>
	<form class="bmlt-lit-filters bmlt-lit-orders-filters" role="search" aria-label="<?php esc_attr_e( 'Filter orders', 'bmlt-literature-manager' ); ?>">
		<?php if ( count( $config['areas'] ) > 1 ) : ?>
			<p class="bmlt-lit-field">
				<label for="<?php echo esc_attr( $uid ); ?>-area"><?php esc_html_e( 'Area', 'bmlt-literature-manager' ); ?></label>
				<select id="<?php echo esc_attr( $uid ); ?>-area" name="area" class="bmlt-lit-select">
					<option value=""><?php esc_html_e( 'All my Areas', 'bmlt-literature-manager' ); ?></option>
					<?php foreach ( $config['areas'] as $aid => $aname ) : ?>
						<option value="<?php echo esc_attr( $aid ); ?>" <?php selected( (int) $config['defaultArea'], (int) $aid ); ?>><?php echo esc_html( $aname ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
		<?php endif; ?>
		<p class="bmlt-lit-field">
			<label for="<?php echo esc_attr( $uid ); ?>-status"><?php esc_html_e( 'Status', 'bmlt-literature-manager' ); ?></label>
			<select id="<?php echo esc_attr( $uid ); ?>-status" name="status" class="bmlt-lit-select">
				<option value=""><?php esc_html_e( 'Any status', 'bmlt-literature-manager' ); ?></option>
				<option value="open"><?php esc_html_e( 'Open (not fulfilled/cancelled)', 'bmlt-literature-manager' ); ?></option>
				<?php foreach ( Helpers::order_statuses() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="bmlt-lit-field">
			<label for="<?php echo esc_attr( $uid ); ?>-special"><?php esc_html_e( 'Special orders', 'bmlt-literature-manager' ); ?></label>
			<select id="<?php echo esc_attr( $uid ); ?>-special" name="special" class="bmlt-lit-select">
				<option value=""><?php esc_html_e( 'All orders', 'bmlt-literature-manager' ); ?></option>
				<option value="1"><?php esc_html_e( 'With special-order items', 'bmlt-literature-manager' ); ?></option>
				<option value="0"><?php esc_html_e( 'Without special-order items', 'bmlt-literature-manager' ); ?></option>
			</select>
		</p>
		<p class="bmlt-lit-field">
			<label for="<?php echo esc_attr( $uid ); ?>-search"><?php esc_html_e( 'Order #, name, email or phone', 'bmlt-literature-manager' ); ?></label>
			<input type="search" id="<?php echo esc_attr( $uid ); ?>-search" name="search" class="bmlt-lit-input" maxlength="100">
		</p>
		<p class="bmlt-lit-field">
			<label for="<?php echo esc_attr( $uid ); ?>-meeting"><?php esc_html_e( 'Meeting', 'bmlt-literature-manager' ); ?></label>
			<input type="search" id="<?php echo esc_attr( $uid ); ?>-meeting" name="meeting" class="bmlt-lit-input" maxlength="100">
		</p>
		<p class="bmlt-lit-field">
			<label for="<?php echo esc_attr( $uid ); ?>-from"><?php esc_html_e( 'From date', 'bmlt-literature-manager' ); ?></label>
			<input type="date" id="<?php echo esc_attr( $uid ); ?>-from" name="date_from" class="bmlt-lit-input">
		</p>
		<p class="bmlt-lit-field">
			<label for="<?php echo esc_attr( $uid ); ?>-to"><?php esc_html_e( 'To date', 'bmlt-literature-manager' ); ?></label>
			<input type="date" id="<?php echo esc_attr( $uid ); ?>-to" name="date_to" class="bmlt-lit-input">
		</p>
		<p class="bmlt-lit-field">
			<label for="<?php echo esc_attr( $uid ); ?>-sort"><?php esc_html_e( 'Sort by', 'bmlt-literature-manager' ); ?></label>
			<select id="<?php echo esc_attr( $uid ); ?>-sort" name="sort" class="bmlt-lit-select">
				<option value="newest"><?php esc_html_e( 'Newest first', 'bmlt-literature-manager' ); ?></option>
				<option value="oldest"><?php esc_html_e( 'Oldest first', 'bmlt-literature-manager' ); ?></option>
				<option value="meeting"><?php esc_html_e( 'Meeting', 'bmlt-literature-manager' ); ?></option>
				<option value="area"><?php esc_html_e( 'Area', 'bmlt-literature-manager' ); ?></option>
				<option value="status"><?php esc_html_e( 'Status', 'bmlt-literature-manager' ); ?></option>
				<option value="total"><?php esc_html_e( 'Total (highest first)', 'bmlt-literature-manager' ); ?></option>
			</select>
		</p>
		<p class="bmlt-lit-field bmlt-lit-field--actions">
			<button type="submit" class="bmlt-lit-button bmlt-lit-button--primary"><?php esc_html_e( 'Apply filters', 'bmlt-literature-manager' ); ?></button>
			<button type="reset" class="bmlt-lit-button"><?php esc_html_e( 'Reset', 'bmlt-literature-manager' ); ?></button>
		</p>
	</form>

	<p class="bmlt-lit-status-line" aria-live="polite"></p>

	<div class="bmlt-lit-table-wrap">
		<table class="bmlt-lit-table bmlt-lit-table--stack bmlt-lit-orders-table">
			<caption class="bmlt-lit-sr-only"><?php esc_html_e( 'Literature orders', 'bmlt-literature-manager' ); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Order #', 'bmlt-literature-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Date', 'bmlt-literature-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Area', 'bmlt-literature-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Meeting', 'bmlt-literature-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Contact', 'bmlt-literature-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Total', 'bmlt-literature-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Special order', 'bmlt-literature-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'bmlt-literature-manager' ); ?></th>
				</tr>
			</thead>
			<tbody></tbody>
		</table>
	</div>

	<p class="bmlt-lit-actions">
		<button type="button" class="bmlt-lit-button bmlt-lit-load-more" hidden><?php esc_html_e( 'Load more', 'bmlt-literature-manager' ); ?></button>
		<button type="button" class="bmlt-lit-button bmlt-lit-load-previous" hidden><?php esc_html_e( 'Load Previous 45 Days', 'bmlt-literature-manager' ); ?></button>
	</p>

	<section class="bmlt-lit-order-detail" tabindex="-1" hidden aria-label="<?php esc_attr_e( 'Order details', 'bmlt-literature-manager' ); ?>"></section>
</div>
