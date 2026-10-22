<?php
/**
 * The JSON file store: what lands on disk, all-or-nothing transactions, locking, damaged files and snapshots.
 */

namespace BmltLitTest;

use BMLT_Lit\Store;

class StoreTest extends TestCase {

	/**
	 * Every file below the data directory, relative paths.
	 *
	 * @return string[]
	 */
	private function files() {
		$out = array();
		$it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			$out[] = substr( $f->getPathname(), strlen( $this->dir ) + 1 );
		}
		sort( $out );
		return $out;
	}

	public function test_documents_are_php_files_that_exit_before_any_data() {
		$path = Store::path( 'catalog' );
		$this->assertSame( $this->dir . '/catalog.json.php', $path );
		$raw = file_get_contents( $path );
		$this->assertStringStartsWith( "<?php exit; ?>\n{", $raw );
		$this->assertIsArray( json_decode( substr( $raw, strlen( Store::HEADER ) ), true ) );
		foreach ( array( '', '/orders', '/audit', '/cache', '/backups' ) as $sub ) {
			$this->assertStringContainsString( 'Require all denied', file_get_contents( $this->dir . $sub . '/.htaccess' ) );
			$this->assertFileExists( $this->dir . $sub . '/index.php' );
			$this->assertFileExists( $this->dir . $sub . '/web.config' );
		}
	}

	/**
	 * nginx ignores .htaccess, and serves every file that is not *.php as it is. So nothing holding data may be
	 * named anything but *.php — previous versions (".bak") included.
	 */
	public function test_no_file_with_data_is_downloadable_from_a_server_that_ignores_htaccess() {
		$this->place_order( $this->order_post( array( 'BT' => 1 ) ) );
		$this->place_order( $this->order_post( array( 'IP1' => 1 ) ) );
		Store::snapshot( 'manual' );
		foreach ( $this->files() as $f ) {
			$base = basename( $f );
			if ( in_array( $base, array( '.htaccess', 'web.config', '.lock' ), true ) ) {
				$this->assertLessThan( 400, filesize( $this->dir . '/' . $f ), $f );
				continue;
			}
			$this->assertStringEndsWith( '.php', $f, 'served as plain text by nginx: ' . $f );
			$this->assertStringStartsWith( '<?php', file_get_contents( $this->dir . '/' . $f ), $f );
		}
		$this->assertNotEmpty( glob( $this->dir . '/orders/*bak*' ), 'previous versions are still kept' );
	}

	public function test_previous_version_is_kept_and_used_when_the_file_is_damaged() {
		Store::put( 'catalog', array( 'v' => 1 ) );
		Store::put( 'catalog', array( 'v' => 2 ) );
		file_put_contents( Store::path( 'catalog' ), "<?php exit; ?>\n{\"v\": 3, trunc" );
		self::reset_plugin_state();
		$this->assertSame( array( 'v' => 1 ), Store::get( 'catalog' ), 'the previous version is read instead' );
		$this->assertSame( array( 'catalog' ), Store::read_errors() );

		// A transaction continues from the previous version and repairs the file.
		$this->assertTrue(
			Store::tx(
				static function () {
					$d      = Store::get( 'catalog' );
					$d['v'] = $d['v'] + 10;
					return Store::put( 'catalog', $d );
				}
			)
		);
		self::reset_plugin_state();
		$this->assertSame( array( 'v' => 11 ), Store::get( 'catalog' ) );
		$this->assertSame( array(), Store::read_errors() );
	}

	public function test_a_damaged_file_without_a_usable_backup_blocks_changes_instead_of_being_replaced() {
		$damaged = "<?php exit; ?>\nnot json";
		file_put_contents( Store::path( 'inventory' ), $damaged );
		foreach ( glob( $this->dir . '/inventory*' ) as $f ) {
			if ( Store::path( 'inventory' ) !== $f ) {
				unlink( $f );
			}
		}
		self::reset_plugin_state();
		wp_set_current_user( self::$admin );
		$res = \BMLT_Lit\Inventory::adjust( self::RIVERSIDE, $this->items['BT'], array( 'set' => 9 ) );
		$this->assertWPError( $res );
		$this->assertStringContainsString( 'is damaged and could not be read. No changes were saved.', $res->get_error_message() );
		$this->assertSame( $damaged, file_get_contents( Store::path( 'inventory' ) ) );
	}

	public function test_transactions_are_all_or_nothing() {
		$before = file_get_contents( Store::path( 'catalog' ) );
		$res    = Store::tx(
			static function () {
				Store::put( 'catalog', array( 'items' => array() ) );
				Store::put( 'inventory', array( 'areas' => array() ) );
				return new \WP_Error( 'stop', 'stop' );
			}
		);
		$this->assertWPError( $res );
		$res = Store::tx(
			static function () {
				Store::put( 'catalog', array( 'items' => array() ) );
				throw new \LogicException( 'boom' );
			}
		);
		$this->assertWPError( $res );
		$this->assertSame( 'The literature data could not be saved because of a system problem. Please try again.', $res->get_error_message() );
		$this->assertSame( $before, file_get_contents( Store::path( 'catalog' ) ) );
		self::reset_plugin_state();
		$this->assertNotEmpty( Store::get( 'catalog' )['items'] );
	}

	public function test_document_names_cannot_leave_the_data_directory() {
		foreach ( array( '../wp-config', 'orders/../../x', '/etc/passwd', 'a/b/c', 'Catalog', '', 'x.php' ) as $bad ) {
			$this->assertFalse( Store::valid_name( $bad ), $bad );
			$this->assertWPError( Store::put( $bad, array() ), $bad );
		}
		$this->expectException( \InvalidArgumentException::class );
		Store::path( '../wp-config' );
	}

	public function test_a_writer_waits_for_another_process_holding_the_lock() {
		if ( ! function_exists( 'proc_open' ) ) {
			$this->markTestSkipped( 'proc_open is not available.' );
		}
		$lock  = $this->dir . '/.lock';
		$ready = $this->dir . '/ready';
		$code  = '$h = fopen(' . var_export( $lock, true ) . ', "c"); flock($h, LOCK_EX); touch(' . var_export( $ready, true ) . '); usleep(1500000); flock($h, LOCK_UN);';
		$proc  = proc_open( array( PHP_BINARY, '-r', $code ), array(), $pipes );
		$wait  = microtime( true );
		while ( ! file_exists( $ready ) && microtime( true ) - $wait < 10 ) {
			usleep( 20000 );
		}
		$start = microtime( true );
		$this->assertTrue( Store::put( 'cache/probe', array( 'ok' => 1 ) ) );
		$waited = microtime( true ) - $start;
		proc_close( $proc );
		$this->assertGreaterThan( 1.0, $waited, 'the write waited for the lock' );
		self::reset_plugin_state();
		$this->assertSame( array( 'ok' => 1 ), Store::get( 'cache/probe' ) );
	}

	public function test_snapshots_copy_every_data_file_and_keep_the_newest_15() {
		$this->place_order( $this->order_post( array( 'BT' => 1 ) ) );
		$snap = Store::snapshot( 'Manual Test!' );
		$this->assertMatchesRegularExpression( '#/backups/\d{8}-\d{6}-manualtest$#', $snap );
		$this->assertFileExists( $snap . '/catalog.json.php' );
		$this->assertFileExists( $snap . '/orders/index.json.php' );
		$this->assertNotEmpty( glob( $snap . '/orders/2*.json.php' ) );
		for ( $i = 0; $i < 20; $i++ ) {
			mkdir( $this->dir . '/backups/2000010' . str_pad( (string) $i, 2, '0', STR_PAD_LEFT ) . '-000000-old' );
		}
		Store::snapshot( 'last' );
		$left = glob( $this->dir . '/backups/*', GLOB_ONLYDIR );
		$this->assertCount( 15, $left );
		$this->assertContains( $snap, $left, 'the newest snapshots are kept' );
	}

	public function test_each_network_site_keeps_separate_data() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		$main = Store::dir();
		$site = self::factory()->blog->create();
		switch_to_blog( $site );
		$this->assertSame( $main . '/site-' . $site, Store::dir() );
		restore_current_blog();
	}

	public function test_upgrading_from_6_2_renames_old_previous_versions_once() {
		// A 6.2 data directory: previous versions as *.json.php.bak, a temp file left by an interrupted save.
		$catalog = file_get_contents( Store::path( 'catalog' ) );
		foreach ( glob( $this->dir . '/{,orders/}*.bak.json.php', GLOB_BRACE ) as $f ) {
			rename( $f, preg_replace( '/\.bak\.json\.php$/', '.json.php.bak', $f ) );
		}
		file_put_contents( Store::path( 'catalog' ) . '.tmp-AbCd1234', 'partial' );
		$doc                   = Store::get( 'settings' );
		$doc['meta']['schema'] = 2;
		file_put_contents( Store::path( 'settings' ), Store::HEADER . wp_json_encode( $doc ) );
		self::reset_plugin_state();

		// Until the upgrade runs, a damaged file is still recovered from the old-style copy.
		file_put_contents( Store::path( 'catalog' ), Store::HEADER . '{broken' );
		$this->assertNotEmpty( Store::get( 'catalog' )['items'] );
		file_put_contents( Store::path( 'catalog' ), $catalog );
		self::reset_plugin_state();

		delete_transient( 'bmlt_lit_upgrade_attempt' );
		\BMLT_Lit\Migrator::maybe_initialize();
		self::reset_plugin_state();
		$this->assertSame( 3, \BMLT_Lit\Settings::meta()['schema'] );
		$this->assertSame( array(), glob( $this->dir . '/{,orders/,audit/,cache/}*.bak', GLOB_BRACE ) );
		$this->assertSame( array(), glob( $this->dir . '/*.tmp-*' ) );
		$this->assertFileExists( Store::bak_path( 'catalog' ) );
		$this->assertFileExists( Store::bak_path( 'inventory' ) );
		$this->assertSame( 0, Store::upgrade_layout(), 'nothing left to do' );
	}

	public function test_health_reports_the_store() {
		$h = Store::health();
		$this->assertTrue( $h['exists'] );
		$this->assertTrue( $h['writable'] );
		$this->assertFalse( $h['configured'] );
		$this->assertGreaterThanOrEqual( 4, $h['files'] );
		$this->assertSame( array(), $h['read_errors'] );
	}
}
