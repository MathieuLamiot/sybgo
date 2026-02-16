<?php
/**
 * Global API Functions
 *
 * Provides global function wrappers for the Sybgo API.
 *
 * @package Rocket\Sybgo\API
 * @since   1.0.0
 */

declare(strict_types=1);

/**
 * Event repository instance for API functions.
 *
 * @var \Rocket\Sybgo\Database\Event_Repository|null
 */
global $sybgo_api_event_repo;
$sybgo_api_event_repo = null;

/**
 * Initialize the API with an event repository.
 *
 * Called during plugin initialization to provide the event repository.
 *
 * @param \Rocket\Sybgo\Database\Event_Repository $event_repo Event repository instance.
 * @return void
 */
function sybgo_init_api( \Rocket\Sybgo\Database\Event_Repository $event_repo ): void {
	global $sybgo_api_event_repo;
	$sybgo_api_event_repo = $event_repo;
}

/**
 * Track a custom event.
 *
 * Global function for third-party plugins to track events.
 *
 * @param string $event_type Event type identifier.
 * @param array  $event_data Event data array.
 * @param string $source_plugin Source plugin identifier (optional).
 * @return int|false Event ID on success, false on failure.
 *
 * @example
 * ```php
 * sybgo_track_event('my_plugin_action', [
 *     'action' => 'item_created',
 *     'object' => [
 *         'type' => 'custom_item',
 *         'id' => 123,
 *         'name' => 'My Item'
 *     ]
 * ], 'my-plugin');
 * ```
 */
function sybgo_track_event( string $event_type, array $event_data, string $source_plugin = '' ) {
	global $sybgo_api_event_repo;

	if ( ! $sybgo_api_event_repo ) {
		return false;
	}

	// Validate event data structure.
	if ( ! isset( $event_data['action'] ) || empty( $event_data['action'] ) ) {
		return false;
	}

	if ( ! isset( $event_data['object'] ) || ! is_array( $event_data['object'] ) ) {
		return false;
	}

	if ( ! isset( $event_data['object']['type'] ) || empty( $event_data['object']['type'] ) ) {
		return false;
	}

	if ( isset( $event_data['context'] ) && ! is_array( $event_data['context'] ) ) {
		return false;
	}

	if ( isset( $event_data['metadata'] ) && ! is_array( $event_data['metadata'] ) ) {
		return false;
	}

	/**
	 * Filter event data before tracking.
	 *
	 * @param array  $event_data Event data.
	 * @param string $event_type Event type.
	 */
	$event_data = apply_filters( 'sybgo_before_track_event', $event_data, $event_type );

	// Build create args.
	$create_args = array(
		'event_type' => $event_type,
		'event_data' => $event_data,
	);

	if ( ! empty( $source_plugin ) ) {
		$create_args['source_plugin'] = $source_plugin;
	}

	// Create event.
	$event_id = $sybgo_api_event_repo->create( $create_args );

	if ( $event_id ) {
		/**
		 * Action fired after event is tracked.
		 *
		 * @param int    $event_id Event ID.
		 * @param string $event_type Event type.
		 * @param array  $event_data Event data.
		 */
		do_action( 'sybgo_event_tracked', $event_id, $event_type, $event_data );
	}

	return $event_id;
}

/**
 * Check if Sybgo is active.
 *
 * Utility function for plugins to check if Sybgo is available.
 *
 * @return bool True if Sybgo is active and API is available.
 */
function sybgo_is_active(): bool {
	return defined( 'SYBGO_VERSION' );
}

/**
 * Get Sybgo version.
 *
 * @return string Sybgo version number.
 */
function sybgo_get_version(): string {
	return defined( 'SYBGO_VERSION' ) ? SYBGO_VERSION : '1.0.0';
}
