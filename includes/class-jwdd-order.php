<?php
/**
 * JWDD Order
 *
 * Displays the selected delivery date and time slot in:
 * - WooCommerce admin order detail page
 * - Order confirmation and notification emails
 * - Customer "My Account > Orders" order detail page
 *
 * @package Jezpress_Woo_Delivery_Dates
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWDD_Order {

	/**
	 * Constructor — registers display hooks.
	 */
	public function __construct() {
		// Admin order page.
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'display_in_admin' ), 10, 1 );

		// Trigger email when checkbox is checked on order save.
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'maybe_send_email' ) );

		// Customer-facing order detail page (My Account).
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'display_in_order_details' ), 10, 1 );

		// Order emails.
		add_action( 'woocommerce_email_after_order_table', array( $this, 'display_in_email' ), 10, 3 );

		// Add delivery date to order meta for display in admin order list columns (optional).
		add_filter( 'woocommerce_shop_order_list_table_columns', array( $this, 'add_order_column' ) );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'render_order_column' ), 10, 2 );
	}

	/**
	 * Get formatted delivery info for an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array|null Array with 'date', 'slot_label', 'carrier_name' or null if not set.
	 */
	private function get_delivery_info( $order ) {
		$date  = $order->get_meta( '_jwdd_delivery_date' );
		$label = $order->get_meta( '_jwdd_time_slot_label' );

		if ( empty( $date ) ) {
			return null;
		}

		$carrier_name = '';
		$carrier_id   = (int) $order->get_meta( '_jwdd_carrier_id' );

		if ( $carrier_id ) {
			$carrier = JWDD_Carriers::get_by_id( $carrier_id );
			if ( $carrier ) {
				$carrier_name = $carrier->name;
			}
		}

		return array(
			'date'         => $date,
			'date_display' => gmdate( 'l, j F Y', strtotime( $date ) ),
			'slot_label'   => $label,
			'carrier_name' => $carrier_name,
		);
	}

	/**
	 * Display delivery info in the admin order detail page.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return void
	 */
	public function display_in_admin( $order ) {
		$info = $this->get_delivery_info( $order );

		if ( ! $info ) {
			return;
		}
		?>
		<div class="jwdd-order-delivery-info" style="margin-top:16px; padding:12px; background:#f8f8f8; border-left:4px solid #0073aa;">
			<h4 style="margin:0 0 10px; font-size:13px; color:#333;"><?php esc_html_e( 'Delivery Details', 'jezpress-woo-delivery-dates' ); ?></h4>
			<?php if ( $info['carrier_name'] ) : ?>
				<p style="margin:0 0 6px;">
					<strong style="display:block;"><?php esc_html_e( 'Carrier:', 'jezpress-woo-delivery-dates' ); ?></strong>
					<?php echo esc_html( $info['carrier_name'] ); ?>
				</p>
			<?php endif; ?>
			<p style="margin:0 0 6px;">
				<strong style="display:block;"><?php esc_html_e( 'Date:', 'jezpress-woo-delivery-dates' ); ?></strong>
				<?php echo esc_html( $info['date_display'] ); ?>
				<?php if ( $info['date'] < current_time( 'Y-m-d' ) ) : ?>
					<span title="<?php esc_attr_e( "You haven't shipped the order in time", 'jezpress-woo-delivery-dates' ); ?>"
						  style="display:inline-flex; align-items:center; justify-content:center; width:15px; height:15px; border-radius:50%; background:#d63638; color:#fff; font-size:10px; font-weight:700; line-height:1; cursor:default; margin-left:5px; vertical-align:middle;"
						  aria-label="<?php esc_attr_e( "You haven't shipped the order in time", 'jezpress-woo-delivery-dates' ); ?>">i</span>
				<?php endif; ?>
			</p>
			<?php if ( $info['slot_label'] ) : ?>
				<p style="margin:0 0 6px;">
					<strong style="display:block;"><?php esc_html_e( 'Time Slot:', 'jezpress-woo-delivery-dates' ); ?></strong>
					<?php echo esc_html( $info['slot_label'] ); ?>
				</p>
			<?php endif; ?>
			<?php $already_shipped = (bool) $order->get_meta( '_jwdd_shipped_to_carrier', true ); ?>
			<p style="margin:12px 0 0;">
				<?php wp_nonce_field( 'jwdd_send_email_' . $order->get_id(), 'jwdd_send_email_nonce' ); ?>
				<label style="display:flex; align-items:flex-start; gap:6px; cursor:<?php echo $already_shipped ? 'default' : 'pointer'; ?>;">
					<input type="checkbox" name="jwdd_send_shipping_email" value="1" style="margin:2px 0 0;"
						<?php checked( $already_shipped ); ?>
						<?php disabled( $already_shipped ); ?>>
					<?php esc_html_e( 'Shipped to Carrier', 'jezpress-woo-delivery-dates' ); ?>
				</label>
			</p>
		</div>
		<?php
	}

	/**
	 * Send the shipping confirmation email if the checkbox was checked on save.
	 *
	 * Hooked on woocommerce_process_shop_order_meta (fires on admin order save
	 * for both legacy posts and HPOS).
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function maybe_send_email( $order_id ) {
		if ( empty( $_POST['jwdd_send_email_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( $_POST['jwdd_send_email_nonce'] ), 'jwdd_send_email_' . $order_id ) ) {
			return;
		}

		if ( empty( $_POST['jwdd_send_shipping_email'] ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_jwdd_shipped_to_carrier', true ) ) {
			return; // Already triggered — do not send again.
		}

		JWDD_Email::send_for_order( $order_id );

		$order->update_meta_data( '_jwdd_shipped_to_carrier', current_time( 'Y-m-d H:i:s' ) );
		$order->save();
	}

	/**
	 * Display delivery info on the customer order detail page.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return void
	 */
	public function display_in_order_details( $order ) {
		$info = $this->get_delivery_info( $order );

		if ( ! $info ) {
			return;
		}
		?>
		<section class="woocommerce-order-details jwdd-order-delivery-section" style="margin-top:24px;">
			<h2 class="woocommerce-column__title"><?php esc_html_e( 'Delivery Details', 'jezpress-woo-delivery-dates' ); ?></h2>
			<table class="woocommerce-table" cellspacing="0">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'Delivery Date', 'jezpress-woo-delivery-dates' ); ?></th>
						<td><?php echo esc_html( $info['date_display'] ); ?></td>
					</tr>
					<?php if ( $info['slot_label'] ) : ?>
						<tr>
							<th><?php esc_html_e( 'Time Slot', 'jezpress-woo-delivery-dates' ); ?></th>
							<td><?php echo esc_html( $info['slot_label'] ); ?></td>
						</tr>
					<?php endif; ?>
					<?php if ( $info['carrier_name'] ) : ?>
						<tr>
							<th><?php esc_html_e( 'Carrier', 'jezpress-woo-delivery-dates' ); ?></th>
							<td><?php echo esc_html( $info['carrier_name'] ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	/**
	 * Display delivery info in WooCommerce order emails.
	 *
	 * @param WC_Order $order         WooCommerce order.
	 * @param bool     $sent_to_admin Whether the email is to admin.
	 * @param bool     $plain_text    Whether the email is plain text.
	 * @return void
	 */
	public function display_in_email( $order, $sent_to_admin, $plain_text ) {
		$info = $this->get_delivery_info( $order );

		if ( ! $info ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Delivery Details', 'jezpress-woo-delivery-dates' ) . "\n";
			echo esc_html__( 'Delivery Date:', 'jezpress-woo-delivery-dates' ) . ' ' . esc_html( $info['date_display'] ) . "\n";
			if ( $info['slot_label'] ) {
				echo esc_html__( 'Time Slot:', 'jezpress-woo-delivery-dates' ) . ' ' . esc_html( $info['slot_label'] ) . "\n";
			}
			if ( $info['carrier_name'] ) {
				echo esc_html__( 'Carrier:', 'jezpress-woo-delivery-dates' ) . ' ' . esc_html( $info['carrier_name'] ) . "\n";
			}
		} else {
			?>
			<h2 style="color:#333; font-size:16px; margin:24px 0 8px;"><?php esc_html_e( 'Delivery Details', 'jezpress-woo-delivery-dates' ); ?></h2>
			<table cellspacing="0" cellpadding="6" style="width:100%; border:1px solid #ddd; margin-bottom:24px;">
				<tr>
					<th style="text-align:left; padding:8px; border-bottom:1px solid #ddd; background:#f8f8f8;"><?php esc_html_e( 'Delivery Date', 'jezpress-woo-delivery-dates' ); ?></th>
					<td style="padding:8px; border-bottom:1px solid #ddd;"><?php echo esc_html( $info['date_display'] ); ?></td>
				</tr>
				<?php if ( $info['slot_label'] ) : ?>
					<tr>
						<th style="text-align:left; padding:8px; border-bottom:1px solid #ddd; background:#f8f8f8;"><?php esc_html_e( 'Time Slot', 'jezpress-woo-delivery-dates' ); ?></th>
						<td style="padding:8px; border-bottom:1px solid #ddd;"><?php echo esc_html( $info['slot_label'] ); ?></td>
					</tr>
				<?php endif; ?>
				<?php if ( $info['carrier_name'] ) : ?>
					<tr>
						<th style="text-align:left; padding:8px; background:#f8f8f8;"><?php esc_html_e( 'Carrier', 'jezpress-woo-delivery-dates' ); ?></th>
						<td style="padding:8px;"><?php echo esc_html( $info['carrier_name'] ); ?></td>
					</tr>
				<?php endif; ?>
			</table>
			<?php
		}
	}

	/**
	 * Add a "Delivery Date" column to the orders list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_order_column( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$new['jwdd_delivery_date'] = __( 'Delivery Date', 'jezpress-woo-delivery-dates' );
			}
		}
		return $new;
	}

	/**
	 * Render the delivery date column value in the orders list.
	 *
	 * @param string   $column   Column name.
	 * @param WC_Order $order    WooCommerce order.
	 * @return void
	 */
	public function render_order_column( $column, $order ) {
		if ( 'jwdd_delivery_date' !== $column ) {
			return;
		}

		$date = $order->get_meta( '_jwdd_delivery_date' );

		if ( ! $date ) {
			echo '—';
			return;
		}

		echo '<span style="white-space:nowrap;">' . esc_html( gmdate( 'd/m/Y', strtotime( $date ) ) ) . '</span>';

		$shipped_at = $order->get_meta( '_jwdd_shipped_to_carrier' );

		if ( ! $shipped_at && $date < current_time( 'Y-m-d' ) ) {
			echo ' <span title="' . esc_attr__( "You haven't shipped the order in time", 'jezpress-woo-delivery-dates' ) . '"'
				. ' style="display:inline-flex; align-items:center; justify-content:center; width:15px; height:15px; border-radius:50%; background:#d63638; color:#fff; font-size:10px; font-weight:700; line-height:1; cursor:default; vertical-align:middle;"'
				. ' aria-label="' . esc_attr__( "You haven't shipped the order in time", 'jezpress-woo-delivery-dates' ) . '">i</span>';
		}
		if ( $shipped_at ) {
			$shipped_formatted = gmdate( 'd/m/Y H:i', strtotime( $shipped_at ) );
			$tooltip           = sprintf(
				/* translators: %s: date and time the order was marked as shipped */
				__( 'Shipped to Carrier - %s', 'jezpress-woo-delivery-dates' ),
				$shipped_formatted
			);
			echo ' <span title="' . esc_attr( $tooltip ) . '" style="display:inline-flex; align-items:center; justify-content:center; width:15px; height:15px; border-radius:50%; background:#00a32a; color:#fff; font-size:10px; font-weight:700; line-height:1; cursor:default; vertical-align:middle;">&#10003;</span>';
		}
	}
}
