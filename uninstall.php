<?php
/**
 * Uninstall script for Jezpress Woo Delivery Dates.
 *
 * Removes all plugin data from the database.
 * Note: License key options are intentionally preserved so re-installation
 * does not require re-activation.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Remove plugin options.
delete_option( 'jwdd_settings' );
delete_option( 'jwdd_db_version' );

// Drop custom tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}jwdd_schedules" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}jwdd_carriers" );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Remove any transients.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_jwdd_%'" );          // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_jwdd_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
