<?php
/**
 * Database tables and transactions.
 *
 * All literature data lives in the plugin's own tables ($wpdb->prefix . 'bmlt_literature_*', one set per site on a
 * network), created and upgraded with dbDelta(). Settings and the BMLT cache are WordPress options.
 *
 * Every change runs inside Db::tx():
 *   - a database transaction that first takes the store-wide lock row, so changes never interleave;
 *   - a change is saved completely or not at all: when the callback returns a WP_Error or throws, or any query
 *     fails, everything it wrote is rolled back;
 *   - a nested Db::tx() is a savepoint: a failed inner step is undone and the outer transaction continues;
 *   - a query that fails always throws (Db::q(), Db::insert() ...), so a write error can never go unnoticed;
 *   - options written inside a transaction (Db::set_option()) reach WordPress's option cache only after COMMIT,
 *     so a rollback never leaves a changed setting behind in the cache;
 *   - a transaction still open when PHP stops (fatal error, timeout) is rolled back.
 *
 * @package BMLT_Lit
 * @version 7.0.0
 */

namespace BMLT_Lit;

defined( 'ABSPATH' ) || exit;

/**
 * Schema, transactions and checked queries.
 */
class Db {

	/**
	 * Table schema version (stored in the DB_VERSION option of each site).
	 */
	const VERSION = 1;

	/**
	 * Option holding the installed table schema version.
	 */
	const DB_VERSION_OPTION = 'bmlt_literature_db_version';

	/**
	 * Table short names.
	 */
	const TABLES = array( 'items', 'inventory', 'orders', 'order_items', 'order_notes', 'audit', 'permissions', 'counters' );

	/**
	 * Seconds a change waits for another one to finish before giving up.
	 */
	const LOCK_WAIT = 15;

	/**
	 * Transaction depth (0 = none).
	 *
	 * @var int
	 */
	private static $depth = 0;

	/**
	 * Options written in the current transaction (name => true).
	 *
	 * @var array
	 */
	private static $options = array();

	/**
	 * Database errors met outside transactions in this request (shown to administrators).
	 *
	 * @var string[]
	 */
	private static $errors = array();

	/**
	 * Whether the shutdown rollback is registered.
	 *
	 * @var bool
	 */
	private static $shutdown = false;

	/* ------------------------------------------------------------------
	 * Schema.
	 * ---------------------------------------------------------------- */

	/**
	 * Full table name.
	 *
	 * @param string $short Short name (see TABLES).
	 * @return string
	 */
	public static function table( $short ) {
		global $wpdb;
		return $wpdb->prefix . 'bmlt_literature_' . $short;
	}

