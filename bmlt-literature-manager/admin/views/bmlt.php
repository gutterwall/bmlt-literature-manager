<?php
/**
 * BMLT / Areas view.
 *
 * @package BMLT_Lit
 * @version 7.0.0
 *
 * @var array      $opts
 * @var array      $tree
 * @var int[]      $selected
 * @var array      $status
 * @var array      $meetings
 * @var int        $sb_time
 * @var array|null $test
 */

use BMLT_Lit\Bmlt_Client;

defined( 'ABSPATH' ) || exit;

$post_url = admin_url( 'admin-post.php' );
$fmt      = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
$types    = array(
	'AS' => __( 'Area', 'bmlt-literature-manager' ),
	'RS' => __( 'Region', 'bmlt-literature-manager' ),
	'ZF' => __( 'Zonal Forum', 'bmlt-literature-manager' ),
	'MA' => __( 'Metro Area', 'bmlt-literature-manager' ),
	'GR' => __( 'Group', 'bmlt-literature-manager' ),
	'WS' => __( 'World Service', 'bmlt-literature-manager' ),
	'LS' => __( 'Local Service Body', 'bmlt-literature-manager' ),
);
$counts = array();
foreach ( $meetings['meetings'] as $m ) {
	$counts[ (int) $m['area_id'] ] = isset( $counts[ (int) $m['area_id'] ] ) ? $counts[ (int) $m['area_id'] ] + 1 : 1;
}
?>
<div class="wrap bmlt-lit-admin">
	<h1><?php esc_html_e( 'BMLT / Areas', 'bmlt-literature-manager' ); ?></h1>

	<div class="postbox bmlt-lit-box">
		<h2 class="hndle"><?php esc_html_e( '1. Connect to your BMLT Root Server', 'bmlt-literature-manager' ); ?></h2>
		<div class="inside">
			<form method="post" action="<?php echo esc_url( $post_url ); ?>">
				<?php wp_nonce_field( 'bmlt_lit_save_bmlt' ); ?>
				<input type="hidden" name="action" value="bmlt_lit_save_bmlt">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bmlt-lit-root"><?php esc_html_e( 'Root Server URL', 'bmlt-literature-manager' ); ?></label></th>
						<td><input type="url" id="bmlt-lit-root" name="root_url" class="large-text" value="<?php echo esc_attr( $opts['root_url'] ); ?>" placeholder="https://bmlt.example.org/main_server">
							<p class="description"><?php esc_html_e( 'The base URL of your BMLT Root Server (usually ends in /main_server). The plugin uses the documented JSON client interface at {root}/client_interface/json/.', 'bmlt-literature-manager' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="bmlt-lit-override"><?php esc_html_e( 'API endpoint override (optional)', 'bmlt-literature-manager' ); ?></label></th>
						<td><input type="url" id="bmlt-lit-override" name="endpoint_override" class="large-text" value="<?php echo esc_attr( $opts['endpoint_override'] ); ?>" placeholder="https://bmlt.example.org/main_server/client_interface/json/">
							<p class="description"><?php esc_html_e( 'Only needed if your server publishes the JSON interface at a non-standard address.', 'bmlt-literature-manager' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="bmlt-lit-timeout"><?php esc_html_e( 'Connection timeout (seconds)', 'bmlt-literature-manager' ); ?></label></th>
						<td><input type="number" id="bmlt-lit-timeout" name="timeout" min="3" max="120" class="small-text" value="<?php echo esc_attr( $opts['timeout'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="bmlt-lit-cache"><?php esc_html_e( 'Cache duration (hours)', 'bmlt-literature-manager' ); ?></label></th>
						<td><input type="number" id="bmlt-lit-cache" name="cache_hours" min="1" max="168" class="small-text" value="<?php echo esc_attr( $opts['cache_hours'] ); ?>">
							<p class="description"><?php esc_html_e( 'Meeting data is reused for this long before BMLT is queried again. If BMLT is unreachable, the last good data keeps being used.', 'bmlt-literature-manager' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="bmlt-lit-refresh"><?php esc_html_e( 'Automatic refresh (hours)', 'bmlt-literature-manager' ); ?></label></th>
						<td><input type="number" id="bmlt-lit-refresh" name="refresh_hours" min="1" max="168" class="small-text" value="<?php echo esc_attr( $opts['refresh_hours'] ); ?>">
							<p class="description"><?php esc_html_e( 'Background refresh interval using WordPress cron.', 'bmlt-literature-manager' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Debug mode', 'bmlt-literature-manager' ); ?></th>
						<td><label><input type="checkbox" name="debug" value="1" <?php checked( (int) $opts['debug'], 1 ); ?>> <?php esc_html_e( 'Log BMLT requests to the PHP error log when WP_DEBUG_LOG is enabled', 'bmlt-literature-manager' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button( __( 'Save BMLT settings', 'bmlt-literature-manager' ) ); ?>
			</form>

			<div class="bmlt-lit-test">
				<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="bmlt-lit-inline-form">
					<?php wp_nonce_field( 'bmlt_lit_test_bmlt' ); ?>
					<input type="hidden" name="action" value="bmlt_lit_test_bmlt">
					<button type="submit" class="button button-secondary bmlt-lit-test-button" <?php disabled( '' === Bmlt_Client::endpoint() ); ?>><?php esc_html_e( 'Test BMLT Connection', 'bmlt-literature-manager' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="bmlt-lit-inline-form">
					<?php wp_nonce_field( 'bmlt_lit_refresh_bmlt' ); ?>
					<input type="hidden" name="action" value="bmlt_lit_refresh_bmlt">
					<button type="submit" class="button" <?php disabled( '' === Bmlt_Client::endpoint() ); ?>><?php esc_html_e( 'Refresh BMLT Data', 'bmlt-literature-manager' ); ?></button>
				</form>
				<div class="bmlt-lit-test-result" role="status" aria-live="polite">
					<?php if ( $test ) : ?>
						<div class="notice inline notice-<?php echo $test['success'] ? 'success' : 'error'; ?>">
							<p><strong><?php echo $test['success'] ? esc_html__( 'Connection successful.', 'bmlt-literature-manager' ) : esc_html__( 'Connection failed.', 'bmlt-literature-manager' ); ?></strong></p>
							<?php if ( $test['version'] ) : ?><p><?php esc_html_e( 'Server version', 'bmlt-literature-manager' ); ?>: <?php echo esc_html( $test['version'] ); ?></p><?php endif; ?>
							<p><?php esc_html_e( 'Service bodies detected', 'bmlt-literature-manager' ); ?>: <?php echo (int) $test['service_bodies']; ?></p>
							<p><?php esc_html_e( 'Meetings detected', 'bmlt-literature-manager' ); ?>: <?php echo (int) $test['meetings']; ?> <?php echo 'selected' === $test['meetings_scope'] ? esc_html__( '(in selected Areas)', 'bmlt-literature-manager' ) : esc_html__( '(on the whole server)', 'bmlt-literature-manager' ); ?></p>
							<?php foreach ( $test['messages'] as $msg ) : ?><p><?php echo esc_html( $msg ); ?></p><?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: last success, 2: last error. */
						__( 'Last successful contact: %1$s. Last error: %2$s', 'bmlt-literature-manager' ),
						$status['last_success'] ? wp_date( $fmt, $status['last_success'] ) : __( 'never', 'bmlt-literature-manager' ),
						$status['last_error'] ? $status['last_error'] : __( 'none', 'bmlt-literature-manager' )
					)
				);
				?>
			</p>
		</div>
	</div>

	<div class="postbox bmlt-lit-box">
		<h2 class="hndle"><?php esc_html_e( '2. Choose the Areas this site manages', 'bmlt-literature-manager' ); ?></h2>
		<div class="inside">
			<?php if ( empty( $tree ) ) : ?>
				<p><?php esc_html_e( 'No service bodies are loaded yet. Save the Root Server URL and click “Test BMLT Connection” or “Refresh BMLT Data”.', 'bmlt-literature-manager' ); ?></p>
			<?php else : ?>
				<p class="description">
					<?php
					/* translators: %s: date. */
					echo esc_html( sprintf( __( 'Service bodies loaded from BMLT on %s. Each selected Area gets its own inventory, email routing and (optionally) its own catalog.', 'bmlt-literature-manager' ), $sb_time ? wp_date( $fmt, $sb_time ) : '—' ) );
					?>
				</p>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>">
					<?php wp_nonce_field( 'bmlt_lit_save_area_selection' ); ?>
					<input type="hidden" name="action" value="bmlt_lit_save_area_selection">
					<p><label for="bmlt-lit-sb-filter"><?php esc_html_e( 'Filter list', 'bmlt-literature-manager' ); ?></label> <input type="search" id="bmlt-lit-sb-filter" class="regular-text bmlt-lit-sb-filter"></p>
					<fieldset class="bmlt-lit-sb-list">
						<legend class="screen-reader-text"><?php esc_html_e( 'Service bodies', 'bmlt-literature-manager' ); ?></legend>
						<?php foreach ( $tree as $node ) : ?>
							<?php $b = $node['body']; ?>
							<label class="bmlt-lit-sb" style="margin-left:<?php echo (int) min( 10, $node['depth'] ) * 1.5; ?>em" data-name="<?php echo esc_attr( strtolower( $b['name'] ) ); ?>">
								<input type="checkbox" name="areas[]" value="<?php echo (int) $b['id']; ?>" <?php checked( in_array( (int) $b['id'], $selected, true ) ); ?>>
								<strong><?php echo esc_html( $b['name'] ); ?></strong>
								<span class="description">
									<?php echo esc_html( isset( $types[ $b['type'] ] ) ? $types[ $b['type'] ] : $b['type'] ); ?> · ID <?php echo (int) $b['id']; ?>
									<?php if ( isset( $counts[ (int) $b['id'] ] ) ) : ?>
										· <?php echo esc_html( sprintf( /* translators: %d: meetings. */ _n( '%d meeting', '%d meetings', $counts[ (int) $b['id'] ], 'bmlt-literature-manager' ), $counts[ (int) $b['id'] ] ) ); ?>
									<?php endif; ?>
								</span>
							</label>
						<?php endforeach; ?>
					</fieldset>
					<?php submit_button( __( 'Save selected Areas', 'bmlt-literature-manager' ) ); ?>
				</form>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=bmlt-lit-settings&tab=areas' ) ); ?>"><?php esc_html_e( 'Next: configure literature-worker emails for each Area →', 'bmlt-literature-manager' ); ?></a></p>
			<?php endif; ?>
		</div>
	</div>

	<div class="postbox bmlt-lit-box">
		<h2 class="hndle"><?php esc_html_e( 'Cached meetings', 'bmlt-literature-manager' ); ?></h2>
		<div class="inside">
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: count, 2: date, 3: freshness. */
						__( '%1$d meetings cached (fetched %2$s, %3$s).', 'bmlt-literature-manager' ),
						count( $meetings['meetings'] ),
						$meetings['fetched_at'] ? wp_date( $fmt, $meetings['fetched_at'] ) : __( 'never', 'bmlt-literature-manager' ),
						$meetings['stale'] ? __( 'due for refresh — cached data is being used', 'bmlt-literature-manager' ) : __( 'fresh', 'bmlt-literature-manager' )
					)
				);
				?>
			</p>
		</div>
	</div>
</div>
