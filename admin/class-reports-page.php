<?php
/**
 * Reports Page class file.
 *
 * This file defines the Reports Admin Page for viewing and managing reports.
 *
 * @package Rocket\Sybgo\Admin
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Rocket\Sybgo\Admin;

use Rocket\Sybgo\Database\Event_Repository;
use Rocket\Sybgo\Database\Report_Repository;
use Rocket\Sybgo\Reports\Report_Manager;
use Rocket\Sybgo\Reports\Report_Generator;
use Rocket\Sybgo\Email\Email_Manager;

/**
 * Reports Page class.
 *
 * Displays all reports with filtering and manual freeze functionality.
 *
 * @package Rocket\Sybgo\Admin
 * @since   1.0.0
 */
class Reports_Page {
	/**
	 * Event repository instance.
	 *
	 * @var Event_Repository
	 */
	private Event_Repository $event_repo;

	/**
	 * Report repository instance.
	 *
	 * @var Report_Repository
	 */
	private Report_Repository $report_repo;

	/**
	 * Report manager instance.
	 *
	 * @var Report_Manager
	 */
	private Report_Manager $report_manager;

	/**
	 * Report generator instance.
	 *
	 * @var Report_Generator
	 */
	private Report_Generator $report_generator;

	/**
	 * Email manager instance.
	 *
	 * @var Email_Manager
	 */
	private Email_Manager $email_manager;

	/**
	 * Constructor.
	 *
	 * @param Event_Repository  $event_repo Event repository.
	 * @param Report_Repository $report_repo Report repository.
	 * @param Report_Manager    $report_manager Report manager.
	 * @param Report_Generator  $report_generator Report generator.
	 * @param Email_Manager     $email_manager Email manager.
	 */
	public function __construct(
		Event_Repository $event_repo,
		Report_Repository $report_repo,
		Report_Manager $report_manager,
		Report_Generator $report_generator,
		Email_Manager $email_manager
	) {
		$this->event_repo       = $event_repo;
		$this->report_repo      = $report_repo;
		$this->report_manager   = $report_manager;
		$this->report_generator = $report_generator;
		$this->email_manager    = $email_manager;
	}

	/**
	 * Initialize the reports page.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'add_reports_page' ) );
		add_action( 'admin_post_sybgo_freeze_now', array( $this, 'handle_manual_freeze' ) );
		add_action( 'admin_post_sybgo_resend_email', array( $this, 'handle_resend_email' ) );
	}

	/**
	 * Add reports page to admin menu.
	 *
	 * @return void
	 */
	public function add_reports_page(): void {
		add_menu_page(
			__( 'Sybgo Reports', 'sybgo' ),
			__( 'Sybgo Reports', 'sybgo' ),
			'manage_options',
			'sybgo-reports',
			array( $this, 'render_reports_page' ),
			'dashicons-chart-line',
			30
		);
	}

