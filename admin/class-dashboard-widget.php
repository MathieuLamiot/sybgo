<?php
/**
 * Dashboard Widget class file.
 *
 * This file defines the Dashboard Widget for displaying Sybgo activity.
 *
 * @package Rocket\Sybgo\Admin
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Rocket\Sybgo\Admin;

use Rocket\Sybgo\Database\Event_Repository;
use Rocket\Sybgo\Database\Report_Repository;
use Rocket\Sybgo\Reports\Report_Generator;

/**
 * Dashboard Widget class.
 *
 * Displays weekly activity digest in WordPress dashboard.
 *
 * @package Rocket\Sybgo\Admin
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
	 * Constructor.
	 *
	 * @param Event_Repository  $event_repo Event repository.
	 * @param Report_Repository $report_repo Report repository.
	 * @param Report_Generator  $report_generator Report generator.
	 */
	public function __construct(
		Event_Repository $event_repo,
		Report_Repository $report_repo,
		Report_Generator $report_generator
	) {
		$this->event_repo       = $event_repo;
		$this->report_repo      = $report_repo;
		$this->report_generator = $report_generator;
	}

	/**
	 * Initialize the dashboard widget.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_sybgo_filter_events', array( $this, 'ajax_filter_events' ) );
		add_action( 'wp_ajax_sybgo_preview_digest', array( $this, 'ajax_preview_digest' ) );
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
			array( $this, 'render_widget' ),
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

		wp_enqueue_style(
			'sybgo-dashboard-widget',
			plugins_url( 'assets/admin.css', dirname( __FILE__ ) ),
			array(),
			'1.0.0'
		);

		wp_enqueue_script(
			'sybgo-dashboard-widget',
			plugins_url( 'assets/admin.js', dirname( __FILE__ ) ),
			array( 'jquery' ),
			'1.0.0',
			true
		);

		wp_localize_script(
			'sybgo-dashboard-widget',
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
		// Get last frozen report.
		$last_report = $this->report_repo->get_last_frozen();

		// Get current week's events (unassigned).
		$current_events = $this->event_repo->get_by_report( null );

		// Get active report for trend comparison.
		$active_report = $this->report_repo->get_active();

		?>
		<div class="sybgo-widget">
			<?php if ( $last_report ) : ?>
				<?php $this->render_last_report_section( $last_report ); ?>
			<?php endif; ?>

			<div class="sybgo-current-week">
				<h3><?php esc_html_e( 'This Week\'s Activity', 'sybgo' ); ?></h3>

				<?php $this->render_filter_buttons(); ?>

				<div class="sybgo-event-stats">
					<strong><?php echo esc_html( count( $current_events ) ); ?></strong>
					<?php esc_html_e( 'events tracked', 'sybgo' ); ?>
				</div>

				<div class="sybgo-events-list" data-filter="all">
					<?php $this->render_events_list( $current_events ); ?>
				</div>

				<div class="sybgo-widget-actions">
					<button type="button" class="button button-secondary sybgo-preview-btn">
						<?php esc_html_e( 'Preview This Week\'s Digest', 'sybgo' ); ?>
					</button>
				</div>
			</div>

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
	 * Render last report section.
	 *
	 * @param array $report Last frozen report.
	 * @return void
	 */
	private function render_last_report_section( array $report ): void {
		$summary = json_decode( $report['summary_data'], true );
		if ( ! $summary ) {
			return;
		}

		?>
		<div class="sybgo-last-report">
			<h3><?php esc_html_e( 'Last Week\'s Summary', 'sybgo' ); ?></h3>

			<div class="sybgo-period">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %1$s: start date, %2$s: end date */
						__( '%1$s to %2$s', 'sybgo' ),
						gmdate( 'M j', strtotime( $report['period_start'] ) ),
						gmdate( 'M j, Y', strtotime( $report['period_end'] ) )
					)
				);
				?>
			</div>

			<?php if ( ! empty( $summary['highlights'] ) ) : ?>
				<ul class="sybgo-highlights">
					<?php foreach ( array_slice( $summary['highlights'], 0, 3 ) as $highlight ) : ?>
						<li><?php echo esc_html( $highlight ); ?></li>
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
			'all'      => __( 'All', 'sybgo' ),
			'post'     => __( 'Posts', 'sybgo' ),
			'user'     => __( 'Users', 'sybgo' ),
			'update'   => __( 'Updates', 'sybgo' ),
			'comment'  => __( 'Comments', 'sybgo' ),
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
	 * @param array $events Events to display.
	 * @param int   $limit Maximum events to show.
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
		usort( $events, function( $a, $b ) {
			return strtotime( $b['event_timestamp'] ) - strtotime( $a['event_timestamp'] );
		} );

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
	 * @param array $event Event data.
	 * @return void
	 */
	private function render_event_item( array $event ): void {
		$event_data = json_decode( $event['event_data'], true );
		if ( ! $event_data ) {
			return;
		}

		$icon  = $this->get_event_icon( $event['event_type'] );
		$title = $this->get_event_title( $event['event_type'], $event_data );
		$time  = human_time_diff( strtotime( $event['event_timestamp'] ), current_time( 'timestamp' ) );

		?>
		<li class="sybgo-event-item" data-type="<?php echo esc_attr( $event['event_type'] ); ?>">
			<span class="sybgo-event-icon"><?php echo esc_html( $icon ); ?></span>
			<span class="sybgo-event-title"><?php echo esc_html( $title ); ?></span>
			<span class="sybgo-event-time"><?php echo esc_html( $time . ' ago' ); ?></span>
		</li>
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
		$action = $event_data['action'] ?? '';
		$object = $event_data['object'] ?? array();

		switch ( $event_type ) {
			case 'post_published':
				return sprintf( 'New %s: %s', $object['type'] ?? 'post', $object['title'] ?? 'Untitled' );

			case 'post_edited':
				$magnitude = $event_data['metadata']['edit_magnitude'] ?? 0;
				return sprintf( '%s edited (%d%% changed)', $object['title'] ?? 'Post', $magnitude );

			case 'post_deleted':
				return sprintf( '%s deleted', $object['title'] ?? 'Post' );

			case 'user_registered':
				return sprintf( 'New user: %s', $object['username'] ?? 'Unknown' );

			case 'user_role_changed':
				return sprintf( 'User %s role changed to %s', $object['username'] ?? 'Unknown', $event_data['metadata']['role'] ?? 'subscriber' );

			case 'core_updated':
				return sprintf( 'WordPress updated to %s', $event_data['metadata']['new_version'] ?? 'latest' );

			case 'plugin_installed':
				return sprintf( 'Plugin installed: %s', $object['name'] ?? 'Unknown' );

			case 'plugin_activated':
				return sprintf( 'Plugin activated: %s', $object['name'] ?? 'Unknown' );

			case 'plugin_deactivated':
				return sprintf( 'Plugin deactivated: %s', $object['name'] ?? 'Unknown' );

			case 'plugin_updated':
				return sprintf( 'Plugin updated: %s', $object['name'] ?? 'Unknown' );

			case 'theme_installed':
				return sprintf( 'Theme installed: %s', $object['name'] ?? 'Unknown' );

			case 'theme_updated':
				return sprintf( 'Theme updated: %s', $object['name'] ?? 'Unknown' );

			case 'theme_switched':
				return sprintf( 'Theme switched to: %s', $object['name'] ?? 'Unknown' );

			case 'comment_new':
				return sprintf( 'New comment on: %s', $event_data['metadata']['post_title'] ?? 'Unknown post' );

			case 'comment_approved':
				return 'Comment approved';

			default:
				return ucwords( str_replace( '_', ' ', $event_type ) );
		}
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
				function( $event ) use ( $filter ) {
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

			// Generate preview summary.
			$totals = $this->count_events_by_type( $events );

			// Try to get active report for trends, but don't fail if it doesn't exist.
			$active_report = $this->report_repo->get_active();
			$trends        = array();

			if ( $active_report ) {
				$trends = $this->report_generator->get_trend_comparison( (int) $active_report['id'], $totals );
			}

			ob_start();
			$this->render_preview_content( $totals, $trends, $events );
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
	 * Render preview content.
	 *
	 * @param array $totals Event totals.
	 * @param array $trends Trend data.
	 * @param array $events All events.
	 * @return void
	 */
	private function render_preview_content( array $totals, array $trends, array $events ): void {
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
							<?php echo esc_html( $count ); ?>
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
	 * Count events by type.
	 *
	 * @param array $events Events to count.
	 * @return array Counts by type.
	 */
	private function count_events_by_type( array $events ): array {
		$counts = array();

		foreach ( $events as $event ) {
			$type = $event['event_type'];

			if ( ! isset( $counts[ $type ] ) ) {
				$counts[ $type ] = 0;
			}

			++$counts[ $type ];
		}

		return $counts;
	}
}
