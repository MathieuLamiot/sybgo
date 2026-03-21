<?php
/**
 * AI Transport Interface file.
 *
 * @package Sybgo\AI
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Sybgo\AI;

/**
 * AI Transport Interface.
 *
 * Defines the contract for AI completion transports.
 * Implementations must call an AI provider and return the completion text.
 * Throw \RuntimeException on failure (the caller handles it gracefully).
 *
 * Note: $max_tokens is part of the interface contract for future-proofing.
 * Individual transport implementations may not honour it if the underlying
 * provider API does not expose token limits.
 *
 * @package Sybgo\AI
 * @since   1.0.0
 */
interface AI_Transport_Interface {
	/**
	 * Send a prompt and return the completion text.
	 *
	 * @param string $prompt     The prompt to send.
	 * @param int    $max_tokens Maximum tokens to generate.
	 * @return string The completion text.
	 * @throws \RuntimeException If the transport call fails.
	 */
	public function complete( string $prompt, int $max_tokens ): string;
}
