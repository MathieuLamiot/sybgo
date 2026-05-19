<?php
/**
 * Report Module class file.
 *
 * Owns all WordPress integration wiring for the reporting domain:
 * registers the Dashboard_Widget, the Reports_Page, and the
 * sybgo_freeze_weekly_report cron callback.
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
 * Report Module.
 *
 * Responsible for the reporting UI (dashboard widget, reports page) and the
 * weekly freeze cron event.
 *
 * @since 1.0.0
 */
class Report_Module implements Module_Interface {

	/**
	 * Factory instance.
	 *
	 * @var Factory
	 * @phpstan-ignore property.onlyWritten (used once boot() is implemented in sub-issue #96)
	 */
	private Factory $factory;

	/**
	 * Cron Manager instance.
	 *
	 * @var Cron_Manager
	 * @phpstan-ignore property.onlyWritten (used once boot() is implemented in sub-issue #96)
	 */
	private Cron_Manager $cron;

	/**
	 * Admin Manager instance.
	 *
	 * @var Admin_Manager
	 * @phpstan-ignore property.onlyWritten (used once boot() is implemented in sub-issue #96)
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
	 * Register reporting hooks, admin pages, and cron events.
	 *
	 * @return void
	 */
	public function boot(): void {
		// No-op stub — implementation follows in a dedicated sub-issue.
	}
}
