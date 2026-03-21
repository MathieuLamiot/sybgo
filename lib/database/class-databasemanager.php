<?php
/**
 * DatabaseManager class file.
 *
 * This file defines the DatabaseManager class, responsible for managing database interactions.
 *
 * @package Sybgo\Database
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Sybgo\Database;

/**
 * DatabaseManager class.
 *
 * This class provides methods for managing database interactions for the Sybgo plugin.
 * Creates and manages four tables: events, reports, email_log, and aggregated_events.
 *
 * @package Sybgo\Database
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
	 * Table name for storing aggregated event counts.
	 *
	 * @var string $aggregated_events_table The name of the aggregated events database table.
	 * @since 1.0.0
	 */
	private string $aggregated_events_table = '';

	/**
	 * Constructor for the DatabaseManager class.
	 *
	 * Initializes table names only. Call maybe_create_tables() explicitly
	 * when table creation is needed (e.g. on activation or first run).
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->init_table_names();
	}

	/**
	 * Initialize table name properties from the WordPress prefix.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function init_table_names(): void {
		$table_names                   = $this->get_table_names();
		$this->events_table            = $table_names['events'];
		$this->reports_table           = $table_names['reports'];
		$this->email_log_table         = $table_names['email_log'];
		$this->aggregated_events_table = $table_names['aggregated_events'];
	}

	/**
	 * Get all table names owned by the plugin.
	 *
	 * Single source of truth for plugin table names. Safe to call without
	 * triggering any table creation (e.g. during uninstall).
	 *
	 * @return array<string, string> Table names keyed by identifier.
	 * @since 1.0.0
	 */
	public function get_table_names(): array {
		global $wpdb;

		return array(
			'events'            => $wpdb->prefix . 'sybgo_events',
			'reports'           => $wpdb->prefix . 'sybgo_reports',
			'email_log'         => $wpdb->prefix . 'sybgo_email_log',
			'aggregated_events' => $wpdb->prefix . 'sybgo_aggregated_events',
		);
	}

	/**
	 * Create or upgrade all plugin database tables, and run migrations.
	 *
	 * Must be called explicitly on plugin activation and on init (to handle
	 * schema upgrades). Safe to call multiple times — dbDelta is idempotent.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function maybe_create_tables(): void {
		$this->create_tables();
		$this->migrate_from_old_schema();
		$this->migrate_aggregated_events_is_assigned();
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

		// Aggregated events table - stores daily accumulated values per event type and dimension set.
		// dimensions_hash is a MySQL generated column (SHA2 of the dimensions JSON blob) used in
		// the UNIQUE KEY because LONGTEXT columns cannot be indexed directly.
		// Empty dimensions are encoded as '{}' (not NULL) to produce a stable hash for global rows.
		// is_assigned is 0 for rows belonging to the current unassigned period, 1 after freeze.
		// It is part of the UNIQUE KEY so that a post-freeze upsert for the same signature creates
		// a fresh row (is_assigned=0) rather than incrementing the already-frozen row (is_assigned=1).
		$aggregated_events_sql = "CREATE TABLE {$this->aggregated_events_table} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			event_type VARCHAR(100) NOT NULL,
			dimensions LONGTEXT DEFAULT NULL,
			dimensions_hash VARCHAR(64) GENERATED ALWAYS AS (SHA2(dimensions, 256)) STORED,
			value DECIMAL(20,4) NOT NULL DEFAULT 0,
			report_id BIGINT UNSIGNED DEFAULT NULL,
			is_assigned TINYINT(1) NOT NULL DEFAULT 0,
			date DATE NOT NULL,
			meta LONGTEXT DEFAULT NULL,
			UNIQUE KEY uq_event_dim_date (event_type, dimensions_hash, date, is_assigned),
			INDEX idx_report_id (report_id),
			INDEX idx_date (date),
			INDEX idx_event_type (event_type)
		) $charset_collate;";

		// Execute table creation.
		dbDelta( $events_sql );
		dbDelta( $reports_sql );
		dbDelta( $email_log_sql );
		dbDelta( $aggregated_events_sql );
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
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) );

		if ( $table_exists === $old_table ) {
			// Drop old table.
			$wpdb->query( "DROP TABLE IF EXISTS $old_table" );
		}
	}

	/**
	 * Add the is_assigned column and update the UNIQUE KEY on wp_sybgo_aggregated_events.
	 *
	 * DbDelta cannot drop or rename an existing unique key, so this migration runs an
	 * explicit ALTER TABLE. It is guarded by checking whether the is_assigned column already
	 * exists, making it safe to call multiple times.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function migrate_aggregated_events_is_assigned(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$col = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM {$this->aggregated_events_table} LIKE %s", 'is_assigned' ) );
		if ( ! empty( $col ) ) {
			return; // Already migrated.
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"ALTER TABLE {$this->aggregated_events_table}
			 ADD COLUMN is_assigned TINYINT(1) NOT NULL DEFAULT 0,
			 DROP INDEX uq_event_dim_date,
			 ADD UNIQUE KEY uq_event_dim_date (event_type, dimensions_hash, date, is_assigned)"
		);
	}

	/**
	 * Drop a single database table.
	 *
	 * @param string $table Fully-qualified table name (including prefix).
	 * @return void
	 * @since 1.0.0
	 */
	public function drop_table( string $table ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
	}

	/**
	 * Cleanup old events and aggregated events older than the given number of days.
	 *
	 * Deletes from both sybgo_events (by event_timestamp) and sybgo_aggregated_events
	 * (by date) using the same retention window. No foreign key constraints exist between
	 * these tables and reports/email_log, so deletion order does not matter.
	 *
	 * This should be called by a daily cron job.
	 *
	 * @param int $days Retention period in days. Defaults to 90.
	 * @return int Total number of rows deleted across both tables.
	 * @since 1.0.0
	 */
	public function cleanup_old_events( int $days = 90 ): int {
		global $wpdb;

		$cutoff_datetime = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$cutoff_date     = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		$deleted_events = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->events_table} WHERE event_timestamp < %s",
				$cutoff_datetime
			)
		);

		$deleted_aggregated = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->aggregated_events_table} WHERE date < %s",
				$cutoff_date
			)
		);

		// Clear any cached data.
		wp_cache_delete( 'sybgo_events', 'sybgo_cache' );
		wp_cache_delete( 'sybgo_aggregated_events', 'sybgo_cache' );

		return (int) $deleted_events + (int) $deleted_aggregated;
	}
}
