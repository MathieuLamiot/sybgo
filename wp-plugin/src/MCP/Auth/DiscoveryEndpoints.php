<?php
/**
 * OAuth 2.0 Discovery Endpoints.
 *
 * Registers the two RFC-mandated well-known documents so MCP clients can
 * auto-discover the authorization server metadata without hard-coding URLs.
 *
 * Paths served (no /wp-json prefix):
 *   GET /.well-known/oauth-protected-resource  (RFC 9728)
 *   GET /.well-known/oauth-authorization-server (RFC 8414)
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
 * Discovery Endpoints.
 *
 * @since 1.0.0
 */
class Discovery_Endpoints {

	/**
	 * Query var name used to route discovery requests.
	 */
	const QUERY_VAR = 'mcp_oauth_discovery'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

	/**
	 * Register all WordPress hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'handle_request' ) );
	}

	/**
	 * Register rewrite rules for the .well-known paths.
	 *
	 * Called both on the 'init' action (normal requests) and directly during
	 * plugin activation before flush_rewrite_rules().
	 *
	 * @return void
	 */
	public function add_rewrite_rules(): void {
		add_rewrite_rule(
			'^\\.well-known/oauth-protected-resource$',
			'index.php?' . self::QUERY_VAR . '=protected-resource',
			'top'
		);
		add_rewrite_rule(
			'^\\.well-known/oauth-authorization-server$',
			'index.php?' . self::QUERY_VAR . '=authorization-server',
			'top'
		);
	}

	/**
	 * Expose the discovery query var to WordPress.
	 *
	 * @param string[] $vars Existing public query variables.
	 * @return string[] Modified list.
	 */
	public function add_query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Serve the discovery document if the request matches.
	 *
	 * @return void
	 */
	public function handle_request(): void {
		$discovery = (string) get_query_var( self::QUERY_VAR, '' );

		if ( '' === $discovery ) {
			return;
		}

		$site_url = get_site_url();

		if ( 'protected-resource' === $discovery ) {
			wp_send_json(
				array(
					'resource'                 => get_rest_url( null, 'mcp/mcp-adapter-default-server' ),
					'authorization_servers'    => array( $site_url ),
					'bearer_methods_supported' => array( 'header' ),
					'scopes_supported'         => array( 'mcp' ),
				)
			);
		} elseif ( 'authorization-server' === $discovery ) {
			wp_send_json(
				array(
					'issuer'                           => $site_url,
					'authorization_endpoint'           => $site_url . '/oauth/authorize',
					'token_endpoint'                   => $site_url . '/oauth/token',
					'response_types_supported'         => array( 'code' ),
					'grant_types_supported'            => array( 'authorization_code', 'refresh_token' ),
					'code_challenge_methods_supported' => array( 'S256' ),
					'scopes_supported'                 => array( 'mcp' ),
					'registration_endpoint'            => $site_url . '/oauth/register',
				)
			);
		}
	}
}
