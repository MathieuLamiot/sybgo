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
 * Provides the increment() method for trackers that accumulate daily values
 * rather than logging each event individually. Supports multi-dimensional
 * breakdowns (e.g. per role, per product) via the $dimensions parameter.
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
	 * Accumulate a daily value for an event type and optional dimension set.
	 *
	 * Pass $value = 1.0 (the default) for simple event counts.
	 * Pass a decimal amount (e.g. 249.95) to accumulate sums such as revenue.
	 * Pass $dimensions to break the metric down by object, role, product, etc.
	 *
	 * @param string               $event_type  Event type identifier.
	 * @param float                $value       Amount to accumulate. Default 1.0.
	 * @param array<string, mixed> $dimensions  Breakdown axes as key→value pairs (e.g. ['role' => 'editor']).
	 * @param array<string, mixed> $meta        Optional context snapshot stored alongside the value.
	 * @return void
	 */
	protected function increment(
		string $event_type,
		float $value = 1.0,
		array $dimensions = array(),
		array $meta = array()
	): void {
		$date = gmdate( 'Y-m-d' );
		$this->aggregated_repo->upsert( $event_type, $date, $value, $dimensions, $meta );
	}

	/**
	 * Register WordPress hooks for this tracker.
	 *
	 * @return void
	 */
	abstract public function register_hooks(): void;
}
