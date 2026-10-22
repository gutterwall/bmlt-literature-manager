<?php
/**
 * Constants the plugin defines at load time (bmlt-literature-manager.php) or reads from wp-config.php, for PHPStan.
 */

define( 'BMLT_LIT_VERSION', '0.0.0' );
define( 'BMLT_LIT_FILE', __FILE__ );
define( 'BMLT_LIT_DIR', dirname( __DIR__ ) . '/bmlt-literature-manager/' );
define( 'BMLT_LIT_URL', 'https://example.org/' );
define( 'BMLT_LIT_BASENAME', 'bmlt-literature-manager/bmlt-literature-manager.php' );
define( 'BMLT_LIT_DATA_DIR', '' );
define( 'BMLT_LIT_SMTP_PASSWORD', '' );
define( 'BMLT_LIT_LOCK_METHOD', 'flock' );
