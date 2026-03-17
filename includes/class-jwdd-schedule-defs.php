<?php
/**
 * JWDD Schedule Definitions
 *
 * Manages schedule definitions (named recurring delivery patterns with days of week).
 *
 * @package Jezpress_Woo_Delivery_Dates
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWDD_Schedule_Defs {

	public function __construct() {
		add_action( 'wp_ajax_jwdd_save_schedule_def',   array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_jwdd_delete_schedule_def', array( $this, 'ajax_delete' ) );
	}

	// -------------------------------------------------------------------------
	// Public data accessors
	// -------------------------------------------------------------------------

	public static function get_all() {
		global $wpdb;
		$table          = JWDD_DB::schedule_defs_table();
		$carriers_table = JWDD_DB::carriers_table();
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT d.*, c.name AS carrier_name
			 FROM {$table} d
			 LEFT JOIN {$carriers_table} c ON c.id = d.carrier_id
			 ORDER BY d.name ASC"
		);
	}

	public static function get_by_id( $id ) {
		global $wpdb;
		$table          = JWDD_DB::schedule_defs_table();
		$carriers_table = JWDD_DB::carriers_table();
		return $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT d.*, c.name AS carrier_name
			 FROM {$table} d
			 LEFT JOIN {$carriers_table} c ON c.id = d.carrier_id
			 WHERE d.id = %d",
			absint( $id )
		) );
	}

	// -------------------------------------------------------------------------
	// AJAX handlers — require manage_woocommerce + nonce
	// -------------------------------------------------------------------------

	public function ajax_save() {
		check_ajax_referer( 'jwdd_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezpress-woo-delivery-dates' ) ), 403 );
		}

		$id         = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$name       = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$carrier_id = isset( $_POST['carrier_id'] ) ? absint( $_POST['carrier_id'] ) : 0;
		$is_active  = isset( $_POST['is_active'] ) ? (int) $_POST['is_active'] : 1;

		if ( empty( $name ) ) {
			wp_send_json_error( array( 'message' => __( 'Schedule name is required.', 'jezpress-woo-delivery-dates' ) ) );
		}

		// days_of_week: JSON string of [{day, start, end}] objects sent from the form.
		$raw  = isset( $_POST['days_of_week_json'] ) ? wp_unslash( $_POST['days_of_week_json'] ) : '[]'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$days = json_decode( $raw, true );
		if ( ! is_array( $days ) ) {
			$days = array();
		}

		$sanitized = array();
		foreach ( $days as $d ) {
			if ( ! is_array( $d ) || ! isset( $d['day'] ) ) {
				continue;
			}
			$day_num = absint( $d['day'] );
			if ( $day_num > 6 ) {
				continue;
			}
			$cutoff = isset( $d['cutoff'] ) ? sanitize_text_field( $d['cutoff'] ) : '';
			if ( '' !== $cutoff && ! preg_match( '/^\d{2}:\d{2}$/', $cutoff ) ) { $cutoff = ''; }
			$sanitized_slots = array();
			if ( isset( $d['slots'] ) && is_array( $d['slots'] ) ) {
				foreach ( $d['slots'] as $slot ) {
					if ( ! is_array( $slot ) ) continue;
					$s_start = isset( $slot['start'] ) ? sanitize_text_field( $slot['start'] ) : '09:00';
					$s_end   = isset( $slot['end'] )   ? sanitize_text_field( $slot['end'] )   : '17:00';
					$s_label = isset( $slot['label'] ) ? sanitize_text_field( $slot['label'] ) : '';
					if ( ! preg_match( '/^\d{2}:\d{2}$/', $s_start ) ) { $s_start = '09:00'; }
					if ( ! preg_match( '/^\d{2}:\d{2}$/', $s_end ) )   { $s_end   = '17:00'; }
					$sanitized_slots[] = array( 'start' => $s_start, 'end' => $s_end, 'label' => $s_label );
				}
			}
			$sanitized[] = array( 'day' => $day_num, 'cutoff' => $cutoff, 'slots' => $sanitized_slots );
		}

		$days_json = wp_json_encode( $sanitized );

		global $wpdb;
		$table = JWDD_DB::schedule_defs_table();
		$now   = current_time( 'mysql' );

		if ( $id > 0 ) {
			$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'name'         => $name,
					'carrier_id'   => $carrier_id,
					'days_of_week' => $days_json,
					'is_active'    => $is_active,
					'updated_at'   => $now,
				),
				array( 'id' => $id ),
				array( '%s', '%d', '%s', '%d', '%s' ),
				array( '%d' )
			);

			if ( false === $result ) {
				wp_send_json_error( array( 'message' => __( 'Failed to update schedule.', 'jezpress-woo-delivery-dates' ) ) );
			}

			wp_send_json_success( array(
				'message' => __( 'Schedule updated.', 'jezpress-woo-delivery-dates' ),
				'def'     => self::get_by_id( $id ),
			) );
		}

		$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'name'         => $name,
				'carrier_id'   => $carrier_id,
				'days_of_week' => $days_json,
				'is_active'    => $is_active,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s' )
		);

		if ( ! $result ) {
			wp_send_json_error( array( 'message' => __( 'Failed to add schedule.', 'jezpress-woo-delivery-dates' ) ) );
		}

		$new_id = $wpdb->insert_id;

		wp_send_json_success( array(
			'message' => __( 'Schedule added.', 'jezpress-woo-delivery-dates' ),
			'def'     => self::get_by_id( $new_id ),
			'def_id'  => $new_id,
		) );
	}

	public function ajax_delete() {
		check_ajax_referer( 'jwdd_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezpress-woo-delivery-dates' ) ), 403 );
		}

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid schedule ID.', 'jezpress-woo-delivery-dates' ) ) );
		}

		global $wpdb;

		// Delete associated slots first.
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			JWDD_DB::schedules_table(),
			array( 'schedule_def_id' => $id ),
			array( '%d' )
		);

		$result = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			JWDD_DB::schedule_defs_table(),
			array( 'id' => $id ),
			array( '%d' )
		);

		if ( ! $result ) {
			wp_send_json_error( array( 'message' => __( 'Failed to delete schedule.', 'jezpress-woo-delivery-dates' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Schedule and its slots deleted.', 'jezpress-woo-delivery-dates' ) ) );
	}
}
