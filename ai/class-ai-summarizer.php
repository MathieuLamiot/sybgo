<?php
/**
 * AI Summarizer class file.
 *
 * This file defines the AI Summarizer for generating human-friendly report summaries using Claude API.
 *
 * @package Rocket\Sybgo\AI
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Rocket\Sybgo\AI;

use Rocket\Sybgo\Database\Report_Repository;

/**
 * AI Summarizer class.
 *
 * Generates natural language summaries of reports using Anthropic's Claude API.
 *
 * @package Rocket\Sybgo\AI
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
	 * Constructor.
	 *
	 * @param Report_Repository $report_repo Report repository.
	 */
	public function __construct( Report_Repository $report_repo ) {
		$this->report_repo = $report_repo;
	}

	/**
	 * Generate AI summary for events.
	 *
	 * @param array $events Array of events.
	 * @param array $totals Event totals by type.
	 * @param array $trends Trend data comparing to previous report.
	 * @return string|null AI-generated summary or null if API key not configured.
	 */
	public function generate_summary( array $events, array $totals, array $trends ): ?string {
		// Get API key from settings.
		$api_key = \Rocket\Sybgo\Admin\Settings_Page::get_anthropic_api_key();

		if ( empty( $api_key ) ) {
			return null;
		}

		// Build the prompt.
		$prompt = $this->build_prompt( $events, $totals, $trends );

		// Call Claude API.
		try {
			$response = $this->call_claude_api( $api_key, $prompt );
			return $response;
		} catch ( \Exception $e ) {
			// Log error but don't fail the whole process.
			error_log( 'Sybgo AI Summarizer error: ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * Build the prompt for Claude.
	 *
	 * @param array $events Array of events.
	 * @param array $totals Event totals by type.
	 * @param array $trends Trend data.
	 * @return string The prompt.
	 */
	private function build_prompt( array $events, array $totals, array $trends ): string {
		$prompt  = "You are a friendly coworker reviewing WordPress site activity for the week. ";
		$prompt .= "Write a conversational summary as if you're telling a colleague what happened on their website. ";
		$prompt .= "Be warm, encouraging, and focus on the most important changes. ";
		$prompt .= "Use 'you' to address them directly (e.g., 'You published 3 new posts this week'). ";
		$prompt .= "Keep it concise (3-5 sentences max). Don't list every event - highlight the main activities.\n\n";

		// Add totals summary.
		$prompt .= "## Event Summary\n";
		$prompt .= "Total events this week: " . count( $events ) . "\n\n";

		if ( ! empty( $totals ) ) {
			$prompt .= "Event breakdown:\n";
			foreach ( $totals as $type => $count ) {
				$prompt .= "- " . ucwords( str_replace( '_', ' ', $type ) ) . ": {$count}\n";
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
					$prompt .= "- " . ucwords( str_replace( '_', ' ', $type ) ) . ": {$arrow} {$change}% ";
					$prompt .= "({$trend['previous']} → {$trend['current']})\n";
				}
			}
			$prompt .= "\n";
		}

		// Add key events (max 10 most recent).
		$prompt         .= "## Recent Events\n";
		$recent_events   = array_slice( $events, 0, 10 );
		foreach ( $recent_events as $event ) {
			$event_data = json_decode( $event['event_data'], true );
			if ( ! $event_data ) {
				continue;
			}

			$type        = $event['event_type'];
			$object      = $event_data['object'] ?? array();
			$metadata    = $event_data['metadata'] ?? array();
			$description = $this->get_event_description( $type, $object, $metadata );

			if ( $description ) {
				$prompt .= "- {$description}\n";
			}
		}

		$prompt .= "\n## Instructions\n";
		$prompt .= "Write a friendly 3-5 sentence summary highlighting the most important activities. ";
		$prompt .= "Mention trends if significant. Use a warm, encouraging tone. ";
		$prompt .= "Don't just list numbers - tell a story about what happened on the site this week.";

		return $prompt;
	}

	/**
	 * Get human-readable description of an event.
	 *
	 * @param string $type Event type.
	 * @param array  $object Object data.
	 * @param array  $metadata Metadata.
	 * @return string Event description.
	 */
	private function get_event_description( string $type, array $object, array $metadata ): string {
		switch ( $type ) {
			case 'post_published':
				return sprintf( 'Published post: "%s"', $object['title'] ?? 'Untitled' );

			case 'post_edited':
				$magnitude = $metadata['edit_magnitude'] ?? 0;
				return sprintf( 'Edited post "%s" (%d%% changed)', $object['title'] ?? 'Untitled', $magnitude );

			case 'plugin_installed':
				return sprintf( 'Installed plugin: %s', $object['name'] ?? 'Unknown' );

			case 'plugin_activated':
				return sprintf( 'Activated plugin: %s', $object['name'] ?? 'Unknown' );

			case 'plugin_deactivated':
				return sprintf( 'Deactivated plugin: %s', $object['name'] ?? 'Unknown' );

			case 'plugin_updated':
				$new_ver = $metadata['new_version'] ?? 'latest';
				return sprintf( 'Updated plugin %s to v%s', $object['name'] ?? 'Unknown', $new_ver );

			case 'theme_switched':
				return sprintf( 'Switched theme to: %s', $object['name'] ?? 'Unknown' );

			case 'user_registered':
				return sprintf( 'New user registered: %s', $object['username'] ?? 'Unknown' );

			case 'core_updated':
				$new_ver = $metadata['new_version'] ?? 'latest';
				return sprintf( 'Updated WordPress to v%s', $new_ver );

			default:
				return '';
		}
	}

	/**
	 * Call Claude API.
	 *
	 * @param string $api_key API key.
	 * @param string $prompt The prompt.
	 * @return string The response.
	 * @throws \Exception If API call fails.
	 */
	private function call_claude_api( string $api_key, string $prompt ): string {
		$url = 'https://api.anthropic.com/v1/messages';

		$body = array(
			'model'      => 'claude-3-5-haiku-20241022',
			'max_tokens' => 500,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'         => 'application/json',
					'x-api-key'            => $api_key,
					'anthropic-version'    => '2023-06-01',
					'anthropic-dangerous-direct-browser-access' => 'true',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \Exception( 'API request failed: ' . $response->get_error_message() );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			$body = wp_remote_retrieve_body( $response );
			throw new \Exception( "API returned status {$status_code}: {$body}" );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! isset( $data['content'][0]['text'] ) ) {
			throw new \Exception( 'Invalid API response format' );
		}

		return $data['content'][0]['text'];
	}
}
