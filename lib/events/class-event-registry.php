<?php
/**
 * Event Registry class file.
 *
 * Provides a centralized, filter-driven registry for event types and their display metadata.
 *
 * @package Rocket\Sybgo\Events
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Rocket\Sybgo\Events;

/**
 * Event Registry class.
 *
 * Read-only accessor backed by the `sybgo_event_types` filter.
 * Core trackers and third-party plugins register event types via `add_filter`.
 *
 * @package Rocket\Sybgo\Events
 * @since   1.0.0
 */
class Event_Registry {
	/**
	 * Cached event types from the filter.
	 *
	 * @var array<string, array>|null
	 */
	private ?array $event_types = null;

	/**
	 * Get all registered event types.
	 *
	 * Applies the `sybgo_event_types` filter on first call, then caches.
	 *
	 * @return array<string, array> Event types keyed by identifier.
	 */
	public function get_event_types(): array {
		if ( null === $this->event_types ) {
			$this->event_types = wpm_apply_filters_typesafe( 'sybgo_event_types', array() );
		}

		return $this->event_types;
	}

	/**
	 * Get a single event type definition.
	 *
	 * @param string $event_type Event type identifier.
	 * @return array|null Event type definition or null if not registered.
	 */
	public function get_event_type( string $event_type ): ?array {
		$types = $this->get_event_types();

		return $types[ $event_type ] ?? null;
	}

	/**
	 * Get all registered event type identifiers.
	 *
	 * @return array<string> Array of event type identifiers.
	 */
	public function get_registered_types(): array {
		return array_keys( $this->get_event_types() );
	}

	/**
	 * Check if an event type is registered.
	 *
	 * @param string $event_type Event type to check.
	 * @return bool True if registered, false otherwise.
	 */
	public function is_registered( string $event_type ): bool {
		$types = $this->get_event_types();

		return isset( $types[ $event_type ] );
	}

	/**
	 * Get icon for an event type.
	 *
	 * @param string $event_type Event type.
	 * @return string Icon string (emoji or bullet fallback).
	 */
	public function get_icon( string $event_type ): string {
		$type = $this->get_event_type( $event_type );

		return $type['icon'] ?? '•';
	}

	/**
	 * Get human-readable stat label for an event type.
	 *
	 * @param string $event_type Event type.
	 * @return string Translatable label for statistics and settings.
	 */
	public function get_stat_label( string $event_type ): string {
		$type = $this->get_event_type( $event_type );

		if ( ! empty( $type['stat_label'] ) ) {
			return $type['stat_label'];
		}

		return ucwords( str_replace( '_', ' ', $event_type ) );
	}

	/**
	 * Get short title for an event (dashboard-style).
	 *
	 * Falls back to detailed_title, then to a generic ucwords title.
	 *
	 * @param string $event_type Event type.
	 * @param array  $event_data Decoded event data.
	 * @return string Short event title.
	 */
	public function get_short_title( string $event_type, array $event_data ): string {
		$type = $this->get_event_type( $event_type );

		if ( isset( $type['short_title'] ) && is_callable( $type['short_title'] ) ) {
			return call_user_func( $type['short_title'], $event_data );
		}

		return $this->get_detailed_title( $event_type, $event_data );
	}

	/**
	 * Get detailed title for an event (reports-style).
	 *
	 * Falls back to a generic ucwords title.
	 *
	 * @param string $event_type Event type.
	 * @param array  $event_data Decoded event data.
	 * @return string Detailed event title.
	 */
	public function get_detailed_title( string $event_type, array $event_data ): string {
		$type = $this->get_event_type( $event_type );

		if ( isset( $type['detailed_title'] ) && is_callable( $type['detailed_title'] ) ) {
			return call_user_func( $type['detailed_title'], $event_data );
		}

		return ucwords( str_replace( '_', ' ', $event_type ) );
	}

	/**
	 * Get AI-friendly short description for prompt building.
	 *
	 * @param string $event_type Event type.
	 * @param array  $object Object data from event_data.
	 * @param array  $metadata Metadata from event_data.
	 * @return string AI description or empty string.
	 */
	public function get_ai_description( string $event_type, array $object, array $metadata ): string {
		$type = $this->get_event_type( $event_type );

		if ( isset( $type['ai_description'] ) && is_callable( $type['ai_description'] ) ) {
			return call_user_func( $type['ai_description'], $object, $metadata );
		}

		return '';
	}

	/**
	 * Get AI-friendly schema description of an event type.
	 *
	 * @param string $event_type The event type.
	 * @param array  $event_data The JSON data for this event.
	 * @return string Text description for AI context.
	 */
	public function describe_event( string $event_type, array $event_data ): string {
		$type = $this->get_event_type( $event_type );

		if ( isset( $type['describe'] ) && is_callable( $type['describe'] ) ) {
			return call_user_func( $type['describe'], $event_data );
		}

		return sprintf( 'Unknown event type: %s', $event_type );
	}

	/**
	 * Get AI context for a report.
	 *
	 * Generates full AI context including event type descriptions.
	 *
	 * @param array $events Array of events from database.
	 * @return string AI-ready context string.
	 */
	public function get_ai_context_for_events( array $events ): string {
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
					$context .= $this->describe_event( $type, $event_data );
					$context .= "\n---\n\n";
				}
			}
		}

		return $context;
	}
}
