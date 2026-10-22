<?php
/**
 * A failed database query.
 *
 * @package BMLT_Lit
 * @version 7.0.0
 */

namespace BMLT_Lit;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by Db when a query fails; aborts (rolls back) the transaction it happens in.
 */
class Db_Error extends \RuntimeException {

	/**
	 * Whether the failure was a lock timeout or deadlock (another change was in progress).
	 *
	 * @var bool
	 */
	public $busy;

	/**
	 * Constructor.
	 *
	 * @param string $message Message (not shown to users).
	 * @param bool   $busy    Lock timeout or deadlock.
	 */
	public function __construct( $message, $busy = false ) {
		parent::__construct( $message );
		$this->busy = (bool) $busy;
	}
}
