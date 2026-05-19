<?php
/**
 * Module interface file.
 *
 * Defines the contract that every feature module must satisfy.
 * A module owns one domain area's WordPress integration wiring
 * and exposes a single boot() entry point called by Sybgo::init().
 *
 * @package Sybgo\Modules
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Sybgo\Modules;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module interface.
 *
 * Each feature module must implement boot(), which registers its hooks,
 * cron events, admin pages, and abilities on the relevant managers.
 * boot() is called once during Sybgo::init(), before any manager is
 * initialised, so it must only register — never execute domain logic.
 *
 * @since 1.0.0
 */
interface Module_Interface {
	/**
	 * Register the module's hooks, cron events, and admin pages.
	 *
	 * Called once per request during Sybgo::init(), before CronManager::init(),
	 * AbilityManager::init(), and AdminManager::init() are invoked.
	 *
	 * @return void
	 */
	public function boot(): void;
}
