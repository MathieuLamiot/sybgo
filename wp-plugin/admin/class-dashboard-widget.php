<?php
/**
 * Dashboard Widget class file.
 *
 * This file defines the Dashboard Widget for displaying Sybgo activity.
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
use Sybgo\Reports\Report_Generator;
use Sybgo\AI\AI_Summarizer;
use Sybgo\Events\Event_Registry;

/**
 * Dashboard Widget class.
 *
 * Displays weekly activity digest in WordPress dashboard.
 *
 * @package Sybgo\Admin
 * @since   1.0.0
 */
class Dashboard_Widget {
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
	 * Report generator instance.
	 *
	 * @var Report_Generator
	 */
	private Report_Generator $report_generator;

	/**
	 * AI summarizer instance.
	 *
	 * @var AI_Summarizer|null
	 */
	private ?AI_Summarizer $ai_summarizer;

	/**
	 * Event registry instance.
	 *
	 * @var Event_Registry
	 */
	private Event_Registry $event_registry;

	/**
	 * Aggregated event repository instance.
	 *
	 * Used to query PHP error counts for the dashboard display.
	 *
	 * @var Aggregated_Event_Repository
	 */
	private Aggregated_Event_Repository $aggregated_repo;

	/**
	 * Constructor.
	 *
	 * @param Event_Repository            $event_repo          Event repository.
	 * @param Report_Repository           $report_repo         Report repository.
	 * @param Report_Generator            $report_generator    Report generator.
	 * @param AI_Summarizer|null          $ai_summarizer       AI summarizer or null if unavailable.
	 * @param Event_Registry              $event_registry      Event registry.
	 * @param Aggregated_Event_Repository $aggregated_repo         Aggregated event repository.
	 */
	public function __construct(
		Event_Repository $event_repo,
		Report_Repository $report_repo,
		Report_Generator $report_generator,
		?AI_Summarizer $ai_summarizer,
		Event_Registry $event_registry,
		Aggregated_Event_Repository $aggregated_repo
	) {
		$this->event_repo       = $event_repo;
		$this->report_repo      = $report_repo;
		$this->report_generator = $report_generator;
		$this->ai_summarizer    = $ai_summarizer;
		$this->event_registry   = $event_registry;
		$this->aggregated_repo  = $aggregated_repo;
	}

	/**
	 * Initialize the dashboard widget.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'wp_dashboard_setup', [ $this, 'register_widget' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_sybgo_filter_events', [ $this, 'ajax_filter_events' ] );
		add_action( 'wp_ajax_sybgo_preview_digest', [ $this, 'ajax_preview_digest' ] );
		add_action( 'wp_ajax_sybgo_widget_ai_summary', [ $this, 'ajax_widget_ai_summary' ] );
		add_action( 'wp_ajax_sybgo_preview_last_digest', [ $this, 'ajax_preview_last_digest' ] );
	}

	/**
	 * Register the dashboard widget.
	 *
	 * @return void
	 */
	public function register_widget(): void {
		wp_add_dashboard_widget(
			'sybgo_activity_widget',
			esc_html__( 'Site Activity Digest', 'sybgo' ),
			[ $this, 'render_widget' ),
			null,
			null,
			'side',
			'high'
		);
	}

