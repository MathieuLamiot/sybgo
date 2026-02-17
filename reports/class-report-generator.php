<?php
/**
 * Report Generator class file.
 *
 * This file defines the Report Generator for creating report summaries.
 *
 * @package Rocket\Sybgo\Reports
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Rocket\Sybgo\Reports;

use Rocket\Sybgo\Database\Event_Repository;
use Rocket\Sybgo\Database\Report_Repository;
use Rocket\Sybgo\AI\AI_Summarizer;

/**
 * Report Generator class.
 *
 * Aggregates events and generates report summaries with trends.
 *
 * @package Rocket\Sybgo\Reports
 * @since   1.0.0
 */
class Report_Generator {
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
	 * AI summarizer instance.
	 *
	 * @var AI_Summarizer
	 */
	private AI_Summarizer $ai_summarizer;

	/**
	 * Constructor.
	 *
	 * @param Event_Repository  $event_repo Event repository.
	 * @param Report_Repository $report_repo Report repository.
	 * @param AI_Summarizer     $ai_summarizer AI summarizer.
	 */
	public function __construct( Event_Repository $event_repo, Report_Repository $report_repo, AI_Summarizer $ai_summarizer ) {
		$this->event_repo    = $event_repo;
		$this->report_repo   = $report_repo;
		$this->ai_summarizer = $ai_summarizer;
	}

	/**
	 * Generate summary data for a report.
	 *
	 * @param int $report_id Report ID.
	 * @return array Summary data array.
	 */
	public function generate_summary( int $report_id ): array {
		// Get all events for this report.
		$events = $this->event_repo->get_by_report( $report_id );

		// Count events by type.
		$totals = $this->count_events_by_type( $events );

		// Get trend comparison with previous report.
		$trends = $this->get_trend_comparison( $report_id, $totals );

		// Generate highlights.
		$highlights = $this->generate_highlights( $totals, $trends );

		// Get top authors.
		$top_authors = $this->get_top_authors( $events );

		// Generate AI summary.
		$ai_summary = $this->ai_summarizer->generate_summary( $events, $totals, $trends );

		// Build summary data.
		$summary = [
			'totals'       => $totals,
			'trends'       => $trends,
			'highlights'   => $highlights,
			'top_authors'  => $top_authors,
			'total_events' => count( $events ),
			'ai_summary'   => $ai_summary,
		];

		// Allow filtering.
		return apply_filters( 'sybgo_report_summary', $summary, $report_id );
	}

	/**
	 * Count events by type.
	 *
	 * @param array $events Array of events.
	 * @return array Counts by event type.
	 */
	private function count_events_by_type( array $events ): array {
		$counts = [];

		foreach ( $events as $event ) {
			$type = $event['event_type'];

			if ( ! isset( $counts[ $type ] ) ) {
				$counts[ $type ] = 0;
			}

			++$counts[ $type ];
		}

		// Sort by count (descending).
		arsort( $counts );

		return $counts;
	}

	/**
	 * Get trend comparison with previous report.
	 *
	 * @param int   $current_report_id Current report ID.
	 * @param array $current_totals Current totals.
	 * @return array Trend data.
	 */
	public function get_trend_comparison( int $current_report_id, array $current_totals ): array {
		// Get previous frozen report.
		$all_reports = $this->report_repo->get_all_frozen( 2 );

		$previous_report = null;
		foreach ( $all_reports as $report ) {
			if ( (int) $report['id'] !== $current_report_id ) {
				$previous_report = $report;
				break;
			}
		}

		if ( ! $previous_report ) {
			return []; // First report, no comparison.
		}

		// Get previous totals from summary_data.
		$previous_summary = json_decode( $previous_report['summary_data'], true );
		$previous_totals  = $previous_summary['totals'] ?? [];

		$trends = [];

		// Calculate trends for each event type.
		foreach ( $current_totals as $type => $current_count ) {
			$previous_count = $previous_totals[ $type ] ?? 0;

			// Calculate percentage change.
			if ( $previous_count > 0 ) {
				$change = ( ( $current_count - $previous_count ) / $previous_count ) * 100;
			} else {
				$change = $current_count > 0 ? 100 : 0;
			}

			// Determine direction.
			if ( $change > 0 ) {
				$direction = 'up';
			} elseif ( $change < 0 ) {
				$direction = 'down';
			} else {
				$direction = 'same';
			}

			$trends[ $type ] = [
				'current'        => $current_count,
				'previous'       => $previous_count,
				'change_percent' => round( $change, 1 ),
				'direction'      => $direction,
			];
		}

		return $trends;
	}

	/**
	 * Generate highlights from totals and trends.
	 *
	 * @param array $totals Event totals.
	 * @param array $trends Trend data.
	 * @return array Array of highlight strings.
	 */
	private function generate_highlights( array $totals, array $trends ): array {
		$highlights = [];

		// Event type labels (user-friendly).
		$labels = [
			'post_published'    => 'new posts published',
			'post_edited'       => 'posts edited',
			'page_published'    => 'new pages published',
			'user_registered'   => 'new users registered',
			'user_role_changed' => 'user role changes',
			'core_updated'      => 'WordPress core updated',
			'plugin_updated'    => 'plugins updated',
			'theme_updated'     => 'themes updated',
			'comment_posted'    => 'new comments',
			'comment_approved'  => 'comments approved',
			'comment_spam'      => 'comments marked as spam',
		];

		foreach ( $totals as $type => $count ) {
			if ( 0 === $count ) {
				continue;
			}

			$label = $labels[ $type ] ?? $type;

			// Build highlight string with trend indicator.
			$highlight = sprintf( '%d %s', $count, $label );

			if ( isset( $trends[ $type ] ) ) {
				$trend = $trends[ $type ];

				if ( 'same' !== $trend['direction'] ) {
					$arrow      = 'up' === $trend['direction'] ? '↑' : '↓';
					$highlight .= sprintf( ' %s %.1f%%', $arrow, abs( $trend['change_percent'] ) );
				}
			}

			$highlights[] = $highlight;
		}

		return $highlights;
	}

	/**
	 * Get top authors from post events.
	 *
	 * @param array $events All events.
	 * @return array Top authors with post counts.
	 */
	private function get_top_authors( array $events ): array {
		$author_counts = [];

		foreach ( $events as $event ) {
			// Only count post/page publish events.
			if ( ! in_array( $event['event_type'], [ 'post_published', 'page_published' ], true ) ) {
				continue;
			}

			$event_data = json_decode( $event['event_data'], true );

			if ( ! $event_data || ! isset( $event_data['context']['user_name'] ) ) {
				continue;
			}

			$author = $event_data['context']['user_name'];

			if ( ! isset( $author_counts[ $author ] ) ) {
				$author_counts[ $author ] = 0;
			}

			++$author_counts[ $author ];
		}

		// Sort by count (descending).
		arsort( $author_counts );

		// Format as array of objects.
		$top_authors = [];
		$count       = 0;

		foreach ( $author_counts as $author => $posts ) {
			$top_authors[] = [
				'name'  => $author,
				'count' => $posts,
			];

			++$count;
			if ( $count >= 5 ) { // Top 5 authors.
				break;
			}
		}

		return $top_authors;
	}
}
