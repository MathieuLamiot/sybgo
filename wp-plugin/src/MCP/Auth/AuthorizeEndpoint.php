<?php
/**
 * Authorization Endpoint.
 *
 * Handles GET /oauth/authorize — validates the PKCE parameters, stores the
 * challenge in a 60-second transient, then redirects the browser to the
 * WordPress login form.  After successful login WordPress delivers the user
 * to /oauth/authorize-callback where the auth code is issued.
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
 * Authorize Endpoint.
 *
 * @since 1.0.0
 */
class Authorize_Endpoint {

	/**
	 * Transient TTL for the state parameter (seconds).
	 */
	const STATE_TTL = 60; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

	/**
	 * Handle an authorization request.
	 *
	 * @return void
	 */
	public function handle_request(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$client_id             = sanitize_text_field( wp_unslash( $_GET['client_id'] ?? '' ) );
		$redirect_uri          = esc_url_raw( wp_unslash( $_GET['redirect_uri'] ?? '' ) );
		$response_type         = sanitize_text_field( wp_unslash( $_GET['response_type'] ?? '' ) );
		$code_challenge        = sanitize_text_field( wp_unslash( $_GET['code_challenge'] ?? '' ) );
		$code_challenge_method = sanitize_text_field( wp_unslash( $_GET['code_challenge_method'] ?? '' ) );
		$state                 = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		Mcp_Logger::log(
			'AUTHORIZE',
			'authorization request received',
			array(
				'response_type'         => $response_type,
				'client_id'             => $client_id,
				'redirect_uri'          => $redirect_uri,
				'code_challenge_method' => $code_challenge_method,
				'has_code_challenge'    => '' !== $code_challenge ? 'yes' : 'no',
				'has_state'             => '' !== $state ? 'yes' : 'no',
				'remote_addr'           => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			)
		);

		if ( 'code' !== $response_type ) {
			Mcp_Logger::log( 'AUTHORIZE', 'rejected: unsupported response_type', array( 'response_type' => $response_type ) );
			$this->send_error( $redirect_uri, 'unsupported_response_type', $state );
			return;
		}

		if ( '' === $client_id || '' === $redirect_uri || '' === $code_challenge || 'S256' !== $code_challenge_method ) {
			Mcp_Logger::log(
				'AUTHORIZE',
				'rejected: missing or invalid params',
				array(
					'has_client_id'             => '' !== $client_id ? 'yes' : 'no',
					'has_redirect_uri'          => '' !== $redirect_uri ? 'yes' : 'no',
					'has_code_challenge'        => '' !== $code_challenge ? 'yes' : 'no',
					'code_challenge_method'     => $code_challenge_method,
				)
			);
			$this->send_error( $redirect_uri, 'invalid_request', $state );
			return;
		}

		$client = Client_Registration::get_client( $client_id );
		if ( null === $client ) {
			Mcp_Logger::log( 'AUTHORIZE', 'rejected: unknown client_id', array( 'client_id' => $client_id ) );
			wp_die( esc_html__( 'Unknown OAuth client.', 'sybgo' ), esc_html__( 'OAuth Error', 'sybgo' ), array( 'response' => 400 ) );
		}

		if ( ! in_array( $redirect_uri, $client['redirect_uris'], true ) ) {
			Mcp_Logger::log(
				'AUTHORIZE',
				'rejected: redirect_uri mismatch',
				array(
					'provided'   => $redirect_uri,
					'registered' => $client['redirect_uris'],
				)
			);
			wp_die( esc_html__( 'redirect_uri does not match registered value.', 'sybgo' ), esc_html__( 'OAuth Error', 'sybgo' ), array( 'response' => 400 ) );
		}

		// Generate a state token if the client did not supply one.
		if ( '' === $state ) {
			$state = bin2hex( random_bytes( 16 ) );
		}

		set_transient(
			'mcp_oauth_state_' . $state,
			array(
				'client_id'             => $client_id,
				'redirect_uri'          => $redirect_uri,
				'code_challenge'        => $code_challenge,
				'code_challenge_method' => $code_challenge_method,
				'state'                 => $state,
			),
			self::STATE_TTL
		);

		$callback_url = add_query_arg( 'state', rawurlencode( $state ), get_site_url() . '/oauth/authorize-callback' );
		$login_url    = wp_login_url( $callback_url );

		Mcp_Logger::log(
			'AUTHORIZE',
			'redirecting to login',
			array(
				'state'        => $state,
				'callback_url' => $callback_url,
			)
		);

		wp_redirect( $login_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Redirect the client to redirect_uri with an error parameter.
	 *
	 * @param string $redirect_uri Destination URI (may be empty on early failure).
	 * @param string $error        OAuth error code.
	 * @param string $state        State token echoed back to the client.
	 * @return void
	 */
	private function send_error( string $redirect_uri, string $error, string $state ): void {
		if ( '' !== $redirect_uri ) {
			$params = array( 'error' => $error );
			if ( '' !== $state ) {
				$params['state'] = $state;
			}
			wp_redirect( add_query_arg( $params, $redirect_uri ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		}

		wp_die( esc_html( $error ), esc_html__( 'OAuth Error', 'sybgo' ), array( 'response' => 400 ) );
	}
}
