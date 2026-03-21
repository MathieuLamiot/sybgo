<?php
/**
 * WP7 AI Transport class file.
 *
 * @package Sybgo\AI
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Sybgo\AI;

require_once __DIR__ . '/interface-ai-transport.php';

/**
 * WP7 AI Transport.
 *
 * Implements AI_Transport_Interface using the WordPress 7 native AI API
 * (wp_ai_client_prompt). Only instantiated when running on WordPress 7+.
 *
 * Note: The $max_tokens parameter is accepted per the interface contract but
 * is not forwarded to the WP 7 AI API, which does not expose token limits
 * directly. This may be addressed in future versions.
 *
 * @package Sybgo\AI
 * @since   1.0.0
 */
class WP7_AI_Transport implements AI_Transport_Interface {
	/**
	 * Send a prompt to the WordPress 7 native AI provider and return the completion.
	 *
	 * @param string $prompt     The prompt to send.
	 * @param int    $max_tokens Maximum tokens to generate (not forwarded to WP 7 API).
	 * @return string The completion text.
	 * @throws \RuntimeException If the WP 7 AI API returns a WP_Error.
	 */
	public function complete( string $prompt, int $max_tokens ): string {
		$result = wp_ai_client_prompt( $prompt )->generate_text();

		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( $result->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $result;
	}
}
