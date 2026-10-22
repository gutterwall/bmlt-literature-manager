<?php
/**
 * Import / Export view.
 *
 * @package BMLT_Lit
 * @version 6.2.0
 *
 * @var array|null $preview
 * @var string     $token
 * @var string     $editor
 * @var array      $areas
 */

use BMLT_Lit\Catalog;
use BMLT_Lit\Helpers;
use BMLT_Lit\Permissions;

defined( 'ABSPATH' ) || exit;

$post_url  = admin_url( 'admin-post.php' );
$can_inv   = current_user_can( Permissions::MANAGE_INVENTORY );
$can_share = Permissions::can_area( 0 );
$editor_url = static function ( $type, $extra ) {
	return wp_nonce_url( add_query_arg( array_merge( array( 'page' => 'bmlt-lit-import', 'editor' => $type ), $extra ), admin_url( 'admin.php' ) ), 'bmlt_lit_editor' );
};
?>
<div class="wrap bmlt-lit-admin">
	<h1><?php esc_html_e( 'Import / Export', 'bmlt-literature-manager' ); ?></h1>

	<?php if ( $preview ) : ?>
		<div class="postbox bmlt-lit-box bmlt-lit-preview">
			<h2 class="hndle"><?php esc_html_e( 'Import preview — nothing has been changed yet', 'bmlt-literature-manager' ); ?></h2>
			<div class="inside">
				<p>
					<?php
					if ( 'inventory' === $preview['type'] ) {
						/* translators: 1: Area name, 2: updates, 3: unchanged, 4: invalid. */
						echo esc_html( sprintf( __( 'Inventory for %1$s: %2$d to update, %3$d unchanged, %4$d invalid.', 'bmlt-literature-manager' ), \BMLT_Lit\Settings::area_name( (int) $preview['target'] ), $preview['counts']['update'], $preview['counts']['unchanged'], $preview['counts']['invalid'] ) );
					} else {
						/* translators: 1: catalog, 2: new, 3: updates, 4: unchanged, 5: skipped, 6: invalid. */
						echo esc_html( sprintf( __( 'Catalog “%1$s”: %2$d new, %3$d to update, %4$d unchanged, %5$d skipped (already exist), %6$d invalid.', 'bmlt-literature-manager' ), Catalog::catalog_label( (int) $preview['target'] ), $preview['counts']['new'], $preview['counts']['update'], $preview['counts']['unchanged'], $preview['counts']['skip'], $preview['counts']['invalid'] ) );
					}
					?>
				</p>
				<?php if ( ! empty( $preview['errors'] ) ) : ?>
					<div class="notice notice-error inline">
						<p><strong><?php esc_html_e( 'These rows failed validation and will not be imported:', 'bmlt-literature-manager' ); ?></strong></p>
						<ul class="ul-disc">
							<?php foreach ( array_slice( $preview['errors'], 0, 100 ) as $e ) : ?>
								<li><?php echo esc_html( $e ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<div class="bmlt-lit-table-scroll">
				<table class="widefat striped">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Action', 'bmlt-literature-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'SKU', 'bmlt-literature-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Name', 'bmlt-literature-manager' ); ?></th>
						<?php if ( 'inventory' === $preview['type'] ) : ?>
							<th scope="col"><?php esc_html_e( 'On hand: current → new / special order', 'bmlt-literature-manager' ); ?></th>
						<?php else : ?>
							<th scope="col"><?php esc_html_e( 'Price', 'bmlt-literature-manager' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Active', 'bmlt-literature-manager' ); ?></th>
						<?php endif; ?>
					</tr></thead>
					<tbody>
						<?php
						$labels = array(
							'new'       => __( 'Create', 'bmlt-literature-manager' ),
							'update'    => __( 'Update', 'bmlt-literature-manager' ),
							'unchanged' => __( 'No change', 'bmlt-literature-manager' ),
							'skip'      => __( 'Skip (exists)', 'bmlt-literature-manager' ),
						);
						foreach ( array_slice( $preview['rows'], 0, 1000 ) as $row ) :
							?>
							<tr>
								<td><?php echo esc_html( isset( $labels[ $row['action'] ] ) ? $labels[ $row['action'] ] : $row['action'] ); ?></td>
								<?php if ( 'inventory' === $preview['type'] ) : ?>
									<td><?php echo esc_html( $row['sku'] ); ?></td>
									<td><?php echo esc_html( $row['name'] ); ?></td>
									<td>
										<?php echo (int) $row['old']; ?> → <?php echo null === $row['data']['quantity_on_hand'] ? '—' : (int) $row['data']['quantity_on_hand']; ?>
										<?php if ( isset( $row['data']['special'] ) && null !== $row['data']['special'] ) : ?>
											<br><small><?php echo $row['data']['special'] ? esc_html__( 'Special order', 'bmlt-literature-manager' ) : esc_html__( 'Not a special order', 'bmlt-literature-manager' ); ?></small>
										<?php endif; ?>
									</td>
								<?php else : ?>
									<td><?php echo esc_html( $row['data']['sku'] ); ?></td>
									<td><?php echo esc_html( $row['data']['name'] ); ?></td>
									<td><?php echo '' !== $row['old_price'] && $row['old_price'] !== $row['data']['price'] ? esc_html( Helpers::format_money( $row['old_price'] ) . ' → ' ) : ''; ?><?php echo esc_html( Helpers::format_money( $row['data']['price'] ) ); ?></td>
									<td><?php echo $row['data']['is_active'] ? esc_html__( 'Yes', 'bmlt-literature-manager' ) : esc_html__( 'No', 'bmlt-literature-manager' ); ?></td>
								<?php endif; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>

				<?php if ( ! empty( $preview['deactivate'] ) && ! empty( $preview['missing'] ) ) : ?>
					<h3><?php esc_html_e( 'These items are not in the file and will be deactivated:', 'bmlt-literature-manager' ); ?></h3>
					<ul class="ul-disc">
						<?php foreach ( $preview['missing'] as $m ) : ?>
							<li><?php echo esc_html( $m['sku'] . ' — ' . $m['name'] ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( $post_url ); ?>">
					<?php wp_nonce_field( 'bmlt_lit_import_confirm' ); ?>
					<input type="hidden" name="action" value="bmlt_lit_import_confirm">
					<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
					<p><label><input type="checkbox" name="confirm" value="1" required> <?php esc_html_e( 'I have reviewed the changes above and want to apply them.', 'bmlt-literature-manager' ); ?></label></p>
					<p>
						<?php submit_button( __( 'Apply import', 'bmlt-literature-manager' ), 'primary', 'submit', false ); ?>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=bmlt-lit-import' ) ); ?>"><?php esc_html_e( 'Discard', 'bmlt-literature-manager' ); ?></a>
					</p>
				</form>
			</div>
		</div>
	<?php endif; ?>

	<div class="bmlt-lit-columns">
		<div class="bmlt-lit-col">
			<div class="postbox bmlt-lit-box">
				<h2 class="hndle"><?php esc_html_e( 'Export', 'bmlt-literature-manager' ); ?></h2>
				<div class="inside">
					<form method="post" action="<?php echo esc_url( $post_url ); ?>">
						<?php wp_nonce_field( 'bmlt_lit_export' ); ?>
						<input type="hidden" name="action" value="bmlt_lit_export">
						<input type="hidden" name="type" value="catalog">
						<p>
							<label for="bmlt-lit-exp-cat"><?php esc_html_e( 'Catalog to export', 'bmlt-literature-manager' ); ?></label><br>
							<select id="bmlt-lit-exp-cat" name="catalog">
								<?php if ( $can_share ) : ?>
									<option value="all"><?php esc_html_e( 'Everything (all catalogs)', 'bmlt-literature-manager' ); ?></option>
								<?php endif; ?>
								<option value="shared"><?php esc_html_e( 'Shared catalog', 'bmlt-literature-manager' ); ?></option>
								<?php foreach ( $areas as $id => $name ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( sprintf( /* translators: %s: Area. */ __( '%s catalog only', 'bmlt-literature-manager' ), $name ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<p>
							<?php submit_button( __( 'Download catalog JSON', 'bmlt-literature-manager' ), 'secondary', 'submit', false ); ?>
							<a class="button" href="<?php echo esc_url( $editor_url( 'catalog', array( 'catalog' => $can_share ? 'all' : 'shared' ) ) ); ?>"><?php esc_html_e( 'Copy catalog to editor', 'bmlt-literature-manager' ); ?></a>
						</p>
					</form>

					<?php if ( $can_inv && ! empty( $areas ) ) : ?>
						<hr>
						<form method="post" action="<?php echo esc_url( $post_url ); ?>">
							<?php wp_nonce_field( 'bmlt_lit_export' ); ?>
							<input type="hidden" name="action" value="bmlt_lit_export">
							<input type="hidden" name="type" value="inventory">
							<p>
								<label for="bmlt-lit-exp-inv"><?php esc_html_e( 'Area inventory to export', 'bmlt-literature-manager' ); ?></label><br>
								<select id="bmlt-lit-exp-inv" name="inv_area">
									<?php foreach ( $areas as $id => $name ) : ?>
										<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $name ); ?></option>
									<?php endforeach; ?>
								</select>
							</p>
							<p>
								<?php submit_button( __( 'Download inventory JSON', 'bmlt-literature-manager' ), 'secondary', 'submit', false ); ?>
								<a class="button" href="<?php echo esc_url( $editor_url( 'inventory', array( 'inv_area' => (int) key( $areas ) ) ) ); ?>"><?php esc_html_e( 'Copy first Area’s inventory to editor', 'bmlt-literature-manager' ); ?></a>
							</p>
						</form>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<div class="bmlt-lit-col bmlt-lit-col--wide">
			<div class="postbox bmlt-lit-box">
				<h2 class="hndle"><?php esc_html_e( 'Import', 'bmlt-literature-manager' ); ?></h2>
				<div class="inside">
					<form method="post" action="<?php echo esc_url( $post_url ); ?>" enctype="multipart/form-data">
						<?php wp_nonce_field( 'bmlt_lit_import_preview' ); ?>
						<input type="hidden" name="action" value="bmlt_lit_import_preview">
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'What to import', 'bmlt-literature-manager' ); ?></legend>
							<label><input type="radio" name="type" value="catalog" checked> <?php esc_html_e( 'Literature catalog', 'bmlt-literature-manager' ); ?></label>
							<?php if ( $can_inv ) : ?>
								&nbsp; <label><input type="radio" name="type" value="inventory"> <?php esc_html_e( 'Area inventory', 'bmlt-literature-manager' ); ?></label>
							<?php endif; ?>
						</fieldset>
						<p class="bmlt-lit-import-catalog">
							<label for="bmlt-lit-imp-target"><?php esc_html_e( 'Import catalog into', 'bmlt-literature-manager' ); ?></label><br>
							<select id="bmlt-lit-imp-target" name="target">
								<?php if ( $can_share ) : ?>
									<option value="0"><?php esc_html_e( 'Shared catalog (all Areas)', 'bmlt-literature-manager' ); ?></option>
								<?php endif; ?>
								<?php foreach ( $areas as $id => $name ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $name ); ?></option>
								<?php endforeach; ?>
							</select><br>
							<label><input type="checkbox" name="update_existing" value="1" checked> <?php esc_html_e( 'Update existing items with the same SKU', 'bmlt-literature-manager' ); ?></label><br>
							<label><input type="checkbox" name="deactivate_missing" value="1"> <?php esc_html_e( 'Deactivate items in this catalog that are missing from the file', 'bmlt-literature-manager' ); ?></label>
						</p>
						<?php if ( $can_inv ) : ?>
							<p class="bmlt-lit-import-inventory">
								<label for="bmlt-lit-imp-area"><?php esc_html_e( 'Import inventory into Area', 'bmlt-literature-manager' ); ?></label><br>
								<select id="bmlt-lit-imp-area" name="inv_area">
									<?php foreach ( $areas as $id => $name ) : ?>
										<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $name ); ?></option>
									<?php endforeach; ?>
								</select>
							</p>
						<?php endif; ?>
						<p>
							<label for="bmlt-lit-imp-file"><?php esc_html_e( 'Upload a JSON file', 'bmlt-literature-manager' ); ?></label><br>
							<input type="file" id="bmlt-lit-imp-file" name="json_file" accept=".json,application/json">
						</p>
						<p>
							<label for="bmlt-lit-json"><?php esc_html_e( '…or paste JSON here', 'bmlt-literature-manager' ); ?></label><br>
							<textarea id="bmlt-lit-json" name="json" rows="16" class="large-text code" spellcheck="false"><?php echo esc_textarea( $editor ); ?></textarea>
						</p>
						<p class="bmlt-lit-json-status" role="status" aria-live="polite"></p>
						<p>
							<button type="button" class="button bmlt-lit-json-validate"><?php esc_html_e( 'Validate JSON', 'bmlt-literature-manager' ); ?></button>
							<button type="button" class="button bmlt-lit-json-copy"><?php esc_html_e( 'Copy JSON', 'bmlt-literature-manager' ); ?></button>
							<?php submit_button( __( 'Preview import', 'bmlt-literature-manager' ), 'primary', 'submit', false ); ?>
						</p>
						<p class="description"><?php esc_html_e( 'Imports are always previewed first. Files must use the BMLT Literature Manager format (format + version fields). Only plain values are accepted; no code or objects are ever executed.', 'bmlt-literature-manager' ); ?></p>
					</form>
				</div>
			</div>
		</div>
	</div>
</div>
