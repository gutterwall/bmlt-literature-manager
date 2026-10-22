<?php
/**
 * Single order management view.
 *
 * @package BMLT_Lit
 * @version 7.0.0
 *
 * @var object   $order
 * @var object[] $items
 * @var object[] $notes
 */

use BMLT_Lit\Audit;
use BMLT_Lit\Email;
use BMLT_Lit\Helpers;
use BMLT_Lit\Permissions;
use BMLT_Lit\Settings;

defined( 'ABSPATH' ) || exit;

$can_manage = current_user_can( Permissions::MANAGE_ORDERS );
$is_final   = in_array( $order->status, array( 'cancelled', 'fulfilled' ), true );
$nonce      = 'bmlt_lit_order_' . (int) $order->id;
$print_url  = wp_nonce_url( admin_url( 'admin-post.php?action=bmlt_lit_print_order&order_id=' . (int) $order->id ), 'bmlt_lit_print_' . (int) $order->id );
$post_url   = admin_url( 'admin-post.php' );
$delivery   = Email::delivery_message( $order, $items, true );
$modes      = array(
	'reserve'  => __( 'Reserved at order, deducted when fulfilled', 'bmlt-literature-manager' ),
	'deduct'   => __( 'Deducted when ordered', 'bmlt-literature-manager' ),
	'none'      => __( 'Unchanged until fulfilled', 'bmlt-literature-manager' ),
	'untracked' => __( 'Area does not track quantities (special orders marked per item)', 'bmlt-literature-manager' ),
);
?>
<div class="wrap bmlt-lit-admin">
	<h1 class="wp-heading-inline">
		<?php
		/* translators: %s: order number. */
		echo esc_html( sprintf( __( 'Order %s', 'bmlt-literature-manager' ), $order->order_number ) );
		?>
	</h1>
	<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=bmlt-lit-orders' ) ); ?>"><?php esc_html_e( '← All orders', 'bmlt-literature-manager' ); ?></a>
	<a class="page-title-action" href="<?php echo esc_url( $print_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Print Order', 'bmlt-literature-manager' ); ?></a>
	<hr class="wp-header-end">

	<?php if ( (int) $order->has_special_order ) : ?>
		<div class="bmlt-lit-special-box" role="note">
			<p><strong>! <?php esc_html_e( 'THIS ORDER CONTAINS SPECIAL ORDER ITEMS', 'bmlt-literature-manager' ); ?></strong></p>
			<p>
				<?php
				printf(
					/* translators: %s: turnaround. */
					esc_html__( 'Highlighted items are not in stock for this Area and may require %s to obtain and stock.', 'bmlt-literature-manager' ),
					esc_html( (string) Settings::get( 'special_order_turnaround' ) )
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<div class="bmlt-lit-columns">
		<div class="bmlt-lit-col bmlt-lit-col--wide">
			<table class="form-table bmlt-lit-kv" role="presentation">
				<tr><th scope="row"><?php esc_html_e( 'Status', 'bmlt-literature-manager' ); ?></th><td><span class="bmlt-lit-status bmlt-lit-status--<?php echo esc_attr( $order->status ); ?>"><?php echo esc_html( Helpers::order_status_label( $order->status ) ); ?></span></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Order date', 'bmlt-literature-manager' ); ?></th><td><?php echo esc_html( Helpers::local_date( $order->created_at ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Area', 'bmlt-literature-manager' ); ?></th><td><?php echo esc_html( $order->area_name ); ?> <small>(<?php echo esc_html( sprintf( /* translators: %d: ID. */ __( 'BMLT ID %d', 'bmlt-literature-manager' ), (int) $order->area_id ) ); ?>)</small></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Meeting', 'bmlt-literature-manager' ); ?></th><td><?php echo esc_html( $order->meeting_name ); ?> <small>(<?php echo esc_html( sprintf( /* translators: %d: ID. */ __( 'BMLT ID %d', 'bmlt-literature-manager' ), (int) $order->meeting_id ) ); ?>)</small><br><?php echo esc_html( Helpers::meeting_when( (int) $order->meeting_day, (string) $order->meeting_time ) ); ?> <?php echo esc_html( $order->meeting_location ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Contact name', 'bmlt-literature-manager' ); ?></th><td><?php echo esc_html( $order->contact_name ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Phone', 'bmlt-literature-manager' ); ?></th><td><?php echo esc_html( $order->contact_phone ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Email', 'bmlt-literature-manager' ); ?></th><td><?php if ( $order->contact_email ) : ?><a href="<?php echo esc_url( 'mailto:' . $order->contact_email ); ?>"><?php echo esc_html( $order->contact_email ); ?></a><?php endif; ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Notes', 'bmlt-literature-manager' ); ?></th><td><?php echo '' !== trim( (string) $order->notes ) ? nl2br( esc_html( $order->notes ) ) : '—'; ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Inventory handling', 'bmlt-literature-manager' ); ?></th><td><?php echo esc_html( isset( $modes[ $order->inventory_mode ] ) ? $modes[ $order->inventory_mode ] : $order->inventory_mode ); ?></td></tr>
				<?php if ( $order->fulfilled_at ) : ?>
					<tr><th scope="row"><?php esc_html_e( 'Fulfilled', 'bmlt-literature-manager' ); ?></th><td><?php echo esc_html( Helpers::local_date( $order->fulfilled_at ) . ' — ' . Audit::user_name( (int) $order->fulfilled_by ) ); ?></td></tr>
				<?php endif; ?>
			</table>

			<h2><?php esc_html_e( 'Literature', 'bmlt-literature-manager' ); ?></h2>
			<table class="widefat striped">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'SKU', 'bmlt-literature-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Literature', 'bmlt-literature-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Qty', 'bmlt-literature-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Unit price', 'bmlt-literature-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Line total', 'bmlt-literature-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Stock / special order', 'bmlt-literature-manager' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $items as $it ) : ?>
						<tr class="<?php echo (int) $it->quantity_special > 0 ? 'bmlt-lit-row--special' : ''; ?>">
							<td><?php echo esc_html( $it->sku ); ?></td>
							<td><?php echo esc_html( $it->name ); ?></td>
							<td><?php echo (int) $it->quantity; ?></td>
							<td><?php echo esc_html( Helpers::format_money( $it->unit_price ) ); ?></td>
							<td><?php echo esc_html( Helpers::format_money( $it->line_total ) ); ?></td>
							<td>
								<?php
								if ( (int) $it->quantity_special > 0 && (int) $it->quantity_in_stock > 0 ) {
									echo Helpers::stock_badge( 'partial', sprintf( /* translators: 1: stock qty, 2: special qty. */ __( '%1$d from stock, %2$d special order', 'bmlt-literature-manager' ), (int) $it->quantity_in_stock, (int) $it->quantity_special ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								} elseif ( (int) $it->quantity_special > 0 ) {
									echo Helpers::stock_badge( 'special_order', sprintf( /* translators: %d: qty. */ __( '%d special order', 'bmlt-literature-manager' ), (int) $it->quantity_special ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								} else {
									echo Helpers::stock_badge( 'low_stock' === $it->stock_status ? 'low_stock' : 'in_stock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr><th colspan="4" class="bmlt-lit-right"><?php esc_html_e( 'Items', 'bmlt-literature-manager' ); ?></th><td colspan="2"><?php echo (int) $order->item_count; ?></td></tr>
					<tr><th colspan="4" class="bmlt-lit-right"><?php esc_html_e( 'Subtotal', 'bmlt-literature-manager' ); ?></th><td colspan="2"><?php echo esc_html( Helpers::format_money( $order->subtotal ) ); ?></td></tr>
					<tr><th colspan="4" class="bmlt-lit-right"><?php esc_html_e( 'Total', 'bmlt-literature-manager' ); ?></th><td colspan="2"><strong><?php echo esc_html( Helpers::format_money( $order->total ) ); ?></strong></td></tr>
				</tfoot>
			</table>
			<?php if ( '' !== $delivery ) : ?>
				<p class="bmlt-lit-delivery-note">✓ <?php echo esc_html( $delivery ); ?></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'History', 'bmlt-literature-manager' ); ?></h2>
			<ul class="bmlt-lit-history">
				<?php foreach ( $notes as $n ) : ?>
					<li class="bmlt-lit-history--<?php echo esc_attr( $n->note_type ); ?>">
						<span class="bmlt-lit-history__meta"><?php echo esc_html( Helpers::local_date( $n->created_at ) . ' — ' . Audit::user_name( (int) $n->user_id ) ); ?></span>
						<?php echo nl2br( esc_html( $n->note ) ); ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>

		<?php if ( $can_manage ) : ?>
			<div class="bmlt-lit-col">
				<div class="postbox bmlt-lit-box">
					<h2 class="hndle"><?php esc_html_e( 'Update status', 'bmlt-literature-manager' ); ?></h2>
					<div class="inside">
						<?php if ( $is_final ) : ?>
							<p><?php esc_html_e( 'This order is final and can no longer change status.', 'bmlt-literature-manager' ); ?></p>
						<?php else : ?>
							<form method="post" action="<?php echo esc_url( $post_url ); ?>">
								<?php wp_nonce_field( $nonce ); ?>
								<input type="hidden" name="action" value="bmlt_lit_order_status">
								<input type="hidden" name="order_id" value="<?php echo (int) $order->id; ?>">
								<p>
									<label for="bmlt-lit-status-note"><?php esc_html_e( 'Optional note', 'bmlt-literature-manager' ); ?></label><br>
									<textarea id="bmlt-lit-status-note" name="note" rows="3" class="large-text" maxlength="2000"></textarea>
								</p>
								<p>
									<input type="checkbox" id="bmlt-lit-notify" name="notify" value="1" <?php checked( (bool) Settings::get( 'notify_requester_ready' ) ); ?>>
									<label for="bmlt-lit-notify"><?php esc_html_e( 'Email the requester when marking Ready or Waiting for Special Order', 'bmlt-literature-manager' ); ?></label>
								</p>
								<div class="bmlt-lit-button-stack">
									<?php
									$buttons = array(
										'reviewed'        => array( __( 'Mark Reviewed', 'bmlt-literature-manager' ), 'secondary', '' ),
										'waiting_special' => array( __( 'Waiting for Special Order', 'bmlt-literature-manager' ), 'secondary', '' ),
										'ready'           => array( __( 'Mark Ready', 'bmlt-literature-manager' ), 'secondary', '' ),
										'partial'         => array( __( 'Partially Fulfilled', 'bmlt-literature-manager' ), 'secondary', '' ),
										'fulfilled'       => array( __( 'Mark Fulfilled', 'bmlt-literature-manager' ), 'primary', __( 'Mark this order fulfilled? In-stock quantities will be permanently deducted from inventory.', 'bmlt-literature-manager' ) ),
										'cancelled'       => array( __( 'Cancel Order', 'bmlt-literature-manager' ), 'delete', __( 'Cancel this order? Reserved inventory will be released. This cannot be undone.', 'bmlt-literature-manager' ) ),
									);
									foreach ( $buttons as $status => $b ) :
										if ( $status === $order->status ) {
											continue;
										}
										?>
										<button type="submit" name="status" value="<?php echo esc_attr( $status ); ?>" class="button button-<?php echo esc_attr( 'delete' === $b[1] ? 'secondary bmlt-lit-danger' : $b[1] ); ?>"<?php echo $b[2] ? ' data-bmlt-confirm="' . esc_attr( $b[2] ) . '"' : ''; ?>><?php echo esc_html( $b[0] ); ?></button>
									<?php endforeach; ?>
								</div>
							</form>
						<?php endif; ?>
					</div>
				</div>

				<div class="postbox bmlt-lit-box">
					<h2 class="hndle"><?php esc_html_e( 'Notifications', 'bmlt-literature-manager' ); ?></h2>
					<div class="inside">
						<form method="post" action="<?php echo esc_url( $post_url ); ?>">
							<?php wp_nonce_field( $nonce ); ?>
							<input type="hidden" name="action" value="bmlt_lit_order_resend">
							<input type="hidden" name="order_id" value="<?php echo (int) $order->id; ?>">
							<p><button type="submit" class="button"><?php esc_html_e( 'Resend Notification', 'bmlt-literature-manager' ); ?></button></p>
							<p class="description"><?php esc_html_e( 'Sends the order again to this Area’s literature workers.', 'bmlt-literature-manager' ); ?></p>
						</form>
						<hr>
						<form method="post" action="<?php echo esc_url( $post_url ); ?>">
							<?php wp_nonce_field( $nonce ); ?>
							<input type="hidden" name="action" value="bmlt_lit_order_email">
							<input type="hidden" name="order_id" value="<?php echo (int) $order->id; ?>">
							<p>
								<label for="bmlt-lit-email-msg"><?php esc_html_e( 'Email Requester', 'bmlt-literature-manager' ); ?></label><br>
								<textarea id="bmlt-lit-email-msg" name="message" rows="4" class="large-text" maxlength="5000" <?php disabled( ! is_email( $order->contact_email ) ); ?>></textarea>
							</p>
							<p><button type="submit" class="button" <?php disabled( ! is_email( $order->contact_email ) ); ?>><?php esc_html_e( 'Send email', 'bmlt-literature-manager' ); ?></button></p>
						</form>
					</div>
				</div>

				<div class="postbox bmlt-lit-box">
					<h2 class="hndle"><?php esc_html_e( 'Add note', 'bmlt-literature-manager' ); ?></h2>
					<div class="inside">
						<form method="post" action="<?php echo esc_url( $post_url ); ?>">
							<?php wp_nonce_field( $nonce ); ?>
							<input type="hidden" name="action" value="bmlt_lit_order_note">
							<input type="hidden" name="order_id" value="<?php echo (int) $order->id; ?>">
							<p>
								<label class="screen-reader-text" for="bmlt-lit-note"><?php esc_html_e( 'Note', 'bmlt-literature-manager' ); ?></label>
								<textarea id="bmlt-lit-note" name="note" rows="3" class="large-text" maxlength="5000"></textarea>
							</p>
							<p><button type="submit" class="button"><?php esc_html_e( 'Add note', 'bmlt-literature-manager' ); ?></button></p>
						</form>
					</div>
				</div>
			</div>
		<?php endif; ?>
	</div>
</div>
