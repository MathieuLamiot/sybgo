<?php
/**
 * Token Endpoint.
 *
 * Handles POST /oauth/token for two grant types:
 *   - authorization_code: PKCE verification → create Application Password → issue JWT pair.
 *   - refresh_token:      verify refresh JWT → revocation check → issue new access JWT.
 *
 * Raw Application Passwords are never stored and are discarded immediately
 * after creation.  Only the UUID is retained inside the JWT claims for
 * revocation checking.
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
 * Token Endpoint.
 *
 * @since 1.0.0
 */
class Token_Endpoint {

	/**
	 * Handle the token request.
	 *
	 * @return void
	 */
	public function handle_request(): void {
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		$content_type   = isset( $_SERVER['CONTENT_TYPE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) ) : '';

		Mcp_Logger::log(
			'TOKEN',
			'token request received',
			array(
				'method'       => $request_method,
				'content_type' => $content_type,
				'remote_addr'  => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
				'user_agent'   => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
				'headers'      => Mcp_Logger::safe_request_headers(),
				'body'         => Mcp_Logger::safe_request_body(),
			)
		);

		if ( 'POST' !== $request_method ) {
			Mcp_Logger::log( 'TOKEN', 'rejected: wrong method', array( 'method' => $request_method ) );
			$this->send_error( 405, 'invalid_request', 'Method not allowed.' );
			return;
		}

		$body = $this->parse_body();

		$grant_type = sanitize_text_field( $body['grant_type'] ?? '' );

		Mcp_Logger::log( 'TOKEN', 'grant_type received', array( 'grant_type' => $grant_type ) );

		if ( 'authorization_code' === $grant_type ) {
			$this->handle_authorization_code( $body );
		} elseif ( 'refresh_token' === $grant_type ) {
			$this->handle_refresh_token( $body );
		} else {
			Mcp_Logger::log( 'TOKEN', 'rejected: unsupported grant_type', array( 'grant_type' => $grant_type ) );
			$this->send_error( 400, 'unsupported_grant_type' );
		}
	}

	/**
	 * Exchange an auth code for a JWT pair.
	 *
	 * @param array<string, mixed> $body Parsed request body.
	 * @return void
	 */
	private function handle_authorization_code( array $body ): void {
		$code          = sanitize_text_field( $body['code'] ?? '' );
		$code_verifier = sanitize_text_field( $body['code_verifier'] ?? '' );
		$redirect_uri  = esc_url_raw( $body['redirect_uri'] ?? '' );

		Mcp_Logger::log(
			'TOKEN',
			'authorization_code exchange: params',
			array(
				'has_code'          => '' !== $code ? 'yes' : 'no',
				'has_code_verifier' => '' !== $code_verifier ? 'yes' : 'no',
				'redirect_uri'      => $redirect_uri,
			)
		);

		if ( '' === $code || '' === $code_verifier ) {
			Mcp_Logger::log( 'TOKEN', 'rejected: missing code or code_verifier' );
			$this->send_error( 400, 'invalid_request', 'code and code_verifier are required.' );
			return;
		}

		// Look up and immediately consume the single-use code transient.
		$code_data = get_transient( 'mcp_oauth_code_' . $code );

		if ( false === $code_data || ! is_array( $code_data ) ) {
			Mcp_Logger::log( 'TOKEN', 'rejected: auth code transient missing or expired (60 s window)' );
			$this->send_error( 400, 'invalid_grant', 'Code is invalid or has expired.' );
			return;
		}

		delete_transient( 'mcp_oauth_code_' . $code );

		// Verify PKCE S256: BASE64URL(SHA256(verifier)) must equal stored challenge.
		$expected = rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		if ( ! hash_equals( (string) $code_data['code_challenge'], $expected ) ) {
			Mcp_Logger::log( 'TOKEN', 'rejected: PKCE code_verifier does not match challenge' );
			$this->send_error( 400, 'invalid_grant', 'PKCE code_verifier does not match challenge.' );
			return;
		}

		// Optional redirect_uri check.
		if ( '' !== $redirect_uri && $redirect_uri !== $code_data['redirect_uri'] ) {
			Mcp_Logger::log(
				'TOKEN',
				'rejected: redirect_uri mismatch',
				array(
					'provided' => $redirect_uri,
					'stored'   => $code_data['redirect_uri'],
				)
			);
			$this->send_error( 400, 'invalid_grant', 'redirect_uri mismatch.' );
			return;
		}

		$user_id = (int) $code_data['user_id'];

		Mcp_Logger::log( 'TOKEN', 'PKCE verified — creating Application Password', array( 'user_id' => $user_id ) );

		// Create a WordPress Application Password (raw password is discarded).
		$result = \WP_Application_Passwords::create_new_application_password(
			$user_id,
			array( 'name' => 'MCP Session – ' . wp_date( 'Y-m-d H:i' ) )
		);

		if ( \is_wp_error( $result ) ) {
			Mcp_Logger::log(
				'TOKEN',
				'server_error: Application Password creation failed',
				array(
					'wp_error_code'    => $result->get_error_code(),
					'wp_error_message' => $result->get_error_message(),
					'user_id'          => $user_id,
				)
			);
			$this->send_error( 500, 'server_error', 'Could not create MCP session.' );
			return;
		}

		// create_new_application_password() returns [raw_password, metadata]. Raw password is discarded.
		$app_pass_uuid = (string) $result[1]['uuid'];

		Mcp_Logger::log(
			'TOKEN',
			'Application Password created — issuing token pair',
			array(
				'user_id'       => $user_id,
				'app_pass_uuid' => $app_pass_uuid,
			)
		);

		$this->issue_token_pair( $user_id, $app_pass_uuid );
	}

	/**
	 * Refresh an access token using a valid refresh JWT.
	 *
	 * @param array<string, mixed> $body Parsed request body.
	 * @return void
	 */
	private function handle_refresh_token( array $body ): void {
		$refresh_token = sanitize_text_field( $body['refresh_token'] ?? '' );

		Mcp_Logger::log( 'TOKEN', 'refresh_token grant: validating', array( 'has_refresh_token' => '' !== $refresh_token ? 'yes' : 'no' ) );

		if ( '' === $refresh_token ) {
			Mcp_Logger::log( 'TOKEN', 'rejected: refresh_token missing' );
			$this->send_error( 400, 'invalid_request', 'refresh_token is required.' );
			return;
		}

		$secret = Secret_Manager::get_secret();
		$claims = JWT::decode( $refresh_token, $secret );

		if ( null === $claims || 'refresh' !== ( $claims['type'] ?? '' ) ) {
			Mcp_Logger::log(
				'TOKEN',
				'rejected: refresh token decode failed or wrong type',
				array(
					'claims_null' => null === $claims ? 'yes' : 'no',
					'type'        => null !== $claims ? ( $claims['type'] ?? '(missing)' ) : 'n/a',
				)
			);
			$this->send_error( 401, 'invalid_token', 'Refresh token is invalid or expired.' );
			return;
		}

		$user_id       = (int) $claims['sub'];
		$app_pass_uuid = (string) ( $claims['app_pass_id'] ?? '' );

		Mcp_Logger::log( 'TOKEN', 'refresh token decoded — checking revocation', array( 'user_id' => $user_id, 'app_pass_uuid' => $app_pass_uuid ) );

		// Revocation check: if the Application Password was deleted the session is gone.
		$app_pass = \WP_Application_Passwords::get_user_application_password( $user_id, $app_pass_uuid );

		if ( ! is_array( $app_pass ) ) {
			Mcp_Logger::log( 'TOKEN', 'rejected: Application Password revoked or not found', array( 'user_id' => $user_id, 'app_pass_uuid' => $app_pass_uuid ) );
			$this->send_error( 401, 'invalid_token', 'MCP session has been revoked.' );
			return;
		}

		Mcp_Logger::log( 'TOKEN', 'refresh token valid — issuing new token pair', array( 'user_id' => $user_id, 'app_pass_uuid' => $app_pass_uuid ) );

		// Issue a new access token; rotate the refresh token as well.
		$this->issue_token_pair( $user_id, $app_pass_uuid );
	}

	/**
	 * Build and return an access + refresh JWT pair.
	 *
	 * @param int    $user_id       WordPress user ID.
	 * @param string $app_pass_uuid UUID of the Application Password.
	 * @return void
	 */
	private function issue_token_pair( int $user_id, string $app_pass_uuid ): void {
		$secret   = Secret_Manager::get_secret();
		$site_url = get_site_url();
		$now      = time();
		$aud      = get_rest_url( null, 'mcp/mcp-adapter-default-server' );

		$access_payload = array(
			'iss'         => $site_url,
			'aud'         => $aud,
			'sub'         => (string) $user_id,
			'app_pass_id' => $app_pass_uuid,
			'scope'       => 'mcp',
			'iat'         => $now,
			'exp'         => $now + HOUR_IN_SECONDS,
		);

		$refresh_payload = array(
			'iss'         => $site_url,
			'sub'         => (string) $user_id,
			'app_pass_id' => $app_pass_uuid,
			'type'        => 'refresh',
			'iat'         => $now,
			'exp'         => $now + ( 30 * DAY_IN_SECONDS ),
		);

		$access_token  = JWT::encode( $access_payload, $secret );
		$refresh_token = JWT::encode( $refresh_payload, $secret );

		Mcp_Logger::log(
			'TOKEN',
			'token pair issued',
			array(
				'user_id'          => $user_id,
				'app_pass_uuid'    => $app_pass_uuid,
				'access_exp'       => $access_payload['exp'],
				'refresh_exp'      => $refresh_payload['exp'],
				'access_token_len' => strlen( $access_token ),
			)
		);

		wp_send_json(
			array(
				'access_token'  => $access_token,
				'token_type'    => 'Bearer',
				'expires_in'    => HOUR_IN_SECONDS,
				'refresh_token' => $refresh_token,
				'scope'         => 'mcp',
			)
		);
	}

	/**
	 * Parse the request body from either JSON or form-encoded content.
	 *
	 * @return array<string, mixed> Associative array of body parameters.
	 */
	private function parse_body(): array {
		$content_type = isset( $_SERVER['CONTENT_TYPE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) ) : '';

		if ( false !== strpos( $content_type, 'application/json' ) ) {
			$raw  = file_get_contents( 'php://input' );
			$body = json_decode( $raw ? $raw : '{}', true );
			return is_array( $body ) ? $body : array();
		}

		// Form-encoded (application/x-www-form-urlencoded).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return array_map( 'sanitize_text_field', wp_unslash( $_POST ) );
	}

	/**
	 * Send a JSON error response and exit.
	 *
	 * @param int    $status      HTTP status code.
	 * @param string $error       OAuth error code.
	 * @param string $description Optional human-readable description.
	 * @return void
	 */
	private function send_error( int $status, string $error, string $description = '' ): void {
		status_header( $status );
		$body = array( 'error' => $error );
		if ( '' !== $description ) {
			$body['error_description'] = $description;
		}
		wp_send_json( $body );
	}
}
