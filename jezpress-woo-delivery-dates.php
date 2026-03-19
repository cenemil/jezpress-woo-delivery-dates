<?php
/**
 * Plugin Name: Jezpress WooCommerce Delivery Dates
 * Plugin URI:  https://jezpress.com.au
 * Description: Customer delivery date and time slot selection at WooCommerce checkout, with carrier and schedule management.
 * Version:     1.3.2
 * Author:      Jezpress
 * Author URI:  https://jezpress.com.au
 * Text Domain: jezpress-woo-delivery-dates
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * Tested up to: 6.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'JWDD_VERSION', '1.3.2' );
define( 'JWDD_DIR', plugin_dir_path( __FILE__ ) );
define( 'JWDD_URL', plugin_dir_url( __FILE__ ) );

register_activation_hook( __FILE__, 'jwdd_activate' );
register_deactivation_hook( __FILE__, 'jwdd_deactivate' );
register_uninstall_hook( __FILE__, 'jwdd_uninstall_stub' );

/**
 * Set default options and create DB tables on activation.
 */
function jwdd_activate() {
	if ( ! get_option( 'jwdd_settings' ) ) {
		update_option( 'jwdd_settings', array(
			'enabled'         => 1,
			'required'        => 1,
			'max_future_days' => 30,
			'checkout_label'  => 'Select Delivery Date & Time',
		) );
	}

	require_once plugin_dir_path( __FILE__ ) . 'includes/class-jwdd-db.php';
	JWDD_DB::create_tables();
}

/**
 * Clean up license cron on deactivation.
 */
function jwdd_deactivate() {
	$license = JWDD_License::get_instance();
	if ( $license ) {
		$license->cleanup();
	}
}

/**
 * Stub — actual cleanup is in uninstall.php.
 */
function jwdd_uninstall_stub() {}

// Load the updater and license handler early — NOT gated by plugins_loaded
// because WP cron auto-updates need the update hooks outside admin context.
require_once JWDD_DIR . 'includes/class-jwdd-updater.php';
require_once JWDD_DIR . 'includes/class-jwdd-license.php';

$_jwdd_license = JWDD_License::get_instance( __FILE__, 'jezpress-woo-delivery-dates', 'Jezpress WooCommerce Delivery Dates' );
$_jwdd_lic_key = $_jwdd_license->get_license_key();

$_jwdd_updater = new JWDD_Updater( __FILE__ );
$_jwdd_updater->set_slug( 'jezpress-woo-delivery-dates' )
               ->set_api_url( 'https://updates.jezpress.com' );

if ( ! empty( $_jwdd_lic_key ) ) {
	$_jwdd_updater->set_license( $_jwdd_lic_key );
}
unset( $_jwdd_lic_key );

$_jwdd_updater->initialize();
unset( $_jwdd_updater );

add_action( 'plugins_loaded', 'jwdd_init', 20 );

/**
 * Main plugin bootstrap — runs on plugins_loaded priority 20.
 */
function jwdd_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>Jezpress WooCommerce Delivery Dates</strong> requires WooCommerce to be active.</p></div>';
		} );
		return;
	}

	require_once JWDD_DIR . 'includes/class-jwdd-db.php';
	require_once JWDD_DIR . 'includes/class-jwdd-admin.php';
	require_once JWDD_DIR . 'includes/class-jwdd-carriers.php';
	require_once JWDD_DIR . 'includes/class-jwdd-schedule-defs.php';
	require_once JWDD_DIR . 'includes/class-jwdd-schedules.php';
	require_once JWDD_DIR . 'includes/class-jwdd-holidays.php';
	require_once JWDD_DIR . 'includes/class-jwdd-checkout.php';
	require_once JWDD_DIR . 'includes/class-jwdd-order.php';
	require_once JWDD_DIR . 'includes/class-jwdd-calendar.php';

	// Create or upgrade DB tables whenever the stored version is behind.
	if ( (int) get_option( 'jwdd_db_version' ) < JWDD_DB::DB_VERSION ) {
		JWDD_DB::create_tables();
	}

	// Initialize license — registers admin hooks, notices, and daily cron.
	$license = JWDD_License::get_instance();
	if ( $license ) {
		$license->init();
	}

	JWDD_Admin::get_instance();
	new JWDD_Carriers();
	new JWDD_Schedule_Defs();
	new JWDD_Schedules();
	new JWDD_Holidays();
	new JWDD_Checkout();
	new JWDD_Order();
	new JWDD_Calendar();
}
