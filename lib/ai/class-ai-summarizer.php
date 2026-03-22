<?php
/**
 * AI Summarizer class file.
 *
 * This file defines the AI Summarizer for generating human-friendly report summaries using Claude API.
 *
 * @package Sybgo\AI
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Sybgo\AI;

require_once __DIR__ . '/interface-ai-transport.php';

use Sybgo\Database\Report_Repository;
use Sybgo\Events\Event_Registry;
use Sybgo\Logger;

/**
 * AI Summarizer class.
 *
 * Generates natural language summaries of reports using an AI transport.
 *
 * @package Sybgo\AI
 * @since   1.0.0
 */
class AI_Summarizer {
	/**
	 * Report repository instance.
	 *
	 * @var Report_Repository
	 */
	private Report_Repository $report_repo;

	/**
	 * Event registry instance.
	 *
	 * @var Event_Registry
	 */
	private Event_Registry $event_registry;

	/**
	 * AI transport instance.
	 *
	 * @var AI_Transport_Interface
	 */
	private AI_Transport_Interface $transport;

	/**
	 * Constructor.
	 *
	 * @param Report_Repository      $report_repo    Report repository.
	 * @param Event_Registry         $event_registry Event registry.
	 * @param AI_Transport_Interface $transport      AI transport implementation.
	 */
	public function __construct( Report_Repository $report_repo, Event_Registry $event_registry, AI_Transport_Interface $transport ) {
		$this->report_repo    = $report_repo;
		$this->event_registry = $event_registry;
		$this->transport      = $transport;
	}

	/**
	 * Generate AI summary for events.
	 *
	 * @param array<int, array<string, mixed>>    $events Array of events.
	 * @param array<string, int>                  $totals Event totals by type.
	 * @param array<string, array<string, mixed>> $trends Trend data comparing to previous report.
	 * @return string|null AI-generated summary or null if transport fails.
	 */
	public function generate_summary( array $events, array $totals, array $trends ): ?string {
		// Build the prompt.
		$prompt = $this->build_prompt( $events, $totals, $trends );

		// Call transport.
		try {
			$response = $this->transport->complete( $prompt, 500 );
			return $response;
		} catch ( \RuntimeException $e ) {
			// Log error but don't fail the whole process.
			Logger::error( 'AI Summarizer: ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * Build the prompt for the AI provider.
	 *
	 * @param array<int, array<string, mixed>>    $events Array of events.
	 * @param array<string, int>                  $totals Event totals by type.
	 * @param array<string, array<string, mixed>> $trends Trend data.
	 * @return string The prompt.
	 */
	private function build_prompt( array $events, array $totals, array $trends ): string {
		$prompt  = 'You are a friendly coworker reviewing WordPress site activity for the week. ';
		$prompt .= "Write a conversational summary as if you're telling a colleague what happened on their website. ";
		$prompt .= 'Be warm, encouraging, and focus on the most important changes. ';
		$prompt .= "Use 'you' to address them directly (e.g., 'You published 3 new posts this week'). ";
		$prompt .= "Keep it concise (3-5 sentences max). Don't list every event - highlight the main activities.\n\n";

		// Add totals summary.
		$prompt .= "## Event Summary\n";
		$prompt .= 'Total events this week: ' . count( $events ) . "\n\n";

		if ( ! empty( $totals ) ) {
			$prompt .= "Event breakdown:\n";
			foreach ( $totals as $type => $count ) {
				$prompt .= '- ' . ucwords( str_replace( '_', ' ', $type ) ) . ": {$count}\n";
			}
			$prompt .= "\n";
		}

		// Add trends if available.
		if ( ! empty( $trends ) ) {
			$prompt .= "## Trends vs. Last Week\n";
			foreach ( $trends as $type => $trend ) {
				if ( 'same' !== $trend['direction'] ) {
					$arrow   = 'up' === $trend['direction'] ? '↑' : '↓';
					$change  = abs( $trend['change_percent'] );
					$prompt .= '- ' . ucwords( str_replace( '_', ' ', $type ) ) . ": {$arrow} {$change}% ";
					$prompt .= "({$trend['previous']} → {$trend['current']})\n";
				}
			}
			$prompt .= "\n";
		}

		// Add key events (max 10 most recent).
		$prompt       .= "## Recent Events\n";
		$recent_events = array_slice( $events, 0, 10 );
		foreach ( $recent_events as $event ) {
			$event_data = json_decode( $event['event_data'], true );
			if ( ! $event_data ) {
				continue;
			}

			$type        = $event['event_type'];
			$object      = $event_data['object'] ?? array();
			$metadata    = $event_data['metadata'] ?? array();
			$description = $this->event_registry->get_ai_description( $type, $object, $metadata );

			if ( $description ) {
				$prompt .= "- {$description}\n";
			}
		}

		$prompt .= "\n## Instructions\n";
		$prompt .= 'Write a friendly 3-5 sentence summary highlighting the most important activities. ';
		$prompt .= 'Mention trends if significant. Use a warm, encouraging tone. ';
		$prompt .= "Don't just list numbers - tell a story about what happened on the site this week.";

		return $prompt;
	}
}
