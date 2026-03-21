<?php
/**
 * DB Stats class file.
 *
 * This file defines the DB_Stats class, responsible for querying database footprint.
 *
 * @package Sybgo\Database
 * @since   1.1.0
 */

declare(strict_types=1);

namespace Sybgo\Database;

/**
 * DB Stats class.
 *
 * Provides per-table row counts and estimated sizes for the plugin's database tables.
 * Uses information_schema for size estimates; gracefully returns null when the host
 * restricts access (common on managed WordPress hosts).
 *
 * @package Sybgo\Database
 * @since   1.1.0
 */
class DB_Stats {
	/**
	 * Database manager instance (used to retrieve table names).
	 *
	 * @var DatabaseManager
	 */
	private DatabaseManager $db_manager;

	/**
	 * Constructor.
	 *
	 * @param DatabaseManager $db_manager Database manager instance.
	 */
	public function __construct( DatabaseManager $db_manager ) {
		$this->db_manager = $db_manager;
	}

	/**
	 * Get stats for all plugin tables.
	 *
	 * Returns an array keyed by table identifier (events, reports, email_log, aggregated_events).
	 * Each entry contains the full table name, row count, and estimated size in MB.
	 *
	 * @return array<string, array<string, mixed>> Stats keyed by table identifier.
	 * @since 1.1.0
	 */
	public function get_table_stats(): array {
		$table_names = $this->db_manager->get_table_names();
		$stats       = array();

		foreach ( $table_names as $key => $table_name ) {
			$stats[ $key ] = array(
				'table_name' => $table_name,
				'row_count'  => $this->get_row_count( $table_name ),
				'size_mb'    => $this->get_table_size_mb( $table_name ),
			);
		}

		return $stats;
	}

	/**
	 * Get total estimated size across all plugin tables in MB.
	 *
	 * Tables with unavailable size data (null) are treated as 0 for the total.
	 *
	 * @return float Total MB, rounded to 2 decimal places.
	 * @since 1.1.0
	 */
	public function get_total_size_mb(): float {
		$stats = $this->get_table_stats();
		$total = 0.0;

		foreach ( $stats as $table_stats ) {
			$total += $table_stats['size_mb'] ?? 0.0;
		}

		return round( $total, 2 );
	}

	/**
	 * Query the row count for a single table.
	 *
	 * @param string $table Fully-qualified table name (including prefix).
	 * @return int Row count.
	 * @since 1.1.0
	 */
	private function get_row_count( string $table ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

		return (int) $count;
	}

	/**
	 * Query the estimated size in MB for a single table via information_schema.
	 *
	 * Returns null when information_schema is not accessible (common on managed hosts).
	 *
	 * @param string $table Fully-qualified table name (including prefix).
	 * @return float|null MB rounded to 2 decimal places, or null on failure.
	 * @since 1.1.0
	 */
	private function get_table_size_mb( string $table ): ?float {
		global $wpdb;

		$size = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT (data_length + index_length) / 1024 / 1024
				FROM information_schema.TABLES
				WHERE TABLE_SCHEMA = %s
				AND TABLE_NAME = %s',
				DB_NAME,
				$table
			)
		);

		if ( null === $size ) {
			return null;
		}

		return round( (float) $size, 2 );
	}
}
