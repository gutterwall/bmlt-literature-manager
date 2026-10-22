<?php
/**
 * Order confirmation view.
 *
 * @package BMLT_Lit
 * @version 6.3.0
 *
 * @var object   $order
 * @var object[] $items
 * @var bool     $show_prices
 * @var string   $new_url
 */

use BMLT_Lit\Email;
use BMLT_Lit\Helpers;
use BMLT_Lit\Settings;

defined( 'ABSPATH' ) || exit;

$delivery = Email::delivery_message( $order, $items, false );
$turn     = (string) Settings::get( 'special_order_turnaround' );
?>
<div class="bmlt-lit bmlt-lit-order-form bmlt-lit-confirmation" id="bmlt-lit-confirmation">
	<div class="bmlt-lit-notice bmlt-lit-notice--success" role="status" tabindex="-1">
		<h2 class="bmlt-lit-confirmation__title"><span aria-hidden="true">✓</span> <?php esc_html_e( 'Thank you — your order has been received', 'bmlt-literature-manager' ); ?></h2>
		<p class="bmlt-lit-confirmation__number">
			<?php esc_html_e( 'Order number:', 'bmlt-literature-manager' ); ?> <strong><?php echo esc_html( $order->order_number ); ?></strong>
		</p>
		<p><?php esc_html_e( 'Please keep this order number for your records.', 'bmlt-literature-manager' ); ?></p>
	</div>

	<?php if ( (int) $order->has_special_order ) : ?>
		<div class="bmlt-lit-special-warning" role="alert">
			<p class="bmlt-lit-special-warning__title"><span aria-hidden="true">!</span> <?php esc_html_e( 'THIS ORDER CONTAINS SPECIAL ORDER ITEMS', 'bmlt-literature-manager' ); ?></p>
			<p>
				<?php
				printf(
					/* translators: %s: turnaround, e.g. approximately one month. */
					esc_html__( 'Some literature is not currently in stock for your Area and must be ordered. Special-order items may require %s to obtain and stock before delivery.', 'bmlt-literature-manager' ),
					esc_html( $turn )
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<dl class="bmlt-lit-dl">
		<div><dt><?php esc_html_e( 'Area', 'bmlt-literature-manager' ); ?></dt><dd><?php echo esc_html( $order->area_name ); ?></dd></div>
		<div><dt><?php esc_html_e( 'Meeting', 'bmlt-literature-manager' ); ?></dt><dd><?php echo esc_html( $order->meeting_name ); ?><?php $when = Helpers::meeting_when( (int) $order->meeting_day, (string) $order->meeting_time ); echo '' !== $when ? ' — ' . esc_html( $when ) : ''; ?></dd></div>
		<div><dt><?php esc_html_e( 'Contact', 'bmlt-literature-manager' ); ?></dt><dd><?php echo esc_html( $order->contact_name ); ?></dd></div>
		<div><dt><?php esc_html_e( 'Phone', 'bmlt-literature-manager' ); ?></dt><dd><?php echo esc_html( $order->contact_phone ); ?></dd></div>
		<div><dt><?php esc_html_e( 'Email', 'bmlt-literature-manager' ); ?></dt><dd><?php echo esc_html( $order->contact_email ); ?></dd></div>
		<div><dt><?php esc_html_e( 'Submitted', 'bmlt-literature-manager' ); ?></dt><dd><?php echo esc_html( Helpers::local_date( $order->created_at ) ); ?></dd></div>
		<?php if ( '' !== trim( (string) $order->notes ) ) : ?>
			<div><dt><?php esc_html_e( 'Notes', 'bmlt-literature-manager' ); ?></dt><dd><?php echo nl2br( esc_html( $order->notes ) ); ?></dd></div>
		<?php endif; ?>
	</dl>

	<div class="bmlt-lit-table-wrap">
		<table class="bmlt-lit-table bmlt-lit-table--stack">
			<caption class="bmlt-lit-sr-only"><?php esc_html_e( 'Literature ordered', 'bmlt-literature-manager' ); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'SKU', 'bmlt-literature-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Literature', 'bmlt-literature-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Qty', 'bmlt-literature-manager' ); ?></th>
					<?php if ( $show_prices ) : ?>
						<th scope="col"><?php esc_html_e( 'Unit price', 'bmlt-literature-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Line total', 'bmlt-literature-manager' ); ?></th>
					<?php endif; ?>
					<th scope="col"><?php esc_html_e( 'Availability', 'bmlt-literature-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $items as $it ) : ?>
					<tr class="<?php echo (int) $it->quantity_special > 0 ? 'bmlt-lit-row--special' : ''; ?>">
						<td data-label="<?php esc_attr_e( 'SKU', 'bmlt-literature-manager' ); ?>"><?php echo esc_html( $it->sku ); ?></td>
						<td data-label="<?php esc_attr_e( 'Literature', 'bmlt-literature-manager' ); ?>"><?php echo esc_html( $it->name ); ?></td>
						<td data-label="<?php esc_attr_e( 'Qty', 'bmlt-literature-manager' ); ?>"><?php echo (int) $it->quantity; ?></td>
						<?php if ( $show_prices ) : ?>
							<td data-label="<?php esc_attr_e( 'Unit price', 'bmlt-literature-manager' ); ?>"><?php echo esc_html( Helpers::format_money( $it->unit_price ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Line total', 'bmlt-literature-manager' ); ?>"><?php echo esc_html( Helpers::format_money( $it->line_total ) ); ?></td>
						<?php endif; ?>
						<td data-label="<?php esc_attr_e( 'Availability', 'bmlt-literature-manager' ); ?>">
							<?php
							if ( (int) $it->quantity_special > 0 && (int) $it->quantity_in_stock > 0 ) {
								echo Helpers::stock_badge( 'partial', sprintf( /* translators: 1: in stock qty, 2: special qty. */ __( '%1$d from stock, %2$d special order', 'bmlt-literature-manager' ), (int) $it->quantity_in_stock, (int) $it->quantity_special ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							} elseif ( (int) $it->quantity_special > 0 ) {
								echo Helpers::stock_badge( 'special_order' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							} else {
								echo Helpers::stock_badge( 'low_stock' === $it->stock_status ? 'low_stock' : 'in_stock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<dl class="bmlt-lit-totals">
		<div><dt><?php esc_html_e( 'Items', 'bmlt-literature-manager' ); ?></dt><dd><?php echo (int) $order->item_count; ?></dd></div>
		<?php if ( $show_prices ) : ?>
			<div><dt><?php esc_html_e( 'Subtotal', 'bmlt-literature-manager' ); ?></dt><dd><?php echo esc_html( Helpers::format_money( $order->subtotal ) ); ?></dd></div>
			<div class="bmlt-lit-totals__total"><dt><?php esc_html_e( 'Total', 'bmlt-literature-manager' ); ?></dt><dd><?php echo esc_html( Helpers::format_money( $order->total ) ); ?></dd></div>
		<?php endif; ?>
	</dl>

	<?php if ( '' !== $delivery ) : ?>
		<p class="bmlt-lit-delivery"><span aria-hidden="true">✓</span> <?php echo esc_html( $delivery ); ?></p>
	<?php endif; ?>

	<p class="bmlt-lit-actions">
		<a class="bmlt-lit-button" href="<?php echo esc_url( $new_url ); ?>"><?php esc_html_e( 'Place another order', 'bmlt-literature-manager' ); ?></a>
	</p>
</div>
