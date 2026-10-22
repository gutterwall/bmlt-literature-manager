<?php
/**
 * [bmlt_literature_manager] view.
 *
 * @package BMLT_Lit
 * @version 6.3.0
 *
 * @var array    $config
 * @var string[] $categories
 */

defined( 'ABSPATH' ) || exit;

$tabs = array();
if ( $config['caps']['viewOrders'] ) {
	$tabs['orders'] = __( 'Orders', 'bmlt-literature-manager' );
}
if ( $config['caps']['inventory'] ) {
	$tabs['inventory'] = __( 'Inventory', 'bmlt-literature-manager' );
}
if ( $config['caps']['catalog'] ) {
	$tabs['catalog'] = __( 'Literature Catalog', 'bmlt-literature-manager' );
}
$active = isset( $tabs[ $config['defaultTab'] ] ) ? $config['defaultTab'] : key( $tabs );
$uid    = 'bmlt-lit-m-' . wp_generate_password( 5, false, false );
?>
<div class="bmlt-lit bmlt-lit-worker" data-bmlt-lit-worker>
	<noscript><div class="bmlt-lit-notice"><p><?php esc_html_e( 'The literature workspace requires JavaScript. You can also use the Literature menu in the WordPress dashboard.', 'bmlt-literature-manager' ); ?></p></div></noscript>

	<div class="bmlt-lit-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Literature tools', 'bmlt-literature-manager' ); ?>">
		<?php foreach ( $tabs as $key => $label ) : ?>
			<button type="button" role="tab" class="bmlt-lit-tab" id="<?php echo esc_attr( $uid . '-tab-' . $key ); ?>" aria-controls="<?php echo esc_attr( $uid . '-panel-' . $key ); ?>" aria-selected="<?php echo $active === $key ? 'true' : 'false'; ?>" tabindex="<?php echo $active === $key ? '0' : '-1'; ?>" data-tab="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></button>
		<?php endforeach; ?>
	</div>

	<?php if ( isset( $tabs['orders'] ) ) : ?>
		<div class="bmlt-lit-panel" role="tabpanel" id="<?php echo esc_attr( $uid . '-panel-orders' ); ?>" aria-labelledby="<?php echo esc_attr( $uid . '-tab-orders' ); ?>" data-panel="orders" <?php echo 'orders' === $active ? '' : 'hidden'; ?>>
			<?php include __DIR__ . '/orders-app.php'; ?>
		</div>
	<?php endif; ?>

	<?php if ( isset( $tabs['inventory'] ) ) : ?>
		<div class="bmlt-lit-panel" role="tabpanel" id="<?php echo esc_attr( $uid . '-panel-inventory' ); ?>" aria-labelledby="<?php echo esc_attr( $uid . '-tab-inventory' ); ?>" data-panel="inventory" <?php echo 'inventory' === $active ? '' : 'hidden'; ?>>
			<div class="bmlt-lit-inventory-app" data-bmlt-lit-inventory>
				<div class="bmlt-lit-filters">
					<p class="bmlt-lit-field">
						<label for="<?php echo esc_attr( $uid ); ?>-inv-area"><?php esc_html_e( 'Area', 'bmlt-literature-manager' ); ?></label>
						<select id="<?php echo esc_attr( $uid ); ?>-inv-area" class="bmlt-lit-select bmlt-lit-inv-area">
							<?php foreach ( $config['areas'] as $aid => $aname ) : ?>
								<option value="<?php echo esc_attr( $aid ); ?>" <?php selected( (int) $config['defaultArea'], (int) $aid ); ?>><?php echo esc_html( $aname ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p class="bmlt-lit-field">
						<label for="<?php echo esc_attr( $uid ); ?>-inv-search"><?php esc_html_e( 'Search', 'bmlt-literature-manager' ); ?></label>
						<input type="search" id="<?php echo esc_attr( $uid ); ?>-inv-search" class="bmlt-lit-input bmlt-lit-inv-search" placeholder="<?php esc_attr_e( 'Title or SKU', 'bmlt-literature-manager' ); ?>">
					</p>
					<p class="bmlt-lit-field bmlt-lit-field--check">
						<input type="checkbox" id="<?php echo esc_attr( $uid ); ?>-inv-low" class="bmlt-lit-inv-low">
						<label for="<?php echo esc_attr( $uid ); ?>-inv-low"><?php esc_html_e( 'Show only low / out of stock', 'bmlt-literature-manager' ); ?></label>
					</p>
				</div>
				<p class="bmlt-lit-status-line" aria-live="polite"></p>
				<div class="bmlt-lit-inv-list" role="list" aria-label="<?php esc_attr_e( 'Inventory for the selected Area', 'bmlt-literature-manager' ); ?>"></div>
			</div>
		</div>
	<?php endif; ?>

	<?php if ( isset( $tabs['catalog'] ) ) : ?>
		<div class="bmlt-lit-panel" role="tabpanel" id="<?php echo esc_attr( $uid . '-panel-catalog' ); ?>" aria-labelledby="<?php echo esc_attr( $uid . '-tab-catalog' ); ?>" data-panel="catalog" <?php echo 'catalog' === $active ? '' : 'hidden'; ?>>
			<div class="bmlt-lit-catalog-app" data-bmlt-lit-catalog>
				<div class="bmlt-lit-filters">
					<p class="bmlt-lit-field">
						<label for="<?php echo esc_attr( $uid ); ?>-cat-catalog"><?php esc_html_e( 'Catalog', 'bmlt-literature-manager' ); ?></label>
						<select id="<?php echo esc_attr( $uid ); ?>-cat-catalog" class="bmlt-lit-select bmlt-lit-cat-catalog">
							<option value=""><?php esc_html_e( 'All catalogs I can see', 'bmlt-literature-manager' ); ?></option>
							<option value="0"><?php esc_html_e( 'Shared (all Areas)', 'bmlt-literature-manager' ); ?></option>
							<?php foreach ( $config['areas'] as $aid => $aname ) : ?>
								<option value="<?php echo esc_attr( $aid ); ?>"><?php echo esc_html( $aname ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p class="bmlt-lit-field">
						<label for="<?php echo esc_attr( $uid ); ?>-cat-search"><?php esc_html_e( 'Search', 'bmlt-literature-manager' ); ?></label>
						<input type="search" id="<?php echo esc_attr( $uid ); ?>-cat-search" class="bmlt-lit-input bmlt-lit-cat-search" placeholder="<?php esc_attr_e( 'Title, SKU or category', 'bmlt-literature-manager' ); ?>">
					</p>
					<p class="bmlt-lit-field bmlt-lit-field--actions">
						<button type="button" class="bmlt-lit-button bmlt-lit-button--primary bmlt-lit-cat-add"><?php esc_html_e( 'Add Item', 'bmlt-literature-manager' ); ?></button>
					</p>
				</div>

				<form class="bmlt-lit-item-form" hidden>
					<h3 class="bmlt-lit-item-form__title"><?php esc_html_e( 'Add Item', 'bmlt-literature-manager' ); ?></h3>
					<input type="hidden" name="id" value="">
					<div class="bmlt-lit-grid">
						<p class="bmlt-lit-field">
							<label for="<?php echo esc_attr( $uid ); ?>-f-area"><?php esc_html_e( 'Catalog', 'bmlt-literature-manager' ); ?></label>
							<select id="<?php echo esc_attr( $uid ); ?>-f-area" name="area_id" class="bmlt-lit-select">
								<?php if ( $config['shared'] ) : ?>
									<option value="0"><?php esc_html_e( 'Shared (all Areas)', 'bmlt-literature-manager' ); ?></option>
								<?php endif; ?>
								<?php foreach ( $config['areas'] as $aid => $aname ) : ?>
									<option value="<?php echo esc_attr( $aid ); ?>"><?php echo esc_html( $aname ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<p class="bmlt-lit-field">
							<label for="<?php echo esc_attr( $uid ); ?>-f-sku"><?php esc_html_e( 'SKU / item number', 'bmlt-literature-manager' ); ?> <span class="bmlt-lit-required" aria-hidden="true">*</span></label>
							<input type="text" id="<?php echo esc_attr( $uid ); ?>-f-sku" name="sku" class="bmlt-lit-input" maxlength="64" required>
						</p>
						<p class="bmlt-lit-field">
							<label for="<?php echo esc_attr( $uid ); ?>-f-name"><?php esc_html_e( 'Title', 'bmlt-literature-manager' ); ?> <span class="bmlt-lit-required" aria-hidden="true">*</span></label>
							<input type="text" id="<?php echo esc_attr( $uid ); ?>-f-name" name="name" class="bmlt-lit-input" maxlength="255" required>
						</p>
						<p class="bmlt-lit-field">
							<label for="<?php echo esc_attr( $uid ); ?>-f-cat"><?php esc_html_e( 'Category', 'bmlt-literature-manager' ); ?></label>
							<input type="text" id="<?php echo esc_attr( $uid ); ?>-f-cat" name="category" class="bmlt-lit-input" maxlength="100" list="<?php echo esc_attr( $uid ); ?>-cats">
							<datalist id="<?php echo esc_attr( $uid ); ?>-cats">
								<?php foreach ( $categories as $c ) : ?>
									<option value="<?php echo esc_attr( $c ); ?>"></option>
								<?php endforeach; ?>
							</datalist>
						</p>
						<p class="bmlt-lit-field">
							<label for="<?php echo esc_attr( $uid ); ?>-f-price"><?php esc_html_e( 'Unit price', 'bmlt-literature-manager' ); ?> <span class="bmlt-lit-required" aria-hidden="true">*</span></label>
							<input type="text" id="<?php echo esc_attr( $uid ); ?>-f-price" name="price" class="bmlt-lit-input" inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,2})?" required>
						</p>
						<p class="bmlt-lit-field">
							<label for="<?php echo esc_attr( $uid ); ?>-f-sort"><?php esc_html_e( 'Sort order', 'bmlt-literature-manager' ); ?></label>
							<input type="number" id="<?php echo esc_attr( $uid ); ?>-f-sort" name="sort_order" class="bmlt-lit-input" step="1" value="0">
						</p>
						<p class="bmlt-lit-field">
							<label for="<?php echo esc_attr( $uid ); ?>-f-naws"><?php esc_html_e( 'NAWS product ID', 'bmlt-literature-manager' ); ?></label>
							<input type="text" id="<?php echo esc_attr( $uid ); ?>-f-naws" name="naws_id" class="bmlt-lit-input" maxlength="64">
						</p>
						<p class="bmlt-lit-field">
							<label for="<?php echo esc_attr( $uid ); ?>-f-ref"><?php esc_html_e( 'Reference link', 'bmlt-literature-manager' ); ?></label>
							<input type="url" id="<?php echo esc_attr( $uid ); ?>-f-ref" name="reference_url" class="bmlt-lit-input" maxlength="2048">
						</p>
						<p class="bmlt-lit-field">
							<label for="<?php echo esc_attr( $uid ); ?>-f-img"><?php esc_html_e( 'Image URL', 'bmlt-literature-manager' ); ?></label>
							<input type="url" id="<?php echo esc_attr( $uid ); ?>-f-img" name="image_url" class="bmlt-lit-input" maxlength="2048">
						</p>
					</div>
					<p class="bmlt-lit-field">
						<label for="<?php echo esc_attr( $uid ); ?>-f-short"><?php esc_html_e( 'Short description', 'bmlt-literature-manager' ); ?></label>
						<textarea id="<?php echo esc_attr( $uid ); ?>-f-short" name="short_description" class="bmlt-lit-textarea" rows="2" maxlength="1000"></textarea>
					</p>
					<p class="bmlt-lit-field">
						<label for="<?php echo esc_attr( $uid ); ?>-f-long"><?php esc_html_e( 'Long description', 'bmlt-literature-manager' ); ?></label>
						<textarea id="<?php echo esc_attr( $uid ); ?>-f-long" name="long_description" class="bmlt-lit-textarea" rows="4"></textarea>
					</p>
					<p class="bmlt-lit-field bmlt-lit-field--check">
						<input type="checkbox" id="<?php echo esc_attr( $uid ); ?>-f-active" name="is_active" value="1" checked>
						<label for="<?php echo esc_attr( $uid ); ?>-f-active"><?php esc_html_e( 'Active (available for ordering)', 'bmlt-literature-manager' ); ?></label>
					</p>
					<p class="bmlt-lit-form-message" role="status" aria-live="polite"></p>
					<p class="bmlt-lit-actions">
						<button type="submit" class="bmlt-lit-button bmlt-lit-button--primary"><?php esc_html_e( 'Save item', 'bmlt-literature-manager' ); ?></button>
						<button type="button" class="bmlt-lit-button bmlt-lit-item-cancel"><?php esc_html_e( 'Cancel', 'bmlt-literature-manager' ); ?></button>
					</p>
				</form>

				<p class="bmlt-lit-status-line" aria-live="polite"></p>
				<div class="bmlt-lit-table-wrap">
					<table class="bmlt-lit-table bmlt-lit-fluid-table bmlt-lit-cat-table" role="table">
						<caption class="bmlt-lit-sr-only"><?php esc_html_e( 'Literature catalog', 'bmlt-literature-manager' ); ?></caption>
						<thead role="rowgroup">
							<tr role="row">
								<th scope="col" role="columnheader"><?php esc_html_e( 'SKU', 'bmlt-literature-manager' ); ?></th>
								<th scope="col" role="columnheader"><?php esc_html_e( 'Title', 'bmlt-literature-manager' ); ?></th>
								<th scope="col" role="columnheader"><?php esc_html_e( 'Category', 'bmlt-literature-manager' ); ?></th>
								<th scope="col" role="columnheader"><?php esc_html_e( 'Catalog', 'bmlt-literature-manager' ); ?></th>
								<th scope="col" role="columnheader"><?php esc_html_e( 'Price', 'bmlt-literature-manager' ); ?></th>
								<th scope="col" role="columnheader"><?php esc_html_e( 'Sort', 'bmlt-literature-manager' ); ?></th>
								<th scope="col" role="columnheader"><?php esc_html_e( 'Status', 'bmlt-literature-manager' ); ?></th>
								<th scope="col" role="columnheader"><?php esc_html_e( 'Actions', 'bmlt-literature-manager' ); ?></th>
							</tr>
						</thead>
						<tbody role="rowgroup"></tbody>
					</table>
				</div>
			</div>
		</div>
	<?php endif; ?>
</div>
