<?php
/**
 * Reports Page class file.
 *
 * This file defines the Reports Admin Page for viewing and managing reports.
 *
 * @package Sybgo\Admin
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Sybgo\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Sybgo\Database\Aggregated_Event_Repository;
use Sybgo\Database\Event_Repository;
use Sybgo\Database\Report_Repository;
use Sybgo\Reports\Report_Manager;
use Sybgo\Reports\Report_Generator;
use Sybgo\Email\Email_Manager;
use Sybgo\Events\Event_Registry;
use Sybgo\AI\AI_Summarizer;

/**
 * Reports Page class.
 *
 * Displays all reports with filtering and manual freeze functionality.
 *
 * @package Sybgo\Admin
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
	 * Event registry instance.
	 *
	 * @var Event_Registry
	 */
	private Event_Registry $event_registry;

	/**
	 * Aggregated event repository instance.
	 *
	 * Used to query PHP error rows for the report detail view.
	 *
	 * @var Aggregated_Event_Repository
	 */
	private Aggregated_Event_Repository $aggregated_repo;

	/**
	 * AI summarizer instance.
	 *
	 * @var AI_Summarizer|null
	 */
	private ?AI_Summarizer $ai_summarizer;

	/**
	 * Constructor.
	 *
	 * @param Event_Repository            $event_repo       Event repository.
	 * @param Report_Repository           $report_repo      Report repository.
	 * @param Report_Manager              $report_manager   Report manager.
	 * @param Report_Generator            $report_generator Report generator.
	 * @param Email_Manager               $email_manager    Email manager.
	 * @param Event_Registry              $event_registry   Event registry.
	 * @param Aggregated_Event_Repository $aggregated_repo  Aggregated event repository.
	 * @param AI_Summarizer|null          $ai_summarizer    AI summarizer or null if unavailable.
	 */
	public function __construct(
		Event_Repository $event_repo,
		Report_Repository $report_repo,
		Report_Manager $report_manager,
		Report_Generator $report_generator,
		Email_Manager $email_manager,
		Event_Registry $event_registry,
		Aggregated_Event_Repository $aggregated_repo,
		?AI_Summarizer $ai_summarizer = null
	) {
		$this->event_repo       = $event_repo;
		$this->report_repo      = $report_repo;
		$this->report_manager   = $report_manager;
		$this->report_generator = $report_generator;
		$this->email_manager    = $email_manager;
		$this->event_registry   = $event_registry;
		$this->aggregated_repo  = $aggregated_repo;
		$this->ai_summarizer    = $ai_summarizer;
	}

	/**
	 * Initialize the reports page.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'add_reports_page' ] );
		add_action( 'admin_post_sybgo_freeze_now', [ $this, 'handle_manual_freeze' ] );
		add_action( 'admin_post_sybgo_resend_email', [ $this, 'handle_resend_email' ] );
		add_action( 'wp_ajax_sybgo_generate_ai_summary', [ $this, 'ajax_generate_ai_summary' ] );
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
			[ $this, 'render_reports_page' ),
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
		$view      = 'list';
		$report_id = 0;

		if ( isset( $_GET['view'] ) ) {
			check_admin_referer( 'sybgo_view_report' );
			$view      = sanitize_text_field( wp_unslash( $_GET['view'] ) );
			$report_id = isset( $_GET['report_id'] ) ? absint( $_GET['report_id'] ) : 0;
		}

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Sybgo Reports', 'sybgo' ); ?></h1>

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
	 * Render admin notices.
	 *
	 * @return void
	 */
	private function render_notices(): void {
		if ( ! isset( $_GET['message'] ) ) {
			return;
		}

		check_admin_referer( 'sybgo_report_message' );
		$message = sanitize_text_field( wp_unslash( $_GET['message'] ) );

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

		$table_name = esc_sql( $this->report_repo->get_table_name() );

		// Get all frozen/emailed reports ordered by date.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin page query; not in repository.
		$reports = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name variable; not user input.
				"SELECT * FROM {$table_name} WHERE status != %s ORDER BY period_end DESC LIMIT 50",
				'active'
			),
			ARRAY_A
		);

		$active_report = $this->report_repo->get_active();
		$events_count  = $active_report ? count( $this->event_repo->get_by_report( null ) ) : 0;

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
				<?php if ( ! $active_report && empty( $reports ) ) : ?>
					<tr>
						<td colspan="5" style="text-align: center;">
							<?php esc_html_e( 'No reports found. Reports will appear here after the first freeze.', 'sybgo' ); ?>
						</td>
					</tr>
				<?php else : ?>
					<?php if ( $active_report ) : ?>
						<?php $this->render_active_report_row( $active_report, $events_count ); ?>
					<?php endif; ?>
					<?php foreach ( $reports as $report ) : ?>
						<?php $this->render_report_row( $report ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the active (ongoing) report as the first table row.
	 *
	 * @param array<string, mixed> $report       Active report data.
	 * @param int                  $events_count Live count of unassigned events.
	 * @return void
	 */
	private function render_active_report_row( array $report, int $events_count ): void {
		$period_start = gmdate( 'M j, Y', strtotime( $report['period_start'] ) );
		$running_for  = human_time_diff( strtotime( $report['period_start'] ), time() ) . ' ago';

		?>
		<tr>
			<td>
				<strong><?php echo esc_html( $period_start . ' – ' . __( 'Now', 'sybgo' ) ); ?></strong>
			</td>
			<td>
				<?php echo esc_html( number_format_i18n( $events_count ) ); ?>
			</td>
			<td>
				<?php $this->render_status_badge( 'active' ); ?>
			</td>
			<td>
				<?php echo esc_html( $running_for ); ?>
			</td>
			<td>
				<a
					href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=sybgo-reports&view=details&report_id=' . $report['id'] ), 'sybgo_view_report' ) ); ?>"
					class="button button-small"
				>
					<?php esc_html_e( 'View Details', 'sybgo' ); ?>
				</a>
				<a
					href="#"
					class="button button-small sybgo-freeze-btn"
					data-events="<?php echo esc_attr( (string) $events_count ); ?>"
				>
					<?php esc_html_e( 'Freeze & Send Now', 'sybgo' ); ?>
				</a>
			</td>
		</tr>

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
						<?php /* translators: %d: number of tracked events to freeze */ ?>
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
	 * Render single report row.
	 *
	 * @param array<string, mixed> $report Report data.
	 * @return void
	 */
	private function render_report_row( array $report ): void {
		$summary      = json_decode( $report['summary_data'], true );
		$event_count  = $summary['total_events'] ?? 0;
		$period_start = gmdate( 'M j, Y', strtotime( $report['period_start'] ) );
		$period_end   = gmdate( 'M j, Y', strtotime( $report['period_end'] ) );
		$created      = human_time_diff( strtotime( $report['period_end'] ), time() ) . ' ago';

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
					href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=sybgo-reports&view=details&report_id=' . $report['id'] ), 'sybgo_view_report' ) ); ?>"
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
			'active'  => array(
				'label' => __( 'Active', 'sybgo' ),
				'color' => '#2271b1',
			),
			'frozen'  => array(
				'label' => __( 'Frozen', 'sybgo' ),
				'color' => '#dba617',
			),
			'emailed' => array(
				'label' => __( 'Sent', 'sybgo' ),
				'color' => '#00a32a',
			),
		);

		$badge = $badges[ $status ] ?? array(
			'label' => $status,
			'color' => '#646970',
		);

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

		$summary = ! empty( $report['summary_data'] ) ? json_decode( $report['summary_data'], true ) : null;
		$events  = $this->event_repo->get_by_report( 'active' === $report['status'] ? null : $report_id );

		if ( null === $summary && 'active' === $report['status'] ) {
			$summary = $this->report_generator->generate_live_summary( $events, (int) $report['id'] );
		}

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
							! empty( $report['period_end'] ) ? gmdate( 'F j, Y', strtotime( $report['period_end'] ) ) : __( 'Now', 'sybgo' )
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

					<div class="sybgo-ai-summary-section" style="margin-top: 20px;">
						<h3><?php esc_html_e( 'AI Summary', 'sybgo' ); ?></h3>

						<div
							id="sybgo-ai-summary-box"
							class="sybgo-ai-summary-box"
							style="<?php echo ! empty( $summary['ai_summary'] ) ? '' : 'display:none;'; ?>background: #f0f6fc; border-left: 4px solid #0073aa; padding: 15px; margin-bottom: 15px; border-radius: 4px;"
						>
							<p id="sybgo-ai-summary-text" style="margin: 0; line-height: 1.6; color: #23282d;">
								<?php echo esc_html( $summary['ai_summary'] ?? '' ); ?>
							</p>
						</div>

						<button
							type="button"
							id="sybgo-generate-ai-btn"
							class="button button-secondary sybgo-generate-ai-btn"
							data-report-id="<?php echo esc_attr( (string) $report_id ); ?>"
							<?php if ( null === $this->ai_summarizer ) : ?>
								disabled
								title="<?php esc_attr_e( 'AI summaries require WordPress 7', 'sybgo' ); ?>"
							<?php endif; ?>
						>
							<?php
							if ( ! empty( $summary['ai_summary'] ) ) {
								esc_html_e( 'Regenerate AI Summary', 'sybgo' );
							} else {
								esc_html_e( 'Generate AI Summary', 'sybgo' );
							}
							?>
						</button>
					</div>
				</div>
			<?php endif; ?>

			<h3><?php esc_html_e( 'All Events', 'sybgo' ); ?> (<?php echo esc_html( (string) count( $events ) ); ?>)</h3>

			<?php if ( empty( $events ) ) : ?>
				<p><?php esc_html_e( 'No events in this report.', 'sybgo' ); ?></p>
			<?php else : ?>
				<?php $this->render_events_table( $events ); ?>
			<?php endif; ?>

			<?php $this->render_php_errors_table( $report ); ?>
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
	 * Render PHP errors table for a report.
	 *
	 * Queries aggregated error rows for the report's date range and renders a
	 * wp-list-table with one row per distinct error signature, showing the error
	 * level emoji, message + file:line, and occurrence count.
	 * Renders nothing if no errors were recorded in the period.
	 *
	 * @param array<string, mixed> $report Report data (period_start, period_end, status).
	 * @return void
	 */
	private function render_php_errors_table( array $report ): void {
		// Use report_id IS NULL for the active (unassigned) period, or report_id = N
		// for frozen/emailed reports — same pattern as singular events.
		$report_id  = 'active' === $report['status'] ? null : (int) $report['id'];
		$error_rows = $this->aggregated_repo->get_rows_for_report( 'php_error', $report_id );

		if ( empty( $error_rows ) ) {
			return;
		}

		$level_emoji = array(
			'warning'         => '⚠️',
			'user_warning'    => '⚠️',
			'notice'          => 'ℹ️',
			'user_notice'     => 'ℹ️',
			'deprecated'      => '🔔',
			'user_deprecated' => '🔔',
			'user_error'      => '❌',
		);

		?>
		<h3>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of distinct PHP error signatures */
					__( 'PHP Errors (%d)', 'sybgo' ),
					count( $error_rows )
				)
			);
			?>
		</h3>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width: 50px;"><?php esc_html_e( 'Type', 'sybgo' ); ?></th>
					<th><?php esc_html_e( 'Description', 'sybgo' ); ?></th>
					<th style="width: 80px;"><?php esc_html_e( 'Count', 'sybgo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $error_rows as $row ) : ?>
					<?php
					$dims    = json_decode( $row['dimensions'], true );
					$meta    = json_decode( $row['meta'], true );
					$level   = $dims['level'] ?? 'warning';
					$emoji   = $level_emoji[ $level ] ?? '⚠️';
					$message = $meta['message'] ?? '';
					$file    = $meta['file'] ?? '';
					$line    = $meta['line'] ?? '';
					$count   = (int) $row['total'];
					?>
					<tr>
						<td style="text-align: center; font-size: 20px;">
							<?php echo esc_html( $emoji ); ?>
						</td>
						<td>
							<strong><?php echo esc_html( $message ); ?></strong>
							<?php if ( $file ) : ?>
								<br><code><?php echo esc_html( $file . ':' . $line ); ?></code>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( (string) $count ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render events table.
	 *
	 * @param array<int, array<string, mixed>> $events Events to display.
	 * @return void
	 */
	private function render_events_table( array $events ): void {
		// Sort by timestamp descending.
		usort(
			$events,
			function ( $a, $b ) {
				return strtotime( $b['event_timestamp'] ) - strtotime( $a['event_timestamp'] );
			}
		);

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
					$icon       = $this->event_registry->get_icon( $event['event_type'] );
					$title      = $this->event_registry->get_detailed_title( $event['event_type'], $event_data );
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
	 * Handle manual freeze request.
	 *
	 * @throws \Exception If freeze or email fails.
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
				wp_nonce_url(
					add_query_arg(
						array(
							'page'    => 'sybgo-reports',
							'message' => 'frozen',
						),
						admin_url( 'admin.php' )
					),
					'sybgo_report_message'
				)
			);
			exit;

		} catch ( \Exception $e ) {
			// Redirect with error message.
			wp_safe_redirect(
				wp_nonce_url(
					add_query_arg(
						array(
							'page'    => 'sybgo-reports',
							'message' => 'error',
						),
						admin_url( 'admin.php' )
					),
					'sybgo_report_message'
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
			wp_nonce_url(
				add_query_arg(
					array(
						'page'    => 'sybgo-reports',
						'message' => $message,
					),
					admin_url( 'admin.php' )
				),
				'sybgo_report_message'
			)
		);
		exit;
	}

	/**
	 * AJAX handler: generate and persist an AI summary for a specific report.
	 *
	 * Requires nonce `sybgo_admin_nonce` and `manage_options` capability.
	 * Returns JSON with `success: true` and `summary` string on success.
	 *
	 * @return void
	 */
	public function ajax_generate_ai_summary(): void {
		check_ajax_referer( 'sybgo_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'sybgo' ) ) );
			return; // @phpstan-ignore deadCode.unreachable
		}

		if ( null === $this->ai_summarizer ) {
			wp_send_json_error( array( 'message' => __( 'AI summaries require WordPress 7 or later.', 'sybgo' ) ) );
			return; // @phpstan-ignore deadCode.unreachable
		}

		$report_id = isset( $_POST['report_id'] ) ? absint( $_POST['report_id'] ) : 0;

		if ( ! $report_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid report ID.', 'sybgo' ) ) );
			return; // @phpstan-ignore deadCode.unreachable
		}

		$report = $this->report_repo->get_by_id( $report_id );

		if ( ! $report ) {
			wp_send_json_error( array( 'message' => __( 'Report not found.', 'sybgo' ) ) );
			return; // @phpstan-ignore deadCode.unreachable
		}

		$events       = $this->event_repo->get_by_report( $report_id );
		$live_summary = $this->report_generator->generate_live_summary( $events, $report_id );
		$ai_summary   = $this->ai_summarizer->generate_summary( $events, $live_summary['totals'], $live_summary['trends'] );

		if ( null === $ai_summary ) {
			wp_send_json_error( array( 'message' => __( 'The AI summary could not be generated. Please check your WordPress AI connector configuration.', 'sybgo' ) ) );
			return; // @phpstan-ignore deadCode.unreachable
		}

		$this->report_repo->set_ai_summary( $report_id, $ai_summary );

		wp_send_json_success( array( 'summary' => $ai_summary ) );
	}
}
