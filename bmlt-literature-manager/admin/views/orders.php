<?php
/**
 * Orders list view.
 *
 * @package BMLT_Lit
 * @version 6.2.0
 *
 * @var \BMLT_Lit\Admin\Orders_List_Table $table
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap bmlt-lit-admin">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Literature Orders', 'bmlt-literature-manager' ); ?></h1>
	<hr class="wp-header-end">
	<form method="get">
		<input type="hidden" name="page" value="bmlt-lit-orders">
		<?php
		$table->search_box( __( 'Search orders', 'bmlt-literature-manager' ), 'bmlt-lit-orders' );
		$table->display();
		?>
	</form>
</div>
