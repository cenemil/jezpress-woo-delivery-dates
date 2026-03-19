<?php
/**
 * JWDD Email
 *
 * Sends the shipping confirmation email to the customer. Triggered manually
 * from the admin order edit screen via the "Send shipping confirmation email"
 * checkbox when the order is saved.
 *
 * Variables supported in subject and content:
 *   {order_id}        — order number
 *   {customer_name}   — customer billing first name
 *   {delivery_date}   — formatted delivery date
 *   {site_title}      — site name
 *
 * @package Jezpress_Woo_Delivery_Dates
 * @since   1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWDD_Email {

	/**
	 * Send the shipping confirmation email for an order.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return bool True on success, false if skipped.
	 */
	public static function send_for_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		$delivery_date = $order->get_meta( '_jwdd_delivery_date', true );
		if ( empty( $delivery_date ) ) {
			return false;
		}

		$customer_email = $order->get_billing_email();
		if ( empty( $customer_email ) ) {
			return false;
		}

		$es       = get_option( 'jwdd_email_settings', array() );
		$settings = get_option( 'jwdd_settings', array() );

		// Build variable map.
		$date_format    = isset( $settings['date_format'] ) ? $settings['date_format'] : 'MM d, yy';
		$formatted_date = self::format_delivery_date( $delivery_date, $date_format );

		$vars = array(
			'{order_id}'      => $order->get_order_number(),
			'{customer_name}' => $order->get_billing_first_name(),
			'{delivery_date}' => $formatted_date,
			'{site_title}'    => get_option( 'blogname' ),
		);

		// Resolve subject.
		$raw_subject = ! empty( $es['subject'] )
			? $es['subject']
			: __( 'Your delivery for order #{order_id} is confirmed', 'jezpress-woo-delivery-dates' );
		$subject = str_replace( array_keys( $vars ), array_values( $vars ), $raw_subject );

		// Resolve content.
		$raw_content = ! empty( $es['content'] )
			? $es['content']
			: JWDD_Admin::default_email_content();
		$body_text = str_replace( array_keys( $vars ), array_values( $vars ), $raw_content );

		// Sender name from settings; address always from WooCommerce.
		$sender_name    = ! empty( $es['sender_name'] ) ? $es['sender_name'] : get_option( 'woocommerce_email_from_name', get_option( 'blogname' ) );
		$sender_address = get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $sender_name . ' <' . $sender_address . '>',
		);

		$html = self::wrap_in_wc_template( $subject, $body_text );

		return wp_mail( $customer_email, $subject, $html, $headers );
	}

	/**
	 * Wrap content in the WooCommerce email header/footer.
	 *
	 * @param string $heading Email heading shown inside the WC header.
	 * @param string $content Plain text body.
	 * @return string Full HTML email.
	 */
	private static function wrap_in_wc_template( $heading, $content ) {
		// Ensure WC email styles are loaded.
		WC()->mailer();

		ob_start();
		wc_get_template( 'emails/email-header.php', array( 'email_heading' => $heading ) );
		// Remove the cellpadding="20" WC adds on the inner content table,
		// and ensure content inherits WC's body font styles via #body_content_inner.
		echo '<style type="text/css">#body_content > table td { padding: 0 !important; }</style>';
		echo wp_kses_post( wpautop( wptexturize( $content ) ) );
		wc_get_template( 'emails/email-footer.php' );
		return ob_get_clean();
	}

	/**
	 * Convert a Y-m-d delivery date to a display string matching the configured
	 * datepicker format as closely as possible using PHP date().
	 *
	 * @param string $ymd       Date in Y-m-d format.
	 * @param string $jq_format jQuery UI datepicker format string.
	 * @return string Formatted date.
	 */
	private static function format_delivery_date( $ymd, $jq_format ) {
		$ts = strtotime( $ymd );
		if ( false === $ts ) {
			return $ymd;
		}

		$map = array(
			'MM d, yy' => 'F j, Y',
			'yy-mm-dd' => 'Y-m-d',
			'mm/dd/yy' => 'm/d/Y',
			'dd/mm/yy' => 'd/m/Y',
		);

		$php_format = isset( $map[ $jq_format ] ) ? $map[ $jq_format ] : 'F j, Y';

		return wp_date( $php_format, $ts );
	}
}
