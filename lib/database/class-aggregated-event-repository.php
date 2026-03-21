<?php
/**
 * Aggregated Event Repository class file.
 *
 * This file defines the Aggregated Event Repository for upsert operations on daily event values.
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
 * Uses INSERT ... ON DUPLICATE KEY UPDATE to accumulate daily values per event type
 * and dimension set. The dimensions_hash column is computed by MySQL automatically.
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
	 * Insert or accumulate a daily value for an event type and dimension set.
	 *
	 * Creates a new row for the given (event_type, dimensions, date) combination,
	 * or adds $value to the existing row if that combination already exists.
	 * Meta is overwritten (last-write-wins) on conflict — use it for context snapshots.
	 *
	 * @param string               $event_type  Event type identifier.
	 * @param string               $date        Date string in Y-m-d format.
	 * @param float                $value       Amount to accumulate (default 1.0 for simple counts).
	 * @param array<string, mixed> $dimensions  Breakdown axes as key→value pairs (e.g. ['role' => 'editor']).
	 *                                          Empty array produces a global row with dimensions = '{}'.
	 * @param array<string, mixed> $meta        Optional context snapshot stored alongside the value.
	 * @return bool True on success, false on failure.
	 */
	public function upsert(
		string $event_type,
		string $date,
		float $value = 1.0,
		array $dimensions = array(),
		array $meta = array()
	): bool {
		global $wpdb;

		$dimensions_json = $this->encode_dimensions( $dimensions );
		$meta_json       = wp_json_encode( $meta );

		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$this->table} (event_type, dimensions, value, date, meta)
				VALUES (%s, %s, %f, %s, %s)
				ON DUPLICATE KEY UPDATE value = value + VALUES(value), meta = VALUES(meta)",
				$event_type,
				$dimensions_json,
				$value,
				$date,
				$meta_json
			)
		);

		return false !== $result;
	}

	/**
	 * Count distinct dimension sets recorded for a given event type and date.
	 *
	 * Uses the pre-computed dimensions_hash column to efficiently count unique
	 * dimension sets. Used by Error_Tracker to enforce the daily cap of 5 distinct
	 * error signatures.
	 *
	 * @param string $event_type Event type identifier (e.g. 'php_error').
	 * @param string $date       Date string in Y-m-d format.
	 * @return int Number of distinct dimension hashes recorded for that day.
	 */
	public function count_distinct_dimensions_for_date( string $event_type, string $date ): int {
		global $wpdb;

		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT dimensions_hash) FROM {$this->table}
				 WHERE event_type = %s AND date = %s",
				$event_type,
				$date
			)
		);

		return (int) $result;
	}

	/**
	 * Sum all accumulated values for a given event type across a date range.
	 *
	 * Used by the dashboard widget to display total error counts for today
	 * or the current week.
	 *
	 * @param string $event_type Event type identifier.
	 * @param string $date_from  Start date inclusive (Y-m-d).
	 * @param string $date_to    End date inclusive (Y-m-d).
	 * @return float Total accumulated value, or 0.0 if no rows match.
	 */
	public function get_sum_for_date_range( string $event_type, string $date_from, string $date_to ): float {
		global $wpdb;

		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(value), 0) FROM {$this->table}
				 WHERE event_type = %s AND date BETWEEN %s AND %s",
				$event_type,
				$date_from,
				$date_to
			)
		);

		return (float) $result;
	}

	/**
	 * Encode dimensions array to canonical JSON.
	 *
	 * Keys are sorted alphabetically so the same set of dimensions always produces
	 * the same JSON string (and therefore the same SHA2 hash in dimensions_hash).
	 * An empty dimensions array encodes to '{}' — not null — so the UNIQUE KEY
	 * produces a stable hash for global (non-dimensioned) rows.
	 *
	 * @param array<string, mixed> $dimensions Dimension key→value pairs.
	 * @return string Canonical JSON string.
	 */
	private function encode_dimensions( array $dimensions ): string {
		ksort( $dimensions );
		return (string) wp_json_encode( $dimensions, JSON_FORCE_OBJECT );
	}
}
