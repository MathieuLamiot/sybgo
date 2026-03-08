<?php
/**
 * Aggregated Event Repository class file.
 *
 * This file defines the Aggregated Event Repository for upsert operations on daily event counts.
 *
 * @package Sybgo\Database
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Sybgo\Database;

/**
 * Aggregated Event Repository class.
 *
 * Handles database operations for the aggregated_events table.
 * Uses INSERT ... ON DUPLICATE KEY UPDATE to increment daily counts.
 *
 * @package Sybgo\Database
 * @since   1.0.0
 */
class Aggregated_Event_Repository {
	/**
	 * Table name for aggregated events.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Constructor.
	 *
	 * @param string $table The aggregated events table name.
	 */
	public function __construct( string $table ) {
		$this->table = $table;
	}

	/**
	 * Insert or increment the daily count for an event type.
	 *
	 * Creates a new row for the given event_type + date combination,
	 * or increments the count if that combination already exists.
	 *
	 * @param string               $event_type Event type identifier.
	 * @param string               $date       Date string in Y-m-d format.
	 * @param array<string, mixed> $meta       Optional metadata to store alongside the count.
	 * @return bool True on success, false on failure.
	 */
	public function upsert_count( string $event_type, string $date, array $meta = array() ): bool {
		global $wpdb;

		$meta_json = wp_json_encode( $meta );

		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$this->table} (event_type, count, date, meta)
				VALUES (%s, 1, %s, %s)
				ON DUPLICATE KEY UPDATE count = count + 1, meta = %s",
				$event_type,
				$date,
				$meta_json,
				$meta_json
			)
		);

		return false !== $result;
	}
}
