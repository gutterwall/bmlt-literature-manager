<?php
/**
 * Dashboard view.
 *
 * @package BMLT_Lit
 * @version 6.3.0
 *
 * @var int   $area
 * @var int[] $areas
 * @var array $counts
 * @var array $alerts
 * @var array $recent
 * @var array $adjusts
 */

use BMLT_Lit\Audit;
use BMLT_Lit\Helpers;
use BMLT_Lit\Permissions;
use BMLT_Lit\Settings;

defined( 'ABSPATH' ) || exit;

$options    = Settings::area_options( Permissions::allowed_areas() );
$orders_url = admin_url( 'admin.php?page=bmlt-lit-orders' . ( $area ? '&area=' . $area : '' ) );
$cards      = array(
	array( __( 'New orders', 'bmlt-literature-manager' ), $counts['new'], add_query_arg( 'status', 'new', $orders_url ) ),
	array( __( 'Open special orders', 'bmlt-literature-manager' ), $counts['special'], add_query_arg( array( 'special' => '1', 'status' => 'open' ), $orders_url ) ),
	array( __( 'Waiting for fulfillment', 'bmlt-literature-manager' ), $counts['open'], add_query_arg( 'status', 'open', $orders_url ) ),
	array( __( 'Ready for delivery', 'bmlt-literature-manager' ), $counts['ready'], add_query_arg( 'status', 'ready', $orders_url ) ),
	array( __( 'Orders, last 7 days', 'bmlt-literature-manager' ), $counts['last_7'], '' ),
	array( __( 'Orders, last 30 days', 'bmlt-literature-manager' ), $counts['last_30'], '' ),
	array( __( 'Orders, last 45 days', 'bmlt-literature-manager' ), $counts['last_45'], '' ),
	array( __( 'Low-stock items', 'bmlt-literature-manager' ), $alerts['low'], current_user_can( Permissions::MANAGE_INVENTORY ) ? admin_url( 'admin.php?page=bmlt-lit-inventory&stock=low_stock' . ( $area ? '&area=' . $area : '' ) ) : '' ),
	array( __( 'Out-of-stock items', 'bmlt-literature-manager' ), $alerts['out'], current_user_can( Permissions::MANAGE_INVENTORY ) ? admin_url( 'admin.php?page=bmlt-lit-inventory&stock=special_order' . ( $area ? '&area=' . $area : '' ) ) : '' ),
);
?>
<div class="wrap bmlt-lit-admin">
	<h1><?php esc_html_e( 'Literature Dashboard', 'bmlt-literature-manager' ); ?></h1>

	<?php if ( count( $options ) > 1 ) : ?>
		<form method="get" class="bmlt-lit-inline-form">
			<input type="hidden" name="page" value="bmlt-lit">
			<label for="bmlt-lit-dash-area"><?php esc_html_e( 'Area', 'bmlt-literature-manager' ); ?></label>
			<select id="bmlt-lit-dash-area" name="area">
				<option value=""><?php esc_html_e( 'All my Areas', 'bmlt-literature-manager' ); ?></option>
				<?php foreach ( $options as $id => $name ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $area, $id ); ?>><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Show', 'bmlt-literature-manager' ), 'secondary', '', false ); ?>
		</form>
	<?php endif; ?>

	<?php if ( empty( $areas ) ) : ?>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'No Areas are configured or assigned to you yet.', 'bmlt-literature-manager' ); ?></p></div>
	<?php endif; ?>

	<ul class="bmlt-lit-cards">
		<?php foreach ( $cards as $c ) : ?>
			<li class="bmlt-lit-card">
				<span class="bmlt-lit-card__num"><?php echo esc_html( number_format_i18n( (int) $c[1] ) ); ?></span>
				<span class="bmlt-lit-card__label">
					<?php if ( $c[2] ) : ?>
						<a href="<?php echo esc_url( $c[2] ); ?>"><?php echo esc_html( $c[0] ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $c[0] ); ?>
					<?php endif; ?>
				</span>
			</li>
		<?php endforeach; ?>
	</ul>

	<div class="bmlt-lit-columns">
		<div class="bmlt-lit-col">
			<h2><?php esc_html_e( 'Recent orders', 'bmlt-literature-manager' ); ?></h2>
			<table class="widefat striped">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Order #', 'bmlt-literature-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Date', 'bmlt-literature-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Meeting', 'bmlt-literature-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Total', 'bmlt-literature-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'bmlt-literature-manager' ); ?></th>
				</tr></thead>
				<tbody>
					<?php if ( empty( $recent ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No orders yet.', 'bmlt-literature-manager' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $recent as $o ) : ?>
						<tr class="<?php echo (int) $o->has_special_order ? 'bmlt-lit-row--special' : ''; ?>">
							<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=bmlt-lit-orders&order_id=' . (int) $o->id ) ); ?>"><?php echo esc_html( $o->order_number ); ?></a><?php echo (int) $o->has_special_order ? ' ' . Helpers::stock_badge( 'special_order' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td><?php echo esc_html( Helpers::local_date( $o->created_at ) ); ?></td>
							<td><?php echo esc_html( $o->meeting_name ); ?><br><small><?php echo esc_html( $o->area_name ); ?></small></td>
							<td><?php echo esc_html( Helpers::format_money( $o->total ) ); ?></td>
							<td><?php echo esc_html( Helpers::order_status_label( $o->status ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<div class="bmlt-lit-col">
			<h2><?php esc_html_e( 'Low and out-of-stock items', 'bmlt-literature-manager' ); ?></h2>
			<table class="widefat striped">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Item', 'bmlt-literature-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Area', 'bmlt-literature-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Available', 'bmlt-literature-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'bmlt-literature-manager' ); ?></th>
				</tr></thead>
				<tbody>
					<?php if ( empty( $alerts['low_items'] ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'Everything is in stock.', 'bmlt-literature-manager' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $alerts['low_items'] as $li ) : ?>
						<tr>
							<td><?php echo esc_html( $li['name'] ); ?> <small>(<?php echo esc_html( $li['sku'] ); ?>)</small></td>
							<td><?php echo esc_html( $li['area'] ); ?></td>
							<td><?php echo (int) $li['available']; ?></td>
							<td><?php echo Helpers::stock_badge( $li['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Recent inventory adjustments', 'bmlt-literature-manager' ); ?></h2>
			<?php require __DIR__ . '/partial-audit.php'; ?>
		</div>
	</div>
</div>
