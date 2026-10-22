<?php
/**
 * Inventory audit table partial. Expects $adjusts (audit rows) or $history.
 *
 * @package BMLT_Lit
 * @version 6.3.0
 */

use BMLT_Lit\Audit;
use BMLT_Lit\Catalog;
use BMLT_Lit\Helpers;
use BMLT_Lit\Settings;

defined( 'ABSPATH' ) || exit;

$bmlt_lit_rows = isset( $adjusts ) ? $adjusts : ( isset( $history ) ? $history : array() );
$bmlt_lit_ids  = array();
foreach ( $bmlt_lit_rows as $bmlt_lit_r ) {
	if ( 'inventory' === $bmlt_lit_r->object_type ) {
		$bmlt_lit_ids[] = (int) $bmlt_lit_r->object_id;
	}
}
$bmlt_lit_items = Catalog::get_many( $bmlt_lit_ids );
?>
<table class="widefat striped bmlt-lit-audit">
	<thead><tr>
		<th scope="col"><?php esc_html_e( 'When', 'bmlt-literature-manager' ); ?></th>
		<th scope="col"><?php esc_html_e( 'Who', 'bmlt-literature-manager' ); ?></th>
		<th scope="col"><?php esc_html_e( 'What', 'bmlt-literature-manager' ); ?></th>
		<th scope="col"><?php esc_html_e( 'Change', 'bmlt-literature-manager' ); ?></th>
		<th scope="col"><?php esc_html_e( 'Reason', 'bmlt-literature-manager' ); ?></th>
	</tr></thead>
	<tbody>
		<?php if ( empty( $bmlt_lit_rows ) ) : ?>
			<tr><td colspan="5"><?php esc_html_e( 'No history yet.', 'bmlt-literature-manager' ); ?></td></tr>
		<?php endif; ?>
		<?php
		foreach ( $bmlt_lit_rows as $bmlt_lit_r ) :
			$bmlt_lit_old = json_decode( (string) $bmlt_lit_r->old_value, true );
			$bmlt_lit_new = json_decode( (string) $bmlt_lit_r->new_value, true );
			$bmlt_lit_chg = array();
			if ( is_array( $bmlt_lit_old ) && is_array( $bmlt_lit_new ) ) {
				foreach ( $bmlt_lit_new as $bmlt_lit_k => $bmlt_lit_v ) {
					if ( is_scalar( $bmlt_lit_v ) && isset( $bmlt_lit_old[ $bmlt_lit_k ] ) && is_scalar( $bmlt_lit_old[ $bmlt_lit_k ] ) && (string) $bmlt_lit_old[ $bmlt_lit_k ] !== (string) $bmlt_lit_v ) {
						$bmlt_lit_chg[] = $bmlt_lit_k . ': ' . $bmlt_lit_old[ $bmlt_lit_k ] . ' → ' . $bmlt_lit_v;
					}
				}
			}
			$bmlt_lit_it = isset( $bmlt_lit_items[ (int) $bmlt_lit_r->object_id ] ) ? $bmlt_lit_items[ (int) $bmlt_lit_r->object_id ] : null;
			?>
			<tr>
				<td><?php echo esc_html( Helpers::local_date( $bmlt_lit_r->created_at ) ); ?></td>
				<td><?php echo esc_html( Audit::user_name( (int) $bmlt_lit_r->user_id ) ); ?></td>
				<td><?php echo esc_html( Audit::action_label( $bmlt_lit_r->action ) ); ?><br><small><?php echo esc_html( ( $bmlt_lit_it ? $bmlt_lit_it->sku . ' ' . $bmlt_lit_it->name : '#' . (int) $bmlt_lit_r->object_id ) . ( $bmlt_lit_r->area_id ? ' — ' . Settings::area_name( (int) $bmlt_lit_r->area_id ) : '' ) ); ?></small></td>
				<td><?php echo esc_html( implode( '; ', $bmlt_lit_chg ) ); ?></td>
				<td><?php echo esc_html( (string) $bmlt_lit_r->note ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
