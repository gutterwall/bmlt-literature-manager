<?php
/**
 * Printer-friendly order detail (standalone page, no theme chrome).
 *
 * @package BMLT_Lit
 * @version 6.2.0
 *
 * @var object   $order
 * @var object[] $items
 */

use BMLT_Lit\Helpers;
use BMLT_Lit\Settings;

defined( 'ABSPATH' ) || exit;

$special_count = 0;
foreach ( $items as $it ) {
	if ( (int) $it->quantity_special > 0 ) {
		++$special_count;
	}
}
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( sprintf( /* translators: %s: order number. */ __( 'Literature Order %s', 'bmlt-literature-manager' ), $order->order_number ) ); ?></title>
<style>
	body { font: 14px/1.45 Georgia, "Times New Roman", serif; color: #000; margin: 24px; }
	h1 { font-size: 22px; margin: 0 0 4px; }
	.org { margin: 0 0 16px; color: #333; }
	.special { border: 3px solid #000; padding: 10px 12px; margin: 12px 0 16px; }
	.special strong { font-size: 16px; }
	dl { display: grid; grid-template-columns: max-content 1fr; gap: 4px 16px; margin: 0 0 16px; }
	dt { font-weight: bold; }
	dd { margin: 0; }
	table { width: 100%; border-collapse: collapse; margin: 8px 0 12px; }
	th, td { border: 1px solid #555; padding: 5px 7px; text-align: left; vertical-align: top; }
	th { background: #eee; }
	tr.sp td { font-weight: bold; }
	.num { text-align: right; white-space: nowrap; }
	.tick { display: inline-block; width: 14px; height: 14px; border: 1px solid #000; margin-right: 4px; vertical-align: middle; }
	.toolbar { margin-bottom: 16px; }
	@media print { .toolbar { display: none; } body { margin: 0; } a { color: #000; text-decoration: none; } }
</style>
</head>
<body>
<div class="toolbar"><button type="button" onclick="window.print()"><?php esc_html_e( 'Print', 'bmlt-literature-manager' ); ?></button></div>

<h1><?php echo esc_html( sprintf( /* translators: %s: order number. */ __( 'Literature Order %s', 'bmlt-literature-manager' ), $order->order_number ) ); ?></h1>
<p class="org"><?php echo esc_html( (string) Settings::get( 'org_name' ) ); ?></p>

<?php if ( $special_count ) : ?>
	<div class="special">
		<strong>! <?php esc_html_e( 'THIS ORDER CONTAINS SPECIAL ORDER ITEMS', 'bmlt-literature-manager' ); ?></strong><br>
		<?php
		printf(
			/* translators: %s: turnaround. */
			esc_html__( 'Special-order literature may take %s to order and stock before delivery.', 'bmlt-literature-manager' ),
			esc_html( (string) Settings::get( 'special_order_turnaround' ) )
		);
		?>
	</div>
<?php endif; ?>

<dl>
	<dt><?php esc_html_e( 'Order date', 'bmlt-literature-manager' ); ?></dt><dd><?php echo esc_html( Helpers::local_date( $order->created_at ) ); ?></dd>
	<dt><?php esc_html_e( 'Status', 'bmlt-literature-manager' ); ?></dt><dd><?php echo esc_html( Helpers::order_status_label( $order->status ) ); ?></dd>
	<dt><?php esc_html_e( 'Area', 'bmlt-literature-manager' ); ?></dt><dd><?php echo esc_html( $order->area_name ); ?></dd>
	<dt><?php esc_html_e( 'Meeting', 'bmlt-literature-manager' ); ?></dt><dd><?php echo esc_html( $order->meeting_name ); ?> <?php echo esc_html( Helpers::meeting_when( (int) $order->meeting_day, (string) $order->meeting_time ) ); ?><?php echo $order->meeting_location ? '<br>' . esc_html( $order->meeting_location ) : ''; ?></dd>
	<dt><?php esc_html_e( 'Contact', 'bmlt-literature-manager' ); ?></dt><dd><?php echo esc_html( $order->contact_name ); ?></dd>
	<dt><?php esc_html_e( 'Telephone', 'bmlt-literature-manager' ); ?></dt><dd><?php echo esc_html( $order->contact_phone ); ?></dd>
	<dt><?php esc_html_e( 'Email', 'bmlt-literature-manager' ); ?></dt><dd><?php echo esc_html( $order->contact_email ); ?></dd>
	<dt><?php esc_html_e( 'Notes', 'bmlt-literature-manager' ); ?></dt><dd><?php echo '' !== trim( (string) $order->notes ) ? nl2br( esc_html( $order->notes ) ) : '—'; ?></dd>
</dl>

<table>
	<thead>
		<tr>
			<th scope="col"><span class="tick" aria-hidden="true"></span></th>
			<th scope="col"><?php esc_html_e( 'SKU', 'bmlt-literature-manager' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Literature', 'bmlt-literature-manager' ); ?></th>
			<th scope="col" class="num"><?php esc_html_e( 'Qty', 'bmlt-literature-manager' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Stock / special order', 'bmlt-literature-manager' ); ?></th>
			<th scope="col" class="num"><?php esc_html_e( 'Unit price', 'bmlt-literature-manager' ); ?></th>
			<th scope="col" class="num"><?php esc_html_e( 'Line total', 'bmlt-literature-manager' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $items as $it ) : ?>
			<tr class="<?php echo (int) $it->quantity_special > 0 ? 'sp' : ''; ?>">
				<td><span class="tick" aria-hidden="true"></span></td>
				<td><?php echo esc_html( $it->sku ); ?></td>
				<td><?php echo esc_html( $it->name ); ?></td>
				<td class="num"><?php echo (int) $it->quantity; ?></td>
				<td>
					<?php
					if ( (int) $it->quantity_special > 0 ) {
						echo '! ' . esc_html( sprintf( /* translators: 1: stock qty, 2: special qty. */ __( 'SPECIAL ORDER: %2$d (from stock: %1$d)', 'bmlt-literature-manager' ), (int) $it->quantity_in_stock, (int) $it->quantity_special ) );
					} else {
						echo '✓ ' . esc_html__( 'In stock', 'bmlt-literature-manager' );
					}
					?>
				</td>
				<td class="num"><?php echo esc_html( Helpers::format_money( $it->unit_price ) ); ?></td>
				<td class="num"><?php echo esc_html( Helpers::format_money( $it->line_total ) ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
	<tfoot>
		<tr><th colspan="6" class="num"><?php esc_html_e( 'Items', 'bmlt-literature-manager' ); ?></th><td class="num"><?php echo (int) $order->item_count; ?></td></tr>
		<tr><th colspan="6" class="num"><?php esc_html_e( 'Subtotal', 'bmlt-literature-manager' ); ?></th><td class="num"><?php echo esc_html( Helpers::format_money( $order->subtotal ) ); ?></td></tr>
		<tr><th colspan="6" class="num"><?php esc_html_e( 'Total', 'bmlt-literature-manager' ); ?></th><td class="num"><strong><?php echo esc_html( Helpers::format_money( $order->total ) ); ?></strong></td></tr>
	</tfoot>
</table>

<p><?php esc_html_e( 'Received by:', 'bmlt-literature-manager' ); ?> ______________________ &nbsp; <?php esc_html_e( 'Date:', 'bmlt-literature-manager' ); ?> ____________</p>
</body>
</html>
