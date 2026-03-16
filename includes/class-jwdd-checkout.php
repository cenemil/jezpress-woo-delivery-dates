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

		add_action( 'wp_enqueue_scripts',                array( $this, 'enqueue_scripts' ) );
		add_action( 'woocommerce_before_order_notes',    array( $this, 'render_fields' ) );
		add_action( 'woocommerce_checkout_process',      array( $this, 'validate_fields' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_to_order' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'on_order_created' ) );

		// Decrement booked count when an order is cancelled or refunded.
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'on_order_cancelled' ) );
		add_action( 'woocommerce_order_status_refunded',  array( $this, 'on_order_cancelled' ) );
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
			JWDD_VERSION
		);

		wp_enqueue_script(
			'jwdd-checkout',
			JWDD_URL . 'assets/js/jwdd-checkout.js',
			array( 'jquery' ),
			JWDD_VERSION,
			true
		);

		$settings     = get_option( 'jwdd_settings', array() );
		$available    = JWDD_Schedules::get_available_dates();
		$show_carrier = ! empty( $settings['show_carrier'] );

		wp_localize_script( 'jwdd-checkout', 'jwdd_checkout', array(
			'ajaxurl'        => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( 'jwdd_checkout_nonce' ),
			'available_dates' => $available,
			'show_carrier'   => $show_carrier,
			'i18n'           => array(
				'select_date'      => __( 'Select a date...', 'jezpress-woo-delivery-dates' ),
				'select_slot'      => __( 'Select a time slot...', 'jezpress-woo-delivery-dates' ),
				'loading'          => __( 'Loading time slots...', 'jezpress-woo-delivery-dates' ),
				'no_slots'         => __( 'No time slots available for this date.', 'jezpress-woo-delivery-dates' ),
				'error'            => __( 'Could not load time slots. Please refresh the page.', 'jezpress-woo-delivery-dates' ),
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
		$settings      = get_option( 'jwdd_settings', array() );
		$label         = ! empty( $settings['checkout_label'] ) ? $settings['checkout_label'] : __( 'Select Delivery Date & Time', 'jezpress-woo-delivery-dates' );
		$required      = ! empty( $settings['required'] );
		$show_carrier  = ! empty( $settings['show_carrier'] );
		$available     = JWDD_Schedules::get_available_dates();
		$carriers      = JWDD_Carriers::get_active();

		echo '<div id="jwdd-delivery-dates-wrap" class="jwdd-checkout-section">';
		echo '<h3>' . esc_html( $label ) . '</h3>';

		if ( empty( $available ) ) {
			echo '<p class="jwdd-no-dates">' . esc_html__( 'No delivery dates are currently available. Please contact us.', 'jezpress-woo-delivery-dates' ) . '</p>';
			echo '</div>';
			return;
		}

		// Carrier selector (optional).
		if ( $show_carrier && ! empty( $carriers ) ) {
			echo '<p class="form-row form-row-wide">';
			echo '<label for="jwdd_carrier_id">' . esc_html__( 'Delivery Carrier', 'jezpress-woo-delivery-dates' );
			if ( $required ) {
				echo ' <abbr class="required" title="required">*</abbr>';
			}
			echo '</label>';
			echo '<select id="jwdd_carrier_id" name="jwdd_carrier_id" class="input-text">';
			echo '<option value="">' . esc_html__( 'Select a carrier...', 'jezpress-woo-delivery-dates' ) . '</option>';
			foreach ( $carriers as $carrier ) {
				echo '<option value="' . esc_attr( $carrier->id ) . '">' . esc_html( $carrier->name ) . '</option>';
			}
			echo '</select>';
			echo '</p>';
		}

		// Date selector.
		echo '<p class="form-row form-row-wide">';
		echo '<label for="jwdd_delivery_date">' . esc_html__( 'Delivery Date', 'jezpress-woo-delivery-dates' );
		if ( $required ) {
			echo ' <abbr class="required" title="required">*</abbr>';
		}
		echo '</label>';
		echo '<select id="jwdd_delivery_date" name="jwdd_delivery_date" class="input-text">';
		echo '<option value="">' . esc_html__( 'Select a date...', 'jezpress-woo-delivery-dates' ) . '</option>';
		foreach ( $available as $date ) {
			$display = gmdate( 'l, j F Y', strtotime( $date ) );
			echo '<option value="' . esc_attr( $date ) . '">' . esc_html( $display ) . '</option>';
		}
		echo '</select>';
		echo '</p>';

		// Time slot selector (populated via AJAX).
		echo '<p class="form-row form-row-wide">';
		echo '<label for="jwdd_time_slot_id">' . esc_html__( 'Delivery Time Slot', 'jezpress-woo-delivery-dates' );
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

		if ( ! $slot_id ) {
			wc_add_notice( __( 'Please select a delivery time slot.', 'jezpress-woo-delivery-dates' ), 'error' );
			return;
		}

		// Verify the selected slot is still available (race condition protection).
		$slots = JWDD_Schedules::get_slots_for_date( $date );
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
