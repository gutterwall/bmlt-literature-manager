<?php
/**
 * Items list view.
 *
 * @package BMLT_Lit
 * @version 6.2.0
 *
 * @var \BMLT_Lit\Admin\Items_List_Table $table
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap bmlt-lit-admin">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Literature Items', 'bmlt-literature-manager' ); ?></h1>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=bmlt-lit-items&action=new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New Item', 'bmlt-literature-manager' ); ?></a>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=bmlt-lit-import' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Import / Export', 'bmlt-literature-manager' ); ?></a>
	<hr class="wp-header-end">
	<p class="description"><?php esc_html_e( 'Items on historical orders cannot be deleted; deactivate them instead so past orders stay accurate. Shared items are offered to every Area; Area items are offered only to that Area.', 'bmlt-literature-manager' ); ?></p>
	<form method="get">
		<input type="hidden" name="page" value="bmlt-lit-items">
		<?php
		$table->search_box( __( 'Search items', 'bmlt-literature-manager' ), 'bmlt-lit-items' );
		$table->display();
		?>
	</form>
</div>
