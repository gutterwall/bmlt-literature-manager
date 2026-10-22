<?php
/**
 * Area inventory view.
 *
 * @package BMLT_Lit
 * @version 6.3.0
 *
 * @var int    $area
 * @var bool   $tracked
 * @var array  $areas
 * @var array  $rows
 * @var string $search
 * @var string $filter
 * @var array  $history
 */

use BMLT_Lit\Helpers;
use BMLT_Lit\Settings;

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap bmlt-lit-admin">
	<h1><?php esc_html_e( 'Area Inventory', 'bmlt-literature-manager' ); ?></h1>

	<?php if ( empty( $areas ) ) : ?>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'No Areas are configured or assigned to you yet.', 'bmlt-literature-manager' ); ?></p></div>
	<?php else : ?>
		<form method="get" class="bmlt-lit-inline-form">
			<input type="hidden" name="page" value="bmlt-lit-inventory">
			<label for="bmlt-lit-inv-area"><?php esc_html_e( 'Area', 'bmlt-literature-manager' ); ?></label>
			<select id="bmlt-lit-inv-area" name="area">
				<?php foreach ( $areas as $id => $name ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $area, $id ); ?>><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
			</select>
			<label for="bmlt-lit-inv-stock"><?php esc_html_e( 'Show', 'bmlt-literature-manager' ); ?></label>
			<select id="bmlt-lit-inv-stock" name="stock">
				<option value=""><?php esc_html_e( 'All items', 'bmlt-literature-manager' ); ?></option>
				<option value="in_stock" <?php selected( $filter, 'in_stock' ); ?>><?php esc_html_e( 'In stock', 'bmlt-literature-manager' ); ?></option>
				<option value="low_stock" <?php selected( $filter, 'low_stock' ); ?>><?php esc_html_e( 'Low stock', 'bmlt-literature-manager' ); ?></option>
				<option value="special_order" <?php selected( $filter, 'special_order' ); ?>><?php esc_html_e( 'Out of stock / special order', 'bmlt-literature-manager' ); ?></option>
				<option value="inactive" <?php selected( $filter, 'inactive' ); ?>><?php esc_html_e( 'Inactive items', 'bmlt-literature-manager' ); ?></option>
			</select>
			<label for="bmlt-lit-inv-s"><?php esc_html_e( 'Search', 'bmlt-literature-manager' ); ?></label>
			<input type="search" id="bmlt-lit-inv-s" name="s" value="<?php echo esc_attr( $search ); ?>">
			<?php submit_button( __( 'Show', 'bmlt-literature-manager' ), 'secondary', '', false ); ?>
		</form>

		<p class="description">
			<?php
			printf(
				/* translators: %s: Area name. */
				esc_html__( 'Inventory below applies only to %s. Other Areas keep their own separate inventory.', 'bmlt-literature-manager' ),
				'<strong>' . esc_html( Settings::area_name( $area ) ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
			?>
			<?php if ( $tracked ) : ?>
				<?php esc_html_e( 'Set “On hand” to the counted quantity, or use “Adjust by” for receipts (+) and losses (−). Reserved quantities belong to open orders.', 'bmlt-literature-manager' ); ?>
			<?php else : ?>
				<strong><?php esc_html_e( 'This Area does not track quantities.', 'bmlt-literature-manager' ); ?></strong>
				<?php esc_html_e( 'Tick the items that are special orders for this Area. Change this under Settings → Area Configuration.', 'bmlt-literature-manager' ); ?>
			<?php endif; ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'bmlt_lit_save_inventory' ); ?>
			<input type="hidden" name="action" value="bmlt_lit_save_inventory">
			<input type="hidden" name="area" value="<?php echo esc_attr( $area ); ?>">
			<?php if ( ! $tracked ) : ?>
				<div class="bmlt-lit-table-scroll">
					<table class="widefat striped bmlt-lit-inv-admin">
						<thead><tr>
							<th scope="col"><?php esc_html_e( 'Item', 'bmlt-literature-manager' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Price', 'bmlt-literature-manager' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Special order for this Area', 'bmlt-literature-manager' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'bmlt-literature-manager' ); ?></th>
						</tr></thead>
						<tbody>
							<?php if ( empty( $rows ) ) : ?>
								<tr><td colspan="4"><?php esc_html_e( 'No literature items found. Add items under Literature → Items.', 'bmlt-literature-manager' ); ?></td></tr>
							<?php endif; ?>
							<?php foreach ( $rows as $r ) : ?>
								<?php $k = 'inv[' . (int) $r['item_id'] . ']'; ?>
								<tr class="<?php echo 'special_order' === $r['status'] ? 'bmlt-lit-row--special' : ( 'inactive' === $r['status'] ? 'bmlt-lit-row--inactive' : '' ); ?>">
									<td><strong><?php echo esc_html( $r['name'] ); ?></strong><br><small><?php echo esc_html( $r['sku'] ); ?></small></td>
									<td><?php echo esc_html( $r['price_formatted'] ); ?></td>
									<td>
										<input type="hidden" name="<?php echo esc_attr( $k ); ?>[orig_special]" value="<?php echo (int) $r['special']; ?>">
										<label><input type="checkbox" name="<?php echo esc_attr( $k ); ?>[special]" value="1" <?php checked( (int) $r['special'], 1 ); ?>> <span class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %s: item. */ __( 'Special order: %s', 'bmlt-literature-manager' ), $r['name'] ) ); ?></span><?php esc_html_e( 'Special order', 'bmlt-literature-manager' ); ?></label>
									</td>
									<td><?php echo Helpers::stock_badge( $r['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php else : ?>
			<div class="bmlt-lit-table-scroll">
				<table class="widefat striped bmlt-lit-inv-admin">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Item', 'bmlt-literature-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Price', 'bmlt-literature-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'On hand', 'bmlt-literature-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Adjust by', 'bmlt-literature-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Reserved', 'bmlt-literature-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Available', 'bmlt-literature-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Low-stock threshold', 'bmlt-literature-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Reorder qty', 'bmlt-literature-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'bmlt-literature-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Notes', 'bmlt-literature-manager' ); ?></th>
					</tr></thead>
					<tbody>
						<?php if ( empty( $rows ) ) : ?>
							<tr><td colspan="10"><?php esc_html_e( 'No literature items found. Add items under Literature → Items.', 'bmlt-literature-manager' ); ?></td></tr>
						<?php endif; ?>
						<?php
						foreach ( $rows as $r ) :
							$k   = 'inv[' . (int) $r['item_id'] . ']';
							$cls = 'special_order' === $r['status'] ? 'bmlt-lit-row--special' : ( 'low_stock' === $r['status'] ? 'bmlt-lit-row--low' : ( 'inactive' === $r['status'] ? 'bmlt-lit-row--inactive' : '' ) );
							$lbl = $r['name'];
							?>
							<tr class="<?php echo esc_attr( $cls ); ?>">
								<td><strong><?php echo esc_html( $r['name'] ); ?></strong><br><small><?php echo esc_html( $r['sku'] ); ?><?php echo $r['item_area_id'] ? ' — ' . esc_html__( 'Area catalog', 'bmlt-literature-manager' ) : ''; ?></small>
									<?php if ( $r['updated_at'] ) : ?><br><small class="description"><?php echo esc_html( $r['updated_at'] . ' — ' . $r['updated_by'] ); ?></small><?php endif; ?>
								</td>
								<td><?php echo esc_html( $r['price_formatted'] ); ?></td>
								<td>
									<input type="hidden" name="<?php echo esc_attr( $k ); ?>[orig_on_hand]" value="<?php echo esc_attr( $r['on_hand'] ); ?>">
									<span class="bmlt-lit-stepper">
										<button type="button" class="button bmlt-lit-step" data-step="-1" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: item. */ __( 'Decrease on hand for %s', 'bmlt-literature-manager' ), $lbl ) ); ?>">−</button>
										<input type="number" min="0" step="1" class="small-text" name="<?php echo esc_attr( $k ); ?>[on_hand]" value="<?php echo esc_attr( $r['on_hand'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: item. */ __( 'On hand for %s', 'bmlt-literature-manager' ), $lbl ) ); ?>">
										<button type="button" class="button bmlt-lit-step" data-step="1" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: item. */ __( 'Increase on hand for %s', 'bmlt-literature-manager' ), $lbl ) ); ?>">+</button>
									</span>
								</td>
								<td><input type="number" step="1" class="small-text" name="<?php echo esc_attr( $k ); ?>[delta]" value="" placeholder="±" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: item. */ __( 'Adjust %s by', 'bmlt-literature-manager' ), $lbl ) ); ?>"></td>
								<td><?php echo (int) $r['reserved']; ?></td>
								<td><strong><?php echo (int) $r['available']; ?></strong></td>
								<td>
									<input type="hidden" name="<?php echo esc_attr( $k ); ?>[orig_low]" value="<?php echo esc_attr( $r['low_stock_threshold'] ); ?>">
									<input type="number" min="0" step="1" class="small-text" name="<?php echo esc_attr( $k ); ?>[low]" value="<?php echo esc_attr( $r['low_stock_threshold'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: item. */ __( 'Low-stock threshold for %s', 'bmlt-literature-manager' ), $lbl ) ); ?>">
								</td>
								<td>
									<input type="hidden" name="<?php echo esc_attr( $k ); ?>[orig_reorder]" value="<?php echo esc_attr( $r['reorder_quantity'] ); ?>">
									<input type="number" min="0" step="1" class="small-text" name="<?php echo esc_attr( $k ); ?>[reorder]" value="<?php echo esc_attr( $r['reorder_quantity'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: item. */ __( 'Reorder quantity for %s', 'bmlt-literature-manager' ), $lbl ) ); ?>">
								</td>
								<td><?php echo Helpers::stock_badge( $r['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
								<td>
									<input type="hidden" name="<?php echo esc_attr( $k ); ?>[orig_notes]" value="<?php echo esc_attr( $r['notes'] ); ?>">
									<input type="text" class="regular-text bmlt-lit-notes" maxlength="2000" name="<?php echo esc_attr( $k ); ?>[notes]" value="<?php echo esc_attr( $r['notes'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: item. */ __( 'Inventory notes for %s', 'bmlt-literature-manager' ), $lbl ) ); ?>">
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
			<p>
				<label for="bmlt-lit-reason"><strong><?php esc_html_e( 'Adjustment reason', 'bmlt-literature-manager' ); ?></strong></label><br>
				<input type="text" id="bmlt-lit-reason" name="reason" class="large-text" maxlength="500" placeholder="<?php esc_attr_e( 'e.g. Monthly count, received NAWS shipment, damaged copies', 'bmlt-literature-manager' ); ?>">
			</p>
			<?php submit_button( __( 'Save inventory changes', 'bmlt-literature-manager' ) ); ?>
		</form>

		<h2><?php esc_html_e( 'Inventory history', 'bmlt-literature-manager' ); ?></h2>
		<?php require __DIR__ . '/partial-audit.php'; ?>
	<?php endif; ?>
</div>
