<?php
/**
 * WordPress privacy tools integration.
 *
 * @package BMLT_Lit
 * @version 6.3.0
 */

namespace BMLT_Lit;

defined( 'ABSPATH' ) || exit;

/**
 * Personal data exporter/eraser and privacy policy text.
 */
class Privacy {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy_content' ) );
	}

	/**
	 * Register exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['bmlt-literature-manager'] = array(
			'exporter_friendly_name' => __( 'Literature Orders', 'bmlt-literature-manager' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['bmlt-literature-manager'] = array(
			'eraser_friendly_name' => __( 'Literature Orders', 'bmlt-literature-manager' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Export orders for an email address.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		$per    = 50;
		$page   = max( 1, (int) $page );
		$orders = Orders::for_email( sanitize_email( $email ), $page, $per );
		$data   = array();
		foreach ( (array) $orders as $o ) {
			$items = array();
			foreach ( Orders::get_items( $o->id ) as $it ) {
				$items[] = $it->quantity . ' × ' . $it->sku . ' ' . $it->name;
			}
			$data[] = array(
				'group_id'    => 'bmlt-lit-orders',
				'group_label' => __( 'Literature Orders', 'bmlt-literature-manager' ),
				'item_id'     => 'bmlt-lit-order-' . $o->id,
				'data'        => array(
					array(
						'name'  => __( 'Order Number', 'bmlt-literature-manager' ),
						'value' => $o->order_number,
					),
					array(
						'name'  => __( 'Order Date', 'bmlt-literature-manager' ),
						'value' => Helpers::local_date( $o->created_at ),
					),
					array(
						'name'  => __( 'Area', 'bmlt-literature-manager' ),
						'value' => $o->area_name,
					),
					array(
						'name'  => __( 'Meeting', 'bmlt-literature-manager' ),
						'value' => $o->meeting_name,
					),
					array(
						'name'  => __( 'Contact Name', 'bmlt-literature-manager' ),
						'value' => $o->contact_name,
					),
					array(
						'name'  => __( 'Phone', 'bmlt-literature-manager' ),
						'value' => $o->contact_phone,
					),
					array(
						'name'  => __( 'Email', 'bmlt-literature-manager' ),
						'value' => $o->contact_email,
					),
					array(
						'name'  => __( 'Notes', 'bmlt-literature-manager' ),
						'value' => $o->notes,
					),
					array(
						'name'  => __( 'Items', 'bmlt-literature-manager' ),
						'value' => implode( '; ', $items ),
					),
					array(
						'name'  => __( 'Total', 'bmlt-literature-manager' ),
						'value' => Helpers::format_money( $o->total ),
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => count( (array) $orders ) < $per,
		);
	}

	/**
	 * Anonymize orders for an email address. Order totals and items are kept for literature accounting.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		unset( $page );
		$count = Orders::anonymize_by_email( sanitize_email( $email ) );
		return array(
			'items_removed'  => $count > 0,
			'items_retained' => $count > 0,
			'messages'       => $count > 0 ? array( __( 'Literature order contact details were anonymized; order items and totals were retained for literature accounting.', 'bmlt-literature-manager' ) ) : array(),
			'done'           => true,
		);
	}

	/**
	 * Suggested privacy policy text.
	 */
	public static function policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p>' . esc_html__( 'When you place a literature order we store your selected meeting, your name, phone number, email address, any notes you provide and the literature you ordered. This information is used only by authorized literature trusted servants to process and deliver your order. A one-way hash of your IP address is stored to protect the order form from abuse.', 'bmlt-literature-manager' ) . '</p>';
		wp_add_privacy_policy_content( __( 'BMLT Literature Manager', 'bmlt-literature-manager' ), wp_kses_post( $text ) );
	}
}
