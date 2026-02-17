<?php
/**
 * Email Template class file.
 *
 * This file defines the Email Template for generating HTML email content.
 *
 * @package Rocket\Sybgo\Email
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Rocket\Sybgo\Email;

use Rocket\Sybgo\Events\Event_Registry;

/**
 * Email Template class.
 *
 * Generates HTML email content for weekly digests.
 *
 * @package Rocket\Sybgo\Email
 * @since   1.0.0
 */
class Email_Template {
	/**
	 * Event registry instance.
	 *
	 * @var Event_Registry
	 */
	private Event_Registry $event_registry;

	/**
	 * Constructor.
	 *
	 * @param Event_Registry $event_registry Event registry.
	 */
	public function __construct( Event_Registry $event_registry ) {
		$this->event_registry = $event_registry;
	}
	/**
	 * Get email subject.
	 *
	 * @param array $report Report data.
	 * @return string Email subject.
	 */
	public function get_subject( array $report ): string {
		$period_start = gmdate( 'M j', strtotime( $report['period_start'] ) );
		$period_end   = gmdate( 'M j, Y', strtotime( $report['period_end'] ) );

		$subject = sprintf(
			/* translators: %1$s: start date, %2$s: end date, %3$s: site name */
			__( 'Your Weekly Activity Digest: %1$s - %2$s | %3$s', 'sybgo' ),
			$period_start,
			$period_end,
			get_bloginfo( 'name' )
		);

		/**
		 * Filter email subject.
		 *
		 * @param string $subject Email subject.
		 * @param array  $report Report data.
		 */
		return apply_filters( 'sybgo_email_subject', $subject, $report );
	}

