<?php
/**
 * MCP Helper class file.
 *
 * Self-contained singleton that wires itself into WordPress:
 * profile page modal, asset enqueuing, and AJAX endpoint.
 *
 * Usage from any plugin:
 *   \WPMedia\MCPHelper\MCP_Helper::init();
 *
 * Safe to call from multiple plugins — hooks are registered exactly once.
 *
 * @package WPMedia\MCPHelper
 * @since   1.0.0
 */

declare(strict_types=1);

namespace WPMedia\MCPHelper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrates all WordPress integration for the MCP connect helper.
 *
 * @since 1.0.0
 */
class MCP_Helper {

	/**
	 * Guards against double-registration when multiple plugins call init().
	 *
	 * @var bool
	 */
	private static bool $initialized = false;

	/**
	 * Register all WordPress hooks. Idempotent: safe to call multiple times.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_action( 'show_user_profile', [ self::class, 'render_modal' ] );
		add_action( 'edit_user_profile', [ self::class, 'render_modal' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
		add_action( 'wp_ajax_mcp_helper_get_tools', [ self::class, 'ajax_get_tools' ] );
	}

	/**
	 * Render the hidden modal HTML on profile pages.
	 *
	 * Outputs nothing visible — the modal is shown via JavaScript.
	 * Also outputs the localized data block when called outside the normal
	 * enqueue flow (e.g. block editor profile page).
	 *
	 * @param \WP_User $profile_user The user whose profile is being viewed.
	 * @return void
	 */
	public static function render_modal( \WP_User $profile_user ): void {
		?>
		<div id="mcp-helper-modal" class="mcp-helper-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="mcp-helper-modal-title">
			<div class="mcp-helper-modal-content">
				<button class="mcp-helper-modal-close" aria-label="<?php esc_attr_e( 'Close', 'mcp-helper' ); ?>">&times;</button>

				<h2 id="mcp-helper-modal-title"><?php esc_html_e( 'Connect to an AI Tool', 'mcp-helper' ); ?></h2>
				<p><?php esc_html_e( 'Select your AI tool below. The configuration block will be generated automatically using the application password you just created.', 'mcp-helper' ); ?></p>

				<div class="mcp-helper-tabs" role="tablist">
					<button
						class="mcp-helper-tab-btn active"
						data-tool="claude-desktop"
						role="tab"
						aria-selected="true"
					><?php esc_html_e( 'Claude Desktop', 'mcp-helper' ); ?></button>
					<button
						class="mcp-helper-tab-btn"
						data-tool="github-copilot"
						role="tab"
						aria-selected="false"
					><?php esc_html_e( 'GitHub Copilot', 'mcp-helper' ); ?></button>
				</div>

				<div class="mcp-helper-config-block">
					<div class="mcp-helper-code-header">
						<span class="mcp-helper-filename-label"></span>
						<button id="mcp-helper-copy-btn" class="button button-secondary">
							<?php esc_html_e( 'Copy JSON', 'mcp-helper' ); ?>
						</button>
					</div>
					<pre id="mcp-helper-json-output" class="mcp-helper-code"></pre>
				</div>

				<div id="mcp-helper-instructions" class="mcp-helper-instructions"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue JS and CSS on profile pages only.
	 *
	 * Guarded by wp_script_is() so multiple plugins can safely call init()
	 * without double-registering assets.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( string $hook ): void {
		if ( ! in_array( $hook, [ 'profile.php', 'user-edit.php' ], true ) ) {
			return;
		}

		if ( wp_script_is( 'mcp-helper', 'registered' ) ) {
			return;
		}

		$lib_url     = self::get_lib_url();
		$lib_version = '1.0.0';

		wp_register_style(
			'mcp-helper',
			$lib_url . '/assets/css/mcp-config.css',
			[],
			$lib_version
		);
		wp_enqueue_style( 'mcp-helper' );

		wp_register_script(
			'mcp-helper',
			$lib_url . '/assets/js/mcp-config.js',
			[ 'jquery' ],
			$lib_version,
			true
		);
		wp_enqueue_script( 'mcp-helper' );

		$profile_user = self::get_profile_user();

		wp_localize_script(
			'mcp-helper',
			'mcpHelper',
			[
				'siteUrl'  => get_site_url(),
				'username' => $profile_user instanceof \WP_User ? $profile_user->user_login : '',
				'nonce'    => wp_create_nonce( 'mcp_helper_get_tools' ),
				'tools'    => MCP_Config_Provider::get_tools_metadata(),
				'i18n'     => [
					'copied'        => __( 'Copied!', 'mcp-helper' ),
					'copy'          => __( 'Copy JSON', 'mcp-helper' ),
					'connectBtn'    => __( 'Connect to AI', 'mcp-helper' ),
					'configPaths'   => __( 'Config file location:', 'mcp-helper' ),
					'instructions'  => __( 'How to apply:', 'mcp-helper' ),
				],
			]
		);
	}

	/**
	 * AJAX handler: returns tool metadata as JSON.
	 *
	 * @return void
	 */
	public static function ajax_get_tools(): void {
		check_ajax_referer( 'mcp_helper_get_tools', 'nonce' );

		if ( ! current_user_can( 'edit_users' ) ) {
			wp_send_json_error( 'Insufficient permissions.', 403 );
		}

		wp_send_json_success( MCP_Config_Provider::get_tools_metadata() );
	}

	/**
	 * Resolves the URL to this library's directory.
	 *
	 * Works whether the lib is loaded as a Composer dependency (inside vendor/)
	 * or as a direct path include.
	 *
	 * @return string URL without trailing slash.
	 */
	private static function get_lib_url(): string {
		// __DIR__ is the lib root (where this file lives).
		$lib_path    = wp_normalize_path( __DIR__ );
		$content_dir = wp_normalize_path( WP_CONTENT_DIR );

		// If the lib sits somewhere under wp-content, build a URL from it.
		if ( str_starts_with( $lib_path, $content_dir ) ) {
			$relative = substr( $lib_path, strlen( $content_dir ) );
			return content_url( $relative );
		}

		// Fallback: register via plugin_dir_url() equivalent using ABSPATH.
		$abspath = wp_normalize_path( ABSPATH );
		if ( str_starts_with( $lib_path, $abspath ) ) {
			$relative = substr( $lib_path, strlen( $abspath ) );
			return site_url( '/' . ltrim( $relative, '/' ) );
		}

		return '';
	}

	/**
	 * Returns the WP_User whose profile is currently being viewed.
	 *
	 * On profile.php it is the current user; on user-edit.php it is the
	 * user_id query param (if the current user can edit them).
	 *
	 * @return \WP_User|null
	 */
	private static function get_profile_user(): ?\WP_User {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$user_id = isset( $_GET['user_id'] ) ? (int) $_GET['user_id'] : get_current_user_id();
		$user    = get_userdata( $user_id );
		return $user instanceof \WP_User ? $user : null;
	}
}
