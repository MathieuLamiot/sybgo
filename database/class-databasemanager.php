<?php
/**
 * DatabaseManager class file.
 *
 * This file defines the DatabaseManager class, responsible for managing database interactions.
 *
 * @package Rocket\Sybgo\Database
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Rocket\Sybgo\Database;

/**
 * DatabaseManager class.
 *
 * This class provides methods for managing database interactions for the Sybgo plugin.
 * Creates and manages three tables: events, reports, and email_log.
 *
 * @package Rocket\Sybgo\Database
 * @since   1.0.0
 */
class DatabaseManager {
	/**
	 * Table name for storing events.
	 *
	 * @var string $events_table The name of the events database table.
	 * @since 1.0.0
	 */
	private string $events_table = '';

	/**
	 * Table name for storing reports.
	 *
	 * @var string $reports_table The name of the reports database table.
	 * @since 1.0.0
	 */
	private string $reports_table = '';

	/**
	 * Table name for storing email logs.
	 *
	 * @var string $email_log_table The name of the email log database table.
	 * @since 1.0.0
	 */
	private string $email_log_table = '';

	/**
	 * Constructor for the DatabaseManager class.
	 *
	 * This method initializes the database manager and sets up all required tables.
	 * Also handles migration from old crawling_results table.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		global $wpdb;

		// Set table names.
		$this->events_table    = $wpdb->prefix . 'sybgo_events';
		$this->reports_table   = $wpdb->prefix . 'sybgo_reports';
		$this->email_log_table = $wpdb->prefix . 'sybgo_email_log';

		// Create tables.
		$this->create_tables();

		// Run migration.
		$this->migrate_from_old_schema();
	}

	/**
	 * Create all database tables for Sybgo plugin.
	 *
	 * Creates events, reports, and email_log tables using dbDelta.
	 *
	 * @since 1.0.0
	 */
	private function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Events table - fully generic structure.
		$events_sql = "CREATE TABLE {$this->events_table} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			event_type VARCHAR(50) NOT NULL,
			event_data LONGTEXT DEFAULT NULL,
			event_timestamp DATETIME NOT NULL,
			report_id BIGINT UNSIGNED DEFAULT NULL,
			source_plugin VARCHAR(100) DEFAULT 'core',
			INDEX idx_event_type (event_type),
			INDEX idx_report_id (report_id),
			INDEX idx_timestamp (event_timestamp),
			INDEX idx_source (source_plugin)
		) $charset_collate;";

		// Reports table.
		$reports_sql = "CREATE TABLE {$this->reports_table} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			report_type VARCHAR(20) NOT NULL DEFAULT 'weekly',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			period_start DATETIME NOT NULL,
			period_end DATETIME DEFAULT NULL,
			event_count INT UNSIGNED DEFAULT 0,
			summary_data LONGTEXT DEFAULT NULL,
			frozen_at DATETIME DEFAULT NULL,
			emailed_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL,
			INDEX idx_status (status),
			INDEX idx_period (period_start, period_end)
		) $charset_collate;";

		// Email log table.
		$email_log_sql = "CREATE TABLE {$this->email_log_table} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			report_id BIGINT UNSIGNED NOT NULL,
			recipient_email VARCHAR(255) NOT NULL,
			sent_at DATETIME NOT NULL,
			status VARCHAR(20) NOT NULL,
			error_message TEXT DEFAULT NULL,
			INDEX idx_report_id (report_id),
			INDEX idx_status (status)
		) $charset_collate;";

		// Execute table creation.
		dbDelta( $events_sql );
		dbDelta( $reports_sql );
		dbDelta( $email_log_sql );
	}

	/**
	 * Migrate from old crawling_results table to new schema.
	 *
	 * Deletes the old table if it exists.
	 *
	 * @since 1.0.0
	 */
	private function migrate_from_old_schema(): void {
		global $wpdb;

		$old_table = $wpdb->prefix . 'crawling_results';

		// Check if old table exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) );

		if ( $table_exists === $old_table ) {
			// Drop old table.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS $old_table" );
		}
	}

	/**
	 * Get table names.
	 *
	 * @return array Array of table names.
	 * @since 1.0.0
	 */
	public function get_table_names(): array {
		return array(
			'events'    => $this->events_table,
			'reports'   => $this->reports_table,
			'email_log' => $this->email_log_table,
		);
	}

	/**
	 * Cleanup old events (older than 1 year).
	 *
	 * This should be called by a daily cron job.
	 *
	 * @return int Number of events deleted.
	 * @since 1.0.0
	 */
	public function cleanup_old_events(): int {
		global $wpdb;

		$one_year_ago = gmdate( 'Y-m-d H:i:s', strtotime( '-1 year' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->events_table} WHERE event_timestamp < %s",
				$one_year_ago
			)
		);

		// Clear any cached data.
		wp_cache_delete( 'sybgo_events', 'sybgo_cache' );

		return (int) $deleted;
	}
}
