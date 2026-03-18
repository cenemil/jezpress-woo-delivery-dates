<?php
/**
 * JWDD Holidays
 *
 * Manages holiday date ranges that block delivery availability.
 *
 * carrier_ids is a JSON array of carrier IDs stored as text.
 * An empty array [] means the holiday applies to ALL carriers.
 * A non-empty array means the holiday applies only to those carriers.
 *
 * @package Jezpress_Woo_Delivery_Dates
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWDD_Holidays {

	/**
	 * Constructor — registers AJAX hooks.
	 */
	public function __construct() {
		add_action( 'wp_ajax_jwdd_save_holiday',   array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_jwdd_delete_holiday', array( $this, 'ajax_delete' ) );
	}

	// -------------------------------------------------------------------------
	// Static data accessors
	// -------------------------------------------------------------------------

	/**
	 * Return all holidays ordered by date.
	 *
	 * @return array
	 */
	public static function get_all() {
		global $wpdb;

		$table = JWDD_DB::holidays_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results(
			"SELECT * FROM {$table} ORDER BY date_from ASC, name ASC"
		) ?: array();
	}

	/**
	 * Return a single holiday by ID.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function get_by_id( $id ) {
		global $wpdb;

		return $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT * FROM ' . JWDD_DB::holidays_table() . ' WHERE id = %d',
			absint( $id )
		) );
	}

	/**
	 * Decode a holiday's carrier_ids JSON field into an int array.
	 *
	 * @param string $json
	 * @return int[]
	 */
	public static function decode_carrier_ids( $json ) {
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		return array_map( 'intval', $decoded );
	}

	/**
	 * Return a set of date strings (Y-m-d) blocked by active holidays in a range.
	 *
	 * A holiday with an empty carrier_ids array applies to ALL carriers.
	 * A holiday with specific carrier IDs applies only when those carriers overlap
	 * with the provided $carrier_ids filter.
	 *
	 * When $carrier_ids is null (no address / no carrier filter), only all-carrier
	 * holidays (empty carrier_ids) are applied.
	 *
	 * @param string     $date_from   Y-m-d
	 * @param string     $date_to     Y-m-d
	 * @param int[]|null $carrier_ids
	 * @return array Keys are blocked date strings, values are true.
	 */
	public static function get_blocked_dates( $date_from, $date_to, $carrier_ids = null ) {
		global $wpdb;

		$table = JWDD_DB::holidays_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT date_from, date_to, carrier_ids FROM {$table}
			 WHERE is_active = 1 AND date_from <= %s AND date_to >= %s",
			$date_to,
			$date_from
		) );

		if ( empty( $rows ) ) {
			return array();
		}

		$filter_ids = is_array( $carrier_ids ) ? array_map( 'intval', $carrier_ids ) : null;
		$blocked    = array();

		foreach ( $rows as $row ) {
			$holiday_cids = self::decode_carrier_ids( $row->carrier_ids );

			// Determine whether this holiday applies.
			if ( empty( $holiday_cids ) ) {
				// All-carrier holiday — always applies.
				$applies = true;
			} elseif ( null === $filter_ids ) {
				// No carrier context — carrier-specific holidays do not apply.
				$applies = false;
			} else {
				// Applies if any of the holiday's carriers overlap with the filter.
				$applies = ! empty( array_intersect( $holiday_cids, $filter_ids ) );
			}

			if ( ! $applies ) {
				continue;
			}

			$current = new \DateTime( $row->date_from );
			$end     = new \DateTime( $row->date_to );

			while ( $current <= $end ) {
				$blocked[ $current->format( 'Y-m-d' ) ] = true;
				$current->modify( '+1 day' );
			}
		}

		return $blocked;
	}

	// -------------------------------------------------------------------------
	// AJAX handlers — require nonce + manage_woocommerce
	// -------------------------------------------------------------------------

	/**
	 * AJAX: Insert or update a holiday.
	 *
	 * @return void
	 */
	public function ajax_save() {
		check_ajax_referer( 'jwdd_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezpress-woo-delivery-dates' ) ), 403 );
		}

		$id            = isset( $_POST['id'] )             ? absint( $_POST['id'] )                                        : 0;
		$name          = isset( $_POST['name'] )           ? sanitize_text_field( wp_unslash( $_POST['name'] ) )           : '';
		$carrier_ids   = isset( $_POST['carrier_ids_json'] ) ? wp_unslash( $_POST['carrier_ids_json'] )                    : '[]';
		$date_from     = isset( $_POST['date_from'] )      ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) )      : '';
		$date_to       = isset( $_POST['date_to'] )        ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) )        : '';
		$is_active     = isset( $_POST['is_active'] )      ? (int) $_POST['is_active']                                     : 1;

		if ( empty( $name ) ) {
			wp_send_json_error( array( 'message' => __( 'Holiday name is required.', 'jezpress-woo-delivery-dates' ) ) );
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
			wp_send_json_error( array( 'message' => __( 'Valid from and to dates are required.', 'jezpress-woo-delivery-dates' ) ) );
		}

		if ( $date_to < $date_from ) {
			wp_send_json_error( array( 'message' => __( 'To date must be on or after from date.', 'jezpress-woo-delivery-dates' ) ) );
		}

		// Sanitise carrier_ids: decode, cast to int, re-encode.
		$decoded_ids     = json_decode( $carrier_ids, true );
		$sanitised_ids   = is_array( $decoded_ids ) ? array_values( array_map( 'absint', $decoded_ids ) ) : array();
		$carrier_ids_json = wp_json_encode( $sanitised_ids );

		global $wpdb;
		$table = JWDD_DB::holidays_table();
		$now   = current_time( 'mysql' );

		if ( $id > 0 ) {
			$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'name'        => $name,
					'carrier_ids' => $carrier_ids_json,
					'date_from'   => $date_from,
					'date_to'     => $date_to,
					'is_active'   => $is_active,
					'updated_at'  => $now,
				),
				array( 'id' => $id ),
				array( '%s', '%s', '%s', '%s', '%d', '%s' ),
				array( '%d' )
			);

			if ( false === $result ) {
				wp_send_json_error( array( 'message' => __( 'Failed to update holiday.', 'jezpress-woo-delivery-dates' ) ) );
			}

			wp_send_json_success( array( 'message' => __( 'Holiday updated.', 'jezpress-woo-delivery-dates' ), 'id' => $id ) );
		}

		$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'name'        => $name,
				'carrier_ids' => $carrier_ids_json,
				'date_from'   => $date_from,
				'date_to'     => $date_to,
				'is_active'   => $is_active,
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( ! $result ) {
			wp_send_json_error( array( 'message' => __( 'Failed to add holiday.', 'jezpress-woo-delivery-dates' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Holiday added.', 'jezpress-woo-delivery-dates' ), 'id' => $wpdb->insert_id ) );
	}

	/**
	 * AJAX: Delete a holiday.
	 *
	 * @return void
	 */
	public function ajax_delete() {
		check_ajax_referer( 'jwdd_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezpress-woo-delivery-dates' ) ), 403 );
		}

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid holiday ID.', 'jezpress-woo-delivery-dates' ) ) );
		}

		global $wpdb;

		$result = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			JWDD_DB::holidays_table(),
			array( 'id' => $id ),
			array( '%d' )
		);

		if ( ! $result ) {
			wp_send_json_error( array( 'message' => __( 'Failed to delete holiday.', 'jezpress-woo-delivery-dates' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Holiday deleted.', 'jezpress-woo-delivery-dates' ) ) );
	}
}
