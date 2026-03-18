<?php
/**
 * JWDD Schedules
 *
 * Manages delivery schedules: CRUD for manually-created slot overrides, and
 * the data accessors used by the checkout.
 *
 * Available dates and time slots are derived dynamically from active schedule
 * definitions (days_of_week config) + the max_future_days setting. Slot rows
 * in {prefix}jwdd_schedules are created on-demand the first time a customer
 * loads time slots for a date, so order meta always references a real row ID
 * and capacity (max_orders / booked_count) can be tracked per-slot.
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
		add_action( 'wp_ajax_jwdd_save_schedule',         array( $this, 'ajax_save_schedule' ) );
		add_action( 'wp_ajax_jwdd_delete_schedule',       array( $this, 'ajax_delete_schedule' ) );
		add_action( 'wp_ajax_jwdd_get_schedules',         array( $this, 'ajax_get_schedules' ) );
		add_action( 'wp_ajax_jwdd_get_time_slots',        array( $this, 'ajax_get_time_slots' ) );
		add_action( 'wp_ajax_nopriv_jwdd_get_time_slots', array( $this, 'ajax_get_time_slots' ) );
	}

	// -------------------------------------------------------------------------
	// Public data accessors (used by Checkout class)
	// -------------------------------------------------------------------------

	/**
	 * Get available delivery dates within the booking window.
	 *
	 * Dates are computed from active schedule definitions' days_of_week config
	 * and the max_future_days setting. No pre-generated slot rows required.
	 *
	 * @param int[]|null $carrier_ids Carrier IDs to filter by, or null for no filter.
	 *                                An empty array means no carriers matched — returns empty.
	 * @return string[] Array of date strings in 'Y-m-d' format.
	 */
	public static function get_available_dates( $carrier_ids = null ) {
		if ( is_array( $carrier_ids ) && empty( $carrier_ids ) ) {
			return array();
		}

		$settings = get_option( 'jwdd_settings', array() );
		$max_days = isset( $settings['max_future_days'] ) ? absint( $settings['max_future_days'] ) : 30;

		// Get active schedule defs, optionally filtered by carrier.
		$defs = self::get_active_defs( $carrier_ids );
		if ( empty( $defs ) ) {
			return array();
		}

		// Build a map: day-of-week => array of cutoff times ('HH:MM' or '' = no cutoff).
		$dow_cutoffs = array();
		foreach ( $defs as $def ) {
			$days = json_decode( $def->days_of_week, true ) ?: array();
			foreach ( $days as $d ) {
				if ( ! is_array( $d ) || ! isset( $d['day'] ) ) {
					continue;
				}
				$dow    = (int) $d['day'];
				$cutoff = ( isset( $d['cutoff'] ) && '' !== $d['cutoff'] ) ? $d['cutoff'] : '';
				if ( ! isset( $dow_cutoffs[ $dow ] ) ) {
					$dow_cutoffs[ $dow ] = array();
				}
				$dow_cutoffs[ $dow ][] = $cutoff;
			}
		}

		if ( empty( $dow_cutoffs ) ) {
			return array();
		}

		// Walk the date window starting from today (WP timezone).
		$now_wp     = current_datetime(); // DateTimeImmutable in WP timezone
		$today_str  = $now_wp->format( 'Y-m-d' );
		$current_hm = $now_wp->format( 'H:i' );

		$dates   = array();
		$current = new \DateTime( $today_str );
		$end_dt  = ( new \DateTime( $today_str ) )->modify( "+{$max_days} days" );

		while ( $current <= $end_dt ) {
			$date_str = $current->format( 'Y-m-d' );
			$dow      = (int) $current->format( 'w' );

			if ( isset( $dow_cutoffs[ $dow ] ) ) {
				$include = true;

				// For today: only include if at least one def has no cutoff or a cutoff still in the future.
				if ( $date_str === $today_str ) {
					$include = false;
					foreach ( $dow_cutoffs[ $dow ] as $cutoff ) {
						if ( '' === $cutoff || $current_hm < $cutoff ) {
							$include = true;
							break;
						}
					}
				}

				if ( $include ) {
					$dates[] = $date_str;
				}
			}

			$current->modify( '+1 day' );
		}

		// Remove dates blocked by active holidays.
		if ( ! empty( $dates ) ) {
			$blocked = JWDD_Holidays::get_blocked_dates( $dates[0], end( $dates ), $carrier_ids );
			if ( ! empty( $blocked ) ) {
				$dates = array_values( array_filter( $dates, function ( $d ) use ( $blocked ) {
					return ! isset( $blocked[ $d ] );
				} ) );
			}
		}

		return $dates;
	}

	/**
	 * Get available time slots for a given date.
	 *
	 * Reads active schedule definitions for the day-of-week of $date. For each
	 * slot in the definition, a row is created on-demand in {prefix}jwdd_schedules
	 * if one does not already exist (keyed by schedule_def_id + schedule_date +
	 * start_time). This ensures order meta always stores a real row ID and
	 * capacity limits are tracked correctly.
	 *
	 * @param string     $date        Date string 'Y-m-d'.
	 * @param int[]|null $carrier_ids Carrier IDs to filter by, or null for no filter.
	 * @return array Array of slot row objects (with carrier_name property).
	 */
	public static function get_slots_for_date( $date, $carrier_ids = null ) {
		global $wpdb;

		if ( is_array( $carrier_ids ) && empty( $carrier_ids ) ) {
			return array();
		}

		// Return nothing if this date is blocked by an active holiday.
		$blocked = JWDD_Holidays::get_blocked_dates( $date, $date, $carrier_ids );
		if ( isset( $blocked[ $date ] ) ) {
			return array();
		}

		$dow  = (int) gmdate( 'w', strtotime( $date ) ); // 0=Sun … 6=Sat
		$defs = self::get_active_defs( $carrier_ids );

		if ( empty( $defs ) ) {
			return array();
		}

		$table = JWDD_DB::schedules_table();
		$now   = current_time( 'mysql' );
		$slots = array();

		foreach ( $defs as $def ) {
			$days = json_decode( $def->days_of_week, true ) ?: array();

			foreach ( $days as $d ) {
				if ( ! is_array( $d ) || (int) $d['day'] !== $dow ) {
					continue;
				}
				if ( empty( $d['slots'] ) ) {
					continue;
				}

				foreach ( $d['slots'] as $slot_cfg ) {
					if ( empty( $slot_cfg['start'] ) || empty( $slot_cfg['end'] ) ) {
						continue;
					}

					$start = $slot_cfg['start'];
					$end   = $slot_cfg['end'];
					$label = 'From ' . gmdate( 'g:ia', strtotime( $start ) ) . ' to ' . gmdate( 'g:ia', strtotime( $end ) );

					// Look up (or create) the slot row for this def + date + start_time.
					$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						"SELECT * FROM {$table} WHERE schedule_def_id = %d AND schedule_date = %s AND start_time = %s LIMIT 1",
						(int) $def->id, $date, $start
					) );

					if ( ! $row ) {
						$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
							$table,
							array(
								'schedule_def_id' => (int) $def->id,
								'carrier_id'      => (int) $def->carrier_id,
								'schedule_date'   => $date,
								'start_time'      => $start,
								'end_time'        => $end,
								'label'           => $label,
								'max_orders'      => 0,
								'booked_count'    => 0,
								'is_active'       => 1,
								'created_at'      => $now,
							),
							array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s' )
						);
						$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
							"SELECT * FROM {$table} WHERE id = %d",
							$wpdb->insert_id
						) );
					} elseif ( $row->label !== $label ) {
						// Keep label in sync if the slot definition was updated.
						$wpdb->update( $table, array( 'label' => $label ), array( 'id' => $row->id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$row->label = $label;
					}

					if ( ! $row ) {
						continue;
					}

					// Skip if this slot is fully booked.
					if ( (int) $row->max_orders > 0 && (int) $row->booked_count >= (int) $row->max_orders ) {
						continue;
					}

					$row->carrier_name = $def->carrier_name ?? '';
					$slots[]           = $row;
				}
			}
		}

		// Sort by start_time ascending.
		usort( $slots, function ( $a, $b ) {
			return strcmp( $a->start_time, $b->start_time );
		} );

		// For today (WP timezone): remove slots whose start time has already passed.
		$now_wp = current_datetime();
		if ( $date === $now_wp->format( 'Y-m-d' ) ) {
			$current_hm = $now_wp->format( 'H:i' );
			$slots      = array_values( array_filter( $slots, function ( $slot ) use ( $current_hm ) {
				return substr( $slot->start_time, 0, 5 ) > $current_hm;
			} ) );
		}

		return $slots;
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
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Return active schedule definitions, optionally filtered by carrier.
	 *
	 * @param int[]|null $carrier_ids Carrier IDs to include (plus carrier_id=0), or null for all.
	 * @return array
	 */
	private static function get_active_defs( $carrier_ids = null ) {
		global $wpdb;

		$defs_table     = JWDD_DB::schedule_defs_table();
		$carriers_table = JWDD_DB::carriers_table();

		$carrier_clause = '';
		$params         = array();

		if ( is_array( $carrier_ids ) ) {
			$placeholders   = implode( ',', array_fill( 0, count( $carrier_ids ), '%d' ) );
			$carrier_clause = "AND (d.carrier_id = 0 OR d.carrier_id IN ({$placeholders}))";
			$params         = array_map( 'intval', $carrier_ids );
		}

		$query = "SELECT d.*, c.name AS carrier_name
		          FROM {$defs_table} d
		          LEFT JOIN {$carriers_table} c ON c.id = d.carrier_id
		          WHERE d.is_active = 1
		          {$carrier_clause}
		          ORDER BY d.name ASC";

		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return $wpdb->get_results( $wpdb->prepare( $query, $params ) ) ?: array();
		}

		return $wpdb->get_results( $query ) ?: array(); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
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

		if ( empty( $label ) ) {
			$label = 'From ' . gmdate( 'g:ia', strtotime( $start ) ) . ' to ' . gmdate( 'g:ia', strtotime( $end ) );
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
		$def_id_filter  = isset( $_POST['def_id'] ) ? absint( $_POST['def_id'] ) : 0;

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

		if ( $def_id_filter ) {
			$where[]  = 's.schedule_def_id = %d';
			$params[] = $def_id_filter;
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
	 * AJAX: Return available time slots for a given date (public — used by checkout JS).
	 *
	 * @return void
	 */
	public function ajax_get_time_slots() {
		check_ajax_referer( 'jwdd_checkout_nonce', 'nonce' );

		$date = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';

		if ( empty( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid date.', 'jezpress-woo-delivery-dates' ) ) );
		}

		$carrier_ids = JWDD_Checkout::get_applicable_carrier_ids();
		$slots       = self::get_slots_for_date( $date, $carrier_ids );

		$formatted = array();
		foreach ( $slots as $slot ) {
			$formatted[] = array(
				'id'           => (int) $slot->id,
				'label'        => $slot->label,
				'carrier_name' => $slot->carrier_name,
				'carrier_id'   => (int) $slot->carrier_id,
				'start_time'   => $slot->start_time,
				'end_time'     => $slot->end_time,
			);
		}

		wp_send_json_success( array( 'slots' => $formatted ) );
	}
}
