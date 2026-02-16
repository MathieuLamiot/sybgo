<?php
/**
 * Report Manager class file.
 *
 * This file defines the Report Manager for report lifecycle management.
 *
 * @package Rocket\Sybgo\Reports
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Rocket\Sybgo\Reports;

use Rocket\Sybgo\Database\Event_Repository;
use Rocket\Sybgo\Database\Report_Repository;

/**
 * Report Manager class.
 *
 * Manages report lifecycle: create, freeze, email coordination.
 *
 * @package Rocket\Sybgo\Reports
 * @since   1.0.0
 */
class Report_Manager {
	/**
	 * Event repository instance.
	 *
	 * @var Event_Repository
	 */
	private Event_Repository $event_repo;

	/**
	 * Report repository instance.
	 *
	 * @var Report_Repository
	 */
	private Report_Repository $report_repo;

	/**
	 * Report generator instance.
	 *
	 * @var Report_Generator
	 */
	private Report_Generator $generator;

	/**
	 * Constructor.
	 *
	 * @param Event_Repository  $event_repo Event repository.
	 * @param Report_Repository $report_repo Report repository.
	 * @param Report_Generator  $generator Report generator.
	 */
	public function __construct(
		Event_Repository $event_repo,
		Report_Repository $report_repo,
		Report_Generator $generator
	) {
		$this->event_repo  = $event_repo;
		$this->report_repo = $report_repo;
		$this->generator   = $generator;
	}

	/**
	 * Get or create active report.
	 *
	 * @return array Active report data.
	 */
	public function get_or_create_active_report(): array {
		$active = $this->report_repo->get_active();

		if ( $active ) {
			return $active;
		}

		// Create new active report.
		$report_id = $this->create_new_active_report();

		return $this->report_repo->get_by_id( $report_id );
	}

	/**
	 * Create new active report.
	 *
	 * @return int New report ID.
	 */
	public function create_new_active_report(): int {
		$report_id = $this->report_repo->create(
			array(
				'status'       => 'active',
				'period_start' => current_time( 'mysql' ),
			)
		);

		// Allow plugins to hook in.
		do_action( 'sybgo_report_created', $report_id );

		return $report_id;
	}

	/**
	 * Freeze current active report.
	 *
	 * This is called weekly (Sunday 23:55) or manually.
	 *
	 * @return int|false Frozen report ID on success, false on failure.
	 */
	public function freeze_current_report() {
		// Get active report.
		$active = $this->report_repo->get_active();

		if ( ! $active ) {
			return false;
		}

		$report_id = (int) $active['id'];

		// Fire before freeze hook.
		do_action( 'sybgo_before_report_freeze', $report_id );

		// Generate summary data.
		$summary = $this->generator->generate_summary( $report_id );

		// Assign all unassigned events to this report.
		$period_start = $active['period_start'];
		$period_end   = current_time( 'mysql' );

		$assigned_count = $this->event_repo->assign_to_report(
			$report_id,
			$period_start,
			$period_end
		);

		// Update report.
		$this->report_repo->update(
			$report_id,
			array(
				'status'       => 'frozen',
				'period_end'   => $period_end,
				'frozen_at'    => current_time( 'mysql' ),
				'summary_data' => $summary,
				'event_count'  => $assigned_count,
			)
		);

		// Fire after freeze hook.
		do_action( 'sybgo_after_report_freeze', $report_id, $summary );

		// Create new active report for next period.
		$this->create_new_active_report();

		return $report_id;
	}

	/**
	 * Get last frozen report.
	 *
	 * @return array|null Report data or null.
	 */
	public function get_last_frozen_report(): ?array {
		return $this->report_repo->get_last_frozen();
	}

	/**
	 * Get report by ID.
	 *
	 * @param int $report_id Report ID.
	 * @return array|null Report data or null.
	 */
	public function get_report( int $report_id ): ?array {
		return $this->report_repo->get_by_id( $report_id );
	}

	/**
	 * Get all frozen reports.
	 *
	 * @param int $limit Maximum number of reports.
	 * @param int $offset Offset for pagination.
	 * @return array Array of reports.
	 */
	public function get_all_frozen_reports( int $limit = 20, int $offset = 0 ): array {
		return $this->report_repo->get_all_frozen( $limit, $offset );
	}

	/**
	 * Get events for a report.
	 *
	 * @param int $report_id Report ID.
	 * @param int $limit Maximum events to return.
	 * @return array Array of events.
	 */
	public function get_report_events( int $report_id, int $limit = 100 ): array {
		return $this->event_repo->get_by_report( $report_id, $limit );
	}

	/**
	 * Get recent events for current active report.
	 *
	 * @param int $limit Number of events.
	 * @return array Recent events.
	 */
	public function get_recent_events( int $limit = 5 ): array {
		return $this->event_repo->get_recent( $limit );
	}

	/**
	 * Get event count for current active report.
	 *
	 * @return int Event count.
	 */
	public function get_active_event_count(): int {
		$events = $this->event_repo->get_by_report( null, 1000 ); // NULL = unassigned.
		return count( $events );
	}

	/**
	 * Mark report as emailed.
	 *
	 * @param int $report_id Report ID.
	 * @return bool True on success.
	 */
	public function mark_report_emailed( int $report_id ): bool {
		return $this->report_repo->update(
			$report_id,
			array(
				'status'     => 'emailed',
				'emailed_at' => current_time( 'mysql' ),
			)
		);
	}
}
