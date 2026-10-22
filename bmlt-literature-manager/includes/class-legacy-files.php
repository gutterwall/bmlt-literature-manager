<?php
/**
 * Read-only access to the JSON data folder of versions 2 to 6, for the one-time move into the database.
 *
 * Location (as those versions used it; first match wins):
 *   1. define( 'BMLT_LIT_DATA_DIR', '/absolute/path' ); in wp-config.php
 *   2. the 'bmlt_lit_data_dir' filter
 *   3. wp-content/bmlt-literature-data
 * Each network site other than the main one used "<dir>/site-<id>".
 *
 * Documents are "<name>.json.php" files starting with "<?php exit; ?>". A damaged file is read from its previous
 * version ("<name>.bak.json.php", or "<name>.json.php.bak" before 6.3). Nothing here ever writes to the folder;
 * remove() deletes it, on request, after the data was moved.
 *
 * @package BMLT_Lit
 * @version 7.0.0
 */

namespace BMLT_Lit;

defined( 'ABSPATH' ) || exit;

/**
 * The version 6 data folder.
 */
class Legacy_Files {

	const EXT = '.json.php';

	/**
	 * Absolute folder of the current site (no trailing slash).
	 *
	 * @return string
	 */
	public static function dir() {
		$dir = ( defined( 'BMLT_LIT_DATA_DIR' ) && '' !== (string) BMLT_LIT_DATA_DIR ) ? (string) BMLT_LIT_DATA_DIR : WP_CONTENT_DIR . '/bmlt-literature-data';
		if ( is_multisite() && get_current_blog_id() > 1 ) {
			$dir = untrailingslashit( $dir ) . '/site-' . get_current_blog_id();
		}
		/**
		 * Filter the version 6 data folder.
		 *
		 * @param string $dir Absolute path.
		 */
		$dir = (string) apply_filters( 'bmlt_lit_data_dir', $dir );
		return untrailingslashit( wp_normalize_path( $dir ) );
	}

	/**
	 * Whether the folder holds version 6 data (settings or a catalog).
	 *
	 * @return bool
	 */
	public static function present() {
		$dir = self::dir();
		return '' !== $dir && ( is_file( $dir . '/settings' . self::EXT ) || is_file( $dir . '/catalog' . self::EXT ) );
	}

	/**
	 * Decode one file.
	 *
	 * @param string $path Path.
	 * @return array|null|false Array, null when missing, false when unreadable or damaged.
	 */
	private static function read_file( $path ) {
		if ( ! file_exists( $path ) ) {
			return null;
		}
		$raw = @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
		if ( false === $raw ) {
			return false;
		}
		if ( 0 === strpos( $raw, '<?php' ) ) {
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
	 * One document, from its previous version when the file is damaged.
	 *
	 * @param string $name Name ("catalog", "orders/2026-10", ...).
	 * @return array|null Null when the document does not exist.
	 * @throws \RuntimeException When the file and its previous versions are all unreadable.
	 */
	public static function read( $name ) {
		if ( ! Import_Export::valid_name( $name ) ) {
			throw new \InvalidArgumentException( 'Invalid document name.' );
		}
		$base = self::dir() . '/' . $name;
		$data = self::read_file( $base . self::EXT );
		if ( false !== $data ) {
			return $data;
		}
		foreach ( array( $base . '.bak' . self::EXT, $base . self::EXT . '.bak' ) as $bak ) {
			$copy = self::read_file( $bak );
			if ( is_array( $copy ) ) {
				Helpers::log( 'Version 6 data file was unreadable; its previous version was used.', array( 'file' => $name ) );
				return $copy;
			}
		}
		/* translators: %s: file name. */
		throw new \RuntimeException( sprintf( __( 'The data file "%s" is damaged and has no readable previous version, so the literature data was not moved to the database. Nothing was changed. Restore the file (or its .bak copy) and reload this page.', 'bmlt-literature-manager' ), $name . self::EXT ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- becomes a WP_Error message, escaped where shown.
	}

	/**
	 * Names of the documents in a sub-folder ("orders", "audit"), sorted.
	 *
	 * @param string $subdir Sub-folder.
	 * @return string[]
	 */
	public static function names( $subdir ) {
		$out = array();
		foreach ( (array) glob( self::dir() . '/' . $subdir . '/*' . self::EXT ) as $file ) {
			$base = basename( (string) $file, self::EXT );
			if ( preg_match( '#^\d{4}-\d{2}$#', $base ) ) {
				$out[] = $subdir . '/' . $base;
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * Every data document, in the backup format.
	 *
	 * @return array name => document
	 * @throws \RuntimeException When a file cannot be read.
	 */
	public static function documents() {
		$docs = array();
		foreach ( array_merge( array( 'settings', 'catalog', 'inventory', 'permissions' ), self::names( 'orders' ), self::names( 'audit' ) ) as $name ) {
			$doc = self::read( $name );
			if ( is_array( $doc ) ) {
				$docs[ $name ] = $doc;
			}
		}
		return $docs;
	}

	/**
	 * Delete the folder (only when it really is a version 6 data folder). On a network, the main site's folder also
	 * holds the other sites' "site-<id>" folders: those are kept while they still hold data.
	 *
	 * @return bool Whether the data is gone.
	 */
	public static function remove() {
		if ( ! self::present() ) {
			return true;
		}
		self::rrmdir( self::dir(), is_multisite() && get_current_blog_id() <= 1 ? '/^site-\d+$/' : '' );
		return ! self::present();
	}

	/**
	 * Recursively delete a folder below the data folder.
	 *
	 * @param string $dir  Folder.
	 * @param string $keep Regular expression: entries directly inside $dir that match are kept (and so is $dir).
	 */
	private static function rrmdir( $dir, $keep = '' ) {
		$dir  = wp_normalize_path( $dir );
		$base = self::dir();
		if ( '' === $base || ( $dir !== $base && 0 !== strpos( $dir, $base . '/' ) ) || ! is_dir( $dir ) ) {
			return;
		}
		$kept = false;
		foreach ( (array) scandir( $dir ) as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			if ( '' !== $keep && preg_match( $keep, (string) $item ) ) {
				$kept = true;
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::rrmdir( $path );
			} else {
				@unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
			}
		}
		if ( ! $kept ) {
			@rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
		}
	}

	/**
	 * Information for the status page.
	 *
	 * @return array { present: bool, dir: string, files: int, bytes: int }
	 */
	public static function info() {
		$dir   = self::dir();
		$files = 0;
		$bytes = 0;
		if ( self::present() ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				if ( is_multisite() && get_current_blog_id() <= 1 && preg_match( '#^' . preg_quote( $dir, '#' ) . '/site-\d+/#', wp_normalize_path( $f->getPathname() ) ) ) {
					continue;
				}
				++$files;
				$bytes += (int) $f->getSize();
			}
		}
		return array(
			'present' => self::present(),
			'dir'     => $dir,
			'files'   => $files,
			'bytes'   => $bytes,
		);
	}
}
