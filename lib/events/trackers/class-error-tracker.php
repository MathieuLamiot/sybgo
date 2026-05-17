<?php
/**
 * Error Tracker class file.
 *
 * This file defines the Error Tracker for capturing PHP errors and warnings
 * as daily aggregated events, with a cap of 5 distinct error signatures per day.
 *
 * @package Sybgo\Events\Trackers
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Sybgo\Events\Trackers;

use Sybgo\Events\Abstracts\Abstract_Aggregated_Event;
use Sybgo\Logger;

/**
 * Error Tracker class.
 *
 * Registers a custom PHP error handler via set_error_handler() and records
 * non-fatal PHP errors (warnings, notices, deprecations) as aggregated events
 * in the wp_sybgo_aggregated_events table.
 *
 * Each unique error location (file + line + message excerpt) is treated as a
 * distinct signature. At most DAILY_CAP distinct signatures are stored per
 * report period to prevent database bloat. The cap is filterable via the
 * `sybgo_error_tracker_daily_cap` filter. Occurrences of already-known
 * signatures continue to accumulate beyond the cap.
 *
 * When the cap is reached and a new (unknown) signature arrives, the tracker
 * attempts priority-based eviction: if the incoming event has a higher priority
 * than the lowest-priority stored event, the stored event is deleted and the
 * incoming one takes its place. Priority order (highest → lowest):
 * error > user_error > warning > user_warning > deprecated > user_deprecated > notice > user_notice.
 *
 * The handler always chains to the previously registered error handler so that
 * it does not interfere with WordPress's own error handling or third-party plugins.
 *
 * @package Sybgo\Events\Trackers
 * @since   1.0.0
 */
class Error_Tracker extends Abstract_Aggregated_Event {

	/**
	 * Event type identifier used in the aggregated_events table.
	 */
	private const EVENT_TYPE = 'php_error';

	/**
	 * Default maximum number of distinct error signatures stored per report period.
	 *
	 * Can be overridden at runtime via the `sybgo_error_tracker_daily_cap` filter.
	 */
	private const DAILY_CAP = 5;

	/**
	 * PHP error levels this tracker captures via set_error_handler(), mapped to human-readable names.
	 *
	 * @var array<int, string>
	 */
	private const ERROR_LEVELS = array(
		E_WARNING         => 'warning',
		E_NOTICE          => 'notice',
		E_USER_ERROR      => 'user_error',
		E_USER_WARNING    => 'user_warning',
		E_USER_NOTICE     => 'user_notice',
		E_DEPRECATED      => 'deprecated',
		E_USER_DEPRECATED => 'user_deprecated',
	);

	/**
	 * PHP error levels captured only via the shutdown handler.
	 *
	 * These levels bypass set_error_handler() and can only be detected via
	 * error_get_last() in a shutdown function.
	 *
	 * @var array<int, string>
	 */
	private const FATAL_LEVELS = array(
		E_ERROR         => 'fatal_error',
		E_PARSE         => 'parse_error',
		E_CORE_ERROR    => 'core_error',
		E_COMPILE_ERROR => 'compile_error',
	);

	/**
	 * Priority weight for each non-fatal error level name.
	 *
	 * Higher integer = higher priority. Used to decide eviction: an incoming event
	 * can only evict a stored event with a strictly lower priority.
	 *
	 * Fatal levels (fatal_error, core_error, compile_error, parse_error) are not
	 * included because fatal errors bypass the cap entirely.
	 *
	 * @var array<string, int>
	 */
	private const ERROR_PRIORITY = array(
		'error'           => 8,
		'user_error'      => 7,
		'warning'         => 6,
		'user_warning'    => 5,
		'deprecated'      => 4,
		'user_deprecated' => 3,
		'notice'          => 2,
		'user_notice'     => 1,
	);

	/**
	 * Return the display/eviction priority for a given error level string.
	 *
	 * Levels not present in the priority map (e.g. fatal_error, parse_error)
	 * return 0 so they sort below explicitly ranked levels.
	 *
	 * @param string $level Level string, e.g. 'warning', 'notice', 'fatal_error'.
	 * @return int Priority value (higher = more severe).
	 */
	public static function get_level_priority( string $level ): int {
		return self::ERROR_PRIORITY[ $level ] ?? 0;
	}

	/**
	 * Return the effective daily cap, applying the `sybgo_error_tracker_daily_cap` filter.
	 *
	 * Single source of truth for the cap's filter name and default — callers outside
	 * the tracker (e.g. the admin dashboard widget) must use this method rather than
	 * duplicating the filter call so a future change of filter name or default propagates.
	 *
	 * @return int Effective cap (defaults to DAILY_CAP = 5).
	 */
	public static function get_effective_daily_cap(): int {
		return wpm_apply_filters_typed( 'integer', 'sybgo_error_tracker_daily_cap', self::DAILY_CAP );
	}

