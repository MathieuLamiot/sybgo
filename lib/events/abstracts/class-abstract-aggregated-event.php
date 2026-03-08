<?php
/**
 * Abstract Aggregated Event class file.
 *
 * Base class for events that are counted daily in the aggregated_events table.
 *
 * @package Sybgo\Events\Abstracts
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Sybgo\Events\Abstracts;

use Sybgo\Database\Aggregated_Event_Repository;

/**
 * Abstract Aggregated Event class.
 *
 * Provides the increment() method for trackers that count daily occurrences
 * rather than logging each event individually.
 *
 * @package Sybgo\Events\Abstracts
 * @since   1.0.0
 */
abstract class Abstract_Aggregated_Event {
	/**
	 * Aggregated event repository instance.
	 *
	 * @var Aggregated_Event_Repository
	 */
	protected Aggregated_Event_Repository $aggregated_repo;

	/**
	 * Constructor.
	 *
	 * @param Aggregated_Event_Repository $aggregated_repo Aggregated event repository instance.
	 */
	public function __construct( Aggregated_Event_Repository $aggregated_repo ) {
		$this->aggregated_repo = $aggregated_repo;
	}

	/**
	 * Increment the daily count for an event type.
	 *
	 * @param string               $event_type Event type identifier.
	 * @param array<string, mixed> $meta       Optional metadata to store alongside the count.
	 * @return void
	 */
	protected function increment( string $event_type, array $meta = array() ): void {
		$date = gmdate( 'Y-m-d' );
		$this->aggregated_repo->upsert_count( $event_type, $date, $meta );
	}

	/**
	 * Register WordPress hooks for this tracker.
	 *
	 * @return void
	 */
	abstract public function register_hooks(): void;
}
