<?php
/**
 * Post-login Authorization Callback.
 *
 * Handles GET /oauth/authorize-callback — called by WordPress after the user
 * authenticates.  Looks up the state transient, generates a single-use auth
 * code, stores it in a 60-second transient, and redirects the MCP client to
 * the registered redirect_uri with the code attached.
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
 * Authorize Callback.
 *
 * @since 1.0.0
 */
class Authorize_Callback {

	/**
	 * Auth-code transient TTL (seconds).  Codes are single-use; the transient
	 * is deleted immediately on redemption.
	 */
	const CODE_TTL = 60; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

	/**
	 * Handle the post-login callback.
	 *
	 * @return void
	 */
	public function handle_request(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );

		Mcp_Logger::log(
			'CALLBACK',
			'authorize-callback received',
			array(
				'is_logged_in' => is_user_logged_in() ? 'yes' : 'no',
				'user_id'      => is_user_logged_in() ? get_current_user_id() : 0,
				'has_state'    => '' !== $state ? 'yes' : 'no',
				'remote_addr'  => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			)
		);

		if ( ! is_user_logged_in() ) {
			Mcp_Logger::log( 'CALLBACK', 'rejected: user not logged in' );
			wp_die( esc_html__( 'You must be logged in to authorise an MCP session.', 'sybgo' ), esc_html__( 'OAuth Error', 'sybgo' ), array( 'response' => 401 ) );
		}

		if ( '' === $state ) {
			Mcp_Logger::log( 'CALLBACK', 'rejected: missing state' );
			wp_die( esc_html__( 'Missing state parameter.', 'sybgo' ), esc_html__( 'OAuth Error', 'sybgo' ), array( 'response' => 400 ) );
		}

		$state_data = get_transient( 'mcp_oauth_state_' . $state );

		if ( false === $state_data || ! is_array( $state_data ) ) {
			Mcp_Logger::log( 'CALLBACK', 'rejected: state transient not found or expired', array( 'state' => $state ) );
			wp_die( esc_html__( 'Invalid or expired state.  Please restart the authorization flow.', 'sybgo' ), esc_html__( 'OAuth Error', 'sybgo' ), array( 'response' => 400 ) );
		}

		// Consume the state — one-time use only.
		delete_transient( 'mcp_oauth_state_' . $state );

		$auth_code = bin2hex( random_bytes( 32 ) );
		$user_id   = get_current_user_id();

		set_transient(
			'mcp_oauth_code_' . $auth_code,
			array(
				'user_id'        => $user_id,
				'client_id'      => $state_data['client_id'],
				'code_challenge' => $state_data['code_challenge'],
				'redirect_uri'   => $state_data['redirect_uri'],
			),
			self::CODE_TTL
		);

		Mcp_Logger::log(
			'CALLBACK',
			'auth code issued',
			array(
				'user_id'      => $user_id,
				'client_id'    => $state_data['client_id'],
				'redirect_uri' => $state_data['redirect_uri'],
				'code_ttl_s'   => self::CODE_TTL,
			)
		);

		$redirect = add_query_arg(
			array(
				'code'  => $auth_code,
				'state' => $state,
			),
			$state_data['redirect_uri']
		);

		wp_redirect( $redirect ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}
}
