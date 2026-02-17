<?php
/**
 * Email Manager class file.
 *
 * This file defines the Email Manager for sending weekly digest emails.
 *
 * @package Rocket\Sybgo\Email
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Rocket\Sybgo\Email;

use Rocket\Sybgo\Database\Report_Repository;
use Rocket\Sybgo\Admin\Settings_Page;

/**
 * Email Manager class.
 *
 * Handles sending weekly digest emails with retry logic.
 *
 * @package Rocket\Sybgo\Email
 * @since   1.0.0
 */
class Email_Manager {
	/**
	 * Report repository instance.
	 *
	 * @var Report_Repository
	 */
	private Report_Repository $report_repo;

	/**
	 * Email template instance.
	 *
	 * @var Email_Template
	 */
	private Email_Template $email_template;

	/**
	 * Constructor.
	 *
	 * @param Report_Repository $report_repo Report repository.
	 * @param Email_Template    $email_template Email template.
	 */
	public function __construct( Report_Repository $report_repo, Email_Template $email_template ) {
		$this->report_repo    = $report_repo;
		$this->email_template = $email_template;
	}

	/**
	 * Send report email to all configured recipients.
	 *
	 * @param int $report_id Report ID to send.
	 * @return bool True if all emails sent successfully, false otherwise.
	 */
	public function send_report_email( int $report_id ): bool {
		$report = $this->report_repo->get_by_id( $report_id );

		if ( ! $report ) {
			return false;
		}

		// Get recipients from settings.
		$recipients = Settings_Page::get_recipients();

		if ( empty( $recipients ) ) {
			return false;
		}

		// Check if should send empty reports.
		$summary = json_decode( $report['summary_data'], true );
		if ( ! $this->should_send_report( $summary ) ) {
			// Mark as emailed even though we didn't send (quiet period).
			$this->report_repo->update_status( $report_id, 'emailed' );
			return true;
		}

		// Generate email content.
		$subject = $this->email_template->get_subject( $report );
		$body    = $this->email_template->get_body( $report );
		$headers = $this->get_email_headers();

		$all_sent = true;

		// Send to each recipient individually.
		foreach ( $recipients as $recipient ) {
			$sent = wp_mail( $recipient, $subject, $body, $headers );

			if ( ! $sent ) {
				$all_sent = false;
				$this->log_email_failure( $report_id, $recipient );
			} else {
				$this->log_email_success( $report_id, $recipient );
			}
		}

		// Update report status if all sent successfully.
		if ( $all_sent ) {
			$this->report_repo->update_status( $report_id, 'emailed' );
		}

		return $all_sent;
	}

	/**
	 * Check if report should be sent.
	 *
	 * @param array|null $summary Report summary data.
	 * @return bool True if should send, false otherwise.
	 */
	private function should_send_report( ?array $summary ): bool {
		if ( ! $summary ) {
			return false;
		}

		// Check if report is empty.
		$total_events = $summary['total_events'] ?? 0;

		if ( 0 === $total_events ) {
			// Check settings for empty reports.
			$settings   = get_option( Settings_Page::OPTION_NAME, array() );
			$send_empty = $settings['send_empty_reports'] ?? false;

			return $send_empty;
		}

		return true;
	}

