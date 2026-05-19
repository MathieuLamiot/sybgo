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
	 * @phpstan-ignore property.onlyWritten (used once boot() is implemented in sub-issue #95)
	 */
	private Factory $factory;

	/**
	 * Ability Manager instance.
	 *
	 * @var Ability_Manager
	 * @phpstan-ignore property.onlyWritten (used once boot() is implemented in sub-issue #95)
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
	 * Register event-tracking hooks and abilities.
	 *
	 * @return void
	 */
	public function boot(): void {
		// No-op stub — implementation follows in a dedicated sub-issue.
	}
}
