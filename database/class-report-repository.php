<?php
/**
 * Report Repository class file.
 *
 * This file defines the Report Repository class for CRUD operations on reports.
 *
 * @package Rocket\Sybgo\Database
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Rocket\Sybgo\Database;

/**
 * Report Repository class.
 *
 * Handles all database operations for reports table.
 *
 * @package Rocket\Sybgo\Database
 * @since   1.0.0
 */
class Report_Repository {
	/**
	 * Table name for reports.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Constructor.
	 *
	 * @param string $table The reports table name.
	 */
	public function __construct( string $table ) {
		$this->table = $table;
	}

	/**
	 * Create a new report.
	 *
	 * @param array $report_data Report data array.
	 * @return int|false Report ID on success, false on failure.
	 */
	public function create( array $report_data ) {
		global $wpdb;

		$defaults = array(
			'report_type'  => 'weekly',
			'status'       => 'active',
			'period_start' => current_time( 'mysql' ),
			'period_end'   => null,
			'event_count'  => 0,
			'summary_data' => null,
			'frozen_at'    => null,
			'emailed_at'   => null,
			'created_at'   => current_time( 'mysql' ),
		);

		$data = wp_parse_args( $report_data, $defaults );

		// Convert summary_data array to JSON if needed.
		if ( is_array( $data['summary_data'] ) ) {
			$data['summary_data'] = wp_json_encode( $data['summary_data'] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert( $this->table, $data );

		if ( $result ) {
			wp_cache_delete( 'sybgo_active_report', 'sybgo_cache' );
			return $wpdb->insert_id;
		}

		return false;
	}

	/**
	 * Update a report.
	 *
	 * @param int   $report_id Report ID to update.
	 * @param array $data Data to update.
	 * @return bool True on success, false on failure.
	 */
	public function update( int $report_id, array $data ): bool {
		global $wpdb;

		// Convert summary_data array to JSON if needed.
		if ( isset( $data['summary_data'] ) && is_array( $data['summary_data'] ) ) {
			$data['summary_data'] = wp_json_encode( $data['summary_data'] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$this->table,
			$data,
			array( 'id' => $report_id )
		);

		if ( false !== $result ) {
			wp_cache_delete( 'sybgo_active_report', 'sybgo_cache' );
			wp_cache_delete( 'sybgo_last_frozen_report', 'sybgo_cache' );
			wp_cache_delete( 'sybgo_report_' . $report_id, 'sybgo_cache' );
			return true;
		}

		return false;
	}

	/**
	 * Get active report.
	 *
	 * @return array|null Active report or null if none exists.
	 */
	public function get_active(): ?array {
		$cached = wp_cache_get( 'sybgo_active_report', 'sybgo_cache' );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$report = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE status = %s ORDER BY created_at DESC LIMIT 1",
				'active'
			),
			ARRAY_A
		);

		if ( $report ) {
			wp_cache_set( 'sybgo_active_report', $report, 'sybgo_cache', 300 );
			return $report;
		}

		return null;
	}

	/**
	 * Get last frozen report.
	 *
	 * @return array|null Last frozen report or null if none exists.
	 */
	public function get_last_frozen(): ?array {
		$cached = wp_cache_get( 'sybgo_last_frozen_report', 'sybgo_cache' );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$report = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE status IN ('frozen', 'emailed') ORDER BY frozen_at DESC LIMIT 1",
				''
			),
			ARRAY_A
		);

		if ( $report ) {
			wp_cache_set( 'sybgo_last_frozen_report', $report, 'sybgo_cache', 300 );
			return $report;
		}

		return null;
	}

	/**
	 * Get report by ID.
	 *
	 * @param int $report_id Report ID.
	 * @return array|null Report data or null if not found.
	 */
	public function get_by_id( int $report_id ): ?array {
		$cache_key = 'sybgo_report_' . $report_id;
		$cached    = wp_cache_get( $cache_key, 'sybgo_cache' );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$report = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d",
				$report_id
			),
			ARRAY_A
		);

		if ( $report ) {
			wp_cache_set( $cache_key, $report, 'sybgo_cache', 600 );
			return $report;
		}

		return null;
	}

	/**
	 * Get all frozen reports.
	 *
	 * @param int $limit Maximum number of reports to return.
	 * @param int $offset Offset for pagination.
	 * @return array Array of reports.
	 */
	public function get_all_frozen( int $limit = 20, int $offset = 0 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$reports = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE status IN ('frozen', 'emailed') ORDER BY frozen_at DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);

		return $reports ? $reports : array();
	}

	/**
	 * Update report status.
	 *
	 * @param int    $report_id Report ID.
	 * @param string $status New status.
	 * @return bool True on success, false on failure.
	 */
	public function update_status( int $report_id, string $status ): bool {
		return $this->update( $report_id, array( 'status' => $status ) );
	}

	/**
	 * Increment event count for a report.
	 *
	 * @param int $report_id Report ID.
	 * @return bool True on success, false on failure.
	 */
	public function increment_event_count( int $report_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table} SET event_count = event_count + 1 WHERE id = %d",
				$report_id
			)
		);

		if ( false !== $result ) {
			wp_cache_delete( 'sybgo_report_' . $report_id, 'sybgo_cache' );
			return true;
		}

		return false;
	}
}
