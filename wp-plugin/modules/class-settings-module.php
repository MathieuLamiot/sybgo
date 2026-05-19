<?php
/**
 * Settings Module class file.
 *
 * Owns all WordPress integration wiring for the settings domain:
 * registers the Settings_Page, the admin asset enqueuer, the manual
 * cleanup form handler, and the sybgo_cleanup_old_events cron callback.
 *
 * @package Sybgo\Modules
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Sybgo\Modules;

use Sybgo\Admin\Admin_Manager;
use Sybgo\Cron_Manager;
use Sybgo\Factory;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings Module.
 *
 * Responsible for the settings admin page, asset enqueueing, manual cleanup,
 * and the daily event-cleanup cron event.
 *
 * @since 1.0.0
 */
class Settings_Module implements Module_Interface {

	/**
	 * Factory instance.
	 *
	 * @var Factory
	 * @phpstan-ignore property.onlyWritten (used once boot() is implemented in sub-issue #99)
	 */
	private Factory $factory;

	/**
	 * Cron Manager instance.
	 *
	 * @var Cron_Manager
	 * @phpstan-ignore property.onlyWritten (used once boot() is implemented in sub-issue #99)
	 */
	private Cron_Manager $cron;

	/**
	 * Admin Manager instance.
	 *
	 * @var Admin_Manager
	 * @phpstan-ignore property.onlyWritten (used once boot() is implemented in sub-issue #99)
	 */
	private Admin_Manager $admin;

	/**
	 * Constructor.
	 *
	 * @param Factory       $factory Factory instance.
	 * @param Cron_Manager  $cron    Cron Manager instance.
	 * @param Admin_Manager $admin   Admin Manager instance.
	 */
	public function __construct( Factory $factory, Cron_Manager $cron, Admin_Manager $admin ) {
		$this->factory = $factory;
		$this->cron    = $cron;
		$this->admin   = $admin;
	}

	/**
	 * Register settings hooks, admin pages, and cron events.
	 *
	 * @return void
	 */
	public function boot(): void {
		// No-op stub — implementation follows in a dedicated sub-issue.
	}
}