	/**
	 * Get email body HTML.
	 *
	 * @param array $report Report data.
	 * @return string Email body HTML.
	 */
	public function get_body( array $report ): string {
		$summary = json_decode( $report['summary_data'], true );

		ob_start();
		?>
		<!DOCTYPE html>
		<html lang="en">
		<head>
			<meta charset="UTF-8">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<title><?php echo esc_html( $this->get_subject( $report ) ); ?></title>
			<style>
				body {
					font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
					line-height: 1.6;
					color: #333;
					margin: 0;
					padding: 0;
					background-color: #f5f5f5;
				}
				.container {
					max-width: 600px;
					margin: 20px auto;
					background-color: #ffffff;
					border-radius: 8px;
					overflow: hidden;
					box-shadow: 0 2px 8px rgba(0,0,0,0.1);
				}
				.header {
					background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
					color: #ffffff;
					padding: 30px 20px;
					text-align: center;
				}
				.header h1 {
					margin: 0 0 10px 0;
					font-size: 28px;
					font-weight: 600;
				}
				.header .period {
					font-size: 14px;
					opacity: 0.9;
				}
				.content {
					padding: 30px 20px;
				}
				.section {
					margin-bottom: 30px;
				}
				.section h2 {
					font-size: 20px;
					color: #2c3e50;
					margin: 0 0 15px 0;
					border-bottom: 2px solid #667eea;
					padding-bottom: 8px;
				}
				.stats-grid {
					display: grid;
					grid-template-columns: repeat(2, 1fr);
					gap: 15px;
					margin-bottom: 20px;
				}
				.stat-card {
					background: #f8f9fa;
					padding: 20px;
					border-radius: 6px;
					text-align: center;
					border-left: 4px solid #667eea;
				}
				.stat-label {
					font-size: 12px;
					color: #6c757d;
					text-transform: uppercase;
					letter-spacing: 0.5px;
					margin-bottom: 5px;
				}
				.stat-value {
					font-size: 32px;
					font-weight: 700;
					color: #2c3e50;
					line-height: 1;
				}
				.trend {
					font-size: 14px;
					margin-top: 5px;
				}
				.trend.up {
					color: #28a745;
				}
				.trend.down {
					color: #dc3545;
				}
				.highlights {
					list-style: none;
					padding: 0;
					margin: 0;
				}
				.highlights li {
					padding: 12px 15px;
					margin-bottom: 8px;
					background: #e7f3ff;
					border-left: 4px solid #2196f3;
					border-radius: 4px;
					font-size: 14px;
				}
				.top-authors {
					list-style: none;
					padding: 0;
					margin: 0;
				}
				.top-authors li {
					padding: 10px 0;
					border-bottom: 1px solid #e9ecef;
					display: flex;
					justify-content: space-between;
					align-items: center;
				}
				.top-authors li:last-child {
					border-bottom: none;
				}
				.author-name {
					font-weight: 500;
					color: #2c3e50;
				}
				.author-count {
					background: #667eea;
					color: white;
					padding: 4px 12px;
					border-radius: 12px;
					font-size: 12px;
					font-weight: 600;
				}
				.footer {
					background: #f8f9fa;
					padding: 20px;
					text-align: center;
					font-size: 13px;
					color: #6c757d;
				}
				.footer a {
					color: #667eea;
					text-decoration: none;
				}
				.footer a:hover {
					text-decoration: underline;
				}
				.button {
					display: inline-block;
					padding: 12px 24px;
					background: #667eea;
					color: #ffffff !important;
					text-decoration: none;
					border-radius: 6px;
					font-weight: 600;
					margin-top: 15px;
				}
				.empty-state {
					text-align: center;
					padding: 40px 20px;
					color: #6c757d;
				}
				.empty-state svg {
					width: 64px;
					height: 64px;
					margin-bottom: 15px;
					opacity: 0.5;
				}
				@media only screen and (max-width: 600px) {
					.stats-grid {
						grid-template-columns: 1fr;
					}
				}
			</style>
		</head>
		<body>
			<div class="container">
				<!-- Header -->
				<div class="header">
					<h1><?php esc_html_e( 'Your Weekly Activity Digest', 'sybgo' ); ?></h1>
					<div class="period">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %1$s: start date, %2$s: end date */
								__( '%1$s to %2$s', 'sybgo' ),
								gmdate( 'F j, Y', strtotime( $report['period_start'] ) ),
								gmdate( 'F j, Y', strtotime( $report['period_end'] ) )
							)
						);
						?>
					</div>
				</div>

				<!-- Content -->
				<div class="content">
					<?php if ( empty( $summary['total_events'] ) ) : ?>
						<?php $this->render_empty_state(); ?>
					<?php else : ?>
						<?php $this->render_ai_summary( $summary ); ?>
						<?php $this->render_statistics( $summary ); ?>
						<?php $this->render_highlights( $summary ); ?>
						<?php $this->render_top_authors( $summary ); ?>
						<?php
						/**
						 * Action to add custom sections to email.
						 *
						 * @param array $report Report data.
						 * @param array $summary Summary data.
						 */
						do_action( 'sybgo_email_custom_section', $report, $summary );
						?>
					<?php endif; ?>

					<!-- Call to Action -->
					<div style="text-align: center; margin-top: 30px;">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=sybgo-reports' ) ); ?>" class="button">
							<?php esc_html_e( 'View Full Report', 'sybgo' ); ?>
						</a>
					</div>
				</div>

				<!-- Footer -->
				<div class="footer">
					<p>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: %s: site name */
								__( 'This is an automated digest from %s.', 'sybgo' ),
								'<strong>' . get_bloginfo( 'name' ) . '</strong>'
							)
						);
						?>
					</p>
					<p>
						<a href="<?php echo esc_url( admin_url( 'options-general.php?page=sybgo-settings' ) ); ?>">
							<?php esc_html_e( 'Email Settings', 'sybgo' ); ?>
						</a>
					</p>
					<p style="margin-top: 15px; font-size: 11px;">
						<?php esc_html_e( 'Powered by Sybgo - Activity Digest Plugin', 'sybgo' ); ?>
					</p>
				</div>
			</div>
		</body>
		</html>
		<?php
		$html = ob_get_clean();

		/**
		 * Filter email body HTML.
		 *
		 * @param string $html Email body HTML.
		 * @param array  $report Report data.
		 */
		return apply_filters( 'sybgo_email_body', $html, $report );
	}

	/**
	 * Render empty state.
	 *
	 * @return void
	 */
	private function render_empty_state(): void {
		?>
		<div class="empty-state">
			<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
				<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
			</svg>
			<h3><?php esc_html_e( 'All Quiet This Week', 'sybgo' ); ?></h3>
			<p><?php esc_html_e( 'No significant activity was tracked during this period.', 'sybgo' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render statistics section.
	 *
	 * @param array $summary Summary data.
	 * @return void
	 */
	private function render_statistics( array $summary ): void {
		if ( empty( $summary['totals'] ) ) {
			return;
		}

		?>
		<div class="section">
			<h2><?php esc_html_e( 'Activity Summary', 'sybgo' ); ?></h2>
			<div class="stats-grid">
				<?php foreach ( $summary['totals'] as $type => $count ) : ?>
					<?php
					$trend      = $summary['trends'][ $type ] ?? null;
					$type_label = $this->event_registry->get_stat_label( $type );
					?>
					<div class="stat-card">
						<div class="stat-label"><?php echo esc_html( $type_label ); ?></div>
						<div class="stat-value"><?php echo esc_html( number_format_i18n( $count ) ); ?></div>
						<?php if ( $trend && 'same' !== $trend['direction'] ) : ?>
							<div class="trend <?php echo esc_attr( $trend['direction'] ); ?>">
								<?php
								$arrow = 'up' === $trend['direction'] ? '↑' : '↓';
								echo esc_html( $arrow . ' ' . absint( $trend['change_percent'] ) . '%' );
								?>
							</div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render highlights section.
	 *
	 * @param array $summary Summary data.
	 * @return void
	 */
	private function render_highlights( array $summary ): void {
		if ( empty( $summary['highlights'] ) ) {
			return;
		}

		?>
		<div class="section">
			<h2><?php esc_html_e( 'Key Highlights', 'sybgo' ); ?></h2>
			<ul class="highlights">
				<?php foreach ( $summary['highlights'] as $highlight ) : ?>
					<li><?php echo esc_html( $highlight ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Render top authors section.
	 *
	 * @param array $summary Summary data.
	 * @return void
	 */
	private function render_top_authors( array $summary ): void {
		if ( empty( $summary['top_authors'] ) ) {
			return;
		}

		?>
		<div class="section">
			<h2><?php esc_html_e( 'Most Active Contributors', 'sybgo' ); ?></h2>
			<ul class="top-authors">
				<?php foreach ( array_slice( $summary['top_authors'], 0, 5 ) as $author ) : ?>
					<li>
						<span class="author-name"><?php echo esc_html( $author['name'] ); ?></span>
						<span class="author-count">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of posts */
									_n( '%d post', '%d posts', $author['count'], 'sybgo' ),
									$author['count']
								)
							);
							?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Render AI summary section.
	 *
	 * @param array $summary Summary data.
	 * @return void
	 */
	private function render_ai_summary( array $summary ): void {
		if ( empty( $summary['ai_summary'] ) ) {
			return;
		}

		?>
		<div class="section" style="background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%); padding: 25px; border-radius: 8px; margin-bottom: 30px; border-left: 4px solid #667eea;">
			<h2 style="margin-top: 0; color: #667eea; display: flex; align-items: center; gap: 8px;">
				<span style="font-size: 24px;">💬</span>
				<?php esc_html_e( 'Week in Review', 'sybgo' ); ?>
			</h2>
			<p style="margin: 0; font-size: 15px; line-height: 1.8; color: #2c3e50;">
				<?php echo esc_html( $summary['ai_summary'] ); ?>
			</p>
		</div>
		<?php
	}
}
