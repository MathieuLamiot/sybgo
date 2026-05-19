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
use Sybgo\Admin\Dashboard_Widget;
use Sybgo\Admin\Reports_Page;
use Sybgo\Cron_Manager;
use Sybgo\Factory;
use Sybgo\Logger;
use Sybgo\Reports\Report_Generator;

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
	 */
	private Factory $factory;

	/**
	 * Cron Manager instance.
	 *
	 * @var Cron_Manager
	 */
	private Cron_Manager $cron;

	/**
	 * Admin Manager instance.
	 *
	 * @var Admin_Manager
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
	 * Registers Dashboard_Widget and Reports_Page with Admin_Manager, and
	 * wires the sybgo_freeze_weekly_report cron hook to the named
	 * freeze_report_callback() method so it is independently testable.
	 *
	 * @return void
	 */
	public function boot(): void {
		$this->admin->register_page( $this->build_dashboard_widget() );
		$this->admin->register_page( $this->build_reports_page() );

		$this->cron->register(
			'sybgo_freeze_weekly_report',
			'weekly',
			array( $this, 'freeze_report_callback' ),
			'next Sunday 23:55'
		);
	}

	/**
	 * Cron callback: freeze the current active report.
	 *
	 * Invoked by WP-Cron on the sybgo_freeze_weekly_report hook (weekly,
	 * Sunday 23:55). Delegates to Report_Manager::freeze_current_report()
	 * and logs the outcome.
	 *
	 * @return void
	 */
	public function freeze_report_callback(): void {
		$report_manager = $this->factory->create_report_manager();
		$frozen_id      = $report_manager->freeze_current_report();

		if ( $frozen_id ) {
			Logger::info( sprintf( 'Weekly report #%d frozen successfully', $frozen_id ) );
		} else {
			Logger::info( 'No active report to freeze' );
		}
	}

	/**
	 * Build the Dashboard_Widget instance with all required dependencies.
	 *
	 * @return Dashboard_Widget
	 */
	private function build_dashboard_widget(): Dashboard_Widget {
		$event_repo       = $this->factory->create_event_repository();
		$report_repo      = $this->factory->create_report_repository();
		$event_registry   = $this->factory->create_event_registry();
		$ai_summarizer    = $this->factory->create_ai_summarizer();
		$aggregated_repo  = $this->factory->create_aggregated_event_repository();
		$report_generator = new Report_Generator( $event_repo, $report_repo );

		return new Dashboard_Widget(
			$event_repo,
			$report_repo,
			$report_generator,
			$ai_summarizer,
			$event_registry,
			$aggregated_repo
		);
	}

	/**
	 * Build the Reports_Page instance with all required dependencies.
	 *
	 * @return Reports_Page
	 */
	private function build_reports_page(): Reports_Page {
		$event_repo       = $this->factory->create_event_repository();
		$report_repo      = $this->factory->create_report_repository();
		$event_registry   = $this->factory->create_event_registry();
		$report_manager   = $this->factory->create_report_manager();
		$ai_summarizer    = $this->factory->create_ai_summarizer();
		$report_generator = new Report_Generator( $event_repo, $report_repo );
		$email_manager    = $this->factory->create_email_manager();
		$aggregated_repo  = $this->factory->create_aggregated_event_repository();

		return new Reports_Page(
			$event_repo,
			$report_repo,
			$report_manager,
			$report_generator,
			$email_manager,
			$event_registry,
			$aggregated_repo,
			$ai_summarizer
		);
	}
}
