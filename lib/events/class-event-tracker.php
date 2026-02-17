<?php
/**
 * Event Tracker class file.
 *
 * This file defines the Event Tracker class for tracking WordPress events.
 *
 * @package Rocket\Sybgo\Events
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Rocket\Sybgo\Events;

use Rocket\Sybgo\Database\Event_Repository;

/**
 * Event Tracker class.
 *
 * Core event tracking system that coordinates all event trackers.
 *
 * @package Rocket\Sybgo\Events
 * @since   1.0.0
 */
class Event_Tracker {
	/**
	 * Event repository instance.
	 *
	 * @var Event_Repository
	 */
	private Event_Repository $event_repo;

	/**
	 * Array of tracker instances.
	 *
	 * @var array
	 */
	private array $trackers = array();

	/**
	 * Constructor.
	 *
	 * @param Event_Repository $event_repo Event repository instance.
	 */
	public function __construct( Event_Repository $event_repo ) {
		$this->event_repo = $event_repo;
	}

	/**
	 * Initialize event tracking.
	 *
	 * Loads all tracker classes and initializes them.
	 *
	 * @return void
	 */
	public function init(): void {
		// Load tracker classes.
		$this->load_trackers();

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
		);

		foreach ( $tracker_files as $file ) {
			$file_path = SYBGO_PLUGIN_DIR . 'events/trackers/' . $file;
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
		);
	}

	/**
	 * Track a custom event.
	 *
	 * Public method for other plugins to track custom events.
	 *
	 * @param string $event_type Event type identifier.
	 * @param array  $event_data Event data.
	 * @param string $source_plugin Source plugin identifier.
	 * @return int|false Event ID on success, false on failure.
	 */
	public function track_custom_event( string $event_type, array $event_data, string $source_plugin = 'custom' ) {
		// Allow filtering of event data.
		$event_data = apply_filters( 'sybgo_event_data', $event_data, $event_type );

		// Check if we should track this event.
		$should_track = apply_filters( 'sybgo_should_track_event', true, $event_type, $event_data );

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
