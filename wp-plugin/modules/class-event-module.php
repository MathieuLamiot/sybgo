<?php
/**
 * Event Module class file.
 *
 * Owns all WordPress integration wiring for the event-tracking domain:
 * initialises Event_Tracker, exposes the public extensibility API, and
 * registers the sybgo/track-events Ability.
 *
 * @package Sybgo\Modules
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Sybgo\Modules;

use Sybgo\Ability_Manager;
use Sybgo\Events\Event_Tracker;
use Sybgo\Factory;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Event Module.
 *
 * Responsible for event tracking, the public extensibility API, and the
 * sybgo/track-events WordPress 7 Ability registration.
 *
 * @since 1.0.0
 */
class Event_Module implements Module_Interface {

	/**
	 * Factory instance.
	 *
	 * @var Factory
	 */
	private Factory $factory;

	/**
	 * Ability Manager instance.
	 *
	 * @var Ability_Manager
	 */
	private Ability_Manager $abilities;

	/**
	 * Constructor.
	 *
	 * @param Factory         $factory   Factory instance.
	 * @param Ability_Manager $abilities Ability Manager instance.
	 */
	public function __construct( Factory $factory, Ability_Manager $abilities ) {
		$this->factory   = $factory;
		$this->abilities = $abilities;
	}

	/**
	 * Register event-tracking hooks and the sybgo/track-events ability.
	 *
	 * Initialises Event_Tracker, stores it on the factory, exposes the public
	 * extensibility API via sybgo_init_api(), and schedules ability registration
	 * for priority 5 on the 'init' action so that __() evaluates after the
	 * text domain is loaded (WP 6.7+ fires _doing_it_wrong() otherwise).
	 *
	 * @return void
	 */
	public function boot(): void {
		$event_repo      = $this->factory->create_event_repository();
		$aggregated_repo = $this->factory->create_aggregated_event_repository();
		$event_tracker   = new Event_Tracker( $event_repo, $aggregated_repo );
		$event_tracker->init();
		$this->factory->set_event_tracker( $event_tracker );

		// Expose the public extensibility API.
		\sybgo_init_api( $event_repo );

		// Defer ability registration to 'init' so __() evaluates after the
		// 'sybgo' text domain is loaded. Priority 5 ensures this runs before
		// Ability_Manager::init() which is scheduled at priority 20.
		$abilities = $this->abilities;
		$factory   = $this->factory;

		add_action(
			'init',
			static function () use ( $abilities, $factory ): void {
				$abilities->register(
					'sybgo/track-events',
					array(
						'label'               => __( 'Track Site Events', 'sybgo' ),
						'description'         => __( 'Records WordPress site events for inclusion in the weekly digest.', 'sybgo' ),
						'category'            => 'sybgo',
						'execute_callback'    => static function () use ( $factory ): bool {
							return null !== $factory->get_event_tracker();
						},
						'permission_callback' => static function (): bool {
							return current_user_can( 'manage_options' );
						},
					)
				);
			},
			5
		);
	}
}
