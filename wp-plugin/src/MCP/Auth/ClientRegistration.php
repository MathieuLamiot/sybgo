<?php
/**
 * Dynamic Client Registration endpoint (RFC 7591).
 *
 * Handles POST /oauth/register — Claude presents its metadata, receives a
 * client_id / client_secret pair that is stored in wp_options.
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
 * Client Registration.
 *
 * @since 1.0.0
 */
class Client_Registration {

	/**
	 * WordPress option key for the client registry.
	 */
	const OPTION_KEY = 'mcp_oauth_clients'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

	/**
	 * Handle a dynamic registration request.
	 *
	 * Reads a JSON body with client_name and redirect_uris, creates a new
	 * OAuth client record, and returns the full client metadata as JSON 201.
	 *
	 * @return void
	 */
	public function handle_request(): void {
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';

		if ( 'POST' !== $request_method ) {
			$this->send_error( 405, 'invalid_request', 'Method not allowed.' );
			return;
		}

		$raw  = file_get_contents( 'php://input' );
		$body = json_decode( $raw ? $raw : '{}', true );

		if ( ! is_array( $body ) ) {
			$this->send_error( 400, 'invalid_request', 'Request body must be valid JSON.' );
			return;
		}

		$client_name   = sanitize_text_field( $body['client_name'] ?? '' );
		$redirect_uris = $body['redirect_uris'] ?? array();
		$grant_types   = $body['grant_types'] ?? array( 'authorization_code' );

		if ( '' === $client_name || ! is_array( $redirect_uris ) || empty( $redirect_uris ) ) {
			$this->send_error( 400, 'invalid_request', 'client_name and redirect_uris are required.' );
			return;
		}

		// Sanitize redirect URIs.
		$redirect_uris = array_values(
			array_filter(
				array_map( 'esc_url_raw', $redirect_uris )
			)
		);

		if ( empty( $redirect_uris ) ) {
			$this->send_error( 400, 'invalid_request', 'At least one valid redirect_uri is required.' );
			return;
		}

		$client_id     = wp_generate_uuid4();
		$client_secret = bin2hex( random_bytes( 32 ) );
		$issued_at     = time();

		$client = array(
			'client_id'           => $client_id,
			'client_secret'       => $client_secret,
			'client_name'         => $client_name,
			'redirect_uris'       => $redirect_uris,
			'grant_types'         => array_values( array_map( 'sanitize_text_field', (array) $grant_types ) ),
			'client_id_issued_at' => $issued_at,
		);

		$clients               = (array) get_option( self::OPTION_KEY, array() );
		$clients[ $client_id ] = $client;
		update_option( self::OPTION_KEY, $clients, false );

		status_header( 201 );
		wp_send_json( $client );
	}

	/**
	 * Retrieve a registered client by ID.
	 *
	 * @param string $client_id Client identifier.
	 * @return array<string, mixed>|null Client record, or null if not found.
	 */
	public static function get_client( string $client_id ): ?array {
		$clients = (array) get_option( self::OPTION_KEY, array() );
		$client  = $clients[ $client_id ] ?? null;

		return is_array( $client ) ? $client : null;
	}

	/**
	 * Send a JSON error response and exit.
	 *
	 * @param int    $status      HTTP status code.
	 * @param string $error       OAuth error code.
	 * @param string $description Human-readable description.
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
