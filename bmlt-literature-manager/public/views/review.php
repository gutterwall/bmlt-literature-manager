<?php
/**
 * Order review step: server-priced summary with Edit / Submit.
 *
 * @package BMLT_Lit
 * @version 7.0.0
 *
 * @var array  $valid       Validated submission (area_id, meeting, contact fields, lines, _areas).
 * @var array  $preview     Server preview (items, subtotal, total, item_count, has_special).
 * @var string $sig         Review signature.
 * @var bool   $changed     Whether prices/availability changed since the last review.
 * @var array  $hidden      token, ts.
 * @var array  $values      Sanitized submitted values.
 * @var string $return      Return URL.
 * @var string $area_sig    Signature for the Area list.
 * @var bool   $show_prices Show prices.
 * @var string $form_id     Element ID.
 */

use BMLT_Lit\Helpers;
use BMLT_Lit\Settings;

defined( 'ABSPATH' ) || exit;

$meeting  = $valid['meeting'];
$delivery = (string) Settings::get( 'delivery_message' );
$turn     = (string) Settings::get( 'special_order_turnaround' );
$any_in   = false;
foreach ( $preview['items'] as $it ) {
	if ( (int) $it->quantity_in_stock > 0 ) {
		$any_in = true;
	}
}
?>
<div class="bmlt-lit bmlt-lit-order-form bmlt-lit-review" id="<?php echo esc_attr( $form_id ); ?>">
	<ol class="bmlt-lit-steps" aria-label="<?php esc_attr_e( 'Order progress', 'bmlt-literature-manager' ); ?>">
		<li class="bmlt-lit-steps__done"><?php esc_html_e( 'Choose literature', 'bmlt-literature-manager' ); ?></li>
		<li class="bmlt-lit-steps__current" aria-current="step"><?php esc_html_e( 'Review order', 'bmlt-literature-manager' ); ?></li>
		<li><?php esc_html_e( 'Confirmation', 'bmlt-literature-manager' ); ?></li>
	</ol>

	<h2 class="bmlt-lit-review__title" tabindex="-1"><?php esc_html_e( 'Review your order', 'bmlt-literature-manager' ); ?></h2>
	<p><?php esc_html_e( 'Please check everything below. Your order has not been sent yet.', 'bmlt-literature-manager' ); ?></p>

	<?php if ( $changed ) : ?>
		<div class="bmlt-lit-notice bmlt-lit-notice--warning" role="alert">
			<p><strong><?php esc_html_e( 'Your order changed since it was last reviewed (quantities, prices or availability). Please check the updated totals below, then submit.', 'bmlt-literature-manager' ); ?></strong></p>
		</div>
	<?php endif; ?>

	<?php if ( $preview['has_special'] ) : ?>
		<div class="bmlt-lit-special-warning" role="note">
			<p class="bmlt-lit-special-warning__title"><span aria-hidden="true">!</span> <?php esc_html_e( 'THIS ORDER CONTAINS SPECIAL ORDER ITEMS', 'bmlt-literature-manager' ); ?></p>
			<p>
				<?php
				/* translators: %s: turnaround, e.g. approximately one month. */
				echo esc_html( sprintf( __( 'Special-order items are listed last and may require %s to obtain and stock before delivery.', 'bmlt-literature-manager' ), $turn ) );
				?>
			</p>
		</div>
	<?php endif; ?>

	<dl class="bmlt-lit-dl">
		<div><dt><?php esc_html_e( 'Area', 'bmlt-literature-manager' ); ?></dt><dd><?php echo esc_html( Settings::area_name( (int) $valid['area_id'] ) ); ?></dd></div>
		<div><dt><?php esc_html_e( 'Meeting', 'bmlt-literature-manager' ); ?></dt><dd><?php echo esc_html( Helpers::meeting_label( $meeting ) ); ?></dd></div>
		<div><dt><?php esc_html_e( 'Name', 'bmlt-literature-manager' ); ?></dt><dd><?php echo esc_html( '' !== $valid['contact_name'] ? $valid['contact_name'] : '—' ); ?></dd></div>
		<div><dt><?php esc_html_e( 'Phone', 'bmlt-literature-manager' ); ?></dt><dd><?php echo esc_html( '' !== $valid['contact_phone'] ? $valid['contact_phone'] : '—' ); ?></dd></div>
		<div><dt><?php esc_html_e( 'Email', 'bmlt-literature-manager' ); ?></dt><dd><?php echo esc_html( '' !== $valid['contact_email'] ? $valid['contact_email'] : '—' ); ?></dd></div>
		<?php if ( '' !== trim( (string) $valid['notes'] ) ) : ?>
			<div><dt><?php esc_html_e( 'Notes', 'bmlt-literature-manager' ); ?></dt><dd><?php echo nl2br( esc_html( $valid['notes'] ) ); ?></dd></div>
		<?php endif; ?>
	</dl>

	<form method="post" action="<?php echo esc_url( $return ); ?>#<?php echo esc_attr( $form_id ); ?>" class="bmlt-lit-review__form">
	<div class="bmlt-lit-table-wrap">
		<table class="bmlt-lit-table bmlt-lit-table--stack bmlt-lit-review__items">
			<caption class="bmlt-lit-sr-only"><?php esc_html_e( 'Literature in this order', 'bmlt-literature-manager' ); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Item', 'bmlt-literature-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Qty', 'bmlt-literature-manager' ); ?></th>
					<?php if ( $show_prices ) : ?>
						<th scope="col"><?php esc_html_e( 'Price', 'bmlt-literature-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Line total', 'bmlt-literature-manager' ); ?></th>
					<?php endif; ?>
					<th scope="col"><?php esc_html_e( 'Special order', 'bmlt-literature-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $preview['items'] as $it ) : ?>
					<tr class="<?php echo (int) $it->quantity_special > 0 ? 'bmlt-lit-row--special' : ''; ?>">
						<td data-label="<?php esc_attr_e( 'Item', 'bmlt-literature-manager' ); ?>"><strong><?php echo esc_html( $it->name ); ?></strong><?php if ( '' !== trim( (string) $it->description ) ) : ?><br><small><?php echo esc_html( $it->description ); ?></small><?php endif; ?></td>
						<td data-label="<?php esc_attr_e( 'Qty', 'bmlt-literature-manager' ); ?>">
							<label class="bmlt-lit-sr-only" for="bmlt-lit-rq-<?php echo (int) $it->item_id; ?>"><?php echo esc_html( sprintf( /* translators: %s: item name. */ __( 'Quantity of %s', 'bmlt-literature-manager' ), $it->name ) ); ?></label>
							<input type="number" class="bmlt-lit-input bmlt-lit-review__qty" id="bmlt-lit-rq-<?php echo (int) $it->item_id; ?>" name="bmlt_lit_qty[<?php echo (int) $it->item_id; ?>]" value="<?php echo (int) $it->quantity; ?>" min="0" max="<?php echo esc_attr( max( 1, (int) Settings::get( 'max_qty_per_line' ) ) ); ?>" step="1" inputmode="numeric" data-original="<?php echo (int) $it->quantity; ?>">
						</td>
						<?php if ( $show_prices ) : ?>
							<td data-label="<?php esc_attr_e( 'Price', 'bmlt-literature-manager' ); ?>"><?php echo esc_html( Helpers::format_money( $it->unit_price ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Line total', 'bmlt-literature-manager' ); ?>"><?php echo esc_html( Helpers::format_money( $it->line_total ) ); ?></td>
						<?php endif; ?>
						<td data-label="<?php esc_attr_e( 'Special order', 'bmlt-literature-manager' ); ?>">
							<?php
							if ( (int) $it->quantity_special > 0 && (int) $it->quantity_in_stock > 0 ) {
								echo Helpers::stock_badge( 'special_order', sprintf( /* translators: 1: special qty, 2: total qty. */ __( '%1$d of %2$d', 'bmlt-literature-manager' ), (int) $it->quantity_special, (int) $it->quantity ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							} elseif ( (int) $it->quantity_special > 0 ) {
								echo Helpers::stock_badge( 'special_order' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							} else {
								echo '<span class="bmlt-lit-sr-only">' . esc_html__( 'No', 'bmlt-literature-manager' ) . '</span><span aria-hidden="true">—</span>';
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<p class="bmlt-lit-review__update">
		<?php /* First submit button in the form, so pressing Enter in a quantity updates the totals rather than submitting. */ ?>
		<button type="submit" name="bmlt_lit_step" value="review" class="bmlt-lit-button bmlt-lit-review__refresh"><?php esc_html_e( '↻ Update totals', 'bmlt-literature-manager' ); ?></button>
		<span class="bmlt-lit-help"><?php esc_html_e( 'Change a quantity (0 removes the item), then update the totals.', 'bmlt-literature-manager' ); ?></span>
	</p>
	<p class="bmlt-lit-notice bmlt-lit-notice--warning bmlt-lit-review__stale" role="status" hidden><?php esc_html_e( 'Quantities changed — select “Update totals” to see the new total before submitting.', 'bmlt-literature-manager' ); ?></p>

	<dl class="bmlt-lit-totals">
		<div><dt><?php esc_html_e( 'Items', 'bmlt-literature-manager' ); ?></dt><dd><?php echo (int) $preview['item_count']; ?></dd></div>
		<?php if ( $show_prices ) : ?>
			<div class="bmlt-lit-totals__total"><dt><?php esc_html_e( 'Total', 'bmlt-literature-manager' ); ?></dt><dd><?php echo esc_html( Helpers::format_money( $preview['total'] ) ); ?></dd></div>
		<?php endif; ?>
	</dl>

	<?php if ( $any_in && '' !== trim( $delivery ) ) : ?>
		<p class="bmlt-lit-delivery"><span aria-hidden="true">✓</span> <?php echo esc_html( $delivery ); ?></p>
	<?php endif; ?>

		<?php wp_nonce_field( 'bmlt_lit_submit_order', 'bmlt_lit_nonce' ); ?>
		<input type="hidden" name="bmlt_lit_action" value="submit_order">
		<input type="hidden" name="bmlt_lit_review_sig" value="<?php echo esc_attr( $sig ); ?>">
		<input type="hidden" name="bmlt_lit_token" value="<?php echo esc_attr( $hidden['token'] ); ?>">
		<input type="hidden" name="bmlt_lit_ts" value="<?php echo esc_attr( $hidden['ts'] ); ?>">
		<input type="hidden" name="bmlt_lit_areas" value="<?php echo esc_attr( implode( ',', $valid['_areas'] ) ); ?>">
		<input type="hidden" name="bmlt_lit_areas_sig" value="<?php echo esc_attr( $area_sig ); ?>">
		<input type="hidden" name="bmlt_lit_return" value="<?php echo esc_url( $return ); ?>">
		<input type="hidden" name="bmlt_lit_meeting" value="<?php echo esc_attr( (int) $meeting['id'] ); ?>">
		<input type="hidden" name="bmlt_lit_name" value="<?php echo esc_attr( $values['contact_name'] ); ?>">
		<input type="hidden" name="bmlt_lit_phone" value="<?php echo esc_attr( $values['contact_phone'] ); ?>">
		<input type="hidden" name="bmlt_lit_email" value="<?php echo esc_attr( $values['contact_email'] ); ?>">
		<input type="hidden" name="bmlt_lit_notes" value="<?php echo esc_attr( $values['notes'] ); ?>">
		<?php
		/** This action is documented in public/views/order-form.php */
		do_action( 'bmlt_lit_order_form_captcha' );
		?>
		<p class="bmlt-lit-actions">
			<button type="submit" name="bmlt_lit_step" value="edit" class="bmlt-lit-button" formnovalidate><?php esc_html_e( '← Edit order', 'bmlt-literature-manager' ); ?></button>
			<button type="submit" name="bmlt_lit_step" value="confirm" class="bmlt-lit-button bmlt-lit-button--primary bmlt-lit-submit"><?php esc_html_e( 'Submit order', 'bmlt-literature-manager' ); ?></button>
		</p>
	</form>
</div>
