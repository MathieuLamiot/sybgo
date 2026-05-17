<?php
/**
 * Cron Manager class file.
 *
 * Owns all WP-Cron concerns: schedule registration, hook wiring, and deactivation cleanup.
 * Acts as a pure wiring layer — callbacks delegate to functional classes.
 *
 * @package Sybgo
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Sybgo;

use Sybgo\Database\DatabaseManager;
use Sybgo\Email\Email_Manager;
use Sybgo\Reports\Report_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cron Manager.
 *
 * Registers custom cron intervals, schedules the four plugin events, and wires
 * their callbacks to functional-class methods.
 *
 * @package Sybgo
 * @since   1.0.0
 */
class Cron_Manager {

	/**
	 * Report manager instance.
	 *
	 * @var Report_Manager
	 */
	private Report_Manager $report_manager;

	/**
	 * Email manager instance.
	 *
	 * @var Email_Manager
	 */
	private Email_Manager $email_manager;

	/**
	 * Database manager instance.
	 *
	 * @var DatabaseManager
	 */
	private DatabaseManager $db_manager;

	/**
	 * Constructor.
	 *
	 * @param Report_Manager  $report_manager Report manager for freeze and send callbacks.
	 * @param Email_Manager   $email_manager  Email manager for send and retry callbacks.
	 * @param DatabaseManager $db_manager     Database manager for cleanup callback.
	 */
	public function __construct(
		Report_Manager $report_manager,
		Email_Manager $email_manager,
		DatabaseManager $db_manager
	) {
		$this->report_manager = $report_manager;
		$this->email_manager  = $email_manager;
		$this->db_manager     = $db_manager;
	}

	/**
	 * All WP-Cron hook names registered by the plugin.
	 *
	 * Single source of truth used by init(), deactivate(), and Uninstaller.
	 *
	 * @return array<string>
	 */
	public static function get_hooks(): array {
		return array(
			'sybgo_freeze_weekly_report',
			'sybgo_send_report_emails',
			'sybgo_cleanup_old_events',
			'sybgo_retry_failed_emails',
		);
	}

	/**
	 * Register the custom 'weekly' cron interval.
	 *
	 * @param array<string, array<string, mixed>> $schedules Existing schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public function add_cron_intervals( array $schedules ): array {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => 604800, // 7 days in seconds.
				'display'  => esc_html__( 'Once Weekly', 'sybgo' ),
			);
		}
		return $schedules;
	}

	/**
	 * Schedule all four cron events and wire their callbacks.
	 *
	 * @return void
	 */
	public function init(): void {
		$hooks = self::get_hooks();

		add_filter( 'cron_schedules', array( $this, 'add_cron_intervals' ) );

		// Schedule weekly freeze (Sunday 23:55).
		if ( ! wp_next_scheduled( $hooks[0] ) ) {
			$next_sunday = strtotime( 'next Sunday 23:55' );
			wp_schedule_event( $next_sunday, 'weekly', $hooks[0] );
		}

		// Schedule weekly email (Monday 00:05).
		if ( ! wp_next_scheduled( $hooks[1] ) ) {
			$next_monday = strtotime( 'next Monday 00:05' );
			wp_schedule_event( $next_monday, 'weekly', $hooks[1] );
		}

		// Schedule daily cleanup (3am).
		if ( ! wp_next_scheduled( $hooks[2] ) ) {
			$next_3am = strtotime( 'tomorrow 3:00' );
			wp_schedule_event( $next_3am, 'daily', $hooks[2] );
		}

		// Schedule daily retry for failed emails (9am).
		if ( ! wp_next_scheduled( $hooks[3] ) ) {
			$next_9am = strtotime( 'tomorrow 9:00' );
			wp_schedule_event( $next_9am, 'daily', $hooks[3] );
		}

		add_action( $hooks[0], array( $this, 'freeze_weekly_report_callback' ) );
		add_action( $hooks[1], array( $this, 'send_report_emails_callback' ) );
		add_action( $hooks[2], array( $this, 'cleanup_old_events_callback' ) );
		add_action( $hooks[3], array( $this, 'retry_failed_emails_callback' ) );
	}

	/**
	 * Clear all scheduled cron events.
	 *
	 * Static so it can be called from Sybgo::deactivate() and Uninstaller
	 * without requiring a fully-constructed instance.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		foreach ( self::get_hooks() as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Cron callback: freeze the current weekly report.
	 *
	 * Void wrapper required because freeze_current_report() returns int|false,
	 * which PHPStan rejects when used directly as a WP action callback.
	 *
	 * @return void
	 */
	public function freeze_weekly_report_callback(): void {
		$this->report_manager->freeze_current_report();
	}

	/**
	 * Cron callback: retry all failed email sends.
	 *
	 * Void wrapper required because retry_failed_emails() returns int,
	 * which PHPStan rejects when used directly as a WP action callback.
	 *
	 * @return void
	 */
	public function retry_failed_emails_callback(): void {
		$this->email_manager->retry_failed_emails();
	}

	/**
	 * Cron callback: send the weekly digest for the last frozen report.
	 *
	 * Keeps orchestration logic (get frozen report → cast id → send) that cannot
	 * be a direct wire to a single functional-class method.
	 * The explicit int cast preserves the fix for #68: $wpdb returns column
	 * values as strings, but Email_Manager::send_report_email() is strictly typed.
	 *
	 * @return void
	 */
	public function send_report_emails_callback(): void {
		$last_frozen = $this->report_manager->get_last_frozen_report();

		if ( ! $last_frozen ) {
			return;
		}

		// Cast report id to int — $wpdb returns column values as strings (#68).
		$report_id = (int) $last_frozen['id'];

		$sent = $this->email_manager->send_report_email( $report_id );

		if ( $sent ) {
			Logger::info( sprintf( 'Successfully sent weekly digest for report #%d', $report_id ) );
		} else {
			Logger::error( sprintf( 'Failed to send weekly digest for report #%d', $report_id ) );
		}
	}

	/**
	 * Cron callback: delete events older than the configured retention period.
	 *
	 * Reads the retention setting at call-time so admin changes take effect on
	 * the next scheduled run without requiring re-activation.
	 *
	 * @return void
	 */
	public function cleanup_old_events_callback(): void {
		$days    = Admin\Settings_Page::get_retention_days();
		$deleted = $this->db_manager->cleanup_old_events( $days );

		if ( $deleted > 0 ) {
			Logger::info( sprintf( 'Cleaned up %d rows (retention: %d days)', $deleted, $days ) );
		}
	}
}
