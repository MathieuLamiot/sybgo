<?php
/**
 * MCP Request Validator — JWT Bearer-token middleware.
 *
 * Called by any REST route that needs authenticated MCP access.  Extracts the
 * Bearer token from the Authorization header, verifies the JWT signature, checks
 * audience, performs an Application-Password revocation check, and returns the
 * resolved WP_User on success.
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
 * Request Validator.
 *
 * @since 1.0.0
 */
class Request_Validator {

	/**
	 * Validate an incoming MCP REST request.
	 *
	 * Returns a WP_User on success, or a WP_Error on any authentication failure.
	 * The caller should pass the return value directly as a REST permission
	 * callback (WP_Error triggers a 401/403; WP_User becomes the current user).
	 *
	 * @param \WP_REST_Request $request Incoming REST request.
	 * @return \WP_User|\WP_Error Authenticated user, or an error.
	 */
	public static function validate_request( \WP_REST_Request $request ) {
		$authorization = $request->get_header( 'Authorization' );
		$route         = $request->get_route();
		$method        = $request->get_method();

		// Collect all request headers for diagnosis.
		$all_headers = array();
		foreach ( array_keys( $request->get_headers() ) as $hdr ) {
			$val = (string) $request->get_header( $hdr );
			if ( 'authorization' === strtolower( $hdr ) ) {
				// Truncate: keep scheme prefix + first 12 + last 6 chars of credential.
				$parts = explode( ' ', $val, 2 );
				$cred  = $parts[1] ?? '';
				$clen  = strlen( $cred );
				$trunc = $clen > 20
					? substr( $cred, 0, 12 ) . '...' . substr( $cred, -6 )
					: str_repeat( '*', $clen );
				$val = ( $parts[0] ?? '' ) . ' ' . $trunc;
			}
			$all_headers[ strtolower( $hdr ) ] = $val;
		}

		Mcp_Logger::log(
			'VALIDATOR',
			'validate_request called',
			array(
				'method'  => $method,
				'route'   => $route,
				'headers' => $all_headers,
				'body'    => Mcp_Logger::safe_request_body(),
			),
			true
		);

		if ( empty( $authorization ) || 0 !== strpos( $authorization, 'Bearer ' ) ) {
			Mcp_Logger::log(
				'VALIDATOR',
				'unauthenticated: no Bearer token',
				array(
					'authorization_header' => $authorization ? substr( $authorization, 0, 20 ) . '...' : '(empty)',
				)
			);
			return self::unauthenticated_error();
		}

		$token  = substr( $authorization, 7 );
		$secret = Secret_Manager::get_secret();
		$claims = JWT::decode( $token, $secret );

		if ( null === $claims ) {
			Mcp_Logger::log( 'VALIDATOR', 'rejected: JWT decode failed (signature invalid or token expired)' );
			return self::unauthenticated_error( 'invalid_token', 'JWT signature invalid or token expired.' );
		}

		// Audience must match the MCP adapter REST endpoint.
		$expected_aud = get_rest_url( null, 'mcp/mcp-adapter-default-server' );
		$token_aud    = $claims['aud'] ?? '';

		if ( $token_aud !== $expected_aud ) {
			Mcp_Logger::log(
				'VALIDATOR',
				'rejected: JWT audience mismatch',
				array(
					'token_aud'    => $token_aud,
					'expected_aud' => $expected_aud,
				)
			);
			return self::unauthenticated_error( 'invalid_token', 'JWT audience mismatch.' );
		}

		$user_id       = (int) ( $claims['sub'] ?? 0 );
		$app_pass_uuid = (string) ( $claims['app_pass_id'] ?? '' );

		if ( 0 === $user_id || '' === $app_pass_uuid ) {
			Mcp_Logger::log(
				'VALIDATOR',
				'rejected: malformed JWT claims',
				array(
					'has_sub'         => isset( $claims['sub'] ) ? 'yes' : 'no',
					'has_app_pass_id' => isset( $claims['app_pass_id'] ) ? 'yes' : 'no',
					'claims_keys'     => array_keys( $claims ),
				)
			);
			return self::unauthenticated_error( 'invalid_token', 'Malformed JWT claims.' );
		}

		// Application Password revocation check.
		$app_pass = \WP_Application_Passwords::get_user_application_password( $user_id, $app_pass_uuid );

		if ( ! is_array( $app_pass ) ) {
			Mcp_Logger::log(
				'VALIDATOR',
				'rejected: Application Password revoked or not found',
				array(
					'user_id'       => $user_id,
					'app_pass_uuid' => $app_pass_uuid,
				)
			);
			return self::unauthenticated_error( 'invalid_token', 'MCP session has been revoked.' );
		}

		$user = get_user_by( 'id', $user_id );

		if ( false === $user ) {
			Mcp_Logger::log( 'VALIDATOR', 'rejected: user not found', array( 'user_id' => $user_id ) );
			return self::unauthenticated_error( 'invalid_token', 'User not found.' );
		}

		// Set the current user so that get_current_user_id() returns the correct
		// user for any library code that runs after our permission check (e.g.
		// HttpSessionValidator::create_session() in wordpress/mcp-adapter).
		wp_set_current_user( $user_id );

		Mcp_Logger::log(
			'VALIDATOR',
			'authentication successful',
			array(
				'user_id'        => $user_id,
				'user_login'     => $user->user_login,
				'app_pass_uuid'  => $app_pass_uuid,
				'token_exp'      => $claims['exp'] ?? 'unknown',
				'token_scope'    => $claims['scope'] ?? 'unknown',
			)
		);

		return $user;
	}

	/**
	 * Build a 401 WP_Error with the WWW-Authenticate challenge header.
	 *
	 * @param string $code        OAuth error code (default 'unauthorized').
	 * @param string $description Human-readable message.
	 * @return \WP_Error
	 */
	private static function unauthenticated_error( string $code = 'unauthorized', string $description = '' ): \WP_Error {
		$site_url = get_site_url();

		$www_auth = sprintf(
			'Bearer realm="%s", resource_metadata="%s/.well-known/oauth-protected-resource"',
			esc_url( $site_url ),
			esc_url( $site_url )
		);

		if ( '' !== $description ) {
			$www_auth .= sprintf( ', error="%s", error_description="%s"', $code, $description );
		}

		return new \WP_Error(
			'mcp_unauthorized',
			'' !== $description ? $description : __( 'MCP authentication required.', 'sybgo' ),
			array(
				'status'           => 401,
				'WWW-Authenticate' => $www_auth,
			)
		);
	}
}

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- intentional alias function

/**
 * Convenience wrapper: validate an MCP REST request.
 *
 * @param \WP_REST_Request $request Incoming request.
 * @return \WP_User|\WP_Error
 */
function mcp_validate_request( \WP_REST_Request $request ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	return Request_Validator::validate_request( $request );
}
