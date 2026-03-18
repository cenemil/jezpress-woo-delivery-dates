<?php
/**
 * JWDD Calendar
 *
 * Handles the admin Calendar tab AJAX endpoint — returns WooCommerce orders
 * grouped by delivery date for a requested date window and view type.
 *
 * @package Jezpress_Woo_Delivery_Dates
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWDD_Calendar {

	/**
	 * Constructor — registers AJAX hook.
	 */
	public function __construct() {
		add_action( 'wp_ajax_jwdd_get_calendar_orders', array( $this, 'ajax_get_calendar_orders' ) );
	}

	/**
	 * AJAX handler: return orders grouped by delivery date.
	 *
	 * Expects POST fields:
	 *   nonce       — jwdd_admin_nonce
	 *   view        — month | week | day
	 *   date        — Y-m-d anchor date
	 *   week_start  — 0 (Sun) or 1 (Mon)
	 *
	 * @return void
	 */
	public function ajax_get_calendar_orders() {
		check_ajax_referer( 'jwdd_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezpress-woo-delivery-dates' ) ) );
		}

		$view       = isset( $_POST['view'] )       ? sanitize_key( $_POST['view'] )              : 'month';
		$date       = isset( $_POST['date'] )       ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : current_time( 'Y-m-d' );
		$week_start = isset( $_POST['week_start'] ) ? (int) $_POST['week_start']                   : 1;

		// Validate view.
		if ( ! in_array( $view, array( 'month', 'week', 'day' ), true ) ) {
			$view = 'month';
		}

		$range    = self::date_range( $view, $date, $week_start );
		$orders   = self::get_orders_for_range( $range['start'], $range['end'] );
		$holidays = self::get_holidays_for_range( $range['start'], $range['end'] );

		wp_send_json_success( array(
			'view'         => $view,
			'range_start'  => $range['start'],
			'range_end'    => $range['end'],
			'period_label' => $range['label'],
			'prev_date'    => $range['prev'],
			'next_date'    => $range['next'],
			'today'        => current_time( 'Y-m-d' ),
			'week_start'   => $week_start,
			'orders'       => $orders,
			'holidays'     => $holidays,
		) );
	}

	/**
	 * Calculate the date range for a given view and anchor date.
	 *
	 * @param string $view        month | week | day
	 * @param string $date        Y-m-d anchor date.
	 * @param int    $week_start  0 = Sunday, 1 = Monday.
	 * @return array { start, end, label, prev, next }
	 */
	public static function date_range( $view, $date, $week_start = 1 ) {
		$ts = strtotime( $date );
		if ( ! $ts ) {
			$ts = (int) current_time( 'timestamp' );
		}

		if ( 'day' === $view ) {
			$start = gmdate( 'Y-m-d', $ts );
			$end   = $start;
			$label = date_i18n( 'l, j F Y', $ts );
			$prev  = gmdate( 'Y-m-d', strtotime( '-1 day', $ts ) );
			$next  = gmdate( 'Y-m-d', strtotime( '+1 day', $ts ) );

		} elseif ( 'week' === $view ) {
			// Shift anchor to the start of the week.
			$dow      = (int) gmdate( 'w', $ts ); // 0 = Sun, 6 = Sat
			$diff     = ( $dow - $week_start + 7 ) % 7;
			$start_ts = strtotime( '-' . $diff . ' days', $ts );
			$end_ts   = strtotime( '+' . ( 6 - $diff ) . ' days', $ts );
			$start    = gmdate( 'Y-m-d', $start_ts );
			$end      = gmdate( 'Y-m-d', $end_ts );
			$label    = date_i18n( 'j M', $start_ts ) . ' – ' . date_i18n( 'j M Y', $end_ts );
			$prev     = gmdate( 'Y-m-d', strtotime( '-7 days', $start_ts ) );
			$next     = gmdate( 'Y-m-d', strtotime( '+7 days', $start_ts ) );

		} else {
			// month
			$start = gmdate( 'Y-m-01', $ts );
			$end   = gmdate( 'Y-m-t', $ts );
			$label = date_i18n( 'F Y', $ts );
			$prev  = gmdate( 'Y-m-01', strtotime( '-1 month', $ts ) );
			$next  = gmdate( 'Y-m-01', strtotime( '+1 month', $ts ) );
		}

		return compact( 'start', 'end', 'label', 'prev', 'next' );
	}

	/**
	 * Query all WooCommerce orders that have a delivery date within the given range.
	 *
	 * @param string $start Y-m-d
	 * @param string $end   Y-m-d
	 * @return array Keyed by date string, each value is an array of order data arrays.
	 */
	public static function get_orders_for_range( $start, $end ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$orders = wc_get_orders( array(
			'limit'      => -1,
			'meta_query' => array(
				array(
					'key'     => '_jwdd_delivery_date',
					'value'   => array( $start, $end ),
					'compare' => 'BETWEEN',
					'type'    => 'CHAR',
				),
			),
		) );

		$grouped = array();

		foreach ( $orders as $order ) {
			$delivery_date = $order->get_meta( '_jwdd_delivery_date' );
			if ( ! $delivery_date ) {
				continue;
			}

			$status     = $order->get_status();
			$slot_label = $order->get_meta( '_jwdd_time_slot_label' );

			// HPOS-compatible edit URL.
			if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
				&& method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' )
				&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
			) {
				$edit_url = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order->get_id() );
			} else {
				$edit_url = admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' );
			}

			if ( ! isset( $grouped[ $delivery_date ] ) ) {
				$grouped[ $delivery_date ] = array();
			}

			$grouped[ $delivery_date ][] = array(
				'id'           => $order->get_id(),
				'number'       => $order->get_order_number(),
				'status'       => $status,
				'status_label' => wc_get_order_status_name( $status ),
				'slot_label'   => $slot_label ?: '',
				'edit_url'     => $edit_url,
			);
		}

		// Sort each day's orders by slot_label for consistent display.
		foreach ( $grouped as &$day_orders ) {
			usort( $day_orders, function ( $a, $b ) {
				return strcmp( $a['slot_label'], $b['slot_label'] );
			} );
		}
		unset( $day_orders );

		return $grouped;
	}

	/**
	 * Return active holidays that overlap the given date range, expanded into
	 * individual dates.
	 *
	 * Each date maps to an array of holiday objects:
	 *   [ 'id', 'name', 'carriers' (human-readable string), 'edit_url' ]
	 *
	 * @param string $start Y-m-d
	 * @param string $end   Y-m-d
	 * @return array Keyed by date string.
	 */
	public static function get_holidays_for_range( $start, $end ) {
		if ( ! class_exists( 'JWDD_DB' ) ) {
			return array();
		}

		global $wpdb;

		$table = JWDD_DB::holidays_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE is_active = 1 AND date_from <= %s AND date_to >= %s ORDER BY date_from ASC, name ASC",
			$end,
			$start
		) );

		if ( empty( $rows ) ) {
			return array();
		}

		// Build a carrier name lookup.
		$carrier_map = array();
		if ( class_exists( 'JWDD_Carriers' ) ) {
			foreach ( JWDD_Carriers::get_all() as $c ) {
				$carrier_map[ (int) $c->id ] = $c->name;
			}
		}

		$grouped = array();

		foreach ( $rows as $row ) {
			$cids = JWDD_Holidays::decode_carrier_ids( $row->carrier_ids );

			if ( empty( $cids ) ) {
				$carriers_label = __( 'All Carriers', 'jezpress-woo-delivery-dates' );
			} else {
				$names = array();
				foreach ( $cids as $cid ) {
					$names[] = isset( $carrier_map[ $cid ] ) ? $carrier_map[ $cid ] : sprintf( 'Carrier #%d', $cid );
				}
				$carriers_label = implode( ', ', $names );
			}

			// Clamp expansion to the requested window.
			$from_str = $row->date_from > $start ? $row->date_from : $start;
			$to_str   = $row->date_to < $end     ? $row->date_to   : $end;

			$current = new \DateTime( $from_str );
			$end_dt  = new \DateTime( $to_str );

			while ( $current <= $end_dt ) {
				$date_str = $current->format( 'Y-m-d' );

				if ( ! isset( $grouped[ $date_str ] ) ) {
					$grouped[ $date_str ] = array();
				}

				$grouped[ $date_str ][] = array(
					'id'       => (int) $row->id,
					'name'     => $row->name,
					'carriers' => $carriers_label,
					'edit_url' => admin_url( 'admin.php?page=jwdd-delivery-dates&tab=holidays&action=edit&holiday_id=' . (int) $row->id ),
				);

				$current->modify( '+1 day' );
			}
		}

		return $grouped;
	}
}