	/**
	 * Get email headers.
	 *
	 * @return array Email headers.
	 */
	private function get_email_headers(): array {
		$settings = get_option( Settings_Page::OPTION_NAME, array() );

		$from_name  = $settings['from_name'] ?? get_bloginfo( 'name' );
		$from_email = $settings['from_email'] ?? get_option( 'admin_email' );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $from_name, $from_email ),
		);

		/**
		 * Filter email headers.
		 *
		 * @param array $headers Email headers.
		 */
		return apply_filters( 'sybgo_email_headers', $headers );
	}

	/**
	 * Log successful email send.
	 *
	 * @param int    $report_id Report ID.
	 * @param string $recipient Recipient email.
	 * @return void
	 */
	private function log_email_success( int $report_id, string $recipient ): void {
		global $wpdb;

		$table_name = $wpdb->prefix . 'sybgo_email_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Email log insert; no repository for email_log table.
		$wpdb->insert(
			$table_name,
			array(
				'report_id'     => $report_id,
				'recipient'     => $recipient,
				'status'        => 'sent',
				'sent_at'       => current_time( 'mysql' ),
				'error_message' => null,
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);

		/**
		 * Action fired after successful email send.
		 *
		 * @param int    $report_id Report ID.
		 * @param string $recipient Recipient email.
		 */
		do_action( 'sybgo_email_sent', $report_id, $recipient );
	}

	/**
	 * Log failed email send.
	 *
	 * @param int    $report_id Report ID.
	 * @param string $recipient Recipient email.
	 * @return void
	 */
	private function log_email_failure( int $report_id, string $recipient ): void {
		global $wpdb;

		$table_name = $wpdb->prefix . 'sybgo_email_log';

		$error         = error_get_last();
		$error_message = $error ? $error['message'] : 'Unknown error';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Email log insert; no repository for email_log table.
		$wpdb->insert(
			$table_name,
			array(
				'report_id'     => $report_id,
				'recipient'     => $recipient,
				'status'        => 'failed',
				'sent_at'       => current_time( 'mysql' ),
				'error_message' => $error_message,
				'retry_count'   => 0,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d' )
		);

		/**
		 * Action fired after failed email send.
		 *
		 * @param int    $report_id Report ID.
		 * @param string $recipient Recipient email.
		 * @param string $error_message Error message.
		 */
		do_action( 'sybgo_email_failed', $report_id, $recipient, $error_message );
	}

	/**
	 * Retry failed emails.
	 *
	 * @return int Number of emails retried.
	 */
	public function retry_failed_emails(): int {
		global $wpdb;

		$table_name = $wpdb->prefix . 'sybgo_email_log';

		// Get failed emails with retry count < 3.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Email log query; no repository for email_log table.
		$failed_emails = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name variable; not user input.
				"SELECT * FROM {$table_name} WHERE status = %s AND retry_count < %d ORDER BY sent_at DESC LIMIT 10",
				'failed',
				3
			),
			ARRAY_A
		);

		if ( empty( $failed_emails ) ) {
			return 0;
		}

		$retried = 0;

		foreach ( $failed_emails as $log ) {
			$report = $this->report_repo->get_by_id( $log['report_id'] );

			if ( ! $report ) {
				continue;
			}

			// Generate email content.
			$subject = $this->email_template->get_subject( $report );
			$body    = $this->email_template->get_body( $report );
			$headers = $this->get_email_headers();

			// Attempt to send.
			$sent = wp_mail( $log['recipient'], $subject, $body, $headers );

			if ( $sent ) {
				// Update to successful.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Email log update; no repository for email_log table.
				$wpdb->update(
					$table_name,
					array(
						'status'  => 'sent',
						'sent_at' => current_time( 'mysql' ),
					),
					array( 'id' => $log['id'] ),
					array( '%s', '%s' ),
					array( '%d' )
				);

				++$retried;
			} else {
				// Increment retry count.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Email log update; no repository for email_log table.
				$wpdb->update(
					$table_name,
					array(
						'retry_count' => $log['retry_count'] + 1,
						'sent_at'     => current_time( 'mysql' ),
					),
					array( 'id' => $log['id'] ),
					array( '%d', '%s' ),
					array( '%d' )
				);
			}
		}

		return $retried;
	}

	/**
	 * Get email log for a report.
	 *
	 * @param int $report_id Report ID.
	 * @return array Email log entries.
	 */
	public function get_email_log( int $report_id ): array {
		global $wpdb;

		$table_name = $wpdb->prefix . 'sybgo_email_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Email log query; no repository for email_log table.
		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name variable; not user input.
				"SELECT * FROM {$table_name} WHERE report_id = %d ORDER BY sent_at DESC",
				$report_id
			),
			ARRAY_A
		);
	}
}
