<?php
/**
 * Site Secret Manager.
 *
 * Generates, stores, and rotates the 256-bit HMAC secret used to sign all
 * MCP JWTs.  Regenerating the secret immediately invalidates every outstanding
 * access and refresh token site-wide.
 *
 * @package Sybgo\MCP\Auth
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Sybgo\MCP\Auth;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Secret Manager.
 *
 * @since 1.0.0
 */
class Secret_Manager {

	/**
	 * WordPress option key where the JWT signing secret is stored.
	 */
	const OPTION_KEY = 'mcp_jwt_secret'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

	/**
	 * Return the current site JWT signing secret, generating one if absent.
	 *
	 * @return string Hex-encoded 256-bit secret.
	 */
	public static function get_secret(): string {
		$secret = (string) get_option( self::OPTION_KEY, '' );

		if ( '' === $secret ) {
			$secret = self::generate();
			update_option( self::OPTION_KEY, $secret, false );
		}

		return $secret;
	}

	/**
	 * Ensure a secret exists; create one on first activation.
	 *
	 * Idempotent — safe to call on every activation.
	 *
	 * @return void
	 */
	public static function ensure_secret(): void {
		self::get_secret();
	}

	/**
	 * Regenerate the site secret, invalidating all current MCP sessions.
	 *
	 * @return void
	 */
	public static function regenerate(): void {
		update_option( self::OPTION_KEY, self::generate(), false );
	}

	/**
	 * Generate a fresh 256-bit random secret as a hex string.
	 *
	 * @return string 64-character hex string.
	 */
	private static function generate(): string {
		return bin2hex( random_bytes( 32 ) );
	}
}

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed

/**
 * Return the current MCP JWT signing secret.
 *
 * @return string Hex-encoded 256-bit secret.
 */
function mcp_get_site_secret(): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	return Secret_Manager::get_secret();
}
