<?php
/**
 * Public order form view.
 *
 * @package BMLT_Lit
 * @version 6.2.0
 *
 * @var string $form_id
 * @var array  $atts
 * @var int[]  $areas
 * @var array  $meetings
 * @var array  $items
 * @var array  $stock_js
 * @var array  $js
 * @var array  $settings
 * @var int    $single_area
 * @var array  $errors
 * @var array  $values
 * @var string $areas_sig
 * @var string $time_token
 * @var string $form_token
 * @var string $return_url
 * @var bool   $show_prices
 * @var bool   $show_stock
 * @var bool   $show_desc
 * @var array  $categories
 * @var bool   $stale_notice
 * @var int    $meeting_fetch
 */

use BMLT_Lit\Helpers;
use BMLT_Lit\Settings;

defined( 'ABSPATH' ) || exit;

$v        = wp_parse_args(
	$values,
	array(
		'meeting_id'    => 0,
		'contact_name'  => '',
		'contact_phone' => '',
		'contact_email' => '',
		'notes'         => '',
		'qty'           => array(),
	)
);
$max_qty  = max( 1, (int) $settings['max_qty_per_line'] );
$grouped  = array();
foreach ( $meetings as $m ) {
	$grouped[ (int) $m['area_id'] ][] = $m;
}
$req_mark = '<span class="bmlt-lit-required" aria-hidden="true">*</span>';
?>
<div class="bmlt-lit bmlt-lit-order-form<?php echo $show_prices ? '' : ' bmlt-lit-no-prices'; ?>" id="<?php echo esc_attr( $form_id ); ?>">
	<?php if ( '' !== trim( (string) $atts['title'] ) ) : ?>
		<h2 class="bmlt-lit-title"><?php echo esc_html( $atts['title'] ); ?></h2>
	<?php endif; ?>

	<ol class="bmlt-lit-steps" aria-label="<?php esc_attr_e( 'Order progress', 'bmlt-literature-manager' ); ?>">
		<li class="bmlt-lit-steps__current" aria-current="step"><?php esc_html_e( 'Choose literature', 'bmlt-literature-manager' ); ?></li>
		<li><?php esc_html_e( 'Review order', 'bmlt-literature-manager' ); ?></li>
		<li><?php esc_html_e( 'Confirmation', 'bmlt-literature-manager' ); ?></li>
	</ol>

	<?php if ( $stale_notice ) : ?>
		<div class="bmlt-lit-notice bmlt-lit-notice--warning">
			<p>
				<?php
				printf(
					/* translators: %s: date/time. */
					esc_html__( 'Administrator notice: the BMLT server could not be reached, so meeting information cached on %s is being used.', 'bmlt-literature-manager' ),
					esc_html( $meeting_fetch ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $meeting_fetch ) : '—' )
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $errors ) ) : ?>
		<div class="bmlt-lit-notice bmlt-lit-notice--error bmlt-lit-errors" role="alert" tabindex="-1">
			<p><strong><?php esc_html_e( 'Your order has not been sent yet. Please correct the following:', 'bmlt-literature-manager' ); ?></strong></p>
			<ul>
				<?php foreach ( $errors as $err ) : ?>
					<li><?php echo esc_html( $err ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<noscript>
		<div class="bmlt-lit-notice"><p><?php esc_html_e( 'Live totals and stock indicators need JavaScript. You can still submit your order; totals and stock are always calculated when the order is received.', 'bmlt-literature-manager' ); ?></p></div>
	</noscript>

	<form method="post" action="<?php echo esc_url( $return_url ); ?>#<?php echo esc_attr( $form_id ); ?>" class="bmlt-lit-form" data-bmlt-lit-order-form>
		<script type="application/json" class="bmlt-lit-data"><?php echo wp_json_encode( $js, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?></script>

		<fieldset class="bmlt-lit-fieldset">
			<legend class="bmlt-lit-legend"><?php esc_html_e( 'Your meeting', 'bmlt-literature-manager' ); ?></legend>

			<p class="bmlt-lit-field bmlt-lit-meeting-search-wrap" hidden>
				<label for="<?php echo esc_attr( $form_id ); ?>-msearch"><?php esc_html_e( 'Find your meeting', 'bmlt-literature-manager' ); ?></label>
				<input type="search" id="<?php echo esc_attr( $form_id ); ?>-msearch" class="bmlt-lit-input bmlt-lit-meeting-search" autocomplete="off" placeholder="<?php esc_attr_e( 'Type a meeting name, city or day', 'bmlt-literature-manager' ); ?>">
			</p>

			<p class="bmlt-lit-field">
				<label for="<?php echo esc_attr( $form_id ); ?>-meeting"><?php esc_html_e( 'Meeting', 'bmlt-literature-manager' ); ?> <?php echo $req_mark; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
				<select id="<?php echo esc_attr( $form_id ); ?>-meeting" name="bmlt_lit_meeting" class="bmlt-lit-select bmlt-lit-meeting-select" required aria-describedby="<?php echo esc_attr( $form_id ); ?>-area-info">
					<option value=""><?php esc_html_e( '— Select your meeting —', 'bmlt-literature-manager' ); ?></option>
					<?php foreach ( $grouped as $area_id => $list ) : ?>
						<optgroup label="<?php echo esc_attr( Settings::area_name( $area_id ) ); ?>">
							<?php foreach ( $list as $m ) : ?>
								<option value="<?php echo esc_attr( $m['id'] ); ?>" data-area="<?php echo esc_attr( $area_id ); ?>" <?php selected( (int) $v['meeting_id'], (int) $m['id'] ); ?>><?php echo esc_html( Helpers::meeting_label( $m ) ); ?></option>
							<?php endforeach; ?>
						</optgroup>
					<?php endforeach; ?>
				</select>
			</p>
			<div class="bmlt-lit-area-info" id="<?php echo esc_attr( $form_id ); ?>-area-info" aria-live="polite">
				<?php if ( $single_area ) : ?>
					<?php
					/* translators: %s: Area name. */
					echo esc_html( sprintf( __( 'Your Area: %s', 'bmlt-literature-manager' ), Settings::area_name( $single_area ) ) );
					?>
				<?php endif; ?>
			</div>
		</fieldset>

		<fieldset class="bmlt-lit-fieldset">
			<legend class="bmlt-lit-legend"><?php esc_html_e( 'Your contact details', 'bmlt-literature-manager' ); ?></legend>
			<div class="bmlt-lit-grid">
				<p class="bmlt-lit-field">
					<label for="<?php echo esc_attr( $form_id ); ?>-name"><?php esc_html_e( 'Name', 'bmlt-literature-manager' ); ?> <?php echo $settings['require_name'] ? $req_mark : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<input type="text" id="<?php echo esc_attr( $form_id ); ?>-name" name="bmlt_lit_name" class="bmlt-lit-input" maxlength="200" autocomplete="name" value="<?php echo esc_attr( $v['contact_name'] ); ?>" <?php echo $settings['require_name'] ? 'required' : ''; ?>>
				</p>
				<p class="bmlt-lit-field">
					<label for="<?php echo esc_attr( $form_id ); ?>-phone"><?php esc_html_e( 'Phone number', 'bmlt-literature-manager' ); ?> <?php echo $settings['require_phone'] ? $req_mark : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<input type="tel" id="<?php echo esc_attr( $form_id ); ?>-phone" name="bmlt_lit_phone" class="bmlt-lit-input" maxlength="50" autocomplete="tel" value="<?php echo esc_attr( $v['contact_phone'] ); ?>" <?php echo $settings['require_phone'] ? 'required' : ''; ?>>
				</p>
				<p class="bmlt-lit-field">
					<label for="<?php echo esc_attr( $form_id ); ?>-email"><?php esc_html_e( 'Email address', 'bmlt-literature-manager' ); ?> <?php echo $settings['require_email'] ? $req_mark : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<input type="email" id="<?php echo esc_attr( $form_id ); ?>-email" name="bmlt_lit_email" class="bmlt-lit-input" maxlength="200" autocomplete="email" value="<?php echo esc_attr( $v['contact_email'] ); ?>" <?php echo $settings['require_email'] ? 'required' : ''; ?>>
				</p>
			</div>
			<p class="bmlt-lit-field">
				<label for="<?php echo esc_attr( $form_id ); ?>-notes"><?php esc_html_e( 'Notes or comments (optional)', 'bmlt-literature-manager' ); ?></label>
				<textarea id="<?php echo esc_attr( $form_id ); ?>-notes" name="bmlt_lit_notes" class="bmlt-lit-textarea" rows="3" maxlength="2000"><?php echo esc_textarea( $v['notes'] ); ?></textarea>
			</p>
			<p class="bmlt-lit-help"><span aria-hidden="true">*</span> <?php esc_html_e( 'Required', 'bmlt-literature-manager' ); ?></p>
		</fieldset>

		<fieldset class="bmlt-lit-fieldset">
			<legend class="bmlt-lit-legend"><?php esc_html_e( 'Literature', 'bmlt-literature-manager' ); ?></legend>

			<div class="bmlt-lit-filters" hidden>
				<p class="bmlt-lit-field">
					<label for="<?php echo esc_attr( $form_id ); ?>-isearch"><?php esc_html_e( 'Search all literature', 'bmlt-literature-manager' ); ?></label>
					<input type="search" id="<?php echo esc_attr( $form_id ); ?>-isearch" class="bmlt-lit-input bmlt-lit-item-search" autocomplete="off" placeholder="<?php esc_attr_e( 'Title', 'bmlt-literature-manager' ); ?>">
				</p>
			</div>

			<?php if ( $show_stock && ! $single_area ) : ?>
				<p class="bmlt-lit-help bmlt-lit-choose-meeting-hint"><?php esc_html_e( 'Select your meeting above to see which items are special orders for your Area.', 'bmlt-literature-manager' ); ?></p>
			<?php endif; ?>

			<?php if ( empty( $items ) ) : ?>
				<p class="bmlt-lit-notice"><?php esc_html_e( 'No literature is available to order right now.', 'bmlt-literature-manager' ); ?></p>
			<?php else : ?>
				<div class="bmlt-lit-items__head" aria-hidden="true">
					<span class="bmlt-lit-col--info" data-sort="name"><?php esc_html_e( 'Item', 'bmlt-literature-manager' ); ?></span>
					<?php if ( $show_prices ) : ?><span class="bmlt-lit-col--price" data-sort="price"><?php esc_html_e( 'Price', 'bmlt-literature-manager' ); ?></span><?php endif; ?>
					<?php if ( $show_stock ) : ?><span class="bmlt-lit-col--flag" data-sort="special"><?php esc_html_e( 'Special order', 'bmlt-literature-manager' ); ?></span><?php endif; ?>
					<span class="bmlt-lit-col--qty" data-sort="qty"><?php esc_html_e( 'Quantity', 'bmlt-literature-manager' ); ?></span>
				</div>
				<ul class="bmlt-lit-items" id="<?php echo esc_attr( $form_id ); ?>-items">
					<?php
					foreach ( $items as $it ) :
						$iid     = (int) $it->id;
						$qty     = isset( $v['qty'][ $iid ] ) ? max( 0, (int) $v['qty'][ $iid ] ) : 0;
						$name_id = $form_id . '-item-' . $iid;
						$status  = '';
						if ( $single_area && isset( $stock_js[ $single_area ][ $iid ] ) ) {
							$status = $stock_js[ $single_area ][ $iid ][0];
						}
						$hidden   = $single_area && (int) $it->area_id && (int) $it->area_id !== $single_area;
						$ref_url  = isset( $it->reference_url ) ? (string) $it->reference_url : '';
						$ref_host = '' !== $ref_url ? preg_replace( '/^www\./', '', (string) wp_parse_url( $ref_url, PHP_URL_HOST ) ) : '';
						?>
						<li class="bmlt-lit-item" data-item-id="<?php echo esc_attr( $iid ); ?>" data-area-id="<?php echo esc_attr( (int) $it->area_id ); ?>" data-price-cents="<?php echo esc_attr( Helpers::to_cents( $it->price ) ); ?>" data-category="<?php echo esc_attr( $it->category ); ?>" data-search="<?php echo esc_attr( strtolower( $it->sku . ' ' . $it->name . ' ' . $it->short_description ) ); ?>"<?php echo $hidden ? ' hidden' : ''; ?>>
							<div class="bmlt-lit-item__info">
								<h3 class="bmlt-lit-item__name" id="<?php echo esc_attr( $name_id ); ?>"><?php echo esc_html( $it->name ); ?></h3>
								<?php if ( $show_desc && '' !== trim( (string) $it->short_description ) ) : ?>
									<p class="bmlt-lit-item__desc"><?php echo esc_html( $it->short_description ); ?></p>
								<?php endif; ?>
								<?php if ( '' !== $ref_host ) : ?>
									<p class="bmlt-lit-item__ref"><a href="<?php echo esc_url( $ref_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( sprintf( /* translators: %s: website host. */ __( 'View on %s', 'bmlt-literature-manager' ), $ref_host ) ); ?><span class="bmlt-lit-sr-only"> <?php esc_html_e( '(opens in a new tab)', 'bmlt-literature-manager' ); ?></span></a></p>
								<?php endif; ?>
							</div>
							<?php if ( $show_prices ) : ?>
								<p class="bmlt-lit-item__price"><span class="bmlt-lit-sr-only"><?php esc_html_e( 'Price', 'bmlt-literature-manager' ); ?>: </span><?php echo esc_html( Helpers::format_money( $it->price ) ); ?></p>
							<?php endif; ?>
							<?php if ( $show_stock ) : ?>
								<p class="bmlt-lit-item__flag" aria-live="polite"><?php echo 'special_order' === $status ? Helpers::stock_badge( 'special_order' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in stock_badge(). ?></p>
							<?php endif; ?>
							<div class="bmlt-lit-item__order">
								<label class="bmlt-lit-sr-only" for="<?php echo esc_attr( $name_id ); ?>-qty"><?php echo esc_html( sprintf( /* translators: %s: item name. */ __( 'Quantity of %s', 'bmlt-literature-manager' ), $it->name ) ); ?></label>
								<div class="bmlt-lit-qty">
									<button type="button" class="bmlt-lit-qty__btn" data-step="-1" hidden aria-label="<?php echo esc_attr( sprintf( /* translators: %s: item name. */ __( 'Decrease quantity of %s', 'bmlt-literature-manager' ), $it->name ) ); ?>">−</button>
									<input type="number" class="bmlt-lit-qty__input" id="<?php echo esc_attr( $name_id ); ?>-qty" name="bmlt_lit_qty[<?php echo esc_attr( $iid ); ?>]" min="0" max="<?php echo esc_attr( $max_qty ); ?>" step="1" inputmode="numeric" value="<?php echo esc_attr( $qty ); ?>">
									<button type="button" class="bmlt-lit-qty__btn" data-step="1" hidden aria-label="<?php echo esc_attr( sprintf( /* translators: %s: item name. */ __( 'Increase quantity of %s', 'bmlt-literature-manager' ), $it->name ) ); ?>">+</button>
								</div>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="bmlt-lit-no-results" hidden><?php esc_html_e( 'No literature matches your search.', 'bmlt-literature-manager' ); ?></p>
			<?php endif; ?>
		</fieldset>

		<section class="bmlt-lit-summary" aria-labelledby="<?php echo esc_attr( $form_id ); ?>-summary-title">
			<h3 class="bmlt-lit-summary__title" id="<?php echo esc_attr( $form_id ); ?>-summary-title"><?php esc_html_e( 'Order summary', 'bmlt-literature-manager' ); ?></h3>
			<div class="bmlt-lit-special-warning" role="status" hidden>
				<p class="bmlt-lit-special-warning__title"><span aria-hidden="true">!</span> <?php esc_html_e( 'THIS ORDER CONTAINS SPECIAL ORDER ITEMS', 'bmlt-literature-manager' ); ?></p>
				<p><?php echo esc_html( $settings['special_order_message'] ); ?></p>
			</div>
			<dl class="bmlt-lit-totals" aria-live="polite">
				<div><dt><?php esc_html_e( 'Items', 'bmlt-literature-manager' ); ?></dt><dd class="bmlt-lit-sum-count">0</dd></div>
				<?php if ( $show_prices ) : ?>
					<div class="bmlt-lit-totals__total"><dt><?php esc_html_e( 'Total', 'bmlt-literature-manager' ); ?></dt><dd class="bmlt-lit-sum-total">—</dd></div>
				<?php endif; ?>
			</dl>
			<p class="bmlt-lit-delivery"><span aria-hidden="true">✓</span> <?php echo esc_html( $settings['delivery_message'] ); ?> <span class="bmlt-lit-delivery__extra"></span></p>
			<?php if ( $show_prices ) : ?>
				<p class="bmlt-lit-help"><?php esc_html_e( 'You will see a full review of your order, with confirmed prices, before anything is sent.', 'bmlt-literature-manager' ); ?></p>
			<?php endif; ?>
		</section>

		<div class="bmlt-lit-hp" aria-hidden="true">
			<label for="<?php echo esc_attr( $form_id ); ?>-website"><?php esc_html_e( 'Leave this field empty', 'bmlt-literature-manager' ); ?></label>
			<input type="text" id="<?php echo esc_attr( $form_id ); ?>-website" name="bmlt_lit_website" value="" tabindex="-1" autocomplete="off">
		</div>

		<?php
		/**
		 * Render an optional CAPTCHA widget. Pair with the bmlt_lit_validate_captcha filter.
		 */
		do_action( 'bmlt_lit_order_form_captcha' );
		wp_nonce_field( 'bmlt_lit_submit_order', 'bmlt_lit_nonce' );
		?>
		<input type="hidden" name="bmlt_lit_action" value="submit_order">
		<input type="hidden" name="bmlt_lit_token" value="<?php echo esc_attr( $form_token ); ?>">
		<input type="hidden" name="bmlt_lit_ts" value="<?php echo esc_attr( $time_token ); ?>">
		<input type="hidden" name="bmlt_lit_areas" value="<?php echo esc_attr( implode( ',', $areas ) ); ?>">
		<input type="hidden" name="bmlt_lit_areas_sig" value="<?php echo esc_attr( $areas_sig ); ?>">
		<input type="hidden" name="bmlt_lit_return" value="<?php echo esc_url( $return_url ); ?>">

		<p class="bmlt-lit-actions">
			<input type="hidden" name="bmlt_lit_step" value="review">
			<button type="submit" class="bmlt-lit-button bmlt-lit-button--primary bmlt-lit-submit"><?php esc_html_e( 'Review order →', 'bmlt-literature-manager' ); ?></button>
		</p>
	</form>
</div>
