<?php
/**
 * User Tracker class file.
 *
 * This file defines the User Tracker for tracking user-related events.
 *
 * @package Rocket\Sybgo\Events\Trackers
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Rocket\Sybgo\Events\Trackers;

use Rocket\Sybgo\Database\Event_Repository;

/**
 * User Tracker class.
 *
 * Tracks user registration, role changes, and deletion events.
 *
 * @package Rocket\Sybgo\Events\Trackers
 * @since   1.0.0
 */
class User_Tracker {
	/**
	 * Event repository instance.
	 *
	 * @var Event_Repository
	 */
	private Event_Repository $event_repo;

	/**
	 * Constructor.
	 *
	 * @param Event_Repository $event_repo Event repository instance.
	 */
	public function __construct( Event_Repository $event_repo ) {
		$this->event_repo = $event_repo;

		// Register event types via filter.
		add_filter( 'sybgo_event_types', [ $this, 'register_event_types' ] );
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Track new user registrations.
		add_action( 'user_register', [ $this, 'track_user_registration' ], 10, 1 );

		// Track role changes.
		add_action( 'set_user_role', [ $this, 'track_role_change' ], 10, 3 );

		// Track user deletion.
		add_action( 'delete_user', [ $this, 'track_user_deletion' ], 10, 2 );
	}

	/**
	 * Register user event types via filter.
	 *
	 * @param array $types Existing event types.
	 * @return array Modified event types.
	 */
	public function register_event_types( array $types ): array {
		$types['user_registered'] = [
			'icon'           => '👤',
			'stat_label'     => __( 'New Users', 'sybgo' ),
			'short_title'    => function ( array $event_data ): string {
				$object = $event_data['object'] ?? [];
				return sprintf( 'New user: %s', $object['username'] ?? 'Unknown' );
			},
			'detailed_title' => function ( array $event_data ): string {
				$object = $event_data['object'] ?? [];
				return sprintf( 'New user registered: %s (%s)', $object['username'] ?? 'Unknown', $object['email'] ?? '' );
			},
			'ai_description' => function ( array $object, array $metadata ): string {
				return sprintf( 'New user registered: %s', $object['username'] ?? 'Unknown' );
			},
			'describe'       => function ( array $event_data ): string {
				$description  = "Event Type: User Registered\n";
				$description .= "Description: A new user account was created on the site.\n\n";
				$description .= "Data Structure:\n";
				$description .= "  - object.id: The user ID\n";
				$description .= "  - object.username: The username/login\n";
				$description .= "  - object.email: The user's email address\n";
				$description .= "  - metadata.role: The assigned user role (subscriber, editor, etc.)\n";
				$description .= "  - metadata.registration_method: How they registered (admin, self-signup, etc.)\n";
				return $description;
			},
		];

		$types['user_role_changed'] = [
			'icon'           => '👥',
			'stat_label'     => __( 'Role Changes', 'sybgo' ),
			'short_title'    => function ( array $event_data ): string {
				$object = $event_data['object'] ?? [];
				return sprintf( 'User %s role changed to %s', $object['username'] ?? 'Unknown', $event_data['metadata']['new_role'] ?? 'subscriber' );
			},
			'detailed_title' => function ( array $event_data ): string {
				$object   = $event_data['object'] ?? [];
				$old_role = $event_data['metadata']['old_role'] ?? 'subscriber';
				$new_role = $event_data['metadata']['new_role'] ?? 'subscriber';
				return sprintf( 'User %s role changed from %s to %s', $object['username'] ?? 'Unknown', $old_role, $new_role );
			},
			'ai_description' => function ( array $object, array $metadata ): string {
				return sprintf( 'User %s role changed from %s to %s', $object['username'] ?? 'Unknown', $metadata['old_role'] ?? 'unknown', $metadata['new_role'] ?? 'unknown' );
			},
			'describe'       => function ( array $event_data ): string {
				$description  = "Event Type: User Role Changed\n";
				$description .= "Description: A user's role was changed by an administrator.\n\n";
				$description .= "Data Structure:\n";
				$description .= "  - object.id: The user ID\n";
				$description .= "  - object.username: The username\n";
				$description .= "  - metadata.old_role: The previous role\n";
				$description .= "  - metadata.new_role: The new role assigned\n";
				$description .= "  - context.changed_by_id: ID of admin who made the change\n";
				return $description;
			},
		];

		$types['user_deleted'] = [
			'icon'           => '🚫',
			'stat_label'     => __( 'Users Deleted', 'sybgo' ),
			'short_title'    => function ( array $event_data ): string {
				$object = $event_data['object'] ?? [];
				return sprintf( 'User deleted: %s', $object['username'] ?? 'Unknown' );
			},
			'detailed_title' => function ( array $event_data ): string {
				$object = $event_data['object'] ?? [];
				return sprintf( 'User "%s" was deleted', $object['username'] ?? 'Unknown' );
			},
			'ai_description' => function ( array $object, array $metadata ): string {
				return sprintf( 'User deleted: %s', $object['username'] ?? 'Unknown' );
			},
			'describe'       => function ( array $event_data ): string {
				$description  = "Event Type: User Deleted\n";
				$description .= "Description: A user account was permanently deleted.\n\n";
				$description .= "Data Structure:\n";
				$description .= "  - object.id: The deleted user ID\n";
				$description .= "  - object.username: The username (before deletion)\n";
				$description .= "  - metadata.role: The user's role at time of deletion\n";
				$description .= "  - context.deleted_by_id: ID of admin who deleted the account\n";
				return $description;
			},
		];

		return $types;
	}

