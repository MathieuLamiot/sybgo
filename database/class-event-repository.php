<?php
/**
 * Event Repository class file.
 *
 * This file defines the Event Repository class for CRUD operations on events.
 *
 * @package Rocket\Sybgo\Database
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Rocket\Sybgo\Database;

/**
 * Event Repository class.
 *
 * Handles all database operations for events table.
 *
 * @package Rocket\Sybgo\Database
 * @since   1.0.0
 */
class Event_Repository {
	/**
	 * Table name for events.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Constructor.
	 *
	 * @param string $table The events table name.
	 */
	public function __construct( string $table ) {
		$this->table = $table;
	}

	/**
	 * Create a new event.
	 *
	 * @param array $event_data Event data array.
	 * @return int|false Event ID on success, false on failure.
	 */
	public function create( array $event_data ) {
		global $wpdb;

		$defaults = array(
			'event_type'      => '',
			'event_subtype'   => null,
			'object_id'       => null,
			'user_id'         => null,
			'event_data'      => null,
			'event_timestamp' => current_time( 'mysql' ),
			'report_id'       => null,
			'source_plugin'   => 'core',
		);

		$data = wp_parse_args( $event_data, $defaults );

		// Convert event_data array to JSON if needed.
		if ( is_array( $data['event_data'] ) ) {
			$data['event_data'] = wp_json_encode( $data['event_data'] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert( $this->table, $data );

		if ( $result ) {
			// Clear cache.
			wp_cache_delete( 'sybgo_recent_events', 'sybgo_cache' );
			return $wpdb->insert_id;
		}

		return false;
	}

	/**
	 * Get events by report ID.
	 *
	 * @param int|null $report_id Report ID (null for unassigned events).
	 * @param int      $limit Maximum number of events to return.
	 * @param int      $offset Offset for pagination.
	 * @return array Array of events.
	 */
	public function get_by_report( ?int $report_id, int $limit = 100, int $offset = 0 ): array {
		global $wpdb;

		if ( null === $report_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$events = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->table} WHERE report_id IS NULL ORDER BY event_timestamp DESC LIMIT %d OFFSET %d",
					$limit,
					$offset
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$events = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->table} WHERE report_id = %d ORDER BY event_timestamp DESC LIMIT %d OFFSET %d",
					$report_id,
					$limit,
					$offset
				),
				ARRAY_A
			);
		}

		return $events ? $events : array();
	}

	/**
	 * Get recent events for current week.
	 *
	 * @param int $limit Number of events to return.
	 * @return array Array of recent events.
	 */
	public function get_recent( int $limit = 5 ): array {
		$cache_key = 'sybgo_recent_events_' . $limit;
		$cached    = wp_cache_get( $cache_key, 'sybgo_cache' );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$events = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE report_id IS NULL ORDER BY event_timestamp DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		$events = $events ? $events : array();

		wp_cache_set( $cache_key, $events, 'sybgo_cache', 300 ); // Cache for 5 minutes.

		return $events;
	}

	/**
	 * Get events by type.
	 *
	 * @param string   $event_type Event type to filter by.
	 * @param int|null $report_id Optional report ID to filter by.
	 * @param int      $limit Maximum number of events.
	 * @return array Array of events.
	 */
	public function get_by_type( string $event_type, ?int $report_id = null, int $limit = 100 ): array {
		global $wpdb;

		if ( null === $report_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$events = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->table} WHERE event_type LIKE %s AND report_id IS NULL ORDER BY event_timestamp DESC LIMIT %d",
					'%' . $wpdb->esc_like( $event_type ) . '%',
					$limit
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$events = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$this->table} WHERE event_type LIKE %s AND report_id = %d ORDER BY event_timestamp DESC LIMIT %d",
					'%' . $wpdb->esc_like( $event_type ) . '%',
					$report_id,
					$limit
				),
				ARRAY_A
			);
		}

		return $events ? $events : array();
	}

	/**
	 * Assign events to a report.
	 *
	 * @param int    $report_id Report ID to assign to.
	 * @param string $period_start Period start datetime.
	 * @param string $period_end Period end datetime.
	 * @return int Number of events assigned.
	 */
	public function assign_to_report( int $report_id, string $period_start, string $period_end ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table} SET report_id = %d WHERE report_id IS NULL AND event_timestamp >= %s AND event_timestamp <= %s",
				$report_id,
				$period_start,
				$period_end
			)
		);

		// Clear cache.
		wp_cache_delete( 'sybgo_recent_events', 'sybgo_cache' );

		return (int) $updated;
	}

	/**
	 * Get last event for a specific object (for throttling).
	 *
	 * @param string $event_type Event type.
	 * @param int    $object_id Object ID.
	 * @return array|null Last event or null if none found.
	 */
	public function get_last_event_for_object( string $event_type, int $object_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$event = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE event_type = %s AND object_id = %d ORDER BY event_timestamp DESC LIMIT 1",
				$event_type,
				$object_id
			),
			ARRAY_A
		);

		return $event ? $event : null;
	}

	/**
	 * Count events by type.
	 *
	 * @param int|null $report_id Optional report ID to filter by.
	 * @return array Associative array of event_type => count.
	 */
	public function count_by_type( ?int $report_id = null ): array {
		global $wpdb;

		if ( null === $report_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$counts = $wpdb->get_results(
				"SELECT event_type, COUNT(*) as count FROM {$this->table} WHERE report_id IS NULL GROUP BY event_type",
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$counts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT event_type, COUNT(*) as count FROM {$this->table} WHERE report_id = %d GROUP BY event_type",
					$report_id
				),
				ARRAY_A
			);
		}

		$result = array();
		if ( $counts ) {
			foreach ( $counts as $row ) {
				$result[ $row['event_type'] ] = (int) $row['count'];
			}
		}

		return $result;
	}
}
