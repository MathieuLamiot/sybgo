<?php
/**
 * Email Module class file.
 *
 * Owns all WordPress integration wiring for the email domain:
 * registers the sybgo_send_report_emails and sybgo_retry_failed_emails
 * cron callbacks as named, testable methods.
 *
 * @package Sybgo\Modules
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Sybgo\Modules;

use Sybgo\Cron_Manager;
use Sybgo\Factory;
use Sybgo\Logger;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email Module.
 *
 * Responsible for sending weekly digest emails and retrying failed deliveries
 * via WP-Cron events. Both callbacks are public named methods to make them
 * independently testable.
 *
 * @since 1.0.0
 */
class Email_Module implements Module_Interface {

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
	 * Constructor.
	 *
	 * @param Factory      $factory Factory instance.
	 * @param Cron_Manager $cron    Cron Manager instance.
	 */
	public function __construct( Factory $factory, Cron_Manager $cron ) {
		$this->factory = $factory;
		$this->cron    = $cron;
	}

	/**
	 * Register email cron events.
	 *
	 * @return void
	 */
	public function boot(): void {
		$this->cron->register(
			'sybgo_send_report_emails',
			'weekly',
			array( $this, 'send_report_emails_callback' ),
			'next Monday 00:05'
		);

		$this->cron->register(
			'sybgo_retry_failed_emails',
			'daily',
			array( $this, 'retry_failed_emails_callback' ),
			'tomorrow 9:00'
		);
	}

	/**
	 * Cron callback: send the weekly digest email for the last frozen report.
	 *
	 * Preserves the (int) cast on the report id — $wpdb returns column values
	 * as strings, and Email_Manager::send_report_email() is strictly typed
	 * against int (regression guard for issue #68).
	 *
	 * @return void
	 */
	public function send_report_emails_callback(): void {
		$report_repo   = $this->factory->create_report_repository();
		$email_manager = $this->factory->create_email_manager();

		$last_frozen = $report_repo->get_last_frozen();

		if ( ! $last_frozen ) {
			return;
		}

		// Cast report id to int — $wpdb returns column values as strings, but
		// Email_Manager::send_report_email() is strictly typed against int.
		$report_id = (int) $last_frozen['id'];

		$sent = $email_manager->send_report_email( $report_id );

		if ( $sent ) {
			Logger::info( sprintf( 'Successfully sent weekly digest for report #%d', $report_id ) );
		} else {
			Logger::error( sprintf( 'Failed to send weekly digest for report #%d', $report_id ) );
		}
	}

	/**
	 * Cron callback: retry failed email deliveries.
	 *
	 * @return void
	 */
	public function retry_failed_emails_callback(): void {
		$email_manager = $this->factory->create_email_manager();
		$retried       = $email_manager->retry_failed_emails();

		if ( $retried > 0 ) {
			Logger::info( sprintf( 'Retried %d failed emails', $retried ) );
		}
	}
}
