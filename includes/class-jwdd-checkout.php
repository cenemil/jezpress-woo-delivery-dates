<?php
/**
 * JWDD Checkout
 *
 * Renders the delivery date + time slot fields on the WooCommerce checkout,
 * validates selection, and saves it to order meta.
 *
 * Note: Designed for WooCommerce classic checkout. WooCommerce Blocks checkout
 * is not supported in v1.0.
 *
 * @package Jezpress_Woo_Delivery_Dates
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWDD_Checkout {

	/**
	 * Constructor — registers hooks.
	 */
	public function __construct() {
		$settings = get_option( 'jwdd_settings', array() );

		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		add_action( 'wp_enqueue_scripts',                        array( $this, 'enqueue_scripts' ) );
		add_action( 'woocommerce_before_order_notes',            array( $this, 'render_fields' ) );
		add_action( 'woocommerce_checkout_process',              array( $this, 'validate_fields' ) );
		add_action( 'woocommerce_checkout_create_order',         array( $this, 'save_to_order' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_created',        array( $this, 'on_order_created' ) );
		add_action( 'wp_ajax_jwdd_get_available_dates',          array( $this, 'ajax_get_available_dates' ) );
		add_action( 'wp_ajax_nopriv_jwdd_get_available_dates',   array( $this, 'ajax_get_available_dates' ) );

		// Decrement booked count when an order is cancelled or refunded.
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'on_order_cancelled' ) );
		add_action( 'woocommerce_order_status_refunded',  array( $this, 'on_order_cancelled' ) );
	}

	/**
	 * Resolve the carrier IDs available to the current customer based on their
	 * WooCommerce shipping zone.
	 *
	 * Returns null  → no zone could be determined; show all schedules.
	 * Returns array → only schedules for these carrier IDs (plus unassigned, carrier_id=0).
	 * Returns []    → zone matched but no carriers cover it; show nothing.
	 *
	 * @return int[]|null
	 */
	public static function get_applicable_carrier_ids() {
		if ( ! class_exists( 'WC_Shipping_Zones' ) || ! WC()->customer ) {
			return null;
		}

		// Prefer the already-calculated shipping packages; fall back to customer address.
		$packages = WC()->shipping() ? WC()->shipping()->get_packages() : array();

		if ( ! empty( $packages ) ) {
			$package = $packages[0];
		} else {
			$package = array(
				'destination' => array(
					'country'   => WC()->customer->get_shipping_country(),
					'state'     => WC()->customer->get_shipping_state(),
					'postcode'  => WC()->customer->get_shipping_postcode(),
					'city'      => WC()->customer->get_shipping_city(),
					'address'   => WC()->customer->get_shipping_address(),
					'address_2' => WC()->customer->get_shipping_address_2(),
				),
			);
		}

		// No destination yet (customer hasn't entered address) — don't filter.
		if ( empty( $package['destination']['country'] ) ) {
			return null;
		}

		$zone    = WC_Shipping_Zones::get_zone_matching_package( $package );
		$zone_id = $zone ? (int) $zone->get_id() : 0;

		$carriers = JWDD_Carriers::get_active();

		// No carriers configured at all — don't filter by carrier.
		if ( empty( $carriers ) ) {
			return null;
		}

		$matched_ids = array();

		foreach ( $carriers as $carrier ) {
			$zones = json_decode( $carrier->shipping_zones, true );

			// Carrier with no zone mapping is available in all zones.
			if ( empty( $zones ) || ! is_array( $zones ) ) {
				$matched_ids[] = (int) $carrier->id;
				continue;
			}

			foreach ( $zones as $z ) {
				if ( (int) ( $z['zone_id'] ?? 0 ) === $zone_id ) {
					$matched_ids[] = (int) $carrier->id;
					break;
				}
			}
		}

		return $matched_ids;
	}

	/**
	 * Enqueue checkout JS and CSS.
	 *
	 * @return void
	 */
	public function enqueue_scripts() {
		if ( ! is_checkout() ) {
			return;
		}

		wp_enqueue_style(
			'jwdd-checkout',
			JWDD_URL . 'assets/css/jwdd-checkout.css',
			array(),
			filemtime( JWDD_DIR . 'assets/css/jwdd-checkout.css' )
		);

		wp_enqueue_script(
			'jwdd-checkout',
			JWDD_URL . 'assets/js/jwdd-checkout.js',
			array( 'jquery', 'jquery-ui-datepicker' ),
			filemtime( JWDD_DIR . 'assets/js/jwdd-checkout.js' ),
			true
		);

		$settings        = get_option( 'jwdd_settings', array() );
		$max_future_days = isset( $settings['max_future_days'] ) ? absint( $settings['max_future_days'] ) : 30;
		$carrier_ids     = self::get_applicable_carrier_ids();
		$available       = JWDD_Schedules::get_available_dates( $carrier_ids );

		$customer    = WC()->customer;
		$has_address = $customer && (
			! empty( $customer->get_shipping_country() ) ||
			! empty( $customer->get_billing_country() )
		);

		wp_localize_script( 'jwdd-checkout', 'jwdd_checkout', array(
			'ajaxurl'         => admin_url( 'admin-ajax.php' ),
			'nonce'           => wp_create_nonce( 'jwdd_checkout_nonce' ),
			'available_dates' => $available,
			'max_future_days' => $max_future_days,
			'has_address'     => $has_address,
			'week_start'      => isset( $settings['week_start'] ) ? (int) $settings['week_start'] : 0,
			'date_format'     => isset( $settings['date_format'] ) ? $settings['date_format'] : 'MM d, yy',
			'i18n'            => array(
				'select_date'      => __( 'Select a date...', 'jezpress-woo-delivery-dates' ),
				'select_slot'      => __( 'Select a time slot...', 'jezpress-woo-delivery-dates' ),
				'loading'          => __( 'Loading time slots...', 'jezpress-woo-delivery-dates' ),
				'loading_dates'    => __( 'Checking available delivery dates…', 'jezpress-woo-delivery-dates' ),
				'address_required' => __( 'Enter your shipping address to see available delivery dates.', 'jezpress-woo-delivery-dates' ),
				'no_slots'         => __( 'No time slots available for this date.', 'jezpress-woo-delivery-dates' ),
				'no_dates'         => __( 'No delivery dates are currently available for your area.', 'jezpress-woo-delivery-dates' ),
				'error'            => __( 'Could not load delivery dates. Please refresh the page.', 'jezpress-woo-delivery-dates' ),
			),
		) );
	}

	/**
	 * Render the delivery date and time slot fields.
	 *
	 * @param WC_Checkout $checkout WooCommerce checkout instance.
	 * @return void
	 */
	public function render_fields( $checkout ) {
		$settings   = get_option( 'jwdd_settings', array() );
		$label      = isset( $settings['checkout_label'] ) ? trim( $settings['checkout_label'] ) : '';
		$date_label = ! empty( $settings['date_label'] ) ? $settings['date_label'] : __( 'Delivery Date', 'jezpress-woo-delivery-dates' );
		$slot_label = ! empty( $settings['slot_label'] ) ? $settings['slot_label'] : __( 'Delivery Time Slot', 'jezpress-woo-delivery-dates' );
		$required   = ! empty( $settings['required'] );

		echo '<div id="jwdd-delivery-dates-wrap" class="jwdd-checkout-section">';
		if ( '' !== $label ) {
			echo '<h3>' . esc_html( $label ) . '</h3>';
		}

		// Status message — JS shows this when address is missing, loading, or no dates.
		echo '<p id="jwdd-date-status" class="jwdd-date-status" style="display:none;"></p>';

		// Date selector — calendar picker (JS shows/hides based on address state).
		echo '<p class="form-row form-row-wide jwdd-date-row" style="display:none;">';
		echo '<label for="jwdd_delivery_date_picker">' . esc_html( $date_label );
		if ( $required ) {
			echo ' <abbr class="required" title="required">*</abbr>';
		}
		echo '</label>';
		echo '<input type="text" id="jwdd_delivery_date_picker" class="input-text jwdd-date-picker" readonly'
			. ' placeholder="' . esc_attr__( 'Select a date...', 'jezpress-woo-delivery-dates' ) . '">';
		echo '<input type="hidden" id="jwdd_delivery_date" name="jwdd_delivery_date">';
		echo '</p>';

		// Time slot selector — populated via AJAX on date selection.
		echo '<p class="form-row form-row-wide jwdd-slot-row" style="display:none;">';
		echo '<label for="jwdd_time_slot_id">' . esc_html( $slot_label );
		if ( $required ) {
			echo ' <abbr class="required" title="required">*</abbr>';
		}
		echo '</label>';
		echo '<select id="jwdd_time_slot_id" name="jwdd_time_slot_id" class="input-text" disabled>';
		echo '<option value="">' . esc_html__( 'Select a date first...', 'jezpress-woo-delivery-dates' ) . '</option>';
		echo '</select>';
		echo '</p>';

		echo '</div>';
	}

	/**
	 * AJAX: Return available delivery dates for the current customer session.
	 *
	 * Called by checkout JS on updated_checkout to refresh dates after an
	 * address or shipping method change.
	 *
	 * @return void
	 */
	public function ajax_get_available_dates() {
		check_ajax_referer( 'jwdd_checkout_nonce', 'nonce' );

		$customer    = WC()->customer;
		$has_address = $customer && (
			! empty( $customer->get_shipping_country() ) ||
			! empty( $customer->get_billing_country() )
		);

		$carrier_ids = self::get_applicable_carrier_ids();
		$dates       = JWDD_Schedules::get_available_dates( $carrier_ids );

		wp_send_json_success( array(
			'dates'       => $dates,
			'has_address' => $has_address,
		) );
	}

	/**
	 * Validate the delivery date and time slot fields on checkout submit.
	 *
	 * @return void
	 */
	public function validate_fields() {
		$settings = get_option( 'jwdd_settings', array() );

		if ( empty( $settings['required'] ) ) {
			return;
		}

		$date    = isset( $_POST['jwdd_delivery_date'] ) ? sanitize_text_field( wp_unslash( $_POST['jwdd_delivery_date'] ) ) : '';
		$slot_id = isset( $_POST['jwdd_time_slot_id'] ) ? absint( $_POST['jwdd_time_slot_id'] ) : 0;

		if ( empty( $date ) ) {
			wc_add_notice( __( 'Please select a delivery date.', 'jezpress-woo-delivery-dates' ), 'error' );
			return;
		}

		// Check available slots for the selected date.
		$slots = JWDD_Schedules::get_slots_for_date( $date, self::get_applicable_carrier_ids() );

		// Time slot is only required when slots actually exist for the date.
		if ( ! empty( $slots ) ) {
			if ( ! $slot_id ) {
				wc_add_notice( __( 'Please select a delivery time slot.', 'jezpress-woo-delivery-dates' ), 'error' );
				return;
			}

			// Verify the selected slot is still available (race condition protection).
			$valid = false;
			foreach ( $slots as $slot ) {
				if ( (int) $slot->id === $slot_id ) {
					$valid = true;
					break;
				}
			}

			if ( ! $valid ) {
				wc_add_notice( __( 'The selected delivery time slot is no longer available. Please choose another.', 'jezpress-woo-delivery-dates' ), 'error' );
			}
		}
	}

	/**
	 * Save delivery date and time slot to order meta.
	 *
	 * @param WC_Order    $order    WooCommerce order.
	 * @param WC_Checkout $checkout Checkout instance.
	 * @return void
	 */
	public function save_to_order( $order, $checkout ) {
		$date       = isset( $_POST['jwdd_delivery_date'] ) ? sanitize_text_field( wp_unslash( $_POST['jwdd_delivery_date'] ) ) : '';
		$slot_id    = isset( $_POST['jwdd_time_slot_id'] ) ? absint( $_POST['jwdd_time_slot_id'] ) : 0;
		$carrier_id = isset( $_POST['jwdd_carrier_id'] ) ? absint( $_POST['jwdd_carrier_id'] ) : 0;

		if ( $date ) {
			$order->update_meta_data( '_jwdd_delivery_date', $date );
		}

		if ( $slot_id ) {
			$order->update_meta_data( '_jwdd_time_slot_id', $slot_id );

			// Also cache the slot label/time for display without DB lookup.
			global $wpdb;
			$slot = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'SELECT * FROM ' . JWDD_DB::schedules_table() . ' WHERE id = %d',
				$slot_id
			) );

			if ( $slot ) {
				$order->update_meta_data( '_jwdd_time_slot_label', $slot->label );
				$order->update_meta_data( '_jwdd_carrier_id', (int) $slot->carrier_id );
			}
		}

		if ( $carrier_id && ! $order->get_meta( '_jwdd_carrier_id' ) ) {
			$order->update_meta_data( '_jwdd_carrier_id', $carrier_id );
		}
	}

	/**
	 * Increment the booked count after order is created.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return void
	 */
	public function on_order_created( $order ) {
		$slot_id = (int) $order->get_meta( '_jwdd_time_slot_id' );

		if ( $slot_id ) {
			JWDD_Schedules::increment_booked( $slot_id );
		}
	}

	/**
	 * Decrement the booked count when an order is cancelled or refunded.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function on_order_cancelled( $order_id ) {
		$order   = wc_get_order( $order_id );
		$slot_id = $order ? (int) $order->get_meta( '_jwdd_time_slot_id' ) : 0;

		if ( $slot_id ) {
			JWDD_Schedules::decrement_booked( $slot_id );
		}
	}
}
