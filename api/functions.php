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

use Rocket\Sybgo\API\Extensibility_API;

/**
 * Track a custom event.
 *
 * Global function wrapper for easy API access.
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
function sybgo_track_event( string $event_type, array $event_data, string $source_plugin = '' ): int|false {
	return Extensibility_API::track_event( $event_type, $event_data, $source_plugin );
}

/**
 * Register a custom event type.
 *
 * Global function wrapper for registering event types.
 *
 * @param string   $event_type Event type identifier.
 * @param callable $describe_callback Description callback for AI integration.
 * @return void
 *
 * @example
 * ```php
 * sybgo_register_event_type('my_event', function($event_data) {
 *     return "Event Type: My Custom Event\nDescription: Something happened.";
 * });
 * ```
 */
function sybgo_register_event_type( string $event_type, callable $describe_callback ): void {
	Extensibility_API::register_event_type( $event_type, $describe_callback );
}

/**
 * Check if Sybgo is active.
 *
 * Utility function for plugins to check if Sybgo is available.
 *
 * @return bool True if Sybgo is active and API is available.
 */
function sybgo_is_active(): bool {
	return class_exists( 'Rocket\Sybgo\API\Extensibility_API' );
}

/**
 * Get Sybgo version.
 *
 * @return string Sybgo version number.
 */
function sybgo_get_version(): string {
	return defined( 'SYBGO_VERSION' ) ? SYBGO_VERSION : '1.0.0';
}
