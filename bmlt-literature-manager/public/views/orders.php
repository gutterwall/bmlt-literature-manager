<?php
/**
 * [bmlt_literature_orders] view.
 *
 * @package BMLT_Lit
 * @version 7.0.0
 *
 * @var array $config
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="bmlt-lit bmlt-lit-orders">
	<noscript><div class="bmlt-lit-notice"><p><?php esc_html_e( 'The order history requires JavaScript. You can also use Literature → Orders in the WordPress dashboard.', 'bmlt-literature-manager' ); ?></p></div></noscript>
	<?php include __DIR__ . '/orders-app.php'; ?>
</div>
