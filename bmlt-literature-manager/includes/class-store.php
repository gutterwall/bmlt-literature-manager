<?php
/**
 * JSON file storage engine.
 *
 * All plugin data lives in JSON files inside a data directory (outside the WordPress database), so that
 * pushing a staging database to production never overwrites production orders, inventory or settings.
 *
 * Location (first match wins):
 *   1. define( 'BMLT_LIT_DATA_DIR', '/absolute/path' ); in wp-config.php  (recommended: outside the web root)
 *   2. the 'bmlt_lit_data_dir' filter
 *   3. wp-content/bmlt-literature-data
 *
 * Files are stored as "<name>.json.php" and start with "<?php exit; ?>" so they reveal nothing even if the
 * directory is reachable over the web (works on Apache, Nginx and IIS). .htaccess / web.config / index.php
 * files are added as a second layer.
 *
 * Writes are serialized with an exclusive lock (flock, or a mkdir lock for filesystems without flock),
 * written to a temporary file and atomically renamed into place. The previous version is kept as ".bak".
 * A file that exists but cannot be parsed is never silently replaced: transactions touching it abort.
 *
 * @package BMLT_Lit
 * @version 6.2.0
 */

namespace BMLT_Lit;

defined( 'ABSPATH' ) || exit;

/**
 * Transactional JSON document store.
 */
class Store {

	const HEADER = "<?php exit; ?>\n";
	const EXT    = '.json.php';

	/**
	 * Per-request read cache: name => array|null.
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Transaction depth.
	 *
	 * @var int
	 */
	private static $depth = 0;

	/**
	 * Staged writes during a transaction: name => array|null (null = delete).
	 *
	 * @var array
	 */
	private static $staged = array();

	/**
	 * Open flock handle.
	 *
	 * @var resource|null
	 */
	private static $lock_handle = null;

	/**
	 * Held mkdir lock directory.
	 *
	 * @var string
	 */
	private static $lock_dir = '';

	/**
	 * Lock method actually used in this request.
	 *
	 * @var string
	 */
	private static $lock_method = '';

	/**
	 * Files that could not be read (outside transactions).
	 *
	 * @var string[]
	 */
	private static $read_errors = array();

	/* ------------------------------------------------------------------
	 * Locations.
	 * ---------------------------------------------------------------- */

	/**
	 * Absolute data directory (no trailing slash).
	 *
	 * @return string
	 */
	public static function dir() {
		$dir = ( defined( 'BMLT_LIT_DATA_DIR' ) && '' !== (string) BMLT_LIT_DATA_DIR ) ? (string) BMLT_LIT_DATA_DIR : WP_CONTENT_DIR . '/bmlt-literature-data';
		if ( is_multisite() && get_current_blog_id() > 1 ) {
			$dir = untrailingslashit( $dir ) . '/site-' . get_current_blog_id(); // Each network site keeps separate data.
		}
		/**
		 * Filter the data directory.
		 *
		 * @param string $dir Absolute path.
		 */
		$dir = (string) apply_filters( 'bmlt_lit_data_dir', $dir );
		return untrailingslashit( wp_normalize_path( $dir ) );
	}

	/**
	 * Whether the directory was set explicitly in wp-config.php.
	 *
	 * @return bool
	 */
	public static function dir_is_configured() {
		return defined( 'BMLT_LIT_DATA_DIR' ) && '' !== (string) BMLT_LIT_DATA_DIR;
	}

