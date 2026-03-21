<?php
/**
 * Event Tracker class file.
 *
 * This file defines the Event Tracker class for tracking WordPress events.
 *
 * @package Sybgo\Events
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Sybgo\Events;

use Sybgo\Database\Aggregated_Event_Repository;
use Sybgo\Database\Event_Repository;
use Sybgo\Database\Report_Repository;

/**
 * Event Tracker class.
 *
 * Core event tracking system that coordinates all event trackers.
 *
 * @package Sybgo\Events
 * @since   1.0.0
 */
class Event_Tracker {
	/**
	 * Whether trackers have already been initialized.
	 *
	 * Prevents duplicate hook registration when multiple plugins embed the library.
	 *
	 * @var bool
	 */
	private static bool $initialized = false;

	/**
	 * Event repository instance.
	 *
	 * @var Event_Repository
	 */
	private Event_Repository $event_repo;

	/**
	 * Aggregated event repository instance.
	 *
	 * Passed to trackers that accumulate daily values (e.g. Error_Tracker).
	 *
	 * @var Aggregated_Event_Repository
	 */
	private Aggregated_Event_Repository $aggregated_repo;

	/**
	 * Report repository instance.
	 *
	 * Used to look up the active report's period start date so Error_Tracker
	 * can scope its cap check to the current report period rather than a single day.
	 *
	 * @var Report_Repository
	 */
	private Report_Repository $report_repo;

	/**
	 * Array of tracker instances.
	 *
	 * @var array<string, object>
	 */
	private array $trackers = array();

	/**
	 * Constructor.
	 *
	 * @param Event_Repository            $event_repo      Event repository instance.
	 * @param Aggregated_Event_Repository $aggregated_repo Aggregated event repository instance.
	 * @param Report_Repository           $report_repo     Report repository instance.
	 */
	public function __construct(
		Event_Repository $event_repo,
		Aggregated_Event_Repository $aggregated_repo,
		Report_Repository $report_repo
	) {
		$this->event_repo      = $event_repo;
		$this->aggregated_repo = $aggregated_repo;
		$this->report_repo     = $report_repo;
	}

	/**
	 * Initialize event tracking.
	 *
	 * Loads all tracker classes and initializes them.
	 * Guarded by a static flag to prevent duplicate hook registration
	 * when multiple plugins embed the library.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		// Load tracker classes.
		$this->load_trackers();

		// Pass the active report's period start to Error_Tracker so its cap check
		// is scoped to the current report period rather than the calendar day.
		$active_report = $this->report_repo->get_active();
		if ( null !== $active_report && isset( $active_report['period_start'] ) ) {
			$period_start = gmdate( 'Y-m-d', (int) strtotime( (string) $active_report['period_start'] ) );
			$this->trackers['error']->set_period_start( $period_start );
		}

		// Initialize each tracker.
		foreach ( $this->trackers as $tracker ) {
			if ( method_exists( $tracker, 'register_hooks' ) ) {
				$tracker->register_hooks();
			}
		}
	}

	/**
	 * Load all tracker classes.
	 *
	 * @return void
	 */
	private function load_trackers(): void {
		$tracker_files = array(
			'class-post-tracker.php',
			'class-user-tracker.php',
			'class-update-tracker.php',
			'class-comment-tracker.php',
			'class-error-tracker.php',
		);

		foreach ( $tracker_files as $file ) {
			$file_path = __DIR__ . '/trackers/' . $file;
			if ( file_exists( $file_path ) ) {
				require_once $file_path;
			}
		}

		// Instantiate trackers.
		$this->trackers = array(
			'post'    => new Trackers\Post_Tracker( $this->event_repo ),
			'user'    => new Trackers\User_Tracker( $this->event_repo ),
			'update'  => new Trackers\Update_Tracker( $this->event_repo ),
			'comment' => new Trackers\Comment_Tracker( $this->event_repo ),
			'error'   => new Trackers\Error_Tracker( $this->aggregated_repo ),
		);
	}

	/**
	 * Track a custom event.
	 *
	 * Public method for other plugins to track custom events.
	 *
	 * @param string               $event_type Event type identifier.
	 * @param array<string, mixed> $event_data Event data.
	 * @param string               $source_plugin Source plugin identifier.
	 * @return int|false Event ID on success, false on failure.
	 */
	public function track_custom_event( string $event_type, array $event_data, string $source_plugin = 'custom' ) {
		// Allow filtering of event data.
		$event_data = wpm_apply_filters_typesafe( 'sybgo_event_data', $event_data, $event_type );

		// Check if we should track this event.
		$should_track = wpm_apply_filters_typesafe( 'sybgo_should_track_event', true, $event_type, $event_data );

		if ( ! $should_track ) {
			return false;
		}

		// Create event in database.
		$event_id = $this->event_repo->create(
			array(
				'event_type'    => $event_type,
				'event_data'    => $event_data,
				'source_plugin' => $source_plugin,
			)
		);

		// Fire action after event is recorded.
		if ( $event_id ) {
			do_action( 'sybgo_event_recorded', $event_id, $event_type, $event_data );
		}

		return $event_id;
	}

	/**
	 * Get tracker instance.
	 *
	 * @param string $tracker_name Tracker name (post, user, update, comment).
	 * @return object|null Tracker instance or null if not found.
	 */
	public function get_tracker( string $tracker_name ): ?object {
		return $this->trackers[ $tracker_name ] ?? null;
	}
}
