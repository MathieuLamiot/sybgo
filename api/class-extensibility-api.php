<?php
/**
 * Extensibility API class file.
 *
 * This file defines the public API for extending Sybgo functionality.
 *
 * @package Rocket\Sybgo\API
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Rocket\Sybgo\API;

use Rocket\Sybgo\Events\Event_Registry;

/**
 * Extensibility API class.
 *
 * Provides public methods and hooks for other plugins to extend Sybgo.
 *
 * @package Rocket\Sybgo\API
 * @since   1.0.0
 */
class Extensibility_API {
	/**
	 * Event repository instance.
	 *
	 * @var object|null
	 */
	private static ?object $event_repo = null;

	/**
	 * Initialize the API.
	 *
	 * @param object $event_repo Event repository instance.
	 * @return void
	 */
	public static function init( object $event_repo ): void {
		self::$event_repo = $event_repo;

		// Register default hooks.
		self::register_default_hooks();
	}

	/**
	 * Register default WordPress hooks for extensibility.
	 *
	 * @return void
	 */
	private static function register_default_hooks(): void {
		// No default hooks needed - all extensibility is via direct API calls.
	}

	/**
	 * Track a custom event.
	 *
	 * Public API method for other plugins to track events.
	 *
	 * @param string $event_type Event type identifier (e.g., 'woocommerce_order').
	 * @param array  $event_data Event data following the standard structure.
	 * @param string $source_plugin Source plugin identifier (optional).
	 * @return int|false Event ID on success, false on failure.
	 *
	 * @example
	 * ```php
	 * Sybgo_API::track_event('woocommerce_order', [
	 *     'action' => 'created',
	 *     'object' => [
	 *         'type' => 'order',
	 *         'id' => 123,
	 *         'total' => 99.99
	 *     ],
	 *     'context' => [
	 *         'user_id' => 1,
	 *         'user_name' => 'john'
	 *     ],
	 *     'metadata' => [
	 *         'status' => 'pending',
	 *         'items' => 5
	 *     ]
	 * ], 'woocommerce');
	 * ```
	 */
	public static function track_event( string $event_type, array $event_data, string $source_plugin = '' ) {
		if ( ! self::$event_repo ) {
			return false;
		}

		// Validate event data structure.
		if ( ! self::validate_event_data( $event_data ) ) {
			return false;
		}

		// Add source plugin if provided.
		if ( ! empty( $source_plugin ) ) {
			$event_data['source_plugin'] = $source_plugin;
		}

		/**
		 * Filter event data before tracking.
		 *
		 * @param array  $event_data Event data.
		 * @param string $event_type Event type.
		 */
		$event_data = apply_filters( 'sybgo_before_track_event', $event_data, $event_type );

		// Create event.
		$event_id = self::$event_repo->create(
			array(
				'event_type' => $event_type,
				'event_data' => $event_data,
			)
		);

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
	 * Register a custom event type with description.
	 *
	 * Public API method for plugins to register their event types for AI integration.
	 *
	 * @param string   $event_type Event type identifier.
	 * @param callable $describe_callback Callback that returns event description.
	 * @return void
	 *
	 * @example
	 * ```php
	 * Sybgo_API::register_event_type('woocommerce_order', function($event_data) {
	 *     return "Event Type: WooCommerce Order\n" .
	 *            "Description: A new order was placed.\n" .
	 *            "Data Structure:\n" .
	 *            "  - object.id: Order ID\n" .
	 *            "  - object.total: Order total\n" .
	 *            "  - metadata.status: Order status";
	 * });
	 * ```
	 */
	public static function register_event_type( string $event_type, callable $describe_callback ): void {
		Event_Registry::register_event_type( $event_type, $describe_callback );

		/**
		 * Action fired after event type is registered.
		 *
		 * @param string   $event_type Event type.
		 * @param callable $describe_callback Description callback.
		 */
		do_action( 'sybgo_event_type_registered', $event_type, $describe_callback );
	}

	/**
	 * Check if an event type is registered.
	 *
	 * @param string $event_type Event type to check.
	 * @return bool True if registered, false otherwise.
	 */
	public static function is_event_type_registered( string $event_type ): bool {
		return Event_Registry::is_registered( $event_type );
	}

	/**
	 * Get all registered event types.
	 *
	 * @return array Array of event type identifiers.
	 */
	public static function get_registered_event_types(): array {
		return Event_Registry::get_registered_types();
	}

	/**
	 * Validate event data structure.
	 *
	 * @param array $event_data Event data to validate.
	 * @return bool True if valid, false otherwise.
	 */
	private static function validate_event_data( array $event_data ): bool {
		// Required: action.
		if ( ! isset( $event_data['action'] ) || empty( $event_data['action'] ) ) {
			return false;
		}

		// Required: object with type.
		if ( ! isset( $event_data['object'] ) || ! is_array( $event_data['object'] ) ) {
			return false;
		}

		if ( ! isset( $event_data['object']['type'] ) || empty( $event_data['object']['type'] ) ) {
			return false;
		}

		// Optional but validated if present: context, metadata.
		if ( isset( $event_data['context'] ) && ! is_array( $event_data['context'] ) ) {
			return false;
		}

		if ( isset( $event_data['metadata'] ) && ! is_array( $event_data['metadata'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Add a filter for modifying event data.
	 *
	 * Helper method for plugins to easily add event data filters.
	 *
	 * @param callable $callback Filter callback.
	 * @param int      $priority Filter priority.
	 * @return void
	 */
	public static function add_event_filter( callable $callback, int $priority = 10 ): void {
		add_filter( 'sybgo_before_track_event', $callback, $priority, 2 );
	}

	/**
	 * Add an action hook for when events are tracked.
	 *
	 * Helper method for plugins to easily add event tracking actions.
	 *
	 * @param callable $callback Action callback.
	 * @param int      $priority Action priority.
	 * @return void
	 */
	public static function add_event_action( callable $callback, int $priority = 10 ): void {
		add_action( 'sybgo_event_tracked', $callback, $priority, 3 );
	}

	/**
	 * Add a filter for modifying report summary data.
	 *
	 * Helper method for plugins to add data to report summaries.
	 *
	 * @param callable $callback Filter callback.
	 * @param int      $priority Filter priority.
	 * @return void
	 */
	public static function add_report_summary_filter( callable $callback, int $priority = 10 ): void {
		add_filter( 'sybgo_report_summary', $callback, $priority, 2 );
	}

	/**
	 * Add a filter for modifying email recipients.
	 *
	 * Helper method for plugins to modify who receives emails.
	 *
	 * @param callable $callback Filter callback.
	 * @param int      $priority Filter priority.
	 * @return void
	 */
	public static function add_email_recipients_filter( callable $callback, int $priority = 10 ): void {
		add_filter( 'sybgo_email_recipients', $callback, $priority, 2 );
	}

	/**
	 * Add a filter for modifying email template.
	 *
	 * Helper method for plugins to customize email HTML.
	 *
	 * @param callable $callback Filter callback.
	 * @param int      $priority Filter priority.
	 * @return void
	 */
	public static function add_email_template_filter( callable $callback, int $priority = 10 ): void {
		add_filter( 'sybgo_email_body', $callback, $priority, 2 );
	}

	/**
	 * Add an action for custom email sections.
	 *
	 * Helper method for plugins to add custom sections to emails.
	 *
	 * @param callable $callback Action callback.
	 * @param int      $priority Action priority.
	 * @return void
	 */
	public static function add_email_section_action( callable $callback, int $priority = 10 ): void {
		add_action( 'sybgo_email_custom_section', $callback, $priority, 2 );
	}

	/**
	 * Add an action for before report freeze.
	 *
	 * Helper method for plugins to perform actions before freezing.
	 *
	 * @param callable $callback Action callback.
	 * @param int      $priority Action priority.
	 * @return void
	 */
	public static function add_before_freeze_action( callable $callback, int $priority = 10 ): void {
		add_action( 'sybgo_before_report_freeze', $callback, $priority, 1 );
	}

	/**
	 * Add an action for after report freeze.
	 *
	 * Helper method for plugins to perform actions after freezing.
	 *
	 * @param callable $callback Action callback.
	 * @param int      $priority Action priority.
	 * @return void
	 */
	public static function add_after_freeze_action( callable $callback, int $priority = 10 ): void {
		add_action( 'sybgo_after_report_freeze', $callback, $priority, 1 );
	}
}
