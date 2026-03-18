<?php
/**
 * JWDD Database Manager
 *
 * Creates and manages the custom database tables for carriers and schedules.
 *
 * @package Jezpress_Woo_Delivery_Dates
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWDD_DB {

	/**
	 * Current DB schema version. Increment when table structure changes.
	 */
	const DB_VERSION = 6;

	/**
	 * Create or upgrade custom tables. Safe to call repeatedly (uses dbDelta).
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		// Delivery carriers.
		$sql_carriers = "CREATE TABLE {$wpdb->prefix}jwdd_carriers (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name varchar(100) NOT NULL DEFAULT '',
			code varchar(50) NOT NULL DEFAULT '',
			description text NOT NULL DEFAULT '',
			is_active tinyint(1) NOT NULL DEFAULT 1,
			sort_order int(11) NOT NULL DEFAULT 0,
			shipping_zones text NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY (id),
			KEY code (code),
			KEY is_active (is_active)
		) $charset_collate;";

		// Delivery schedules (specific date + time slot entries).
		$sql_schedules = "CREATE TABLE {$wpdb->prefix}jwdd_schedules (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			carrier_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			schedule_def_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			schedule_date date NOT NULL,
			start_time time NOT NULL,
			end_time time NOT NULL,
			label varchar(100) NOT NULL DEFAULT '',
			max_orders int(11) NOT NULL DEFAULT 0,
			booked_count int(11) NOT NULL DEFAULT 0,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY (id),
			KEY carrier_id (carrier_id),
			KEY schedule_def_id (schedule_def_id),
			KEY schedule_date (schedule_date),
			KEY is_active (is_active)
		) $charset_collate;";

		// Schedule definitions (named recurring delivery patterns).
		$sql_schedule_defs = "CREATE TABLE {$wpdb->prefix}jwdd_schedule_defs (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name varchar(100) NOT NULL DEFAULT '',
			carrier_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			days_of_week text NOT NULL,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY (id),
			KEY is_active (is_active)
		) $charset_collate;";

		// Holidays (named date ranges that block delivery).
		// carrier_ids: JSON array of carrier IDs. Empty array = applies to all carriers.
		$sql_holidays = "CREATE TABLE {$wpdb->prefix}jwdd_holidays (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name varchar(100) NOT NULL DEFAULT '',
			carrier_ids text NOT NULL,
			date_from date NOT NULL,
			date_to date NOT NULL,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY (id),
			KEY date_from (date_from),
			KEY date_to (date_to),
			KEY is_active (is_active)
		) $charset_collate;";

		dbDelta( $sql_carriers );
		dbDelta( $sql_schedules );
		dbDelta( $sql_schedule_defs );
		dbDelta( $sql_holidays );

		update_option( 'jwdd_db_version', self::DB_VERSION );
	}

	/**
	 * Get the carriers table name.
	 *
	 * @return string
	 */
	public static function carriers_table() {
		global $wpdb;
		return $wpdb->prefix . 'jwdd_carriers';
	}

	/**
	 * Get the schedules table name.
	 *
	 * @return string
	 */
	public static function schedules_table() {
		global $wpdb;
		return $wpdb->prefix . 'jwdd_schedules';
	}

	/**
	 * Get the schedule definitions table name.
	 *
	 * @return string
	 */
	public static function schedule_defs_table() {
		global $wpdb;
		return $wpdb->prefix . 'jwdd_schedule_defs';
	}

	/**
	 * Get the holidays table name.
	 *
	 * @return string
	 */
	public static function holidays_table() {
		global $wpdb;
		return $wpdb->prefix . 'jwdd_holidays';
	}
}
