<?php
/**
 * Item create/edit view.
 *
 * @package BMLT_Lit
 * @version 6.3.0
 *
 * @var object|null $item
 * @var string[]    $categories
 * @var array       $history
 */

use BMLT_Lit\Audit;
use BMLT_Lit\Helpers;
use BMLT_Lit\Permissions;
use BMLT_Lit\Settings;

defined( 'ABSPATH' ) || exit;

$saved = get_transient( 'bmlt_lit_item_form_' . get_current_user_id() );
delete_transient( 'bmlt_lit_item_form_' . get_current_user_id() );
$v = array(
	'area_id'           => $item ? (int) $item->area_id : 0,
	'sku'               => $item ? $item->sku : '',
	'name'              => $item ? $item->name : '',
	'short_description' => $item ? $item->short_description : '',
	'long_description'  => $item ? $item->long_description : '',
	'category'          => $item ? $item->category : '',
	'price'             => $item ? Helpers::sql_money( $item->price ) : '',
	'is_active'         => $item ? (int) $item->is_active : 1,
	'sort_order'        => $item ? (int) $item->sort_order : 0,
	'image_url'         => $item ? $item->image_url : '',
	'reference_url'     => ( $item && isset( $item->reference_url ) ) ? $item->reference_url : '',
	'naws_id'           => $item ? $item->naws_id : '',
);
if ( is_array( $saved ) ) {
	$v = array_merge( $v, array_intersect_key( $saved, $v ) );
}
$areas = Settings::area_options( Permissions::allowed_areas() );
?>
<div class="wrap bmlt-lit-admin">
	<h1 class="wp-heading-inline"><?php echo $item ? esc_html__( 'Edit Literature Item', 'bmlt-literature-manager' ) : esc_html__( 'Add Literature Item', 'bmlt-literature-manager' ); ?></h1>
	<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=bmlt-lit-items' ) ); ?>"><?php esc_html_e( '← All items', 'bmlt-literature-manager' ); ?></a>
	<hr class="wp-header-end">

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'bmlt_lit_save_item' ); ?>
		<input type="hidden" name="action" value="bmlt_lit_save_item">
		<input type="hidden" name="item_id" value="<?php echo $item ? (int) $item->id : 0; ?>">
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="bmlt-lit-area"><?php esc_html_e( 'Catalog', 'bmlt-literature-manager' ); ?></label></th>
				<td>
					<select id="bmlt-lit-area" name="area_id">
						<?php if ( Permissions::can_area( 0 ) ) : ?>
							<option value="0" <?php selected( (int) $v['area_id'], 0 ); ?>><?php esc_html_e( 'Shared (all Areas)', 'bmlt-literature-manager' ); ?></option>
						<?php endif; ?>
						<?php foreach ( $areas as $id => $name ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" <?php selected( (int) $v['area_id'], $id ); ?>><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Shared items appear on every Area’s order form. Area items appear only for meetings in that Area and can carry Area-specific pricing.', 'bmlt-literature-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bmlt-lit-sku"><?php esc_html_e( 'SKU / item number', 'bmlt-literature-manager' ); ?> *</label></th>
				<td><input type="text" id="bmlt-lit-sku" name="sku" class="regular-text" maxlength="64" required value="<?php echo esc_attr( $v['sku'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="bmlt-lit-name"><?php esc_html_e( 'Literature name', 'bmlt-literature-manager' ); ?> *</label></th>
				<td><input type="text" id="bmlt-lit-name" name="name" class="large-text" maxlength="255" required value="<?php echo esc_attr( $v['name'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="bmlt-lit-category"><?php esc_html_e( 'Category', 'bmlt-literature-manager' ); ?></label></th>
				<td>
					<input type="text" id="bmlt-lit-category" name="category" class="regular-text" maxlength="100" list="bmlt-lit-cats" value="<?php echo esc_attr( $v['category'] ); ?>">
					<datalist id="bmlt-lit-cats"><?php foreach ( $categories as $c ) : ?><option value="<?php echo esc_attr( $c ); ?>"></option><?php endforeach; ?></datalist>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bmlt-lit-price"><?php esc_html_e( 'Unit price', 'bmlt-literature-manager' ); ?> *</label></th>
				<td><input type="text" id="bmlt-lit-price" name="price" class="small-text" inputmode="decimal" required value="<?php echo esc_attr( $v['price'] ); ?>"> <span class="description"><?php esc_html_e( 'For example 12.50. Price changes apply to new orders only; existing orders keep their price.', 'bmlt-literature-manager' ); ?></span></td>
			</tr>
			<tr>
				<th scope="row"><label for="bmlt-lit-short"><?php esc_html_e( 'Short description', 'bmlt-literature-manager' ); ?></label></th>
				<td><textarea id="bmlt-lit-short" name="short_description" rows="2" class="large-text" maxlength="1000"><?php echo esc_textarea( $v['short_description'] ); ?></textarea></td>
			</tr>
			<tr>
				<th scope="row"><label for="bmlt-lit-long"><?php esc_html_e( 'Long description', 'bmlt-literature-manager' ); ?></label></th>
				<td><textarea id="bmlt-lit-long" name="long_description" rows="6" class="large-text"><?php echo esc_textarea( $v['long_description'] ); ?></textarea><p class="description"><?php esc_html_e( 'Basic HTML is allowed.', 'bmlt-literature-manager' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><label for="bmlt-lit-sort"><?php esc_html_e( 'Sort order', 'bmlt-literature-manager' ); ?></label></th>
				<td><input type="number" id="bmlt-lit-sort" name="sort_order" class="small-text" step="1" value="<?php echo esc_attr( $v['sort_order'] ); ?>"> <span class="description"><?php esc_html_e( 'Lower numbers appear first.', 'bmlt-literature-manager' ); ?></span></td>
			</tr>
			<tr>
				<th scope="row"><label for="bmlt-lit-image"><?php esc_html_e( 'Image URL (optional)', 'bmlt-literature-manager' ); ?></label></th>
				<td><input type="url" id="bmlt-lit-image" name="image_url" class="large-text" maxlength="2048" value="<?php echo esc_attr( $v['image_url'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="bmlt-lit-ref"><?php esc_html_e( 'Reference link (optional)', 'bmlt-literature-manager' ); ?></label></th>
				<td><input type="url" id="bmlt-lit-ref" name="reference_url" class="large-text" maxlength="2048" value="<?php echo esc_attr( $v['reference_url'] ); ?>">
					<p class="description"><?php esc_html_e( 'A page about this item, such as its official reading page on na.org. Shown on the order form as a “View on …” link that opens in a new tab.', 'bmlt-literature-manager' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><label for="bmlt-lit-naws"><?php esc_html_e( 'NAWS product ID (optional)', 'bmlt-literature-manager' ); ?></label></th>
				<td><input type="text" id="bmlt-lit-naws" name="naws_id" class="regular-text" maxlength="64" value="<?php echo esc_attr( $v['naws_id'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Status', 'bmlt-literature-manager' ); ?></th>
				<td><label><input type="checkbox" name="is_active" value="1" <?php checked( (int) $v['is_active'], 1 ); ?>> <?php esc_html_e( 'Active (available for ordering)', 'bmlt-literature-manager' ); ?></label></td>
			</tr>
			<?php
			$untracked = array();
			if ( current_user_can( Permissions::MANAGE_INVENTORY ) ) {
				foreach ( $areas as $aid => $aname ) {
					if ( ! Settings::area_tracks_inventory( $aid ) && ( ! $item || 0 === (int) $item->area_id || (int) $item->area_id === (int) $aid ) ) {
						$untracked[ $aid ] = $aname;
					}
				}
			}
			if ( $untracked ) :
				$inv_doc = \BMLT_Lit\Inventory::doc();
				?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Special order by Area', 'bmlt-literature-manager' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Special order by Area', 'bmlt-literature-manager' ); ?></legend>
							<?php foreach ( $untracked as $aid => $aname ) : ?>
								<?php $row = $item ? \BMLT_Lit\Inventory::row( $inv_doc, (int) $aid, (int) $item->id ) : null; ?>
								<input type="hidden" name="special_area_shown[]" value="<?php echo esc_attr( $aid ); ?>">
								<label><input type="checkbox" name="special_area[]" value="<?php echo esc_attr( $aid ); ?>" <?php checked( $row && ! empty( $row['special'] ) ); ?>> <?php echo esc_html( $aname ); ?></label><br>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'For Areas that don’t track quantities: tick an Area to show this item as a special order there. Areas that track quantities decide automatically from stock.', 'bmlt-literature-manager' ); ?></p>
						</fieldset>
					</td>
				</tr>
			<?php endif; ?>
		</table>
		<?php submit_button( $item ? __( 'Save item', 'bmlt-literature-manager' ) : __( 'Add item', 'bmlt-literature-manager' ) ); ?>
	</form>

	<?php if ( $item && ! empty( $history ) ) : ?>
		<h2><?php esc_html_e( 'Change history', 'bmlt-literature-manager' ); ?></h2>
		<table class="widefat striped">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'When', 'bmlt-literature-manager' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Who', 'bmlt-literature-manager' ); ?></th>
				<th scope="col"><?php esc_html_e( 'What', 'bmlt-literature-manager' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Details', 'bmlt-literature-manager' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $history as $h ) : ?>
					<?php
					$old = json_decode( (string) $h->old_value, true );
					$new = json_decode( (string) $h->new_value, true );
					$chg = array();
					if ( is_array( $new ) ) {
						foreach ( $new as $k => $val ) {
							$was = is_array( $old ) && isset( $old[ $k ] ) ? $old[ $k ] : '';
							if ( is_scalar( $val ) && is_scalar( $was ) && (string) $was !== (string) $val ) {
								$chg[] = $k . ': ' . $was . ' → ' . $val;
							}
						}
					}
					?>
					<tr>
						<td><?php echo esc_html( Helpers::local_date( $h->created_at ) ); ?></td>
						<td><?php echo esc_html( Audit::user_name( (int) $h->user_id ) ); ?></td>
						<td><?php echo esc_html( Audit::action_label( $h->action ) ); ?></td>
						<td><?php echo esc_html( implode( '; ', $chg ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
