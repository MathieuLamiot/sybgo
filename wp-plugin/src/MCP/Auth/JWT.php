<?php
/**
 * JWT Helper — pure HS256 implementation with no external dependencies.
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
 * JWT encoder / decoder.
 *
 * Implements HS256 (HMAC-SHA256) signing. All methods are static so callers
 * never need to instantiate the class, and the two global-function aliases
 * below remain thin wrappers.
 *
 * @since 1.0.0
 */
class JWT {

	/**
	 * Encode a payload as a signed JWT.
	 *
	 * @param array<string, mixed> $payload Claims to include in the token.
	 * @param string               $secret  HMAC signing secret.
	 * @return string Signed JWT string.
	 */
	public static function encode( array $payload, string $secret ): string {
		$header = self::base64url_encode(
			(string) wp_json_encode(
				array(
					'typ' => 'JWT',
					'alg' => 'HS256',
				)
			)
		);
		$body   = self::base64url_encode( (string) wp_json_encode( $payload ) );
		$sig    = self::base64url_encode( hash_hmac( 'sha256', $header . '.' . $body, $secret, true ) );

		return $header . '.' . $body . '.' . $sig;
	}

	/**
	 * Decode and verify a JWT.
	 *
	 * Returns null if the signature is invalid or the token has expired.
	 *
	 * @param string $token  JWT string.
	 * @param string $secret HMAC signing secret.
	 * @return array<string, mixed>|null Decoded payload, or null on failure.
	 */
	public static function decode( string $token, string $secret ): ?array {
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) ) {
			return null;
		}

		list( $header, $body, $signature ) = $parts;

		$expected_sig = self::base64url_encode( hash_hmac( 'sha256', $header . '.' . $body, $secret, true ) );
		if ( ! hash_equals( $expected_sig, $signature ) ) {
			return null;
		}

		$payload = json_decode( self::base64url_decode( $body ), true );
		if ( ! is_array( $payload ) ) {
			return null;
		}

		if ( isset( $payload['exp'] ) && (int) $payload['exp'] < time() ) {
			return null;
		}

		return $payload;
	}

	/**
	 * Base64URL-encode a binary string.
	 *
	 * @param string $data Raw bytes to encode.
	 * @return string URL-safe base64 without padding.
	 */
	private static function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Base64URL-decode a string.
	 *
	 * @param string $data URL-safe base64 string.
	 * @return string Decoded binary string.
	 */
	private static function base64url_decode( string $data ): string {
		return (string) base64_decode( strtr( $data, '-_', '+/' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}
}

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed

/**
 * Encode a JWT with the given payload and secret.
 *
 * @param array<string, mixed> $payload Claims to encode.
 * @param string               $secret  Signing secret.
 * @return string Signed JWT.
 */
function mcp_encode_jwt( array $payload, string $secret ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	return JWT::encode( $payload, $secret );
}

/**
 * Decode and verify a JWT, returning null on failure or expiry.
 *
 * @param string $token  JWT string.
 * @param string $secret Signing secret.
 * @return array<string, mixed>|null Decoded payload or null.
 */
function mcp_decode_jwt( string $token, string $secret ): ?array { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	return JWT::decode( $token, $secret );
}
