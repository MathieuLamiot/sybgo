<?php
/**
 * MCP Auth Bootstrap — single entry point for the OAuth 2.1 JWT façade.
 *
 * Extraction point: to use in another plugin, copy src/MCP/Auth/, update
 * the namespace root (Sybgo\MCP\Auth → YourPlugin\MCP\Auth), and instantiate
 * Mcp_Auth from your main plugin file.  The only sybgo-specific references
 * are in this file (text domain, activation hook name).
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
 * Mcp_Auth — wires every OAuth sub-component to WordPress via hooks.
 *
 * Call Mcp_Auth::boot() once from your main plugin file.  No tight coupling to
 * sybgo internals; all integration points are hooks/filters.
 *
 * @since 1.0.0
 */
class Mcp_Auth {

	/**
	 * Query var name used to route OAuth endpoint requests.
	 */
	const OAUTH_QUERY_VAR = 'mcp_oauth_endpoint'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

	/**
	 * Bootstrap the OAuth layer.
	 *
	 * Safe to call multiple times — only the first call has any effect.
	 *
	 * @return void
	 */
	public static function boot(): void {
		static $booted = false;

		if ( $booted ) {
			return;
		}

		$booted = true;

		// Discovery endpoints (.well-known).
		( new Discovery_Endpoints() )->register_hooks();

		// OAuth endpoint routing.
		add_action( 'init', array( __CLASS__, 'register_oauth_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_oauth_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_oauth_request' ) );

		// Admin UI — hooks only fire in admin context (admin_menu, admin_post_*).
		( new Admin_Page() )->register_hooks();

		// HttpTransport::check_permission() swallows WP_Error returns and returns false,
		// causing WordPress to emit a generic rest_forbidden with no WWW-Authenticate header.
		// MCP clients need that header to know they should attach a Bearer token.
		// rest_pre_dispatch runs before permission checks, letting us return the proper
		// 401 + WWW-Authenticate challenge early for MCP adapter requests.
		add_filter(
			'rest_pre_dispatch',
			static function ( $result, \WP_REST_Server $server, \WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
				if ( null !== $result ) {
					return $result;
				}

				if ( false === strpos( $request->get_route(), '/mcp/mcp-adapter-default-server' ) ) {
					return $result;
				}

				$auth_result = Request_Validator::validate_request( $request );

				Mcp_Logger::log(
					'DISPATCH',
					'rest_pre_dispatch on MCP route',
					array(
						'method'      => $request->get_method(),
						'route'       => $request->get_route(),
						'has_auth'    => '' !== (string) $request->get_header( 'Authorization' ) ? 'yes' : 'no',
						'auth_result' => is_wp_error( $auth_result )
							? 'WP_Error:' . $auth_result->get_error_code()
							: ( $auth_result instanceof \WP_User ? 'WP_User:' . $auth_result->user_login : 'unknown' ),
					),
					true
				);

				if ( ! is_wp_error( $auth_result ) ) {
					return $result;
				}

				$error_data = $auth_result->get_error_data();
				$www_auth   = is_array( $error_data ) ? ( $error_data['WWW-Authenticate'] ?? '' ) : '';
				$response   = new \WP_REST_Response(
					array(
						'code'    => $auth_result->get_error_code(),
						'message' => $auth_result->get_error_message(),
						'data'    => array( 'status' => 401 ),
					),
					401
				);

				if ( '' !== $www_auth ) {
					$response->header( 'WWW-Authenticate', $www_auth );
				}

				Mcp_Logger::log(
					'DISPATCH',
					'returning 401 challenge',
					array(
						'error_code'     => $auth_result->get_error_code(),
						'has_www_auth'   => '' !== $www_auth ? 'yes' : 'no',
					)
				);

				return $response;
			},
			10,
			3
		);

		// Log the final REST response for all authenticated MCP route calls.
		add_filter(
			'rest_post_dispatch',
			static function ( \WP_REST_Response $response, \WP_REST_Server $server, \WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
				if ( false === strpos( $request->get_route(), '/mcp/mcp-adapter-default-server' ) ) {
					return $response;
				}

				$body      = $response->get_data();
				$has_error = is_array( $body ) && isset( $body['error'] );

				Mcp_Logger::log(
					'DISPATCH',
					'rest_post_dispatch — MCP route response',
					array(
						'status'      => $response->get_status(),
						'method'      => $request->get_method(),
						'route'       => $request->get_route(),
						'body_type'   => gettype( $body ),
						'body_keys'   => is_array( $body ) ? array_keys( $body ) : null,
						'jsonrpc_id'  => is_array( $body ) && isset( $body['id'] ) ? $body['id'] : null,
						'has_result'  => is_array( $body ) && isset( $body['result'] ) ? 'yes' : 'no',
						'has_error'   => $has_error ? 'yes' : 'no',
						'error_code'  => $has_error && isset( $body['error']['code'] ) ? $body['error']['code'] : null,
						'error_msg'   => $has_error && isset( $body['error']['message'] ) ? $body['error']['message'] : null,
					),
					! $has_error  // debug-only when successful; always log errors
				);

				return $response;
			},
			10,
			3
		);
	}