	/**
	 * Enqueue widget assets.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'index.php' !== $hook ) {
			return;
		}

		// Localize sybgoWidget onto the sybgo-admin script already enqueued by
		// Sybgo::enqueue_admin_assets(). We do not register a separate JS file
		// here to avoid duplicate event bindings that cause multiple modals.
		wp_localize_script(
			'sybgo-admin',
			'sybgoWidget',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'sybgo_widget_nonce' ),
			)
		);
	}

	/**
	 * Render the dashboard widget.
	 *
	 * @return void
	 */
	public function render_widget(): void {
		// Get current week's events (unassigned).
		$current_events = $this->event_repo->get_by_report( null );

		?>
		<div class="sybgo-widget">
			<div class="sybgo-widget-actions">
				<button type="button" class="button button-secondary sybgo-preview-btn">
					<?php esc_html_e( 'Preview This Week\'s Digest', 'sybgo' ); ?>
				</button>
				<button type="button" class="button button-secondary sybgo-preview-last-btn">
					<?php esc_html_e( 'View Previous Digest', 'sybgo' ); ?>
				</button>
			</div>

			<div class="sybgo-current-week">
				<h3><?php esc_html_e( 'This Week\'s Activity', 'sybgo' ); ?></h3>

				<button
					type="button"
					class="button button-secondary sybgo-widget-ai-btn"
					style="width:100%;margin-bottom:8px;"
					<?php if ( null === $this->ai_summarizer ) : ?>
						disabled
						title="<?php esc_attr_e( 'AI summaries require WordPress 7', 'sybgo' ); ?>"
					<?php endif; ?>
				>
					<?php esc_html_e( 'Get AI Summary', 'sybgo' ); ?>
				</button>

				<div id="sybgo-widget-ai-summary" class="sybgo-widget-ai-result" style="display:none;"></div>

				<?php $this->render_filter_buttons(); ?>

				<div class="sybgo-event-stats">
					<strong><?php echo esc_html( (string) count( $current_events ) ); ?></strong>
					<?php esc_html_e( 'events tracked', 'sybgo' ); ?>
				</div>

				<div class="sybgo-events-list" data-filter="all">
					<?php $this->render_events_list( $current_events ); ?>
				</div>
			</div>

			<?php $this->render_php_errors_section(); ?>

			<div id="sybgo-preview-modal" class="sybgo-modal" style="display:none;">
				<div class="sybgo-modal-content">
					<span class="sybgo-modal-close">&times;</span>
					<h2><?php esc_html_e( 'Digest Preview', 'sybgo' ); ?></h2>
					<div class="sybgo-modal-body"></div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render PHP errors summary section.
	 *
	 * Displays the number of distinct error signatures and total occurrences for
	 * the current report period, plus a top-5 list by occurrence count.
	 * Uses the same stat/list style as "This Week's Activity".
	 * Shows nothing when no errors have been recorded in the period.
	 *
	 * @return void
	 */
	private function render_php_errors_section(): void {
		// Query by report_id IS NULL — same pattern as singular events.
		// This ensures errors are always scoped to the current unassigned period,
		// regardless of calendar dates, and resets automatically after a freeze.
		$total_count    = $this->aggregated_repo->get_sum_for_report( 'php_error', null );
		$top_errors     = array_slice(
			$this->aggregated_repo->get_rows_for_report( 'php_error', null ),
			0,
			5
		);
		$distinct_count = count( $top_errors );

		if ( 0 === $distinct_count && 0.0 === $total_count ) {
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
		<hr class="sybgo-section-separator">
		<div class="sybgo-php-errors">
			<h3><?php esc_html_e( 'PHP Errors', 'sybgo' ); ?></h3>

			<div class="sybgo-event-stats">
				<strong><?php echo esc_html( (string) $distinct_count ); ?></strong>
				<?php esc_html_e( 'distinct signatures', 'sybgo' ); ?>
			</div>
			<div class="sybgo-event-stats">
				<strong><?php echo esc_html( (string) (int) $total_count ); ?></strong>
				<?php esc_html_e( 'total occurrences', 'sybgo' ); ?>
			</div>

			<?php if ( ! empty( $top_errors ) ) : ?>
				<ul class="sybgo-error-items">
					<?php foreach ( $top_errors as $row ) : ?>
						<?php
						$dims    = json_decode( $row['dimensions'], true );
						$meta    = json_decode( $row['meta'], true );
						$level   = $dims['level'] ?? 'warning';
						$emoji   = $level_emoji[ $level ] ?? '⚠️';
						$message = $meta['message'] ?? '';
						$file    = isset( $meta['file'] ) ? basename( $meta['file'] ) : '';
						$line    = $meta['line'] ?? '';
						$count   = (int) $row['total'];
						?>
						<li class="sybgo-error-item">
							<span class="sybgo-error-item-icon"><?php echo esc_html( $emoji ); ?></span>
							<span class="sybgo-error-item-desc" title="<?php echo esc_attr( $message ); ?>">
								<?php echo esc_html( $message ); ?>
								<?php if ( $file ) : ?>
									<span class="sybgo-error-item-location"><?php echo esc_html( $file . ':' . $line ); ?></span>
								<?php endif; ?>
							</span>
							<span class="sybgo-error-item-count"><?php echo esc_html( (string) $count ); ?>×</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render filter buttons.
	 *
	 * @return void
	 */
	private function render_filter_buttons(): void {
		$filters = array(
			'all'     => __( 'All', 'sybgo' ),
			'post'    => __( 'Posts', 'sybgo' ),
			'user'    => __( 'Users', 'sybgo' ),
			'update'  => __( 'Updates', 'sybgo' ),
			'comment' => __( 'Comments', 'sybgo' ),
		);

		?>
		<div class="sybgo-filters">
			<?php foreach ( $filters as $filter => $label ) : ?>
				<button
					type="button"
					class="sybgo-filter-btn <?php echo 'all' === $filter ? 'active' : ''; ?>"
					data-filter="<?php echo esc_attr( $filter ); ?>"
				>
					<?php echo esc_html( $label ); ?>
				</button>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render events list.
	 *
	 * @param array<int, array<string, mixed>> $events Events to display.
	 * @param int                              $limit Maximum events to show.
	 * @return void
	 */
	private function render_events_list( array $events, int $limit = 10 ): void {
		if ( empty( $events ) ) {
			?>
			<p class="sybgo-no-events">
				<?php esc_html_e( 'No events tracked yet this week.', 'sybgo' ); ?>
			</p>
			<?php
			return;
		}

		// Sort by timestamp descending.
		usort(
			$events,
			function ( $a, $b ) {
				return strtotime( $b['event_timestamp'] ) - strtotime( $a['event_timestamp'] );
			}
		);

		// Limit display.
		$display_events = array_slice( $events, 0, $limit );

		?>
		<ul class="sybgo-event-items">
			<?php foreach ( $display_events as $event ) : ?>
				<?php $this->render_event_item( $event ); ?>
			<?php endforeach; ?>
		</ul>

		<?php if ( count( $events ) > $limit ) : ?>
			<p class="sybgo-more-events">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of additional events */
						__( '+ %d more events', 'sybgo' ),
						count( $events ) - $limit
					)
				);
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render single event item.
	 *
	 * @param array<string, mixed> $event Event data.
	 * @return void
	 */
	private function render_event_item( array $event ): void {
		$event_data = json_decode( $event['event_data'], true );
		if ( ! $event_data ) {
			return;
		}

		$icon  = $this->event_registry->get_icon( $event['event_type'] );
		$title = $this->event_registry->get_short_title( $event['event_type'], $event_data );
		$time  = human_time_diff( strtotime( $event['event_timestamp'] ), time() );

		?>
		<li class="sybgo-event-item" data-type="<?php echo esc_attr( $event['event_type'] ); ?>">
			<span class="sybgo-event-icon"><?php echo esc_html( $icon ); ?></span>
			<span class="sybgo-event-title"><?php echo esc_html( $title ); ?></span>
			<span class="sybgo-event-time"><?php echo esc_html( $time . ' ago' ); ?></span>
		</li>
		<?php
	}

	/**
	 * AJAX handler for filtering events.
	 *
	 * @return void
	 */
	public function ajax_filter_events(): void {
		check_ajax_referer( 'sybgo_widget_nonce', 'nonce' );

		if ( ! current_user_can( 'read' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$filter = isset( $_POST['filter'] ) ? sanitize_text_field( wp_unslash( $_POST['filter'] ) ) : 'all';

		// Get current week's events.
		$events = $this->event_repo->get_by_report( null );

		// Filter by type if needed.
		if ( 'all' !== $filter ) {
			$events = array_filter(
				$events,
				function ( $event ) use ( $filter ) {
					// Special handling for 'update' filter.
					if ( 'update' === $filter ) {
						$update_types = array( 'core_updated', 'plugin_installed', 'plugin_activated', 'plugin_deactivated', 'plugin_updated', 'theme_installed', 'theme_updated', 'theme_switched' );
						return in_array( $event['event_type'], $update_types, true );
					}

					// Default: check if event type starts with filter.
					return strpos( $event['event_type'], $filter ) === 0;
				}
			);
		}

		ob_start();
		$this->render_events_list( array_values( $events ) );
		$html = ob_get_clean();

		wp_send_json_success(
			array(
				'html'  => $html,
				'count' => count( $events ),
			)
		);
	}

	/**
	 * AJAX handler for preview digest.
	 *
	 * @return void
	 */
	public function ajax_preview_digest(): void {
		try {
			check_ajax_referer( 'sybgo_widget_nonce', 'nonce' );

			if ( ! current_user_can( 'read' ) ) {
				wp_send_json_error( array( 'message' => 'Unauthorized' ) );
			}

			// Get current week's events.
			$events = $this->event_repo->get_by_report( null );

			// Try to get active report for trends, but don't fail if it doesn't exist.
			$active_report = $this->report_repo->get_active();

			// Generate preview summary (totals + trends).
			$live_summary = $this->report_generator->generate_live_summary(
				$events,
				$active_report ? (int) $active_report['id'] : 0
			);
			$totals       = $live_summary['totals'];
			$trends       = $live_summary['trends'];

			ob_start();
			$this->render_preview_content( $totals, $trends );
			$html = ob_get_clean();

			wp_send_json_success( array( 'html' => $html ) );
		} catch ( \Exception $e ) {
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
					'file'    => $e->getFile(),
					'line'    => $e->getLine(),
					'trace'   => $e->getTraceAsString(),
				)
			);
		}
	}

	/**
	 * AJAX handler for previewing the last frozen digest.
	 *
	 * Renders the summary of the most recently frozen/emailed report.
	 *
	 * @return void
	 */
	public function ajax_preview_last_digest(): void {
		check_ajax_referer( 'sybgo_widget_nonce', 'nonce' );

		if ( ! current_user_can( 'read' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$last_report = $this->report_repo->get_last_frozen();

		if ( ! $last_report ) {
			wp_send_json_error( array( 'message' => __( 'No previous digest available yet.', 'sybgo' ) ) );
		}

		$summary = ! empty( $last_report['summary_data'] ) ? json_decode( $last_report['summary_data'], true ) : null;

		if ( ! $summary ) {
			wp_send_json_error( array( 'message' => __( 'No summary data available for the previous digest.', 'sybgo' ) ) );
		}

		$totals = $summary['totals'];
		$trends = $summary['trends'] ?? array();

		ob_start();
		$this->render_preview_content( $totals, $trends );
		$html = ob_get_clean();

		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * Render preview content.
	 *
	 * @param array<string, int>                  $totals Event totals.
	 * @param array<string, array<string, mixed>> $trends Trend data.
	 * @return void
	 */
	private function render_preview_content( array $totals, array $trends ): void {
		?>
		<div class="sybgo-preview">
			<h3><?php esc_html_e( 'Activity Summary', 'sybgo' ); ?></h3>

			<div class="sybgo-preview-stats">
				<?php foreach ( $totals as $type => $count ) : ?>
					<?php
					$trend      = $trends[ $type ] ?? null;
					$arrow      = '';
					$trend_text = '';

					if ( $trend ) {
						if ( 'up' === $trend['direction'] ) {
							$arrow      = '↑';
							$trend_text = sprintf( '+%d%%', absint( $trend['change_percent'] ) );
						} elseif ( 'down' === $trend['direction'] ) {
							$arrow      = '↓';
							$trend_text = sprintf( '-%d%%', absint( $trend['change_percent'] ) );
						}
					}
					?>
					<div class="sybgo-stat-item">
						<div class="sybgo-stat-label"><?php echo esc_html( ucwords( str_replace( '_', ' ', $type ) ) ); ?></div>
						<div class="sybgo-stat-value">
							<?php echo esc_html( (string) $count ); ?>
							<?php if ( $arrow ) : ?>
								<span class="sybgo-trend <?php echo esc_attr( $trend['direction'] ); ?>">
									<?php echo esc_html( $arrow . ' ' . $trend_text ); ?>
								</span>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<p class="sybgo-preview-note">
				<?php esc_html_e( 'This is a preview of the digest that will be sent on Monday.', 'sybgo' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * AJAX handler for on-demand AI summary in the dashboard widget.
	 *
	 * Generates a live AI summary for the current week's events and returns it as JSON.
	 * The summary is NOT persisted — it is ephemeral for the current page view.
	 *
	 * @return void
	 */
	public function ajax_widget_ai_summary(): void {
		check_ajax_referer( 'sybgo_widget_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
			return; // @phpstan-ignore deadCode.unreachable
		}

		if ( null === $this->ai_summarizer ) {
			wp_send_json_error( array( 'message' => __( 'AI summaries require WordPress 7 or later.', 'sybgo' ) ) );
			return; // @phpstan-ignore deadCode.unreachable
		}

		$events        = $this->event_repo->get_by_report( null );
		$active_report = $this->report_repo->get_active();
		$live_summary  = $this->report_generator->generate_live_summary(
			$events,
			$active_report ? (int) $active_report['id'] : 0
		);

		$summary = $this->ai_summarizer->generate_summary( $events, $live_summary['totals'], $live_summary['trends'] );

		if ( null === $summary ) {
			wp_send_json_error( array( 'message' => __( 'The AI summary could not be generated. Please check your WordPress AI connector configuration.', 'sybgo' ) ) );
			return; // @phpstan-ignore deadCode.unreachable
		}

		wp_send_json_success( array( 'summary' => $summary ) );
	}
}