	/**
	 * CREATE TABLE statements in dbDelta() format.
	 *
	 * @return string[]
	 */
	public static function schema() {
		global $wpdb;
		$c = $wpdb->get_charset_collate();
		$t = array();
		foreach ( self::TABLES as $short ) {
			$t[ $short ] = self::table( $short );
		}
		return array(
			"CREATE TABLE {$t['items']} (
  id bigint(20) unsigned NOT NULL,
  area_id bigint(20) unsigned NOT NULL DEFAULT 0,
  sku varchar(64) NOT NULL DEFAULT '',
  name varchar(255) NOT NULL DEFAULT '',
  short_description text NOT NULL,
  long_description longtext NOT NULL,
  category varchar(100) NOT NULL DEFAULT '',
  price decimal(12,2) NOT NULL DEFAULT 0.00,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  sort_order int(11) NOT NULL DEFAULT 0,
  image_url text NOT NULL,
  reference_url text NOT NULL,
  naws_id varchar(64) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY area_sku (area_id,sku)
) ENGINE=InnoDB {$c};",
			"CREATE TABLE {$t['inventory']} (
  area_id bigint(20) unsigned NOT NULL,
  item_id bigint(20) unsigned NOT NULL,
  on_hand int(10) unsigned NOT NULL DEFAULT 0,
  reserved int(10) unsigned NOT NULL DEFAULT 0,
  low_stock int(10) unsigned NOT NULL DEFAULT 0,
  reorder_qty int(10) unsigned NOT NULL DEFAULT 0,
  notes text NOT NULL,
  special tinyint(1) NOT NULL DEFAULT 0,
  updated_at datetime NULL DEFAULT NULL,
  updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (area_id,item_id),
  KEY item_id (item_id)
) ENGINE=InnoDB {$c};",
			"CREATE TABLE {$t['orders']} (
  id bigint(20) unsigned NOT NULL,
  order_number varchar(40) NOT NULL DEFAULT '',
  access_key varchar(64) NOT NULL DEFAULT '',
  submission_hash varchar(64) NULL DEFAULT NULL,
  area_id bigint(20) unsigned NOT NULL DEFAULT 0,
  area_name varchar(255) NOT NULL DEFAULT '',
  meeting_id bigint(20) unsigned NOT NULL DEFAULT 0,
  meeting_name varchar(255) NOT NULL DEFAULT '',
  meeting_day tinyint(3) unsigned NOT NULL DEFAULT 0,
  meeting_time varchar(10) NOT NULL DEFAULT '',
  meeting_location varchar(255) NOT NULL DEFAULT '',
  contact_name varchar(200) NOT NULL DEFAULT '',
  contact_phone varchar(50) NOT NULL DEFAULT '',
  contact_email varchar(200) NOT NULL DEFAULT '',
  notes text NOT NULL,
  subtotal decimal(12,2) NOT NULL DEFAULT 0.00,
  total decimal(12,2) NOT NULL DEFAULT 0.00,
  item_count int(10) unsigned NOT NULL DEFAULT 0,
  status varchar(30) NOT NULL DEFAULT 'new',
  has_special_order tinyint(1) NOT NULL DEFAULT 0,
  inventory_mode varchar(20) NOT NULL DEFAULT 'none',
  inventory_state varchar(20) NOT NULL DEFAULT 'none',
  ip_hash varchar(64) NOT NULL DEFAULT '',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
  fulfilled_at datetime NULL DEFAULT NULL,
  fulfilled_by bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY submission_hash (submission_hash),
  KEY order_number (order_number),
  KEY area_status (area_id,status),
  KEY created_at (created_at),
  KEY contact_email (contact_email(100))
) ENGINE=InnoDB {$c};",
			"CREATE TABLE {$t['order_items']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  line_no smallint(5) unsigned NOT NULL,
  item_id bigint(20) unsigned NOT NULL DEFAULT 0,
  sku varchar(64) NOT NULL DEFAULT '',
  name varchar(255) NOT NULL DEFAULT '',
  description text NOT NULL,
  quantity int(10) unsigned NOT NULL DEFAULT 0,
  quantity_in_stock int(10) unsigned NOT NULL DEFAULT 0,
  quantity_special int(10) unsigned NOT NULL DEFAULT 0,
  quantity_reserved int(10) unsigned NOT NULL DEFAULT 0,
  unit_price decimal(12,2) NOT NULL DEFAULT 0.00,
  line_total decimal(12,2) NOT NULL DEFAULT 0.00,
  stock_status varchar(20) NOT NULL DEFAULT 'in_stock',
  is_special_order tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY order_line (order_id,line_no),
  KEY item_id (item_id)
) ENGINE=InnoDB {$c};",
			"CREATE TABLE {$t['order_notes']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  note_type varchar(20) NOT NULL DEFAULT 'note',
  old_status varchar(30) NOT NULL DEFAULT '',
  new_status varchar(30) NOT NULL DEFAULT '',
  note text NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY order_id (order_id)
) ENGINE=InnoDB {$c};",
			"CREATE TABLE {$t['audit']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  area_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(64) NOT NULL DEFAULT '',
  object_type varchar(32) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  old_value longtext NULL,
  new_value longtext NULL,
  note text NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY object (object_type,object_id),
  KEY area_id (area_id)
) ENGINE=InnoDB {$c};",
			"CREATE TABLE {$t['permissions']} (
  user_id bigint(20) unsigned NOT NULL,
  caps varchar(255) NOT NULL DEFAULT '',
  areas text NOT NULL,
  PRIMARY KEY  (user_id)
) ENGINE=InnoDB {$c};",
			"CREATE TABLE {$t['counters']} (
  name varchar(32) NOT NULL,
  value bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (name)
) ENGINE=InnoDB {$c};",
		);
	}

	/**
	 * Create or upgrade the tables and their fixed rows. Safe to run any number of times; never removes data.
	 *
	 * @return true|\WP_Error
	 */
	public static function install() {
		global $wpdb;
		if ( self::$depth > 0 ) {
			// CREATE/ALTER TABLE would silently commit the open transaction.
			return new \WP_Error( 'bmlt_lit_db_install', 'Tables cannot be created inside a transaction.' );
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$suppress = $wpdb->suppress_errors( true );
		dbDelta( self::schema() );
		$wpdb->suppress_errors( $suppress );
		$missing = self::missing_tables();
		if ( $missing ) {
			/* translators: %s: table names. */
			return new \WP_Error( 'bmlt_lit_db_install', sprintf( __( 'The literature database tables could not be created: %s. The database user needs the CREATE and ALTER privileges.', 'bmlt-literature-manager' ), implode( ', ', $missing ) ) );
		}
		$c = self::table( 'counters' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		if ( false === $wpdb->query( "INSERT IGNORE INTO {$c} (name, value) VALUES ('lock', 0), ('order', 1), ('item', 1)" ) ) {
			return new \WP_Error( 'bmlt_lit_db_install', __( 'The literature database tables could not be prepared.', 'bmlt-literature-manager' ) );
		}
		update_option( self::DB_VERSION_OPTION, self::VERSION, true );
		return true;
	}

	/**
	 * Install or upgrade the tables when this site's schema version differs (runs on every request; cheap).
	 *
	 * @return true|\WP_Error
	 */
	public static function maybe_install() {
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) === self::VERSION ) {
			return true;
		}
		return self::install();
	}

	/**
	 * Tables that do not exist.
	 *
	 * @return string[] Full names.
	 */
	public static function missing_tables() {
		global $wpdb;
		$missing = array();
		$suppress = $wpdb->suppress_errors( true );
		foreach ( self::TABLES as $short ) {
			$name = self::table( $short );
			// DESCRIBE also sees temporary tables (the WordPress test suite creates tables as temporary ones).
			if ( ! $wpdb->get_results( "DESCRIBE {$name}" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
				$missing[] = $name;
			}
		}
		$wpdb->suppress_errors( $suppress );
		return $missing;
	}

	/**
	 * Add a network site's literature tables to the tables WordPress drops when the site is deleted.
	 *
	 * @param string[] $tables  Tables to drop.
	 * @param int      $blog_id Site being deleted.
	 * @return string[]
	 */
	public static function site_tables( $tables, $blog_id = 0 ) {
		global $wpdb;
		$prefix = $wpdb->get_blog_prefix( (int) $blog_id );
		foreach ( self::TABLES as $short ) {
			$tables[ 'bmlt_literature_' . $short ] = $prefix . 'bmlt_literature_' . $short;
		}
		return $tables;
	}

	/**
	 * Drop every table of the current site (uninstall only).
	 */
	public static function drop_tables() {
		global $wpdb;
		foreach ( self::TABLES as $short ) {
			$name = self::table( $short );
			$wpdb->query( "DROP TABLE IF EXISTS {$name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		}
		delete_option( self::DB_VERSION_OPTION );
	}

	/* ------------------------------------------------------------------
	 * Transactions.
	 * ---------------------------------------------------------------- */

	/**
	 * Run a callback as one all-or-nothing change.
	 *
	 * Nothing is kept unless the callback returns a value other than a WP_Error without throwing, and every query
	 * succeeded. Nested calls are savepoints: a failed inner call is undone and returns its WP_Error to the outer
	 * callback, which may continue. An exception (a failed query included) aborts the whole transaction.
	 *
	 * @param callable $fn Callback.
	 * @return mixed|\WP_Error Callback result.
	 */
	public static function tx( callable $fn ) {
		$top       = 0 === self::$depth;
		$savepoint = 'bmlt_lit_' . self::$depth;
		$outer     = $top && self::inside_foreign_transaction();
		$begun     = false;
		try {
			if ( $top ) {
				self::register_shutdown();
				self::$options = array();
			}
			self::q( $top && ! $outer ? 'START TRANSACTION' : "SAVEPOINT {$savepoint}" );
			$begun = true;
			++self::$depth;
			if ( $top ) {
				self::lock();
			}
			$result = $fn();
		} catch ( \Throwable $e ) {
			$result = $e;
		}

		if ( $result instanceof \Throwable || is_wp_error( $result ) ) {
			if ( $begun ) {
				self::undo( $top, $outer, $savepoint );
			}
			if ( $result instanceof \Throwable ) {
				if ( ! $top ) {
					throw $result; // The outer transaction must not continue after an unexpected failure.
				}
				return self::to_error( $result );
			}
			return $result;
		}

		try {
			self::q( $top && ! $outer ? 'COMMIT' : "RELEASE SAVEPOINT {$savepoint}" );
		} catch ( \Throwable $e ) {
			self::undo( $top, $outer, $savepoint );
			if ( ! $top ) {
				throw $e;
			}
			return self::to_error( $e );
		}
		--self::$depth;
		if ( $top ) {
			self::committed();
		}
		return $result;
	}

	/**
	 * Roll back the current level and leave it.
	 *
	 * @param bool   $top       Outermost level.
	 * @param bool   $outer     Running inside someone else's transaction (savepoint at the top).
	 * @param string $savepoint Savepoint name of this level.
	 */
	private static function undo( $top, $outer, $savepoint ) {
		global $wpdb;
		// After a deadlock the server has already rolled everything back; the savepoint is gone and this fails harmlessly.
		if ( $top && ! $outer ) {
			$wpdb->query( 'ROLLBACK' );
		} else {
			$wpdb->query( "ROLLBACK TO SAVEPOINT {$savepoint}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed savepoint name.
		}
		self::$depth = max( 0, self::$depth - 1 );
		if ( $top ) {
			self::$options = array();
			self::flush_caches();
		}
	}

	/**
	 * After COMMIT: let WordPress and the plugin re-read what changed.
	 */
	private static function committed() {
		foreach ( array_keys( self::$options ) as $name ) {
			wp_cache_delete( $name, 'options' );
		}
		if ( self::$options ) {
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		self::$options = array();
		self::flush_caches();
	}

	/**
	 * Drop the plugin's per-request caches.
	 */
	public static function flush_caches() {
		Settings::flush();
		Catalog::flush();
		Inventory::flush();
		Permissions::flush();
	}

	/**
	 * Whether a transaction is open.
	 *
	 * @return bool
	 */
	public static function in_tx() {
		return self::$depth > 0;
	}

	/**
	 * Whether the plugin runs inside a transaction it did not open (for example a test suite's), so the outermost
	 * level must be a savepoint: START TRANSACTION would commit that transaction.
	 *
	 * @return bool
	 */
	private static function inside_foreign_transaction() {
		/**
		 * Filters whether the outermost literature transaction is a savepoint.
		 *
		 * @param bool $inside Default false.
		 */
		return (bool) apply_filters( 'bmlt_lit_db_inside_transaction', false );
	}

	/**
	 * Take the store-wide lock row (held until COMMIT or ROLLBACK), waiting at most LOCK_WAIT seconds.
	 *
	 * @throws Db_Error When the lock cannot be taken.
	 */
	private static function lock() {
		global $wpdb;
		$c        = self::table( 'counters' );
		$previous = $wpdb->get_var( 'SELECT @@SESSION.innodb_lock_wait_timeout' );
		/**
		 * Filters how many seconds a change waits for another change to finish.
		 *
		 * @param int $seconds Default 15.
		 */
		$wait = max( 1, (int) apply_filters( 'bmlt_lit_lock_wait', self::LOCK_WAIT ) );
		$wpdb->query( $wpdb->prepare( 'SET SESSION innodb_lock_wait_timeout = %d', $wait ) );
		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
			$got = self::var( "SELECT name FROM {$c} WHERE name = 'lock' FOR UPDATE" );
			if ( null === $got ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
				self::q( "INSERT IGNORE INTO {$c} (name, value) VALUES ('lock', 0)" );
				self::var( "SELECT name FROM {$c} WHERE name = 'lock' FOR UPDATE" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
			}
		} finally {
			if ( is_numeric( $previous ) ) {
				$wpdb->query( $wpdb->prepare( 'SET SESSION innodb_lock_wait_timeout = %d', (int) $previous ) );
			}
		}
	}

	/**
	 * Roll back an open transaction when PHP stops before it ended.
	 */
	private static function register_shutdown() {
		if ( self::$shutdown ) {
			return;
		}
		self::$shutdown = true;
		register_shutdown_function(
			static function () {
				global $wpdb;
				if ( self::$depth > 0 ) {
					if ( self::inside_foreign_transaction() ) {
						$wpdb->query( 'ROLLBACK TO SAVEPOINT bmlt_lit_0' );
					} else {
						$wpdb->query( 'ROLLBACK' );
					}
					self::$depth = 0;
					Helpers::log( 'A storage transaction was still open when PHP stopped; it was rolled back.' );
				}
			}
		);
	}

	/**
	 * A failure as the WP_Error shown to the user.
	 *
	 * @param \Throwable $e Failure.
	 * @return \WP_Error
	 */
	private static function to_error( \Throwable $e ) {
		Helpers::log( 'Storage transaction rolled back.', array( 'error' => $e->getMessage() ) );
		if ( $e instanceof Db_Error && $e->busy ) {
			return new \WP_Error( 'bmlt_lit_busy', __( 'The literature data is busy. Please try again in a moment.', 'bmlt-literature-manager' ) );
		}
		if ( $e instanceof Db_Error ) {
			return new \WP_Error( 'bmlt_lit_storage', __( 'The literature data could not be saved because of a database error. Nothing was changed. Please try again; if it keeps happening, run Tools / Status → Verify storage.', 'bmlt-literature-manager' ) );
		}
		return new \WP_Error( 'bmlt_lit_storage', $e instanceof \RuntimeException ? $e->getMessage() : __( 'The literature data could not be saved because of a system problem. Please try again.', 'bmlt-literature-manager' ) );
	}

	/* ------------------------------------------------------------------
	 * Checked queries. Inside a transaction every failure throws; outside, reads log the error and return nothing.
	 * ---------------------------------------------------------------- */

	/**
	 * Fail with the database's last error.
	 *
	 * @param string $what What was attempted.
	 * @throws Db_Error Always.
	 */
	private static function fail( $what ) {
		global $wpdb;
		$msg = '' !== (string) $wpdb->last_error ? (string) $wpdb->last_error : 'unknown error';
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- logged only; users see Db::to_error()'s message.
		throw new Db_Error( $what . ': ' . $msg, (bool) preg_match( '/deadlock|lock wait timeout/i', $msg ) );
	}

	/**
	 * The database's last error ('' when the last query succeeded); queries set it, so it is read afresh each time.
	 *
	 * @return string
	 */
	private static function last_error() {
		global $wpdb;
		return (string) $wpdb->last_error;
	}

	/**
	 * Report a failed read: throws inside a transaction, logs outside.
	 *
	 * @param string $what What was attempted.
	 * @throws Db_Error Inside a transaction.
	 */
	private static function read_failed( $what ) {
		global $wpdb;
		if ( self::$depth > 0 ) {
			self::fail( $what );
		}
		$msg                  = (string) $wpdb->last_error;
		self::$errors[ $msg ] = $msg;
		Helpers::log( 'Database read failed.', array( 'error' => $msg ) );
	}

	/**
	 * Any statement; throws on failure.
	 *
	 * @param string $sql Prepared SQL.
	 * @return int Rows affected.
	 * @throws Db_Error On failure.
	 */
	public static function q( $sql ) {
		global $wpdb;
		$r = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- callers prepare.
		if ( false === $r ) {
			self::fail( 'Query failed' );
		}
		return (int) $r;
	}

	/**
	 * Rows as arrays.
	 *
	 * @param string $sql Prepared SQL.
	 * @return array[]
	 */
	public static function rows( $sql ) {
		global $wpdb;
		$wpdb->last_error = '';
		$r                = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- callers prepare.
		if ( '' !== self::last_error() ) {
			self::read_failed( 'Read failed' );
			return array();
		}
		return is_array( $r ) ? $r : array();
	}

	/**
	 * One row as an array, or null.
	 *
	 * @param string $sql Prepared SQL.
	 * @return array|null
	 */
	public static function row( $sql ) {
		$rows = self::rows( $sql );
		return $rows ? $rows[0] : null;
	}

	/**
	 * One value, or null.
	 *
	 * @param string $sql Prepared SQL.
	 * @return string|null
	 */
	public static function var( $sql ) {
		global $wpdb;
		$wpdb->last_error = '';
		$v                = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- callers prepare.
		if ( '' !== self::last_error() ) {
			self::read_failed( 'Read failed' );
			return null;
		}
		return null === $v ? null : (string) $v;
	}

	/**
	 * First column of every row.
	 *
	 * @param string $sql Prepared SQL.
	 * @return string[]
	 */
	public static function col( $sql ) {
		global $wpdb;
		$wpdb->last_error = '';
		$c                = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- callers prepare.
		if ( '' !== self::last_error() ) {
			self::read_failed( 'Read failed' );
			return array();
		}
		return array_map( 'strval', (array) $c );
	}

	/**
	 * Insert one row; throws on failure (including values too long for their column).
	 *
	 * @param string $short Table short name.
	 * @param array  $data  Column => value (null is stored as NULL).
	 * @return int Insert id (0 for tables without AUTO_INCREMENT).
	 * @throws Db_Error On failure.
	 */
	public static function insert( $short, array $data ) {
		global $wpdb;
		if ( false === $wpdb->insert( self::table( $short ), $data ) ) {
			self::fail( 'Insert into ' . $short . ' failed' );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Insert many rows (same columns) in chunks; throws on failure.
	 *
	 * @param string  $short Table short name.
	 * @param array[] $rows  Rows.
	 * @throws Db_Error On failure.
	 */
	public static function insert_many( $short, array $rows ) {
		global $wpdb;
		$rows = array_values( $rows );
		if ( ! $rows ) {
			return;
		}
		$cols  = array_keys( $rows[0] );
		$table = self::table( $short );
		foreach ( array_chunk( $rows, 100 ) as $chunk ) {
			$values = array();
			$sql    = array();
			foreach ( $chunk as $row ) {
				$ph = array();
				foreach ( $cols as $col ) {
					$v = array_key_exists( $col, $row ) ? $row[ $col ] : null;
					if ( null === $v ) {
						$ph[] = 'NULL';
					} else {
						$ph[]     = '%s';
						$values[] = (string) $v;
					}
				}
				$sql[] = '(' . implode( ',', $ph ) . ')';
			}
			$query = "INSERT INTO {$table} (`" . implode( '`,`', $cols ) . '`) VALUES ' . implode( ',', $sql );
			self::q( $values ? $wpdb->prepare( $query, $values ) : $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
		}
	}

	/**
	 * Update rows; throws on failure.
	 *
	 * @param string $short Table short name.
	 * @param array  $data  Column => value.
	 * @param array  $where Column => value.
	 * @return int Rows changed.
	 * @throws Db_Error On failure.
	 */
	public static function update( $short, array $data, array $where ) {
		global $wpdb;
		$r = $wpdb->update( self::table( $short ), $data, $where );
		if ( false === $r ) {
			self::fail( 'Update of ' . $short . ' failed' );
		}
		return (int) $r;
	}

	/**
	 * Insert a row, or update it when its key exists; throws on failure.
	 *
	 * @param string $short Table short name.
	 * @param array  $data  Column => value, keys included.
	 * @param array  $keys  Key columns (not updated).
	 * @throws Db_Error On failure.
	 */
	public static function upsert( $short, array $data, array $keys ) {
		global $wpdb;
		$cols   = array_keys( $data );
		$ph     = array();
		$values = array();
		$set    = array();
		foreach ( $data as $col => $v ) {
			if ( null === $v ) {
				$ph[] = 'NULL';
			} else {
				$ph[]     = '%s';
				$values[] = (string) $v;
			}
			if ( ! in_array( $col, $keys, true ) ) {
				$set[] = "`{$col}` = VALUES(`{$col}`)";
			}
		}
		$table = self::table( $short );
		$sql   = "INSERT INTO {$table} (`" . implode( '`,`', $cols ) . '`) VALUES (' . implode( ',', $ph ) . ')' . ( $set ? ' ON DUPLICATE KEY UPDATE ' . implode( ', ', $set ) : '' );
		self::q( $values ? $wpdb->prepare( $sql, $values ) : $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
	}

	/**
	 * Delete rows; throws on failure.
	 *
	 * @param string $short Table short name.
	 * @param array  $where Column => value.
	 * @return int Rows deleted.
	 * @throws Db_Error On failure.
	 */
	public static function delete( $short, array $where ) {
		global $wpdb;
		$r = $wpdb->delete( self::table( $short ), $where );
		if ( false === $r ) {
			self::fail( 'Delete from ' . $short . ' failed' );
		}
		return (int) $r;
	}

	/**
	 * Allocate the next id of a sequence ("order", "item") inside a transaction. Ids are never reused, also after
	 * a restore removed the rows that had them.
	 *
	 * @param string $name  Counter.
	 * @param string $table Table whose ids it numbers.
	 * @return int
	 * @throws Db_Error Outside a transaction, or on failure.
	 */
	public static function next_id( $name, $table ) {
		global $wpdb;
		if ( 0 === self::$depth ) {
			throw new Db_Error( 'Ids are only allocated inside a transaction.' );
		}
		$c    = self::table( 'counters' );
		$t    = self::table( $table );
		$next = max( (int) self::var( $wpdb->prepare( "SELECT value FROM {$c} WHERE name = %s", $name ) ), (int) self::var( "SELECT MAX(id) FROM {$t}" ) + 1, 1 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names.
		self::upsert(
			'counters',
			array(
				'name'  => $name,
				'value' => $next + 1,
			),
			array( 'name' )
		);
		return $next;
	}

	/**
	 * Raise a sequence so it continues after $at_least - 1 (restore, migration).
	 *
	 * @param string $name     Counter.
	 * @param int    $at_least Lowest next id.
	 * @throws Db_Error On failure.
	 */
	public static function raise_counter( $name, $at_least ) {
		global $wpdb;
		$c = self::table( 'counters' );
		self::q( $wpdb->prepare( "INSERT INTO {$c} (name, value) VALUES (%s, %d) ON DUPLICATE KEY UPDATE value = GREATEST(value, %d)", $name, $at_least, $at_least ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
	}

	/**
	 * Current value of a sequence (the next id it hands out).
	 *
	 * @param string $name Counter.
	 * @return int
	 */
	public static function counter( $name ) {
		global $wpdb;
		$c = self::table( 'counters' );
		return (int) self::var( $wpdb->prepare( "SELECT value FROM {$c} WHERE name = %s", $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
	}

	/* ------------------------------------------------------------------
	 * Options inside transactions.
	 * ---------------------------------------------------------------- */

	/**
	 * Save an option. Inside a transaction the row is written directly, part of the transaction, and WordPress's
	 * option cache is refreshed only after COMMIT; outside, update_option().
	 *
	 * @param string $name     Option name.
	 * @param mixed  $value    Value.
	 * @param bool   $autoload Load on every request.
	 * @throws Db_Error On failure inside a transaction.
	 */
	public static function set_option( $name, $value, $autoload ) {
		global $wpdb;
		if ( 0 === self::$depth ) {
			update_option( $name, $value, $autoload );
			return;
		}
		// WordPress 6.6 stores "on"/"off"; earlier versions "yes"/"no".
		$auto       = function_exists( 'wp_autoload_values_to_autoload' ) ? ( $autoload ? 'on' : 'off' ) : ( $autoload ? 'yes' : 'no' );
		$serialized = maybe_serialize( $value );
		self::q( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_value = %s", $name, $serialized, $auto, $serialized ) );
		self::$options[ $name ] = true;
	}

	/**
	 * Read an option, seeing what this transaction wrote.
	 *
	 * @param string $name    Option name.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public static function get_option( $name, $default = false ) {
		global $wpdb;
		if ( 0 === self::$depth ) {
			return get_option( $name, $default );
		}
		// Inside a transaction: the row as this transaction sees it (the cache may be older, or someone else's).
		$wpdb->last_error = '';
		$raw              = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		if ( '' !== self::last_error() ) {
			self::fail( 'Read failed' );
		}
		return null === $raw ? $default : maybe_unserialize( $raw );
	}

	/* ------------------------------------------------------------------
	 * Health.
	 * ---------------------------------------------------------------- */

	/**
	 * Database errors met in this request outside transactions.
	 *
	 * @return string[]
	 */
	public static function errors() {
		return array_values( self::$errors );
	}

	/**
	 * Health information for the status page.
	 *
	 * @return array
	 */
	public static function health() {
		global $wpdb;
		$names   = array_map( array( __CLASS__, 'table' ), self::TABLES );
		$names[] = $wpdb->options;
		$ph      = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		$status  = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $ph is a list of %s placeholders.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_ROWS AS row_count, DATA_LENGTH + INDEX_LENGTH AS bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$ph})", $names ), ARRAY_A ) as $r ) {
			$status[ $r['name'] ] = $r;
		}
		$engines = array();
		$bytes   = 0;
		foreach ( $status as $name => $r ) {
			if ( $wpdb->options !== $name ) {
				$bytes += (int) $r['bytes'];
			}
			$engines[ $name ] = (string) $r['engine'];
		}
		$not_innodb = array();
		foreach ( $engines as $name => $engine ) {
			if ( '' !== $engine && 'innodb' !== strtolower( $engine ) ) {
				$not_innodb[] = $name . ' (' . $engine . ')';
			}
		}
		return array(
			'prefix'     => self::table( '' ),
			'version'    => (int) get_option( self::DB_VERSION_OPTION, 0 ),
			'missing'    => self::missing_tables(),
			'not_innodb' => $not_innodb,
			'bytes'      => $bytes,
			'errors'     => self::errors(),
			'server'     => (string) $wpdb->db_server_info(),
		);
	}
}