	/**
	 * Track new user registration.
	 *
	 * @param int $user_id The newly created user ID.
	 * @return void
	 */
	public function track_user_registration( int $user_id ): void {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		// Determine registration method.
		$current_user_id = get_current_user_id();
		$method          = $current_user_id > 0 ? 'admin_created' : 'self_signup';

		// Build event data.
		$event_data = [
			'action'   => 'registered',
			'object'   => [
				'type'     => 'user',
				'id'       => $user_id,
				'username' => $user->user_login,
				'email'    => $user->user_email,
			],
			'context'  => [
				'created_by_id' => $current_user_id > 0 ? $current_user_id : null,
			],
			'metadata' => [
				'role'                => ! empty( $user->roles ) ? $user->roles[0] : 'subscriber',
				'registration_method' => $method,
			],
		];

		// Create event.
		$this->event_repo->create(
			[
				'event_type' => 'user_registered',
				'event_data' => $event_data,
			]
		);
	}

	/**
	 * Track user role change.
	 *
	 * @param int    $user_id The user ID.
	 * @param string $new_role The new role.
	 * @param array  $old_roles Array of old roles.
	 * @return void
	 */
	public function track_role_change( int $user_id, string $new_role, array $old_roles ): void {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		$old_role = ! empty( $old_roles ) ? $old_roles[0] : 'none';

		// Build event data.
		$event_data = [
			'action'   => 'role_changed',
			'object'   => [
				'type'     => 'user',
				'id'       => $user_id,
				'username' => $user->user_login,
				'email'    => $user->user_email,
			],
			'context'  => [
				'changed_by_id' => get_current_user_id(),
			],
			'metadata' => [
				'old_role' => $old_role,
				'new_role' => $new_role,
			],
		];

		// Create event.
		$this->event_repo->create(
			[
				'event_type' => 'user_role_changed',
				'event_data' => $event_data,
			]
		);
	}

	/**
	 * Track user deletion.
	 *
	 * @param int      $user_id ID of the user being deleted.
	 * @param int|null $reassign ID of user to reassign posts to (or null).
	 * @return void
	 */
	public function track_user_deletion( int $user_id, ?int $reassign ): void {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		// Build event data.
		$event_data = [
			'action'   => 'deleted',
			'object'   => [
				'type'     => 'user',
				'id'       => $user_id,
				'username' => $user->user_login,
				'email'    => $user->user_email,
			],
			'context'  => [
				'deleted_by_id' => get_current_user_id(),
			],
			'metadata' => [
				'role'             => ! empty( $user->roles ) ? $user->roles[0] : 'none',
				'posts_reassigned' => $reassign ? true : false,
				'reassigned_to_id' => $reassign,
			],
		];

		// Create event.
		$this->event_repo->create(
			[
				'event_type' => 'user_deleted',
				'event_data' => $event_data,
			]
		);
	}
}
