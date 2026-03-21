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
	 * Retrieve all rows for a given event type across a date range, grouped by signature.
	 *
	 * Returns one row per distinct dimension set (i.e. per error signature), with the
	 * accumulated value summed across the date range. Rows are ordered by total descending,
	 * so callers can slice the top-N results.
	 *
	 * Each result row contains:
	 *   - dimensions (string): JSON-encoded dimension key→value pairs.
	 *   - total       (string): Sum of value across the date range (cast to float by caller).
	 *   - meta        (string): JSON context snapshot from the most recent upsert.
	 *
	 * @param string $event_type Event type identifier (e.g. 'php_error').
	 * @param string $date_from  Start date inclusive (Y-m-d).
	 * @param string $date_to    End date inclusive (Y-m-d).
	 * @return array<int, array<string, string>> Rows ordered by total descending.
	 */
	public function get_rows_for_event_type_and_date_range(
		string $event_type,
		string $date_from,
		string $date_to
	): array {
		global $wpdb;

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT dimensions, SUM(value) AS total, meta
				 FROM {$this->table}
				 WHERE event_type = %s AND date BETWEEN %s AND %s
				 GROUP BY dimensions_hash
				 ORDER BY total DESC",
				$event_type,
				$date_from,
				$date_to
			),
			ARRAY_A
		);

		return $results ? $results : array();
	}

	/**
	 * Count distinct dimension sets recorded for a given event type across a date range.
	 *
	 * Used by Error_Tracker to enforce the per-period cap of 5 distinct error signatures,
	 * scoped to the current report period rather than a single calendar day.
	 *
	 * @param string $event_type Event type identifier (e.g. 'php_error').
	 * @param string $date_from  Start date inclusive (Y-m-d).
	 * @param string $date_to    End date inclusive (Y-m-d).
	 * @return int Number of distinct dimension hashes recorded in the date range.
	 */
	public function count_distinct_dimensions_for_date_range(
		string $event_type,
		string $date_from,
		string $date_to
	): int {
		global $wpdb;

		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT dimensions_hash) FROM {$this->table}
				 WHERE event_type = %s AND date BETWEEN %s AND %s",
				$event_type,
				$date_from,
				$date_to
			)
		);

		return (int) $result;
	}

	/**
	 * Assign all unassigned rows within a date range to the given report.
	 *
	 * Called during the freeze process after singular events have been assigned.
	 * Sets report_id on every row whose report_id IS NULL and whose date falls
	 * within the period, matching the same bulk-assignment pattern used for
	 * wp_sybgo_events.
	 *
	 * @param int    $report_id  The ID of the report to assign rows to.
	 * @param string $date_from  Start date inclusive (Y-m-d).
	 * @param string $date_to    End date inclusive (Y-m-d).
	 * @return void
	 */
	public function assign_to_report( int $report_id, string $date_from, string $date_to ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table}
				 SET report_id = %d
				 WHERE report_id IS NULL AND date BETWEEN %s AND %s",
				$report_id,
				$date_from,
				$date_to
			)
		);
	}

	/**
	 * Count distinct dimension sets for the current unassigned period (or a specific report).
	 *
	 * Passing null counts rows with report_id IS NULL (current active period).
	 * Passing an integer counts rows assigned to that report.
	 * Used by Error_Tracker to enforce the per-period cap of 5 distinct signatures.
	 *
	 * @param string   $event_type Event type identifier (e.g. 'php_error').
	 * @param int|null $report_id  null = unassigned (current period); int = specific report.
	 * @return int Number of distinct dimension hashes.
	 */
	public function count_distinct_dimensions_for_report( string $event_type, ?int $report_id ): int {
		global $wpdb;

		if ( null === $report_id ) {
			$result = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT dimensions_hash) FROM {$this->table}
					 WHERE event_type = %s AND report_id IS NULL",
					$event_type
				)
			);
		} else {
			$result = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT dimensions_hash) FROM {$this->table}
					 WHERE event_type = %s AND report_id = %d",
					$event_type,
					$report_id
				)
			);
		}

		return (int) $result;
	}

	/**
	 * Sum all accumulated values for the current unassigned period (or a specific report).
	 *
	 * Passing null sums rows with report_id IS NULL (current active period).
	 * Passing an integer sums rows assigned to that report.
	 * Used by the dashboard widget to display total error occurrence counts.
	 *
	 * @param string   $event_type Event type identifier.
	 * @param int|null $report_id  null = unassigned (current period); int = specific report.
	 * @return float Total accumulated value, or 0.0 if no rows match.
	 */
	public function get_sum_for_report( string $event_type, ?int $report_id ): float {
		global $wpdb;

		if ( null === $report_id ) {
			$result = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(value), 0) FROM {$this->table}
					 WHERE event_type = %s AND report_id IS NULL",
					$event_type
				)
			);
		} else {
			$result = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(value), 0) FROM {$this->table}
					 WHERE event_type = %s AND report_id = %d",
					$event_type,
					$report_id
				)
			);
		}

		return (float) $result;
	}

	/**
	 * Retrieve rows grouped by dimension set for the current unassigned period (or a specific report).
	 *
	 * Passing null returns rows with report_id IS NULL (current active period).
	 * Passing an integer returns rows assigned to that report.
	 * Used by the dashboard PHP Errors section (top-5 slice) and the report detail view.
	 *
	 * Each result row contains:
	 *   - dimensions (string): JSON-encoded dimension key→value pairs.
	 *   - total       (string): Sum of value (cast to float by caller).
	 *   - meta        (string): JSON context snapshot from the most recent upsert.
	 *
	 * @param string   $event_type Event type identifier (e.g. 'php_error').
	 * @param int|null $report_id  null = unassigned (current period); int = specific report.
	 * @return array<int, array<string, string>> Rows ordered by total descending.
	 */
	public function get_rows_for_report( string $event_type, ?int $report_id ): array {
		global $wpdb;

		if ( null === $report_id ) {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT dimensions, SUM(value) AS total, meta
					 FROM {$this->table}
					 WHERE event_type = %s AND report_id IS NULL
					 GROUP BY dimensions_hash
					 ORDER BY total DESC",
					$event_type
				),
				ARRAY_A
			);
		} else {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT dimensions, SUM(value) AS total, meta
					 FROM {$this->table}
					 WHERE event_type = %s AND report_id = %d
					 GROUP BY dimensions_hash
					 ORDER BY total DESC",
					$event_type,
					$report_id
				),
				ARRAY_A
			);
		}

		return $results ? $results : array();
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
