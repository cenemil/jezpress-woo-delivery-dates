<?php
/**
 * JWDD Carriers
 *
 * Manages delivery carriers: CRUD operations and AJAX handlers.
 *
 * @package Jezpress_Woo_Delivery_Dates
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWDD_Carriers {

	/**
	 * Constructor — registers AJAX hooks.
	 */
	public function __construct() {
		add_action( 'wp_ajax_jwdd_save_carrier',   array( $this, 'ajax_save_carrier' ) );
		add_action( 'wp_ajax_jwdd_delete_carrier', array( $this, 'ajax_delete_carrier' ) );
		add_action( 'wp_ajax_jwdd_get_carriers',   array( $this, 'ajax_get_carriers' ) );
	}

	// -------------------------------------------------------------------------
	// Public data accessors
	// -------------------------------------------------------------------------

	/**
	 * Get all carriers ordered by sort_order, then name.
	 *
	 * @return array
	 */
	public static function get_all() {
		global $wpdb;
		$table = JWDD_DB::carriers_table();
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT * FROM {$table} ORDER BY sort_order ASC, name ASC"
		);
	}

	/**
	 * Get only active carriers.
	 *
	 * @return array
	 */
	public static function get_active() {
		global $wpdb;
		$table = JWDD_DB::carriers_table();
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT * FROM {$table} WHERE is_active = 1 ORDER BY sort_order ASC, name ASC"
		);
	}

	/**
	 * Get a single carrier by ID.
	 *
	 * @param int $id Carrier ID.
	 * @return object|null
	 */
	public static function get_by_id( $id ) {
		global $wpdb;
		$table = JWDD_DB::carriers_table();
		return $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT * FROM {$table} WHERE id = %d",
			absint( $id )
		) );
	}

	// -------------------------------------------------------------------------
	// AJAX handlers — all require manage_woocommerce capability + nonce
	// -------------------------------------------------------------------------

	/**
	 * AJAX: Save (insert or update) a carrier.
	 *
	 * @return void
	 */
	public function ajax_save_carrier() {
		check_ajax_referer( 'jwdd_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezpress-woo-delivery-dates' ) ), 403 );
		}

		$id          = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$name        = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$code        = isset( $_POST['code'] ) ? sanitize_key( wp_unslash( $_POST['code'] ) ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		$is_active   = isset( $_POST['is_active'] ) ? (int) $_POST['is_active'] : 1;
		$sort_order  = isset( $_POST['sort_order'] ) ? absint( $_POST['sort_order'] ) : 0;

		// shipping_zones: JSON array of {zone_id, est_days} objects.
		$raw_zones        = isset( $_POST['shipping_zones_json'] ) ? wp_unslash( $_POST['shipping_zones_json'] ) : '[]'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$zones_arr        = json_decode( $raw_zones, true );
		if ( ! is_array( $zones_arr ) ) {
			$zones_arr = array();
		}
		$sanitized_zones = array();
		foreach ( $zones_arr as $z ) {
			if ( ! is_array( $z ) ) continue;
			$est = isset( $z['est_days'] ) ? sanitize_text_field( $z['est_days'] ) : '';
			if ( '' === $est ) continue;
			$sanitized_zones[] = array(
				'zone_id'  => absint( $z['zone_id'] ?? 0 ),
				'est_days' => $est,
			);
		}
		$shipping_zones_json = wp_json_encode( $sanitized_zones );

		if ( empty( $name ) ) {
			wp_send_json_error( array( 'message' => __( 'Carrier name is required.', 'jezpress-woo-delivery-dates' ) ) );
		}

		global $wpdb;
		$table = JWDD_DB::carriers_table();
		$now   = current_time( 'mysql' );

		if ( $id > 0 ) {
			$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'name'           => $name,
					'code'           => $code,
					'description'    => $description,
					'is_active'      => $is_active,
					'sort_order'     => $sort_order,
					'shipping_zones' => $shipping_zones_json,
					'updated_at'     => $now,
				),
				array( 'id' => $id ),
				array( '%s', '%s', '%s', '%d', '%d', '%s', '%s' ),
				array( '%d' )
			);

			if ( false === $result ) {
				wp_send_json_error( array( 'message' => __( 'Failed to update carrier.', 'jezpress-woo-delivery-dates' ) ) );
			}

			wp_send_json_success( array(
				'message'  => __( 'Carrier updated.', 'jezpress-woo-delivery-dates' ),
				'carrier'  => self::get_by_id( $id ),
			) );
		}

		$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'name'           => $name,
				'code'           => $code,
				'description'    => $description,
				'is_active'      => $is_active,
				'sort_order'     => $sort_order,
				'shipping_zones' => $shipping_zones_json,
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		if ( ! $result ) {
			wp_send_json_error( array( 'message' => __( 'Failed to add carrier.', 'jezpress-woo-delivery-dates' ) ) );
		}

		wp_send_json_success( array(
			'message' => __( 'Carrier added.', 'jezpress-woo-delivery-dates' ),
			'carrier' => self::get_by_id( $wpdb->insert_id ),
		) );
	}

	/**
	 * AJAX: Delete a carrier (and its associated schedules).
	 *
	 * @return void
	 */
	public function ajax_delete_carrier() {
		check_ajax_referer( 'jwdd_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezpress-woo-delivery-dates' ) ), 403 );
		}

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid carrier ID.', 'jezpress-woo-delivery-dates' ) ) );
		}

		global $wpdb;

		// Delete associated schedules first.
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			JWDD_DB::schedules_table(),
			array( 'carrier_id' => $id ),
			array( '%d' )
		);

		$result = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			JWDD_DB::carriers_table(),
			array( 'id' => $id ),
			array( '%d' )
		);

		if ( ! $result ) {
			wp_send_json_error( array( 'message' => __( 'Failed to delete carrier.', 'jezpress-woo-delivery-dates' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Carrier deleted.', 'jezpress-woo-delivery-dates' ) ) );
	}

	/**
	 * AJAX: Return all carriers as JSON (for admin table refresh).
	 *
	 * @return void
	 */
	public function ajax_get_carriers() {
		check_ajax_referer( 'jwdd_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezpress-woo-delivery-dates' ) ), 403 );
		}

		wp_send_json_success( array( 'carriers' => self::get_all() ) );
	}
}