	/**
	 * Re-entrancy guard.
	 *
	 * Prevents the error handler from triggering itself recursively if the
	 * database query inside handle_error() produces a PHP notice or warning.
	 *
	 * @var bool
	 */
	private static bool $handling = false;

	/**
	 * The error handler that was registered before this tracker replaced it.
	 *
	 * Stored so we can chain errors to it, preserving existing handler behaviour.
	 *
	 * @var callable|null
	 */
	private $previous_handler = null;

	/**
	 * The error_reporting() mask captured at handler registration time.
	 *
	 * Used to detect @ suppression: if the current mask differs from this
	 * value at handler invocation time, the error was suppressed and should
	 * be skipped. This is more reliable than checking for 0 or E_ERROR
	 * directly across PHP versions.
	 *
	 * @var int
	 */
	private int $normal_error_reporting = 0;

	/**
	 * Register WordPress hooks for this tracker.
	 *
	 * Installs the PHP error handler and stores the previously registered
	 * handler so it can be chained.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Intentional: this is the error tracking feature.
		$this->previous_handler = set_error_handler( array( $this, 'handle_error' ) );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- Read-only: captures mask at registration for @ suppression detection.
		$this->normal_error_reporting = error_reporting();
		register_shutdown_function( array( $this, 'handle_shutdown' ) );
	}

	/**
	 * PHP error handler callback.
	 *
	 * Called by PHP for every error that passes the current error_reporting() mask.
	 * Tracks qualifying errors as aggregated events, then chains to the previous handler.
	 *
	 * @param int    $errno   The error level constant (e.g. E_WARNING).
	 * @param string $errstr  The error message.
	 * @param string $errfile The file where the error occurred.
	 * @param int    $errline The line number where the error occurred.
	 * @return bool False to let PHP execute its built-in handler after ours;
	 *              result of the previous handler if one is registered.
	 */
	public function handle_error(
		int $errno,
		string $errstr,
		string $errfile = '',
		int $errline = 0
	): bool {
		// Skip errors suppressed by the @ operator.
		// When @ is used, PHP temporarily lowers error_reporting() during handler
		// execution. By comparing against the mask captured at registration time,
		// we detect suppression reliably across all PHP versions without needing to
		// hard-code version-specific suppression values.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- Read-only call to check @ suppression; we do not change the value.
		if ( error_reporting() !== $this->normal_error_reporting ) {
			return $this->call_previous_handler( $errno, $errstr, $errfile, $errline );
		}

		// Only track levels we explicitly support.
		if ( ! isset( self::ERROR_LEVELS[ $errno ] ) ) {
			return $this->call_previous_handler( $errno, $errstr, $errfile, $errline );
		}

		// Re-entrancy guard: skip if we are already inside this handler.
		if ( self::$handling ) {
			return $this->call_previous_handler( $errno, $errstr, $errfile, $errline );
		}

		self::$handling = true;

		try {
			$message_snippet = substr( $errstr, 0, 100 );
			$signature       = md5( $errfile . ':' . $errline . ':' . $message_snippet );
			$level_name      = self::ERROR_LEVELS[ $errno ];

			$dimensions = array(
				'level'     => $level_name,
				'signature' => $signature,
			);

			$meta = array(
				'file'    => $errfile,
				'line'    => $errline,
				'message' => $message_snippet,
			);

			// If this exact signature is already stored in the current period,
			// just increment its counter — no cap or eviction logic applies.
			if ( $this->aggregated_repo->dimensions_hash_exists_for_report(
				self::EVENT_TYPE,
				$this->compute_dimensions_hash( $dimensions ),
				null
			) ) {
				$this->increment( self::EVENT_TYPE, 1.0, $dimensions, $meta );
				return $this->call_previous_handler( $errno, $errstr, $errfile, $errline );
			}

			// Apply the filterable cap (default: DAILY_CAP = 5).
			$cap = $this->get_daily_cap();

			// Enforce the per-period cap: only proceed if fewer than $cap distinct
			// signatures have been stored in the current (unassigned) period.
			// report_id = 0 identifies "current period" — so the cap resets automatically
			// after a freeze without any additional bookkeeping.
			$existing_count = $this->aggregated_repo->count_distinct_dimensions_for_report(
				self::EVENT_TYPE,
				null
			);

			if ( $existing_count >= $cap ) {
				// Attempt priority-based eviction: find the lowest-priority stored row.
				$lowest = $this->aggregated_repo->get_lowest_priority_row_for_report(
					self::EVENT_TYPE,
					self::ERROR_PRIORITY,
					null
				);

				if ( null === $lowest ) {
					// No stored rows to evict — discard incoming event.
					return $this->call_previous_handler( $errno, $errstr, $errfile, $errline );
				}

				$incoming_priority = self::ERROR_PRIORITY[ $level_name ];
				$stored_priority   = self::ERROR_PRIORITY[ $lowest['level'] ] ?? 0;

				if ( $incoming_priority <= $stored_priority ) {
					// Incoming event is not strictly higher priority — discard.
					return $this->call_previous_handler( $errno, $errstr, $errfile, $errline );
				}

				// Evict the lowest-priority stored row to make room.
				$deleted = $this->aggregated_repo->delete_by_dimensions_hash_and_report(
					self::EVENT_TYPE,
					$lowest['dimensions_hash'],
					null
				);

				if ( false === $deleted ) {
					Logger::info(
						sprintf(
							'Error_Tracker: failed to evict row (event_type=%s, dimensions_hash=%s) when making room for higher-priority event.',
							self::EVENT_TYPE,
							$lowest['dimensions_hash']
						)
					);
				}
			}

			$this->increment( self::EVENT_TYPE, 1.0, $dimensions, $meta );
		} finally {
			self::$handling = false;
		}

		return $this->call_previous_handler( $errno, $errstr, $errfile, $errline );
	}

