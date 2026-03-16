<?php
/**
 * JWDD Schedules
 *
 * Manages delivery schedules (date + time slot entries): CRUD, recurring
 * generation, and data accessors used by the checkout.
 *
 * @package Jezpress_Woo_Delivery_Dates
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWDD_Schedules {

	/**
	 * Constructor — registers AJAX hooks.
	 */
	public function __construct() {
		add_action( 'wp_ajax_jwdd_save_schedule',        array( $this, 'ajax_save_schedule' ) );
		add_action( 'wp_ajax_jwdd_delete_schedule',      array( $this, 'ajax_delete_schedule' ) );
		add_action( 'wp_ajax_jwdd_get_schedules',        array( $this, 'ajax_get_schedules' ) );
		add_action( 'wp_ajax_jwdd_generate_recurring',   array( $this, 'ajax_generate_recurring' ) );
		add_action( 'wp_ajax_jwdd_get_time_slots',       array( $this, 'ajax_get_time_slots' ) );
		add_action( 'wp_ajax_nopriv_jwdd_get_time_slots', array( $this, 'ajax_get_time_slots' ) );
	}

	// -------------------------------------------------------------------------
	// Public data accessors (used by Checkout class)
	// -------------------------------------------------------------------------

	/**
	 * Get distinct available delivery dates within the booking window.
	 *
	 * @return array Array of date strings in 'Y-m-d' format.
	 */
	public static function get_available_dates() {
		global $wpdb;

		$settings    = get_option( 'jwdd_settings', array() );
		$cutoff_days = isset( $settings['cutoff_days'] ) ? absint( $settings['cutoff_days'] ) : 1;
		$max_days    = isset( $settings['max_future_days'] ) ? absint( $settings['max_future_days'] ) : 30;

		$min_date = gmdate( 'Y-m-d', strtotime( "+{$cutoff_days} days" ) );
		$max_date = gmdate( 'Y-m-d', strtotime( "+{$max_days} days" ) );

		$table = JWDD_DB::schedules_table();

		$rows = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT DISTINCT schedule_date
			 FROM {$table}
			 WHERE is_active = 1
			   AND schedule_date >= %s
			   AND schedule_date <= %s
			   AND (max_orders = 0 OR booked_count < max_orders)
			 ORDER BY schedule_date ASC",
			$min_date,
			$max_date
		) );

		return $rows ? $rows : array();
	}

	/**
	 * Get available time slots for a given date.
	 *
	 * @param string   $date       Date string 'Y-m-d'.
	 * @param int|null $carrier_id Optional carrier filter.
	 * @return array
	 */
	public static function get_slots_for_date( $date, $carrier_id = null ) {
		global $wpdb;

		$table          = JWDD_DB::schedules_table();
		$carriers_table = JWDD_DB::carriers_table();

		if ( $carrier_id ) {
			$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"SELECT s.*, c.name AS carrier_name
				 FROM {$table} s
				 LEFT JOIN {$carriers_table} c ON c.id = s.carrier_id
				 WHERE s.is_active = 1
				   AND s.schedule_date = %s
				   AND s.carrier_id = %d
				   AND (s.max_orders = 0 OR s.booked_count < s.max_orders)
				 ORDER BY s.start_time ASC",
				$date,
				absint( $carrier_id )
			) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"SELECT s.*, c.name AS carrier_name
				 FROM {$table} s
				 LEFT JOIN {$carriers_table} c ON c.id = s.carrier_id
				 WHERE s.is_active = 1
				   AND s.schedule_date = %s
				   AND (s.max_orders = 0 OR s.booked_count < s.max_orders)
				 ORDER BY s.start_time ASC",
				$date
			) );
		}

		return $rows ? $rows : array();
	}

	/**
	 * Increment the booked_count for a schedule slot.
	 *
	 * @param int $schedule_id Schedule row ID.
	 * @return void
	 */
	public static function increment_booked( $schedule_id ) {
		global $wpdb;
		$table = JWDD_DB::schedules_table();
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"UPDATE {$table} SET booked_count = booked_count + 1 WHERE id = %d",
			absint( $schedule_id )
		) );
	}

	/**
	 * Decrement the booked_count for a schedule slot (min 0).
	 *
	 * @param int $schedule_id Schedule row ID.
	 * @return void
	 */
	public static function decrement_booked( $schedule_id ) {
		global $wpdb;
		$table = JWDD_DB::schedules_table();
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"UPDATE {$table} SET booked_count = GREATEST(0, booked_count - 1) WHERE id = %d",
			absint( $schedule_id )
		) );
	}

	// -------------------------------------------------------------------------
	// AJAX handlers — admin actions require manage_woocommerce + nonce
	// -------------------------------------------------------------------------

	/**
	 * AJAX: Save (insert or update) a schedule slot.
	 *
	 * @return void
	 */
	public function ajax_save_schedule() {
		check_ajax_referer( 'jwdd_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezpress-woo-delivery-dates' ) ), 403 );
		}

		$id         = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$carrier_id = isset( $_POST['carrier_id'] ) ? absint( $_POST['carrier_id'] ) : 0;
		$date       = isset( $_POST['schedule_date'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_date'] ) ) : '';
		$start      = isset( $_POST['start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['start_time'] ) ) : '';
		$end        = isset( $_POST['end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['end_time'] ) ) : '';
		$label      = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$max_orders = isset( $_POST['max_orders'] ) ? absint( $_POST['max_orders'] ) : 0;
		$is_active  = isset( $_POST['is_active'] ) ? (int) $_POST['is_active'] : 1;

		if ( empty( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			wp_send_json_error( array( 'message' => __( 'A valid date is required.', 'jezpress-woo-delivery-dates' ) ) );
		}

		if ( empty( $start ) || empty( $end ) ) {
			wp_send_json_error( array( 'message' => __( 'Start and end times are required.', 'jezpress-woo-delivery-dates' ) ) );
		}

		// Auto-generate label if not provided.
		if ( empty( $label ) ) {
			$label = gmdate( 'g:ia', strtotime( $start ) ) . ' – ' . gmdate( 'g:ia', strtotime( $end ) );
		}

		global $wpdb;
		$table = JWDD_DB::schedules_table();
		$now   = current_time( 'mysql' );

		if ( $id > 0 ) {
			$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'carrier_id'    => $carrier_id,
					'schedule_date' => $date,
					'start_time'    => $start,
					'end_time'      => $end,
					'label'         => $label,
					'max_orders'    => $max_orders,
					'is_active'     => $is_active,
				),
				array( 'id' => $id ),
				array( '%d', '%s', '%s', '%s', '%s', '%d', '%d' ),
				array( '%d' )
			);

			if ( false === $result ) {
				wp_send_json_error( array( 'message' => __( 'Failed to update schedule.', 'jezpress-woo-delivery-dates' ) ) );
			}

			wp_send_json_success( array( 'message' => __( 'Schedule updated.', 'jezpress-woo-delivery-dates' ) ) );
		}

		$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'carrier_id'    => $carrier_id,
				'schedule_date' => $date,
				'start_time'    => $start,
				'end_time'      => $end,
				'label'         => $label,
				'max_orders'    => $max_orders,
				'booked_count'  => 0,
				'is_active'     => $is_active,
				'created_at'    => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s' )
		);

		if ( ! $result ) {
			wp_send_json_error( array( 'message' => __( 'Failed to add schedule.', 'jezpress-woo-delivery-dates' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Schedule added.', 'jezpress-woo-delivery-dates' ) ) );
	}

	/**
	 * AJAX: Delete a schedule slot.
	 *
	 * @return void
	 */
	public function ajax_delete_schedule() {
		check_ajax_referer( 'jwdd_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezpress-woo-delivery-dates' ) ), 403 );
		}

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid schedule ID.', 'jezpress-woo-delivery-dates' ) ) );
		}

		global $wpdb;

		$result = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			JWDD_DB::schedules_table(),
			array( 'id' => $id ),
			array( '%d' )
		);

		if ( ! $result ) {
			wp_send_json_error( array( 'message' => __( 'Failed to delete schedule.', 'jezpress-woo-delivery-dates' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Schedule deleted.', 'jezpress-woo-delivery-dates' ) ) );
	}

	/**
	 * AJAX: Return paginated/filtered schedules for the admin table.
	 *
	 * @return void
	 */
	public function ajax_get_schedules() {
		check_ajax_referer( 'jwdd_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezpress-woo-delivery-dates' ) ), 403 );
		}

		global $wpdb;

		$table          = JWDD_DB::schedules_table();
		$carriers_table = JWDD_DB::carriers_table();

		$carrier_filter = isset( $_POST['carrier_id'] ) ? absint( $_POST['carrier_id'] ) : 0;
		$date_from      = isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : '';
		$date_to        = isset( $_POST['date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) ) : '';

		$where  = array( '1=1' );
		$params = array();

		if ( $carrier_filter ) {
			$where[]  = 's.carrier_id = %d';
			$params[] = $carrier_filter;
		}

		if ( $date_from && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
			$where[]  = 's.schedule_date >= %s';
			$params[] = $date_from;
		}

		if ( $date_to && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
			$where[]  = 's.schedule_date <= %s';
			$params[] = $date_to;
		}

		$where_sql = implode( ' AND ', $where );
		$query     = "SELECT s.*, c.name AS carrier_name
					  FROM {$table} s
					  LEFT JOIN {$carriers_table} c ON c.id = s.carrier_id
					  WHERE {$where_sql}
					  ORDER BY s.schedule_date ASC, s.start_time ASC";

		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $query, $params ) );
		} else {
			$rows = $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		}

		wp_send_json_success( array( 'schedules' => $rows ? $rows : array() ) );
	}

	/**
	 * AJAX: Bulk-generate recurring schedule slots for a date range.
	 *
	 * Accepts: carrier_id, start_date, end_date, days_of_week (array of 0-6),
	 *          start_time, end_time, label, max_orders.
	 *
	 * @return void
	 */
	public function ajax_generate_recurring() {
		check_ajax_referer( 'jwdd_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezpress-woo-delivery-dates' ) ), 403 );
		}

		$carrier_id  = isset( $_POST['carrier_id'] ) ? absint( $_POST['carrier_id'] ) : 0;
		$start_date  = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
		$end_date    = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
		$days        = isset( $_POST['days_of_week'] ) && is_array( $_POST['days_of_week'] )
			? array_map( 'absint', $_POST['days_of_week'] )
			: array();
		$start_time  = isset( $_POST['start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['start_time'] ) ) : '';
		$end_time    = isset( $_POST['end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['end_time'] ) ) : '';
		$label       = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$max_orders  = isset( $_POST['max_orders'] ) ? absint( $_POST['max_orders'] ) : 0;

		if ( ! $start_date || ! $end_date || empty( $days ) || ! $start_time || ! $end_time ) {
			wp_send_json_error( array( 'message' => __( 'Start date, end date, days of week, and times are required.', 'jezpress-woo-delivery-dates' ) ) );
		}

		$ts_start = strtotime( $start_date );
		$ts_end   = strtotime( $end_date );

		if ( ! $ts_start || ! $ts_end || $ts_start > $ts_end ) {
			wp_send_json_error( array( 'message' => __( 'Invalid date range.', 'jezpress-woo-delivery-dates' ) ) );
		}

		if ( empty( $label ) ) {
			$label = gmdate( 'g:ia', strtotime( $start_time ) ) . ' – ' . gmdate( 'g:ia', strtotime( $end_time ) );
		}

		global $wpdb;
		$table   = JWDD_DB::schedules_table();
		$now     = current_time( 'mysql' );
		$created = 0;

		$current = $ts_start;
		while ( $current <= $ts_end ) {
			$dow = (int) gmdate( 'w', $current ); // 0=Sunday, 6=Saturday

			if ( in_array( $dow, $days, true ) ) {
				$date = gmdate( 'Y-m-d', $current );

				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$table,
					array(
						'carrier_id'    => $carrier_id,
						'schedule_date' => $date,
						'start_time'    => $start_time,
						'end_time'      => $end_time,
						'label'         => $label,
						'max_orders'    => $max_orders,
						'booked_count'  => 0,
						'is_active'     => 1,
						'created_at'    => $now,
					),
					array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s' )
				);

				$created++;
			}

			$current = strtotime( '+1 day', $current );
		}

		/* translators: %d: number of schedule slots created */
		wp_send_json_success( array(
			'message' => sprintf( _n( '%d schedule slot created.', '%d schedule slots created.', $created, 'jezpress-woo-delivery-dates' ), $created ),
			'created' => $created,
		) );
	}

	/**
	 * AJAX: Return available time slots for a given date (public — used by checkout JS).
	 *
	 * @return void
	 */
	public function ajax_get_time_slots() {
		check_ajax_referer( 'jwdd_checkout_nonce', 'nonce' );

		$date       = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
		$carrier_id = isset( $_POST['carrier_id'] ) ? absint( $_POST['carrier_id'] ) : null;

		if ( empty( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid date.', 'jezpress-woo-delivery-dates' ) ) );
		}

		$slots = self::get_slots_for_date( $date, $carrier_id );

		$formatted = array();
		foreach ( $slots as $slot ) {
			$formatted[] = array(
				'id'           => (int) $slot->id,
				'label'        => $slot->label,
				'carrier_name' => $slot->carrier_name,
				'carrier_id'   => (int) $slot->carrier_id,
				'start_time'   => $slot->start_time,
				'end_time'     => $slot->end_time,
				'available'    => $slot->max_orders === '0' ? true : ( (int) $slot->booked_count < (int) $slot->max_orders ),
			);
		}

		wp_send_json_success( array( 'slots' => $formatted ) );
	}
}