	/**
	 * Validate a document name ("catalog", "orders/2026-10", ...).
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	public static function valid_name( $name ) {
		return is_string( $name ) && (bool) preg_match( '#^[a-z0-9_-]+(/[a-z0-9_-]+)?$#', $name );
	}

	/**
	 * Absolute path for a document.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function path( $name ) {
		if ( ! self::valid_name( $name ) ) {
			throw new \InvalidArgumentException( 'Invalid storage document name.' );
		}
		return self::dir() . '/' . $name . self::EXT;
	}

	/**
	 * Create the directory structure and protection files.
	 *
	 * @return true|\WP_Error
	 */
	public static function ensure_dir() {
		$dir = self::dir();
		foreach ( array( '', '/orders', '/audit', '/cache', '/backups' ) as $sub ) {
			if ( ! is_dir( $dir . $sub ) && ! wp_mkdir_p( $dir . $sub ) ) {
				return new \WP_Error(
					'bmlt_lit_storage_dir',
					/* translators: %s: directory path. */
					sprintf( __( 'The literature data directory could not be created: %s. Create it manually and make it writable by the web server, or define BMLT_LIT_DATA_DIR in wp-config.php.', 'bmlt-literature-manager' ), $dir . $sub )
				);
			}
			$guards = array(
				'.htaccess'  => "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n",
				'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
				'index.php'  => "<?php\n// Silence is golden.\n",
			);
			foreach ( $guards as $file => $content ) {
				if ( ! file_exists( $dir . $sub . '/' . $file ) ) {
					@file_put_contents( $dir . $sub . '/' . $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
				}
			}
		}
		if ( ! wp_is_writable( $dir ) ) {
			/* translators: %s: directory path. */
			return new \WP_Error( 'bmlt_lit_storage_write', sprintf( __( 'The literature data directory is not writable: %s', 'bmlt-literature-manager' ), $dir ) );
		}
		return true;
	}

	/* ------------------------------------------------------------------
	 * Reading.
	 * ---------------------------------------------------------------- */

	/**
	 * Read and decode a file.
	 *
	 * @param string $path Path.
	 * @return array|null|false Array, null when missing, false when unreadable/corrupt.
	 */
	private static function read_file( $path ) {
		if ( ! file_exists( $path ) ) {
			return null;
		}
		$raw = @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
		if ( false === $raw ) {
			return false;
		}
		if ( 0 === strpos( $raw, self::HEADER ) ) {
			$raw = substr( $raw, strlen( self::HEADER ) );
		} elseif ( 0 === strpos( $raw, '<?php' ) ) {
			$nl  = strpos( $raw, "\n" );
			$raw = false === $nl ? '' : substr( $raw, $nl + 1 );
		}
		if ( '' === trim( $raw ) ) {
			return false;
		}
		$data = json_decode( $raw, true, 512 );
		return is_array( $data ) ? $data : false;
	}

	/**
	 * Load a document from disk (falls back to .bak when the main file is corrupt).
	 *
	 * @param string $name   Name.
	 * @param bool   $strict Throw when unreadable (used inside transactions).
	 * @return array|null
	 * @throws \RuntimeException When strict and unreadable.
	 */
	private static function load( $name, $strict ) {
		$path = self::path( $name );
		$data = self::read_file( $path );
		if ( false !== $data ) {
			return $data;
		}
		$bak = self::read_file( $path . '.bak' );
		if ( is_array( $bak ) ) {
			Helpers::log( 'Storage file was unreadable; using its .bak copy.', array( 'file' => $name ) );
			self::$read_errors[ $name ] = $name;
			if ( $strict ) {
				// Inside a transaction we continue from the backup and will rewrite the main file on commit.
				return $bak;
			}
			return $bak;
		}
		self::$read_errors[ $name ] = $name;
		Helpers::log( 'Storage file is unreadable and has no usable backup.', array( 'file' => $name ) );
		if ( $strict ) {
			/* translators: %s: file name. */
			throw new \RuntimeException( sprintf( __( 'The data file "%s" is damaged and could not be read. No changes were saved. Restore it from a backup (Literature → Tools / Status).', 'bmlt-literature-manager' ), $name . self::EXT ) );
		}
		return null;
	}

	/**
	 * Get a document. Inside a transaction this reads fresh from disk (or the staged copy).
	 *
	 * @param string $name    Name.
	 * @param array  $default Default when missing.
	 * @return array
	 */
	public static function get( $name, array $default = array() ) {
		if ( self::$depth > 0 ) {
			if ( array_key_exists( $name, self::$staged ) ) {
				return null === self::$staged[ $name ] ? $default : self::$staged[ $name ];
			}
			$data                  = self::load( $name, true );
			self::$cache[ $name ] = $data;
			return null === $data ? $default : $data;
		}
		if ( ! array_key_exists( $name, self::$cache ) ) {
			self::$cache[ $name ] = self::load( $name, false );
		}
		return null === self::$cache[ $name ] ? $default : self::$cache[ $name ];
	}

	/**
	 * Whether a document exists on disk.
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	public static function exists( $name ) {
		return file_exists( self::path( $name ) );
	}

	/**
	 * Names of documents in a sub-directory, sorted ascending (e.g. list( 'orders' )).
	 *
	 * @param string $subdir Sub-directory.
	 * @return string[]
	 */
	public static function names( $subdir ) {
		$out = array();
		$dir = self::dir() . '/' . $subdir;
		if ( ! preg_match( '#^[a-z0-9_-]+$#', $subdir ) || ! is_dir( $dir ) ) {
			return $out;
		}
		foreach ( (array) glob( $dir . '/*' . self::EXT ) as $file ) {
			$base = basename( $file, self::EXT );
			if ( preg_match( '#^[a-z0-9_-]+$#', $base ) ) {
				$out[] = $subdir . '/' . $base;
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * Files that failed to read in this request.
	 *
	 * @return string[]
	 */
	public static function read_errors() {
		return array_values( self::$read_errors );
	}

	/**
	 * Drop the request cache.
	 */
	public static function flush_cache() {
		self::$cache = array();
	}

	/* ------------------------------------------------------------------
	 * Writing.
	 * ---------------------------------------------------------------- */

	/**
	 * Stage a document write. Must be called inside tx(); outside it a one-document transaction is used.
	 *
	 * @param string $name Name.
	 * @param array  $data Data.
	 * @return true|\WP_Error
	 */
	public static function put( $name, array $data ) {
		if ( ! self::valid_name( $name ) ) {
			return new \WP_Error( 'bmlt_lit_storage_name', 'Invalid storage document name.' );
		}
		if ( 0 === self::$depth ) {
			return self::tx(
				static function () use ( $name, $data ) {
					self::$staged[ $name ] = $data;
					return true;
				}
			);
		}
		self::$staged[ $name ] = $data;
		return true;
	}

	/**
	 * Stage a document deletion (inside a transaction).
	 *
	 * @param string $name Name.
	 */
	public static function delete( $name ) {
		if ( self::valid_name( $name ) && self::$depth > 0 ) {
			self::$staged[ $name ] = null;
		}
	}

	/**
	 * Run a callback inside an exclusive, all-or-nothing transaction.
	 *
	 * Nothing is written unless the callback returns a non-WP_Error value without throwing.
	 * Nested calls join the outer transaction.
	 *
	 * @param callable $fn Callback.
	 * @return mixed|\WP_Error Callback result.
	 */
	public static function tx( callable $fn ) {
		if ( self::$depth > 0 ) {
			return $fn();
		}
		$lock = self::lock();
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		self::$depth  = 1;
		self::$staged = array();
		try {
			$result = $fn();
		} catch ( \Throwable $e ) {
			Helpers::log( 'Storage transaction aborted.', array( 'error' => $e->getMessage() ) );
			$result = new \WP_Error( 'bmlt_lit_storage', $e instanceof \RuntimeException ? $e->getMessage() : __( 'The literature data could not be saved because of a system problem. Please try again.', 'bmlt-literature-manager' ) );
		}
		if ( is_wp_error( $result ) ) {
			self::$staged = array();
			self::$depth  = 0;
			self::unlock();
			self::$cache = array();
			return $result;
		}
		$written      = self::flush();
		self::$staged = array();
		self::$depth  = 0;
		self::unlock();
		if ( is_wp_error( $written ) ) {
			self::$cache = array();
			return $written;
		}
		return $result;
	}

	/**
	 * Write all staged documents atomically (tmp file + rename), keeping a .bak of each.
	 *
	 * @return true|\WP_Error
	 */
	private static function flush() {
		if ( empty( self::$staged ) ) {
			return true;
		}
		$ready = self::ensure_dir();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		if ( defined( 'JSON_INVALID_UTF8_SUBSTITUTE' ) ) {
			$flags |= JSON_INVALID_UTF8_SUBSTITUTE;
		}

		// Encode and write every temp file first, so a failure leaves all real files untouched.
		$temps = array();
		foreach ( self::$staged as $name => $data ) {
			$path = self::path( $name );
			if ( null === $data ) {
				$temps[ $name ] = null;
				continue;
			}
			$parent = dirname( $path );
			if ( ! is_dir( $parent ) && ! wp_mkdir_p( $parent ) ) {
				self::cleanup( $temps );
				return new \WP_Error( 'bmlt_lit_storage_write', __( 'The literature data could not be saved (directory not writable).', 'bmlt-literature-manager' ) );
			}
			$json = wp_json_encode( $data, $flags );
			if ( false === $json ) {
				self::cleanup( $temps );
				return new \WP_Error( 'bmlt_lit_storage_encode', __( 'The literature data could not be encoded for saving.', 'bmlt-literature-manager' ) );
			}
			$tmp   = $path . '.tmp-' . wp_generate_password( 8, false, false );
			$body  = self::HEADER . $json . "\n";
			$bytes = @file_put_contents( $tmp, $body, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
			if ( strlen( $body ) !== $bytes ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
				self::cleanup( $temps );
				return new \WP_Error( 'bmlt_lit_storage_write', __( 'The literature data could not be saved (disk full or not writable).', 'bmlt-literature-manager' ) );
			}
			$temps[ $name ] = $tmp;
		}

		// Swap into place.
		foreach ( $temps as $name => $tmp ) {
			$path = self::path( $name );
			if ( file_exists( $path ) ) {
				@copy( $path, $path . '.bak' ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
			}
			if ( null === $tmp ) {
				@unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
				self::$cache[ $name ] = null;
				continue;
			}
			if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
				// Windows cannot rename over an existing file.
				@unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
				if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
					Helpers::log( 'Storage rename failed.', array( 'file' => $name ) );
					return new \WP_Error( 'bmlt_lit_storage_write', __( 'The literature data could not be saved (rename failed).', 'bmlt-literature-manager' ) );
				}
			}
			self::$cache[ $name ] = self::$staged[ $name ];
			unset( self::$read_errors[ $name ] );
		}
		return true;
	}

	/**
	 * Remove temp files after a failed flush.
	 *
	 * @param array $temps name => tmp path.
	 */
	private static function cleanup( array $temps ) {
		foreach ( $temps as $tmp ) {
			if ( $tmp && file_exists( $tmp ) ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
			}
		}
	}

	/* ------------------------------------------------------------------
	 * Locking.
	 * ---------------------------------------------------------------- */

	/**
	 * Acquire the store-wide exclusive lock.
	 *
	 * @return true|\WP_Error
	 */
	private static function lock() {
		$ready = self::ensure_dir();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$timeout = 15;
		$start   = microtime( true );
		$method  = defined( 'BMLT_LIT_LOCK_METHOD' ) ? (string) BMLT_LIT_LOCK_METHOD : 'flock';

		if ( 'mkdir' !== $method ) {
			$h = @fopen( self::dir() . '/.lock', 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
			if ( $h ) {
				do {
					$would_block = 0;
					if ( flock( $h, LOCK_EX | LOCK_NB, $would_block ) ) {
						self::$lock_handle = $h;
						self::$lock_method = 'flock';
						return true;
					}
					if ( ! $would_block ) {
						break; // flock unsupported on this filesystem: fall back to mkdir.
					}
					usleep( 50000 );
				} while ( microtime( true ) - $start < $timeout );
				fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( microtime( true ) - $start >= $timeout ) {
					return self::busy();
				}
			}
		}

		$dir = self::dir() . '/.lockdir';
		do {
			if ( @mkdir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
				self::$lock_dir    = $dir;
				self::$lock_method = 'mkdir';
				return true;
			}
			clearstatcache( true, $dir );
			if ( is_dir( $dir ) && filemtime( $dir ) < time() - 60 ) {
				@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
				continue;
			}
			usleep( 50000 );
		} while ( microtime( true ) - $start < $timeout );
		return self::busy();
	}

	/**
	 * Busy error.
	 *
	 * @return \WP_Error
	 */
	private static function busy() {
		return new \WP_Error( 'bmlt_lit_busy', __( 'The literature data is busy. Please try again in a moment.', 'bmlt-literature-manager' ) );
	}

	/**
	 * Release the lock.
	 */
	private static function unlock() {
		if ( self::$lock_handle ) {
			flock( self::$lock_handle, LOCK_UN );
			fclose( self::$lock_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			self::$lock_handle = null;
		}
		if ( self::$lock_dir ) {
			@rmdir( self::$lock_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			self::$lock_dir = '';
		}
	}

	/* ------------------------------------------------------------------
	 * Maintenance.
	 * ---------------------------------------------------------------- */

	/**
	 * All data document names (excluding cache).
	 *
	 * @return string[]
	 */
	public static function data_names() {
		$names = array();
		foreach ( array( 'settings', 'catalog', 'inventory', 'permissions', 'orders/index' ) as $n ) {
			if ( self::exists( $n ) ) {
				$names[] = $n;
			}
		}
		foreach ( array_merge( self::names( 'orders' ), self::names( 'audit' ) ) as $n ) {
			if ( ! in_array( $n, $names, true ) ) {
				$names[] = $n;
			}
		}
		return $names;
	}

	/**
	 * Copy every data file into backups/<timestamp>/ (keeps the newest 15 snapshots).
	 *
	 * @param string $label Short label.
	 * @return string|\WP_Error Snapshot directory.
	 */
	public static function snapshot( $label = 'manual' ) {
		$ready = self::ensure_dir();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$label = preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $label ) );
		$dest  = self::dir() . '/backups/' . gmdate( 'Ymd-His' ) . '-' . ( '' !== $label ? $label : 'manual' );
		if ( ! wp_mkdir_p( $dest . '/orders' ) || ! wp_mkdir_p( $dest . '/audit' ) ) {
			return new \WP_Error( 'bmlt_lit_snapshot', __( 'A backup snapshot could not be created.', 'bmlt-literature-manager' ) );
		}
		foreach ( self::data_names() as $name ) {
			@copy( self::path( $name ), $dest . '/' . $name . self::EXT ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
		}
		$snaps = glob( self::dir() . '/backups/*', GLOB_ONLYDIR );
		if ( is_array( $snaps ) && count( $snaps ) > 15 ) {
			sort( $snaps );
			foreach ( array_slice( $snaps, 0, count( $snaps ) - 15 ) as $old ) {
				self::rrmdir( $old );
			}
		}
		return $dest;
	}

	/**
	 * Recursively delete a directory inside the data directory.
	 *
	 * @param string $dir Directory.
	 */
	public static function rrmdir( $dir ) {
		$dir  = wp_normalize_path( $dir );
		$base = self::dir();
		if ( '' === $base || 0 !== strpos( $dir, $base ) || ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		foreach ( (array) $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::rrmdir( $path );
			} else {
				@unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
	}

	/**
	 * Health information for the status page.
	 *
	 * @return array
	 */
	public static function health() {
		$dir      = self::dir();
		$abspath  = untrailingslashit( wp_normalize_path( ABSPATH ) );
		$content  = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		$size     = 0;
		$count    = 0;
		foreach ( self::data_names() as $name ) {
			$size += (int) @filesize( self::path( $name ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			++$count;
		}
		return array(
			'dir'            => $dir,
			'configured'     => self::dir_is_configured(),
			'exists'         => is_dir( $dir ),
			'writable'       => is_dir( $dir ) && wp_is_writable( $dir ),
			'inside_wp'      => 0 === strpos( $dir . '/', $abspath . '/' ),
			'inside_content' => 0 === strpos( $dir . '/', $content . '/' ),
			'files'          => $count,
			'bytes'          => $size,
			'lock_method'    => defined( 'BMLT_LIT_LOCK_METHOD' ) ? (string) BMLT_LIT_LOCK_METHOD : 'flock (auto fallback: mkdir)',
			'read_errors'    => self::read_errors(),
			'snapshots'      => is_dir( $dir . '/backups' ) ? count( (array) glob( $dir . '/backups/*', GLOB_ONLYDIR ) ) : 0,
		);
	}
}