	/**
	 * Render reports page.
	 *
	 * @return void
	 */
	public function render_reports_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Handle view parameter.
		$view      = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$report_id = isset( $_GET['report_id'] ) ? absint( $_GET['report_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Sybgo Reports', 'sybgo' ); ?></h1>

			<?php if ( 'list' === $view ) : ?>
				<?php $this->render_freeze_button(); ?>
			<?php endif; ?>

			<hr class="wp-header-end">

			<?php $this->render_notices(); ?>

			<?php if ( 'details' === $view && $report_id > 0 ) : ?>
				<?php $this->render_report_details( $report_id ); ?>
			<?php else : ?>
				<?php $this->render_reports_list(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render freeze now button.
	 *
	 * @return void
	 */
	private function render_freeze_button(): void {
		$active_report = $this->report_repo->get_active();

		if ( ! $active_report ) {
			return;
		}

		$events_count = count( $this->event_repo->get_by_report( null ) );

		?>
		<a
			href="#"
			class="page-title-action sybgo-freeze-btn"
			data-events="<?php echo esc_attr( $events_count ); ?>"
		>
			<?php esc_html_e( 'Freeze & Send Now', 'sybgo' ); ?>
		</a>

		<div id="sybgo-freeze-modal" class="sybgo-modal" style="display:none;">
			<div class="sybgo-modal-content">
				<span class="sybgo-modal-close">&times;</span>
				<h2><?php esc_html_e( 'Freeze Current Report?', 'sybgo' ); ?></h2>
				<div class="sybgo-modal-body">
					<p>
						<strong><?php esc_html_e( 'This will:', 'sybgo' ); ?></strong>
					</p>
					<ul>
						<li><?php esc_html_e( 'End the current weekly period early', 'sybgo' ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Freeze %d tracked events', 'sybgo' ), $events_count ) ); ?></li>
						<li><?php esc_html_e( 'Send the digest email immediately', 'sybgo' ); ?></li>
						<li><?php esc_html_e( 'Start a new reporting period', 'sybgo' ); ?></li>
					</ul>
					<p>
						<?php esc_html_e( 'Are you sure you want to continue?', 'sybgo' ); ?>
					</p>
				</div>
				<div class="sybgo-modal-footer">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'sybgo_freeze_now', 'sybgo_freeze_nonce' ); ?>
						<input type="hidden" name="action" value="sybgo_freeze_now">
						<button type="submit" class="button button-primary">
							<?php esc_html_e( 'Yes, Freeze & Send', 'sybgo' ); ?>
						</button>
						<button type="button" class="button sybgo-modal-cancel">
							<?php esc_html_e( 'Cancel', 'sybgo' ); ?>
						</button>
					</form>
				</div>
			</div>
		</div>

		<script>
		jQuery(document).ready(function($) {
			$('.sybgo-freeze-btn').on('click', function(e) {
				e.preventDefault();
				$('#sybgo-freeze-modal').fadeIn(200);
			});

			$('.sybgo-modal-close, .sybgo-modal-cancel, .sybgo-modal').on('click', function(e) {
				if ($(e.target).hasClass('sybgo-modal') ||
				    $(e.target).hasClass('sybgo-modal-close') ||
				    $(e.target).hasClass('sybgo-modal-cancel')) {
					$('#sybgo-freeze-modal').fadeOut(200);
				}
			});

			$('.sybgo-modal-content').on('click', function(e) {
				e.stopPropagation();
			});
		});
		</script>
		<?php
	}

	/**
	 * Render admin notices.
	 *
	 * @return void
	 */
	private function render_notices(): void {
		if ( ! isset( $_GET['message'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$message = sanitize_text_field( wp_unslash( $_GET['message'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		switch ( $message ) {
			case 'frozen':
				?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Report frozen and email sent successfully!', 'sybgo' ); ?></p>
				</div>
				<?php
				break;

			case 'resent':
				?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Email resent successfully!', 'sybgo' ); ?></p>
				</div>
				<?php
				break;

			case 'error':
				?>
				<div class="notice notice-error is-dismissible">
					<p><?php esc_html_e( 'An error occurred. Please try again.', 'sybgo' ); ?></p>
				</div>
				<?php
				break;
		}
	}

	/**
	 * Render reports list table.
	 *
	 * @return void
	 */
	private function render_reports_list(): void {
		global $wpdb;

		$table_name = $this->report_repo->get_table_name();

		// Get all reports ordered by date.
		$reports = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE status != %s ORDER BY period_end DESC LIMIT 50",
				'active'
			),
			ARRAY_A
		);

		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Period', 'sybgo' ); ?></th>
					<th><?php esc_html_e( 'Events', 'sybgo' ); ?></th>
					<th><?php esc_html_e( 'Status', 'sybgo' ); ?></th>
					<th><?php esc_html_e( 'Created', 'sybgo' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'sybgo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $reports ) ) : ?>
					<tr>
						<td colspan="5" style="text-align: center;">
							<?php esc_html_e( 'No reports found. Reports will appear here after the first freeze.', 'sybgo' ); ?>
						</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $reports as $report ) : ?>
						<?php $this->render_report_row( $report ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render single report row.
	 *
	 * @param array $report Report data.
	 * @return void
	 */
	private function render_report_row( array $report ): void {
		$summary      = json_decode( $report['summary_data'], true );
		$event_count  = $summary['total_events'] ?? 0;
		$period_start = gmdate( 'M j, Y', strtotime( $report['period_start'] ) );
		$period_end   = gmdate( 'M j, Y', strtotime( $report['period_end'] ) );
		$created      = human_time_diff( strtotime( $report['period_end'] ), current_time( 'timestamp' ) ) . ' ago';

		?>
		<tr>
			<td>
				<strong><?php echo esc_html( $period_start . ' – ' . $period_end ); ?></strong>
			</td>
			<td>
				<?php echo esc_html( number_format_i18n( $event_count ) ); ?>
			</td>
			<td>
				<?php $this->render_status_badge( $report['status'] ); ?>
			</td>
			<td>
				<?php echo esc_html( $created ); ?>
			</td>
			<td>
				<a
					href="<?php echo esc_url( admin_url( 'admin.php?page=sybgo-reports&view=details&report_id=' . $report['id'] ) ); ?>"
					class="button button-small"
				>
					<?php esc_html_e( 'View Details', 'sybgo' ); ?>
				</a>

				<?php if ( 'frozen' === $report['status'] || 'emailed' === $report['status'] ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline;">
						<?php wp_nonce_field( 'sybgo_resend_email', 'sybgo_resend_nonce' ); ?>
						<input type="hidden" name="action" value="sybgo_resend_email">
						<input type="hidden" name="report_id" value="<?php echo esc_attr( $report['id'] ); ?>">
						<button type="submit" class="button button-small">
							<?php esc_html_e( 'Resend Email', 'sybgo' ); ?>
						</button>
					</form>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render status badge.
	 *
	 * @param string $status Report status.
	 * @return void
	 */
	private function render_status_badge( string $status ): void {
		$badges = array(
			'active'  => array( 'label' => __( 'Active', 'sybgo' ), 'color' => '#2271b1' ),
			'frozen'  => array( 'label' => __( 'Frozen', 'sybgo' ), 'color' => '#dba617' ),
			'emailed' => array( 'label' => __( 'Sent', 'sybgo' ), 'color' => '#00a32a' ),
		);

		$badge = $badges[ $status ] ?? array( 'label' => $status, 'color' => '#646970' );

		?>
		<span style="display: inline-block; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; color: #fff; background-color: <?php echo esc_attr( $badge['color'] ); ?>;">
			<?php echo esc_html( strtoupper( $badge['label'] ) ); ?>
		</span>
		<?php
	}

	/**
	 * Render report details view.
	 *
	 * @param int $report_id Report ID.
	 * @return void
	 */
	private function render_report_details( int $report_id ): void {
		$report = $this->report_repo->get_by_id( $report_id );

		if ( ! $report ) {
			?>
			<div class="notice notice-error">
				<p><?php esc_html_e( 'Report not found.', 'sybgo' ); ?></p>
			</div>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=sybgo-reports' ) ); ?>" class="button">
					<?php esc_html_e( '← Back to Reports', 'sybgo' ); ?>
				</a>
			</p>
			<?php
			return;
		}

		$summary = json_decode( $report['summary_data'], true );
		$events  = $this->event_repo->get_by_report( $report_id );

		?>
		<div class="sybgo-report-details">
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=sybgo-reports' ) ); ?>" class="button">
					<?php esc_html_e( '← Back to Reports', 'sybgo' ); ?>
				</a>
			</p>

			<div class="sybgo-report-header">
				<h2>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %1$s: start date, %2$s: end date */
							__( 'Report: %1$s to %2$s', 'sybgo' ),
							gmdate( 'F j, Y', strtotime( $report['period_start'] ) ),
							gmdate( 'F j, Y', strtotime( $report['period_end'] ) )
						)
					);
					?>
				</h2>
				<?php $this->render_status_badge( $report['status'] ); ?>
			</div>

			<?php if ( $summary ) : ?>
				<div class="sybgo-summary-cards">
					<h3><?php esc_html_e( 'Summary', 'sybgo' ); ?></h3>

					<div class="sybgo-stats-grid">
						<?php foreach ( $summary['totals'] as $type => $count ) : ?>
							<?php
							$trend      = $summary['trends'][ $type ] ?? null;
							$type_label = ucwords( str_replace( '_', ' ', $type ) );
							?>
							<div class="sybgo-stat-card">
								<div class="sybgo-stat-label"><?php echo esc_html( $type_label ); ?></div>
								<div class="sybgo-stat-value">
									<?php echo esc_html( number_format_i18n( $count ) ); ?>
									<?php if ( $trend && 'same' !== $trend['direction'] ) : ?>
										<span class="sybgo-trend <?php echo esc_attr( $trend['direction'] ); ?>">
											<?php
											$arrow = 'up' === $trend['direction'] ? '↑' : '↓';
											echo esc_html( $arrow . ' ' . absint( $trend['change_percent'] ) . '%' );
											?>
										</span>
									<?php endif; ?>
								</div>
							</div>
						<?php endforeach; ?>
					</div>

					<?php if ( ! empty( $summary['highlights'] ) ) : ?>
						<h3><?php esc_html_e( 'Highlights', 'sybgo' ); ?></h3>
						<ul class="sybgo-highlights-list">
							<?php foreach ( $summary['highlights'] as $highlight ) : ?>
								<li><?php echo esc_html( $highlight ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<h3><?php esc_html_e( 'All Events', 'sybgo' ); ?> (<?php echo esc_html( count( $events ) ); ?>)</h3>

			<?php if ( empty( $events ) ) : ?>
				<p><?php esc_html_e( 'No events in this report.', 'sybgo' ); ?></p>
			<?php else : ?>
				<?php $this->render_events_table( $events ); ?>
			<?php endif; ?>
		</div>

		<style>
		.sybgo-report-header {
			display: flex;
			align-items: center;
			gap: 15px;
			margin-bottom: 20px;
		}

		.sybgo-report-header h2 {
			margin: 0;
		}

		.sybgo-summary-cards {
			background: #fff;
			border: 1px solid #c3c4c7;
			padding: 20px;
			margin-bottom: 20px;
		}

		.sybgo-stats-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
			gap: 15px;
			margin: 20px 0;
		}

		.sybgo-stat-card {
			padding: 15px;
			background: #f6f7f7;
			border-radius: 4px;
			text-align: center;
		}

		.sybgo-stat-label {
			font-size: 11px;
			color: #646970;
			text-transform: uppercase;
			letter-spacing: 0.5px;
			margin-bottom: 5px;
		}

		.sybgo-stat-value {
			font-size: 28px;
			font-weight: 600;
			color: #1d2327;
		}

		.sybgo-trend {
			font-size: 14px;
			margin-left: 5px;
		}

		.sybgo-trend.up {
			color: #00a32a;
		}

		.sybgo-trend.down {
			color: #d63638;
		}

		.sybgo-highlights-list {
			list-style: disc;
			padding-left: 20px;
		}

		.sybgo-highlights-list li {
			margin-bottom: 8px;
		}
		</style>
		<?php
	}

	/**
	 * Render events table.
	 *
	 * @param array $events Events to display.
	 * @return void
	 */
	private function render_events_table( array $events ): void {
		// Sort by timestamp descending.
		usort( $events, function( $a, $b ) {
			return strtotime( $b['event_timestamp'] ) - strtotime( $a['event_timestamp'] );
		} );

		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width: 50px;"><?php esc_html_e( 'Type', 'sybgo' ); ?></th>
					<th><?php esc_html_e( 'Description', 'sybgo' ); ?></th>
					<th style="width: 180px;"><?php esc_html_e( 'Time', 'sybgo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $events as $event ) : ?>
					<?php
					$event_data = json_decode( $event['event_data'], true );
					$icon       = $this->get_event_icon( $event['event_type'] );
					$title      = $this->get_event_title( $event['event_type'], $event_data );
					$time       = gmdate( 'M j, Y g:i A', strtotime( $event['event_timestamp'] ) );
					?>
					<tr>
						<td style="text-align: center; font-size: 20px;">
							<?php echo esc_html( $icon ); ?>
						</td>
						<td><?php echo esc_html( $title ); ?></td>
						<td><?php echo esc_html( $time ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Get icon for event type.
	 *
	 * @param string $event_type Event type.
	 * @return string Icon character.
	 */
	private function get_event_icon( string $event_type ): string {
		$icons = array(
			'post_published'     => '📝',
			'post_edited'        => '✏️',
			'post_deleted'       => '🗑️',
			'user_registered'    => '👤',
			'user_role_changed'  => '👥',
			'core_updated'       => '🔄',
			'plugin_installed'   => '➕',
			'plugin_activated'   => '✅',
			'plugin_deactivated' => '⏸️',
			'plugin_updated'     => '🔌',
			'theme_installed'    => '🎨',
			'theme_updated'      => '🎨',
			'theme_switched'     => '🔄',
			'comment_new'        => '💬',
			'comment_approved'   => '✅',
		);

		return $icons[ $event_type ] ?? '•';
	}

	/**
	 * Get human-readable title for event.
	 *
	 * @param string $event_type Event type.
	 * @param array  $event_data Event data.
	 * @return string Event title.
	 */
	private function get_event_title( string $event_type, array $event_data ): string {
		$object = $event_data['object'] ?? array();

		switch ( $event_type ) {
			case 'post_published':
				return sprintf( 'New %s published: %s', $object['type'] ?? 'post', $object['title'] ?? 'Untitled' );

			case 'post_edited':
				$magnitude = $event_data['metadata']['edit_magnitude'] ?? 0;
				return sprintf( '%s edited (%d%% of content changed)', $object['title'] ?? 'Post', $magnitude );

			case 'post_deleted':
				return sprintf( '%s "%s" was deleted', ucfirst( $object['type'] ?? 'Post' ), $object['title'] ?? 'Untitled' );

			case 'user_registered':
				return sprintf( 'New user registered: %s (%s)', $object['username'] ?? 'Unknown', $object['email'] ?? '' );

			case 'user_role_changed':
				$prev_role = $event_data['metadata']['previous_role'] ?? 'subscriber';
				$new_role  = $event_data['metadata']['role'] ?? 'subscriber';
				return sprintf( 'User %s role changed from %s to %s', $object['username'] ?? 'Unknown', $prev_role, $new_role );

			case 'core_updated':
				$old_ver = $event_data['metadata']['old_version'] ?? 'unknown';
				$new_ver = $event_data['metadata']['new_version'] ?? 'latest';
				return sprintf( 'WordPress updated from %s to %s', $old_ver, $new_ver );

			case 'plugin_installed':
				$version = $event_data['metadata']['version'] ?? 'unknown';
				return sprintf( 'Plugin "%s" installed (v%s)', $object['name'] ?? 'Unknown', $version );

			case 'plugin_activated':
				return sprintf( 'Plugin "%s" activated', $object['name'] ?? 'Unknown' );

			case 'plugin_deactivated':
				return sprintf( 'Plugin "%s" deactivated', $object['name'] ?? 'Unknown' );

			case 'plugin_updated':
				$old_ver = $event_data['metadata']['old_version'] ?? 'unknown';
				$new_ver = $event_data['metadata']['new_version'] ?? 'latest';
				return sprintf( 'Plugin "%s" updated from %s to %s', $object['name'] ?? 'Unknown', $old_ver, $new_ver );

			case 'theme_installed':
				$version = $event_data['metadata']['version'] ?? 'unknown';
				return sprintf( 'Theme "%s" installed (v%s)', $object['name'] ?? 'Unknown', $version );

			case 'theme_updated':
				$old_ver = $event_data['metadata']['old_version'] ?? 'unknown';
				$new_ver = $event_data['metadata']['new_version'] ?? 'latest';
				return sprintf( 'Theme "%s" updated from %s to %s', $object['name'] ?? 'Unknown', $old_ver, $new_ver );

			case 'theme_switched':
				$old_theme = $event_data['metadata']['old_theme'] ?? 'Unknown';
				return sprintf( 'Theme switched from "%s" to "%s"', $old_theme, $object['name'] ?? 'Unknown' );

			case 'comment_new':
				return sprintf( 'New comment on: %s', $event_data['metadata']['post_title'] ?? 'Unknown post' );

			case 'comment_approved':
				return sprintf( 'Comment approved on: %s', $event_data['metadata']['post_title'] ?? 'Unknown post' );

			default:
				return ucwords( str_replace( '_', ' ', $event_type ) );
		}
	}

	/**
	 * Handle manual freeze request.
	 *
	 * @return void
	 */
	public function handle_manual_freeze(): void {
		// Verify nonce.
		if ( ! isset( $_POST['sybgo_freeze_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sybgo_freeze_nonce'] ) ), 'sybgo_freeze_now' ) ) {
			wp_die( esc_html__( 'Security check failed', 'sybgo' ) );
		}

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'sybgo' ) );
		}

		try {
			// Freeze current report.
			$frozen_id = $this->report_manager->freeze_current_report();

			if ( ! $frozen_id ) {
				throw new \Exception( 'Failed to freeze report' );
			}

			// Send email immediately.
			$this->email_manager->send_report_email( $frozen_id );

			// Redirect with success message.
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => 'sybgo-reports',
						'message' => 'frozen',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;

		} catch ( \Exception $e ) {
			// Redirect with error message.
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => 'sybgo-reports',
						'message' => 'error',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
	}

	/**
	 * Handle resend email request.
	 *
	 * @return void
	 */
	public function handle_resend_email(): void {
		// Verify nonce.
		if ( ! isset( $_POST['sybgo_resend_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sybgo_resend_nonce'] ) ), 'sybgo_resend_email' ) ) {
			wp_die( esc_html__( 'Security check failed', 'sybgo' ) );
		}

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'sybgo' ) );
		}

		$report_id = isset( $_POST['report_id'] ) ? absint( $_POST['report_id'] ) : 0;

		if ( ! $report_id ) {
			wp_die( esc_html__( 'Invalid report ID', 'sybgo' ) );
		}

		// Resend email.
		$sent = $this->email_manager->send_report_email( $report_id );

		$message = $sent ? 'resent' : 'error';

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'sybgo-reports',
					'message' => $message,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
