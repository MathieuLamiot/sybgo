<?php
/**
 * Event Registry class file.
 *
 * This file defines the Event Registry for registering and describing event types.
 *
 * @package Rocket\Sybgo\Events
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Rocket\Sybgo\Events;

/**
 * Event Registry class.
 *
 * Manages event type registration and provides AI-friendly descriptions.
 *
 * @package Rocket\Sybgo\Events
 * @since   1.0.0
 */
class Event_Registry {
	/**
	 * Registered event types with their describe callbacks.
	 *
	 * @var array<string, callable>
	 */
	private static array $event_types = array();

	/**
	 * Register an event type with its describe method.
	 *
	 * @param string   $event_type Event type identifier (e.g., 'post_published').
	 * @param callable $describe_callback Function that returns AI-friendly description.
	 * @return void
	 */
	public static function register_event_type( string $event_type, callable $describe_callback ): void {
		self::$event_types[ $event_type ] = $describe_callback;
	}

	/**
	 * Get AI-friendly description of an event.
	 *
	 * @param string $event_type The event type.
	 * @param array  $event_data The JSON data for this event.
	 * @return string Text description for AI context.
	 */
	public static function describe_event( string $event_type, array $event_data ): string {
		if ( isset( self::$event_types[ $event_type ] ) ) {
			return call_user_func( self::$event_types[ $event_type ], $event_data );
		}

		return sprintf( 'Unknown event type: %s', $event_type );
	}

	/**
	 * Get all registered event types.
	 *
	 * @return array<string> Array of event type identifiers.
	 */
	public static function get_registered_types(): array {
		return array_keys( self::$event_types );
	}

	/**
	 * Check if an event type is registered.
	 *
	 * @param string $event_type Event type to check.
	 * @return bool True if registered, false otherwise.
	 */
	public static function is_registered( string $event_type ): bool {
		return isset( self::$event_types[ $event_type ] );
	}

	/**
	 * Get AI context for a report.
	 *
	 * Generates full AI context including event type descriptions.
	 *
	 * @param array $events Array of events from database.
	 * @return string AI-ready context string.
	 */
	public static function get_ai_context_for_events( array $events ): string {
		$context = "Event Types Reference:\n\n";

		// Get unique event types.
		$event_types = array();
		foreach ( $events as $event ) {
			if ( ! in_array( $event['event_type'], $event_types, true ) ) {
				$event_types[] = $event['event_type'];
			}
		}

		// Add description for each type.
		foreach ( $event_types as $type ) {
			// Find a sample event of this type.
			$sample_event = null;
			foreach ( $events as $event ) {
				if ( $event['event_type'] === $type ) {
					$sample_event = $event;
					break;
				}
			}

			if ( $sample_event ) {
				$event_data = json_decode( $sample_event['event_data'], true );
				if ( is_array( $event_data ) ) {
					$context .= self::describe_event( $type, $event_data );
					$context .= "\n---\n\n";
				}
			}
		}

		return $context;
	}
}