	/**
	 * Register WordPress rewrite rules for all four OAuth endpoints.
	 *
	 * Called both on the 'init' action (normal page load) and directly during
	 * plugin activation before flush_rewrite_rules().
	 *
	 * @return void
	 */
	public static function register_oauth_rewrite_rules(): void {
		add_rewrite_rule( '^oauth/register$', 'index.php?' . self::OAUTH_QUERY_VAR . '=register', 'top' );
		add_rewrite_rule( '^oauth/authorize$', 'index.php?' . self::OAUTH_QUERY_VAR . '=authorize', 'top' );
		add_rewrite_rule( '^oauth/authorize-callback$', 'index.php?' . self::OAUTH_QUERY_VAR . '=authorize-callback', 'top' );
		add_rewrite_rule( '^oauth/token$', 'index.php?' . self::OAUTH_QUERY_VAR . '=token', 'top' );
	}

	/**
	 * Add the OAuth query var to WordPress's list of recognised vars.
	 *
	 * @param string[] $vars Existing query vars.
	 * @return string[] Modified list.
	 */
	public static function add_oauth_query_vars( array $vars ): array {
		$vars[] = self::OAUTH_QUERY_VAR;
		return $vars;
	}

	/**
	 * Dispatch an incoming OAuth endpoint request to the appropriate handler.
	 *
	 * @return void
	 */
	public static function handle_oauth_request(): void {
		$endpoint = (string) get_query_var( self::OAUTH_QUERY_VAR, '' );

		if ( '' === $endpoint ) {
			return;
		}

		switch ( $endpoint ) {
			case 'register':
				( new Client_Registration() )->handle_request();
				break;
			case 'authorize':
				( new Authorize_Endpoint() )->handle_request();
				break;
			case 'authorize-callback':
				( new Authorize_Callback() )->handle_request();
				break;
			case 'token':
				( new Token_Endpoint() )->handle_request();
				break;
			default:
				status_header( 404 );
				wp_die( esc_html__( 'Unknown OAuth endpoint.', 'sybgo' ), '', array( 'response' => 404 ) );
		}

		exit;
	}

	/**
	 * Called during plugin activation to register rewrite rules and flush.
	 *
	 * Must be invoked from the activation hook callback so the rewrite rules
	 * are present when flush_rewrite_rules() runs.
	 *
	 * @return void
	 */
	public static function on_activation(): void {
		Secret_Manager::ensure_secret();

		// Register rules inline so flush_rewrite_rules() picks them up.
		self::register_oauth_rewrite_rules();
		( new Discovery_Endpoints() )->add_rewrite_rules();
	}
}
