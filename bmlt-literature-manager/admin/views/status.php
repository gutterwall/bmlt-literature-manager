<?php
/**
 * Tools / Status view. Never displays secrets.
 *
 * @package BMLT_Lit
 * @version 6.3.0
 *
 * @var array      $bmlt
 * @var array      $bmlt_state
 * @var array      $meetings
 * @var int        $sb_count
 * @var int        $sb_time
 * @var int        $items
 * @var int        $inv
 * @var int        $orders
 * @var array      $smtp
 * @var string     $pw_source
 * @var array      $health
 * @var array      $meta
 * @var string     $foreign
 * @var bool       $legacy
 * @var array|null $restore
 * @var int|bool   $next_cron
 */

use BMLT_Lit\Bmlt_Client;
use BMLT_Lit\Settings;

defined( 'ABSPATH' ) || exit;

$fmt      = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
$areas    = Settings::area_options();
$cfg      = Settings::areas_config();
$is_admin = current_user_can( 'manage_options' );
$post_url = admin_url( 'admin-post.php' );
$no_email = array();
foreach ( $areas as $id => $name ) {
	if ( empty( $cfg[ $id ]['worker_emails'] ) ) {
		$no_email[] = $name;
	}
}
$rows = array(
	__( 'Plugin version', 'bmlt-literature-manager' )           => BMLT_LIT_VERSION,
	__( 'Storage', 'bmlt-literature-manager' )                  => __( 'JSON files (not the WordPress database)', 'bmlt-literature-manager' ),
	__( 'Data directory', 'bmlt-literature-manager' )           => $health['dir'] . ( $health['configured'] ? ' ' . __( '(set by BMLT_LIT_DATA_DIR)', 'bmlt-literature-manager' ) : ' ' . __( '(default location)', 'bmlt-literature-manager' ) ),
	__( 'Directory writable', 'bmlt-literature-manager' )       => $health['writable'] ? __( 'Yes', 'bmlt-literature-manager' ) : __( 'NO — data cannot be saved', 'bmlt-literature-manager' ),
	__( 'Data files', 'bmlt-literature-manager' )               => sprintf( /* translators: 1: count, 2: size. */ __( '%1$d files, %2$s', 'bmlt-literature-manager' ), $health['files'], size_format( $health['bytes'] ) ),
	__( 'Backup snapshots', 'bmlt-literature-manager' )         => (string) $health['snapshots'],
	__( 'Lock method', 'bmlt-literature-manager' )              => $health['lock_method'],
	__( 'Data created by site', 'bmlt-literature-manager' )     => ! empty( $meta['site_url'] ) ? $meta['site_url'] : '—',
	__( 'Storage schema', 'bmlt-literature-manager' )           => isset( $meta['schema'] ) ? (string) (int) $meta['schema'] : '—',
	__( 'WordPress version', 'bmlt-literature-manager' )        => get_bloginfo( 'version' ),
	__( 'PHP version', 'bmlt-literature-manager' )              => PHP_VERSION,
	__( 'BMLT server', 'bmlt-literature-manager' )              => Bmlt_Client::endpoint() ? Bmlt_Client::endpoint() : __( 'not configured', 'bmlt-literature-manager' ),
	__( 'BMLT last success', 'bmlt-literature-manager' )        => $bmlt_state['last_success'] ? wp_date( $fmt, $bmlt_state['last_success'] ) : __( 'never', 'bmlt-literature-manager' ),
	__( 'BMLT last error', 'bmlt-literature-manager' )          => $bmlt_state['last_error'] ? $bmlt_state['last_error'] : __( 'none', 'bmlt-literature-manager' ),
	__( 'BMLT cache', 'bmlt-literature-manager' )               => sprintf( /* translators: 1: count, 2: date, 3: freshness. */ __( '%1$d service bodies (%2$s); meetings %3$s', 'bmlt-literature-manager' ), $sb_count, $sb_time ? wp_date( $fmt, $sb_time ) : '—', $meetings['stale'] ? __( 'stale — using cached data', 'bmlt-literature-manager' ) : __( 'fresh', 'bmlt-literature-manager' ) ),
	__( 'Next automatic refresh', 'bmlt-literature-manager' )   => $next_cron ? wp_date( $fmt, (int) $next_cron ) : __( 'not scheduled', 'bmlt-literature-manager' ),
	__( 'Configured Areas', 'bmlt-literature-manager' )         => $areas ? implode( ', ', $areas ) : __( 'none', 'bmlt-literature-manager' ),
	__( 'Cached meetings', 'bmlt-literature-manager' )          => (string) count( $meetings['meetings'] ),
	__( 'Literature items', 'bmlt-literature-manager' )         => (string) $items,
	__( 'Inventory records', 'bmlt-literature-manager' )        => (string) $inv,
	__( 'Orders', 'bmlt-literature-manager' )                   => (string) $orders,
	__( 'Email delivery', 'bmlt-literature-manager' )           => ! empty( $smtp['enabled'] ) ? sprintf( /* translators: 1: host, 2: port, 3: encryption. */ __( 'Custom SMTP: %1$s:%2$d (%3$s)', 'bmlt-literature-manager' ), $smtp['host'], (int) $smtp['port'], $smtp['encryption'] ) : __( 'WordPress default mail', 'bmlt-literature-manager' ),
	__( 'SMTP password', 'bmlt-literature-manager' )            => 'constant' === $pw_source ? __( 'defined in wp-config.php', 'bmlt-literature-manager' ) : ( 'database' === $pw_source ? __( 'saved (encrypted, in the data directory)', 'bmlt-literature-manager' ) : __( 'not set', 'bmlt-literature-manager' ) ),
	__( 'Areas without worker email', 'bmlt-literature-manager' ) => $no_email ? implode( ', ', $no_email ) . ' — ' . __( 'orders fall back to the global chair / site admin', 'bmlt-literature-manager' ) : __( 'none', 'bmlt-literature-manager' ),
	__( 'Delete data on uninstall', 'bmlt-literature-manager' ) => Settings::get( 'delete_data_on_uninstall' ) ? __( 'YES', 'bmlt-literature-manager' ) : __( 'No', 'bmlt-literature-manager' ),
);
?>
<div class="wrap bmlt-lit-admin">
	<h1><?php esc_html_e( 'Tools / Status', 'bmlt-literature-manager' ); ?></h1>

	<?php if ( $restore ) : ?>
		<div class="postbox bmlt-lit-box bmlt-lit-preview">
			<h2 class="hndle"><?php esc_html_e( 'Confirm restore — nothing has changed yet', 'bmlt-literature-manager' ); ?></h2>
			<div class="inside">
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: site, 2: items, 3: inventory, 4: orders, 5: audit, 6: users. */
							__( 'Backup from %1$s contains %2$d items, %3$d inventory records, %4$d orders, %5$d audit entries and %6$d user permission sets.', 'bmlt-literature-manager' ),
							$restore['site'] ? $restore['site'] : '?',
							$restore['counts']['items'],
							$restore['counts']['inventory'],
							$restore['counts']['orders'],
							$restore['counts']['audit'],
							$restore['counts']['users']
						)
					);
					?>
				</p>
				<p><strong><?php esc_html_e( 'Restoring REPLACES all current literature data on this site (catalog, inventory, orders, history, settings and permissions). A snapshot of the current data is saved first. This site’s SMTP password is kept.', 'bmlt-literature-manager' ); ?></strong></p>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>">
					<?php wp_nonce_field( 'bmlt_lit_restore_backup' ); ?>
					<input type="hidden" name="action" value="bmlt_lit_restore_backup">
					<p><label for="bmlt-lit-restore-confirm"><?php esc_html_e( 'Type RESTORE to confirm', 'bmlt-literature-manager' ); ?></label>
						<input type="text" id="bmlt-lit-restore-confirm" name="confirm_text" class="regular-text" autocomplete="off" required></p>
					<p>
						<button type="submit" name="confirm" value="1" class="button button-primary"><?php esc_html_e( 'Replace all data with this backup', 'bmlt-literature-manager' ); ?></button>
						<button type="submit" name="cancel" value="1" class="button" formnovalidate><?php esc_html_e( 'Cancel', 'bmlt-literature-manager' ); ?></button>
					</p>
				</form>
			</div>
		</div>
	<?php endif; ?>

	<?php if ( '' !== $foreign ) : ?>
		<div class="notice notice-warning inline">
			<p><strong><?php esc_html_e( 'This data directory was created by a different site.', 'bmlt-literature-manager' ); ?></strong></p>
			<p>
				<?php
				/* translators: 1: other site, 2: this site. */
				echo esc_html( sprintf( __( 'Created by: %1$s. This site: %2$s.', 'bmlt-literature-manager' ), $foreign, home_url( '/' ) ) );
				?>
			</p>
			<p><?php esc_html_e( 'If this is production and the directory was copied from staging (or both sites point at the same directory), give each environment its own directory with BMLT_LIT_DATA_DIR before taking real orders. If you intentionally moved the data here (for example, a domain change), mark it as belonging to this site.', 'bmlt-literature-manager' ); ?></p>
			<?php if ( $is_admin ) : ?>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>">
					<?php wp_nonce_field( 'bmlt_lit_adopt_site' ); ?>
					<input type="hidden" name="action" value="bmlt_lit_adopt_site">
					<p><button type="submit" class="button" data-bmlt-confirm="<?php esc_attr_e( 'Mark this literature data as belonging to this site?', 'bmlt-literature-manager' ); ?>"><?php esc_html_e( 'This is correct — use this data for this site', 'bmlt-literature-manager' ); ?></button></p>
				</form>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( $health['inside_wp'] || ! $health['configured'] ) : ?>
		<div class="notice notice-info inline bmlt-lit-push-help">
			<p><strong><?php esc_html_e( 'Staging / production safety', 'bmlt-literature-manager' ); ?></strong></p>
			<p><?php esc_html_e( 'Literature data is stored in JSON files, so pushing a staging DATABASE to production no longer touches orders, inventory, catalog or settings.', 'bmlt-literature-manager' ); ?></p>
			<?php if ( $health['inside_wp'] ) : ?>
				<p><?php esc_html_e( 'However, the data directory is inside your WordPress installation. If your host’s “push to production” also copies FILES (many do, including wp-content), it would overwrite production’s data with staging’s. To prevent this, either exclude this folder from file pushes, or move it outside the WordPress directory on each server by adding a line like this to wp-config.php (above “That’s all, stop editing”):', 'bmlt-literature-manager' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'For clarity, you can set the location explicitly in wp-config.php on each server:', 'bmlt-literature-manager' ); ?></p>
			<?php endif; ?>
			<p><code>define( 'BMLT_LIT_DATA_DIR', '<?php echo esc_html( wp_normalize_path( dirname( untrailingslashit( ABSPATH ) ) ) ); ?>/bmlt-literature-data' );</code></p>
			<p><?php esc_html_e( 'Move the existing folder to the new location before adding the line, so current data is kept. Never point staging and production at the same directory.', 'bmlt-literature-manager' ); ?></p>
		</div>
	<?php endif; ?>

	<table class="widefat striped bmlt-lit-status-table">
		<tbody>
			<?php foreach ( $rows as $label => $value ) : ?>
				<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( $value ); ?></td></tr>
			<?php endforeach; ?>
			<?php if ( ! empty( $health['read_errors'] ) ) : ?>
				<tr><th scope="row"><?php esc_html_e( 'Unreadable files', 'bmlt-literature-manager' ); ?></th><td><strong><?php echo esc_html( implode( ', ', $health['read_errors'] ) ); ?></strong></td></tr>
			<?php endif; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Backup and restore', 'bmlt-literature-manager' ); ?></h2>
	<div class="bmlt-lit-tools">
		<form method="post" action="<?php echo esc_url( $post_url ); ?>">
			<?php wp_nonce_field( 'bmlt_lit_backup_download' ); ?>
			<input type="hidden" name="action" value="bmlt_lit_backup_download">
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Download full backup (JSON)', 'bmlt-literature-manager' ); ?></button>
				<span class="description"><?php esc_html_e( 'Catalog, inventory, orders, history, settings and permissions. The SMTP password is never included.', 'bmlt-literature-manager' ); ?></span></p>
		</form>
		<?php if ( $is_admin ) : ?>
			<form method="post" action="<?php echo esc_url( $post_url ); ?>" enctype="multipart/form-data">
				<?php wp_nonce_field( 'bmlt_lit_restore_backup' ); ?>
				<input type="hidden" name="action" value="bmlt_lit_restore_backup">
				<p>
					<label for="bmlt-lit-backup-file"><?php esc_html_e( 'Restore from a full backup', 'bmlt-literature-manager' ); ?></label><br>
					<input type="file" id="bmlt-lit-backup-file" name="backup_file" accept=".json,application/json" required>
					<button type="submit" class="button"><?php esc_html_e( 'Check backup', 'bmlt-literature-manager' ); ?></button>
				</p>
				<p class="description"><?php esc_html_e( 'The file is validated and summarized first; nothing changes until you confirm. Use this to copy a catalog/configuration from staging to production deliberately.', 'bmlt-literature-manager' ); ?></p>
			</form>
		<?php endif; ?>
		<p class="description">
			<?php
			/* translators: %s: directory. */
			echo esc_html( sprintf( __( 'Automatic snapshots (before restores, migrations and repairs) are kept in %s. Each data file also keeps its previous version as .bak.', 'bmlt-literature-manager' ), $health['dir'] . '/backups' ) );
			?>
		</p>
	</div>

	<h2><?php esc_html_e( 'Tools', 'bmlt-literature-manager' ); ?></h2>
	<div class="bmlt-lit-tools">
		<form method="post" action="<?php echo esc_url( $post_url ); ?>">
			<?php wp_nonce_field( 'bmlt_lit_refresh_bmlt' ); ?>
			<input type="hidden" name="action" value="bmlt_lit_refresh_bmlt">
			<input type="hidden" name="back" value="status">
			<p><button type="submit" class="button"><?php esc_html_e( 'Refresh BMLT Data', 'bmlt-literature-manager' ); ?></button> <span class="description"><?php esc_html_e( 'Fetch fresh Areas and meetings now (keeps the cache if BMLT is down).', 'bmlt-literature-manager' ); ?></span></p>
		</form>
		<form method="post" action="<?php echo esc_url( $post_url ); ?>">
			<?php wp_nonce_field( 'bmlt_lit_clear_cache' ); ?>
			<input type="hidden" name="action" value="bmlt_lit_clear_cache">
			<p><button type="submit" class="button" data-bmlt-confirm="<?php esc_attr_e( 'Clear the BMLT cache? If BMLT cannot be reached, the order form will be unavailable until it can.', 'bmlt-literature-manager' ); ?>"><?php esc_html_e( 'Clear and rebuild BMLT cache', 'bmlt-literature-manager' ); ?></button></p>
		</form>
		<form method="post" action="<?php echo esc_url( $post_url ); ?>">
			<?php wp_nonce_field( 'bmlt_lit_repair_store' ); ?>
			<input type="hidden" name="action" value="bmlt_lit_repair_store">
			<p><button type="submit" class="button"><?php esc_html_e( 'Verify storage and rebuild order index', 'bmlt-literature-manager' ); ?></button> <span class="description"><?php esc_html_e( 'Safe: recreates folders and protection files and rebuilds the order index from the monthly order files. Never removes data.', 'bmlt-literature-manager' ); ?></span></p>
		</form>
		<?php if ( $legacy && $is_admin ) : ?>
			<form method="post" action="<?php echo esc_url( $post_url ); ?>">
				<?php wp_nonce_field( 'bmlt_lit_migrate_db' ); ?>
				<input type="hidden" name="action" value="bmlt_lit_migrate_db">
				<p><button type="submit" class="button" data-bmlt-confirm="<?php esc_attr_e( 'Re-import all version 1.0 database data into JSON storage? Current JSON data will be replaced (a snapshot is saved first).', 'bmlt-literature-manager' ); ?>"><?php esc_html_e( 'Re-run migration from version 1.0 database', 'bmlt-literature-manager' ); ?></button>
					<span class="description"><?php echo ! empty( $meta['migrated_from_db'] ) ? esc_html( sprintf( /* translators: %s: date. */ __( 'Last migrated %s.', 'bmlt-literature-manager' ), wp_date( $fmt, (int) $meta['migrated_from_db'] ) ) ) : esc_html__( 'Version 1.0 database data was found.', 'bmlt-literature-manager' ); ?></span></p>
			</form>
			<?php if ( ! empty( $meta['migrated_from_db'] ) ) : ?>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>">
					<?php wp_nonce_field( 'bmlt_lit_drop_legacy' ); ?>
					<input type="hidden" name="action" value="bmlt_lit_drop_legacy">
					<p><button type="submit" class="button bmlt-lit-danger" data-bmlt-confirm="<?php esc_attr_e( 'Permanently delete the old version 1.0 database tables and options? Download a full backup first.', 'bmlt-literature-manager' ); ?>"><?php esc_html_e( 'Delete old version 1.0 database tables', 'bmlt-literature-manager' ); ?></button>
						<span class="description"><?php esc_html_e( 'Optional cleanup after you have confirmed the migrated data is correct.', 'bmlt-literature-manager' ); ?></span></p>
				</form>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</div>