	/**
	 * Shutdown handler: captures fatal errors that bypass set_error_handler().
	 *
	 * Called by PHP on every request end. Checks error_get_last() and records
	 * the error if it is a fatal level (E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR).
	 * The daily cap is not applied here — fatal errors terminate the request
	 * immediately and cannot loop, so they are always worth recording.
	 *
	 * @return void
	 */
	public function handle_shutdown(): void {
		$error = $this->get_last_error();

		if ( null === $error ) {
			return;
		}

		if ( ! isset( self::FATAL_LEVELS[ $error['type'] ] ) ) {
			return;
		}

		$message_snippet = substr( $error['message'], 0, 100 );
		$signature       = md5( $error['file'] . ':' . $error['line'] . ':' . $message_snippet );
		$level_name      = self::FATAL_LEVELS[ $error['type'] ];

		$dimensions = array(
			'level'     => $level_name,
			'signature' => $signature,
		);

		$meta = array(
			'file'    => $error['file'],
			'line'    => $error['line'],
			'message' => $message_snippet,
		);

		$this->increment( self::EVENT_TYPE, 1.0, $dimensions, $meta );
	}

	/**
	 * Return the effective daily cap, applying the `sybgo_error_tracker_daily_cap` filter.
	 *
	 * Extracted as a protected method so unit tests can override the value without
	 * needing to stub wpm_apply_filters_typed (which is loaded before Patchwork).
	 *
	 * @return int Effective cap (defaults to DAILY_CAP = 5).
	 */
	protected function get_daily_cap(): int {
		return self::get_effective_daily_cap();
	}

	/**
	 * Return the last PHP error, if any.
	 *
	 * Extracted as a protected method so unit tests can override it without
	 * needing to trigger a real fatal error.
	 *
	 * @return array{type: int, message: string, file: string, line: int}|null
	 */
	protected function get_last_error(): ?array {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_get_last
		return error_get_last();
	}

	/**
	 * Compute the canonical dimensions hash for a given dimensions array.
	 *
	 * Mirrors the SHA2-256 hash computed by MySQL on the `dimensions` column, using
	 * the same canonical JSON encoding (keys sorted alphabetically, JSON_FORCE_OBJECT).
	 * Used to check whether a specific dimension set already exists in the database
	 * without issuing a full-table query.
	 *
	 * @param array<string, mixed> $dimensions Dimension key→value pairs.
	 * @return string SHA2-256 hex string (64 characters).
	 */
	private function compute_dimensions_hash( array $dimensions ): string {
		ksort( $dimensions );
		$json = (string) wp_json_encode( $dimensions, JSON_FORCE_OBJECT );
		return hash( 'sha256', $json );
	}

	/**
	 * Delegate to the previously registered error handler, if any.
	 *
	 * Returning false when there is no previous handler tells PHP to execute
	 * its own built-in error handler (display/log the error normally).
	 *
	 * @param int    $errno   Error level constant.
	 * @param string $errstr  Error message.
	 * @param string $errfile File where the error occurred.
	 * @param int    $errline Line number.
	 * @return bool Result of the previous handler, or false if none is set.
	 */
	private function call_previous_handler(
		int $errno,
		string $errstr,
		string $errfile,
		int $errline
	): bool {
		if ( is_callable( $this->previous_handler ) ) {
			return (bool) call_user_func(
				$this->previous_handler,
				$errno,
				$errstr,
				$errfile,
				$errline
			);
		}

		// false → PHP executes its standard error handler.
		return false;
	}
}
