<?php
/**
 * WordPress integration tests for BMLT Literature Manager.
 *
 *   WP_TESTS_DIR=/path/to/tests-lib vendor/bin/phpunit -c tests/wordpress/phpunit.xml.dist
 *   (WP_MULTISITE=1 runs the same tests on a multisite network.)
 *
 * The plugin's BMLT requests are answered in-process by the fake BMLT root server (fake-bmlt/), and every test
 * gets an empty data directory of its own.
 */

$bmlt_lit_root  = dirname( __DIR__, 2 );
$bmlt_lit_tests = getenv( 'WP_TESTS_DIR' );
if ( ! $bmlt_lit_tests || ! file_exists( $bmlt_lit_tests . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Set WP_TESTS_DIR to a WordPress test library (bin/install-wp-tests.sh).\n" );
	exit( 1 );
}

require_once $bmlt_lit_root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once $bmlt_lit_root . '/fake-bmlt/src/FakeBmlt.php';
require_once $bmlt_lit_tests . '/includes/functions.php';

// Data written while WordPress boots (before the first test switches to its own directory).
define( 'BMLT_LIT_TEST_BOOT_DIR', sys_get_temp_dir() . '/bmlt-lit-boot-' . getmypid() );

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $bmlt_lit_root ) {
		require $bmlt_lit_root . '/bmlt-literature-manager/bmlt-literature-manager.php';
	}
);
tests_add_filter(
	'bmlt_lit_data_dir',
	static function ( $dir ) {
		// Same layout as the default location (including multisite's site-<id> subdirectories), elsewhere.
		$base = ! empty( $GLOBALS['bmlt_lit_test_data_dir'] ) ? $GLOBALS['bmlt_lit_test_data_dir'] : BMLT_LIT_TEST_BOOT_DIR;
		return preg_replace( '#^' . preg_quote( WP_CONTENT_DIR . '/bmlt-literature-data', '#' ) . '#', $base, $dir );
	}
);
tests_add_filter(
	'init',
	static function () {
		\BMLT_Lit\Activator::activate();
	},
	20
);

require $bmlt_lit_tests . '/includes/bootstrap.php';
require_once __DIR__ . '/TestCase.php';
