<?php
/**
 * Email Module class file.
 *
 * Owns all WordPress integration wiring for the email domain:
 * registers the sybgo_send_report_emails and sybgo_retry_failed_emails
 * cron callbacks.
 *
 * @package Sybgo\Modules
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Sybgo\Modules;

use Sybgo\Cron_Manager;
use Sybgo\Factory;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email Module.
 *
 * Responsible for sending weekly digest emails and retrying failed deliveries
 * via WP-Cron events.
 *
 * @since 1.0.0
 */
class Email_Module implements Module_Interface {

	/**
	 * Factory instance.
	 *
	 * @var Factory
	 * @phpstan-ignore property.onlyWritten (used once boot() is implemented in sub-issue #97)
	 */
	private Factory $factory;

	/**
	 * Cron Manager instance.
	 *
	 * @var Cron_Manager
	 * @phpstan-ignore property.onlyWritten (used once boot() is implemented in sub-issue #97)
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
		// No-op stub — implementation follows in a dedicated sub-issue.
	}
}
