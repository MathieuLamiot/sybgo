<?php
/**
 * Abstract Singular Event class file.
 *
 * Base class for events that are logged individually in the events table.
 *
 * @package Sybgo\Events\Abstracts
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Sybgo\Events\Abstracts;

use Sybgo\Database\Event_Repository;

/**
 * Abstract Singular Event class.
 *
 * Provides shared boilerplate for trackers that log individual events:
 * record(), is_throttled(), and automatic filter registration.
 *
 * @package Sybgo\Events\Abstracts
 * @since   1.0.0
 */
abstract class Abstract_Singular_Event {
	/**
	 * Event repository instance.
	 *
	 * @var Event_Repository
	 */
	protected Event_Repository $event_repo;

	/**
	 * Constructor.
	 *
	 * Stores the repository and registers event types via filter.
	 *
	 * @param Event_Repository $event_repo Event repository instance.
	 */
	public function __construct( Event_Repository $event_repo ) {
		$this->event_repo = $event_repo;
		add_filter( 'sybgo_event_types', array( $this, 'register_event_types' ) );
	}

	/**
	 * Record a singular event.
	 *
	 * Applies the sybgo_event_data and sybgo_should_track_event filters,
	 * persists the event, then fires sybgo_event_recorded.
	 *
	 * @param string               $event_type Event type identifier.
	 * @param array<string, mixed> $event_data Event payload.
	 * @param string               $source     Source plugin identifier.
	 * @return void
	 */
	protected function record( string $event_type, array $event_data, string $source = 'core' ): void {
		$event_data = wpm_apply_filters_typesafe( 'sybgo_event_data', $event_data, $event_type );

		$should_track = wpm_apply_filters_typesafe( 'sybgo_should_track_event', true, $event_type, $event_data );
		if ( ! $should_track ) {
			return;
		}

		$event_id = $this->event_repo->create(
			array(
				'event_type'    => $event_type,
				'event_data'    => $event_data,
				'source_plugin' => $source,
			)
		);

		if ( $event_id ) {
			do_action( 'sybgo_event_recorded', $event_id, $event_type, $event_data );
		}
	}

	/**
	 * Check whether an event for a given object is within a throttle period.
	 *
	 * @param string $event_type Event type identifier.
	 * @param int    $object_id  Object ID (post ID, user ID, etc.).
	 * @param int    $seconds    Throttle window in seconds.
	 * @return bool True if within the throttle window (skip the event), false otherwise.
	 */
	protected function is_throttled( string $event_type, int $object_id, int $seconds ): bool {
		$last_event = $this->event_repo->get_last_event_for_object( $event_type, $object_id );

		if ( ! $last_event ) {
			return false;
		}

		$time_since = time() - strtotime( $last_event['event_timestamp'] );

		return $time_since < $seconds;
	}

	/**
	 * Register WordPress hooks for this tracker.
	 *
	 * @return void
	 */
	abstract public function register_hooks(): void;

	/**
	 * Register event types via the sybgo_event_types filter.
	 *
	 * @param array<string, array<string, mixed>> $types Existing event types.
	 * @return array<string, array<string, mixed>> Modified event types.
	 */
	abstract public function register_event_types( array $types ): array;
}
