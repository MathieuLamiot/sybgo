<?php
/**
 * MCP Sessions Admin Page.
 *
 * Adds an "MCP Sessions" options page that lists all active MCP Application
 * Passwords site-wide, allows individual session revocation, and exposes the
 * "Revoke all MCP sessions" button that regenerates the JWT signing secret.
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
 * Admin Page.
 *
 * @since 1.0.0
 */
class Admin_Page {

	/**
	 * Prefix used for all Application Password names created by MCP.
	 */
	const APP_PASS_PREFIX = 'MCP Session'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

	/**
	 * Register WordPress admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_post_mcp_revoke_session', array( $this, 'handle_revoke_session' ) );
		add_action( 'admin_post_mcp_revoke_all_sessions', array( $this, 'handle_revoke_all' ) );
	}

	/**
	 * Register the MCP Sessions options page.
	 *
	 * @return void
	 */
	public function add_menu_page(): void {
		add_options_page(
			esc_html__( 'MCP Sessions', 'sybgo' ),
			esc_html__( 'MCP Sessions', 'sybgo' ),
			'manage_options',
			'mcp-sessions',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the MCP Sessions admin page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'sybgo' ) );
		}

		$sessions = $this->get_all_sessions();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'MCP Sessions', 'sybgo' ); ?></h1>

			<?php if ( isset( $_GET['mcp_notice'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p>
					<?php
					if ( 'revoked' === $_GET['mcp_notice'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						esc_html_e( 'Session revoked.', 'sybgo' );
					} elseif ( 'revoked_all' === $_GET['mcp_notice'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						esc_html_e( 'All MCP sessions revoked and secret regenerated.', 'sybgo' );
					}
					?>
					</p>
				</div>
			<?php endif; ?>

			<p>
				<?php esc_html_e( 'Active MCP sessions below. Revoking a session immediately invalidates the associated access tokens.', 'sybgo' ); ?>
			</p>

			<?php if ( empty( $sessions ) ) : ?>
				<p><em><?php esc_html_e( 'No active MCP sessions.', 'sybgo' ); ?></em></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'User', 'sybgo' ); ?></th>
							<th><?php esc_html_e( 'Session Name', 'sybgo' ); ?></th>
							<th><?php esc_html_e( 'Created', 'sybgo' ); ?></th>
							<th><?php esc_html_e( 'Last Used', 'sybgo' ); ?></th>
							<th><?php esc_html_e( 'Action', 'sybgo' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $sessions as $session ) : ?>
						<tr>
							<td><?php echo esc_html( $session['user_display_name'] ); ?></td>
							<td><?php echo esc_html( $session['name'] ); ?></td>
							<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $session['created'] ) ); ?></td>
							<td>
								<?php
								if ( ! empty( $session['last_used'] ) ) {
									echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $session['last_used'] ) );
								} else {
									esc_html_e( 'Never', 'sybgo' );
								}
								?>
							</td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="mcp_revoke_session">
									<input type="hidden" name="user_id" value="<?php echo esc_attr( $session['user_id'] ); ?>">
									<input type="hidden" name="uuid" value="<?php echo esc_attr( $session['uuid'] ); ?>">
									<?php wp_nonce_field( 'mcp_revoke_session_' . $session['uuid'], 'mcp_nonce' ); ?>
									<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Revoke', 'sybgo' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<hr>

			<h2><?php esc_html_e( 'Revoke All MCP Sessions', 'sybgo' ); ?></h2>
			<p>
				<?php esc_html_e( 'Regenerating the site secret invalidates every outstanding MCP token immediately — all clients must re-authenticate.', 'sybgo' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mcp_revoke_all_sessions">
				<?php wp_nonce_field( 'mcp_revoke_all_sessions', 'mcp_nonce' ); ?>
				<button type="submit" class="button button-secondary"><?php esc_html_e( 'Revoke All MCP Sessions', 'sybgo' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the revoke-single-session form submission.
	 *
	 * @return void
	 */
	public function handle_revoke_session(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sybgo' ) );
		}

		$uuid = sanitize_text_field( wp_unslash( $_POST['uuid'] ?? '' ) );

		check_admin_referer( 'mcp_revoke_session_' . $uuid, 'mcp_nonce' );

		$user_id = absint( wp_unslash( $_POST['user_id'] ?? 0 ) );

		if ( $user_id > 0 && '' !== $uuid ) {
			\WP_Application_Passwords::delete_application_password( $user_id, $uuid );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'mcp-sessions',
					'mcp_notice' => 'revoked',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Handle the revoke-all-sessions form submission.
	 *
	 * Regenerates the JWT signing secret, immediately invalidating all tokens.
	 *
	 * @return void
	 */
	public function handle_revoke_all(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sybgo' ) );
		}

		check_admin_referer( 'mcp_revoke_all_sessions', 'mcp_nonce' );

		// Delete all MCP Application Passwords across all users.
		foreach ( $this->get_all_sessions() as $session ) {
			\WP_Application_Passwords::delete_application_password( (int) $session['user_id'], $session['uuid'] );
		}

		// Regenerate the secret — every outstanding JWT is now invalid.
		Secret_Manager::regenerate();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'mcp-sessions',
					'mcp_notice' => 'revoked_all',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Collect all active MCP sessions across all WordPress users.
	 *
	 * Iterates over every user (feasible for sites where sessions are rare) and
	 * returns Application Passwords whose name starts with self::APP_PASS_PREFIX.
	 *
	 * @return array<int, array<string, mixed>> Flat list of session records.
	 */
	private function get_all_sessions(): array {
		$sessions = array();

		$users = get_users( array( 'fields' => array( 'ID', 'display_name' ) ) );

		foreach ( $users as $user ) {
			$app_passwords = \WP_Application_Passwords::get_user_application_passwords( (int) $user->ID );

			foreach ( $app_passwords as $app_pass ) {
				if ( 0 !== strpos( $app_pass['name'], self::APP_PASS_PREFIX ) ) {
					continue;
				}

				$sessions[] = array(
					'user_id'           => $user->ID,
					'user_display_name' => $user->display_name,
					'uuid'              => $app_pass['uuid'],
					'name'              => $app_pass['name'],
					'created'           => $app_pass['created'],
					'last_used'         => $app_pass['last_used'] ?? null,
				);
			}
		}

		return $sessions;
	}
}
