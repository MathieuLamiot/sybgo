<?php
/**
 * Dashboard Widget Unit Tests
 *
 * @package Sybgo\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Sybgo\Tests\Unit\Admin;

use Sybgo\Admin\Dashboard_Widget;
use Sybgo\Database\Event_Repository;
use Sybgo\Database\Report_Repository;
use Sybgo\Reports\Report_Generator;
use Sybgo\AI\AI_Summarizer;
use Sybgo\Database\Aggregated_Event_Repository;
use Sybgo\Events\Event_Registry;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Test Dashboard_Widget rendering and AJAX methods.
 */
class DashboardWidgetTest extends TestCase {

	/**
	 * @var \Mockery\MockInterface&Event_Repository
	 */
	private $event_repo;

	/**
	 * @var \Mockery\MockInterface&Report_Repository
	 */
	private $report_repo;

	/**
	 * @var \Mockery\MockInterface&Report_Generator
	 */
	private $report_generator;

	/**
	 * @var \Mockery\MockInterface&AI_Summarizer
	 */
	private $ai_summarizer;

	/**
	 * @var \Mockery\MockInterface&Aggregated_Event_Repository
	 */
	private $aggregated_repo;

	/**
	 * @var Dashboard_Widget
	 */
	private Dashboard_Widget $widget;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->event_repo       = Mockery::mock( Event_Repository::class );
		$this->report_repo      = Mockery::mock( Report_Repository::class );
		$this->report_generator = Mockery::mock( Report_Generator::class );
		$this->ai_summarizer    = Mockery::mock( AI_Summarizer::class );
		$this->aggregated_repo  = Mockery::mock( Aggregated_Event_Repository::class );

		$this->widget = new Dashboard_Widget(
			$this->event_repo,
			$this->report_repo,
			$this->report_generator,
			$this->ai_summarizer,
			Mockery::mock( Event_Registry::class ),
			$this->aggregated_repo
		);

		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias(
			function ( string $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'esc_attr_e' )->alias(
			function ( string $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'human_time_diff' )->justReturn( '1 hour' );
		Functions\when( 'admin_url' )->returnArg();
	}

	/**
	 * Tear down test environment.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * Capture output from a public rendering method.
	 */
	private function capture( string $method, array $args = [] ): string {
		$ref = new \ReflectionMethod( Dashboard_Widget::class, $method );
		$ref->setAccessible( true );
		ob_start();
		$ref->invokeArgs( $this->widget, $args );
		return (string) ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// render_widget() — "Get AI Summary" button
	// -------------------------------------------------------------------------

	/**
	 * Widget should render the "Get AI Summary" button when AI summarizer is available.
	 */
	public function test_render_widget_shows_get_ai_summary_button_when_summarizer_available(): void {
		$this->report_repo->shouldReceive( 'get_active' )->andReturn( null );
		$this->report_repo->shouldReceive( 'get_last_frozen' )->andReturn( null );
		$this->event_repo->shouldReceive( 'get_by_report' )->with( null )->andReturn( array() );
		$this->aggregated_repo->shouldReceive( 'get_sum_for_report' )->andReturn( 0 );
		$this->aggregated_repo->shouldReceive( 'get_rows_for_report' )->andReturn( array() );

		$output = $this->capture( 'render_widget' );

		$this->assertStringContainsString( 'sybgo-widget-ai-btn', $output );
		$this->assertStringContainsString( 'Get AI Summary', $output );
		// Button should NOT be disabled when summarizer is available.
		$this->assertStringNotContainsString( 'Requires WordPress 7', $output );
	}

	/**
	 * Widget should render a disabled "Get AI Summary" button when AI summarizer is null.
	 */
	public function test_render_widget_disables_get_ai_summary_button_when_no_summarizer(): void {
		$event_repo  = Mockery::mock( Event_Repository::class );
		$report_repo = Mockery::mock( Report_Repository::class );

		$aggregated_repo = Mockery::mock( Aggregated_Event_Repository::class );

		$widget_no_ai = new Dashboard_Widget(
			$event_repo,
			$report_repo,
			$this->report_generator,
			null,
			Mockery::mock( Event_Registry::class ),
			$aggregated_repo
		);

		$report_repo->shouldReceive( 'get_active' )->andReturn( null );
		$report_repo->shouldReceive( 'get_last_frozen' )->andReturn( null );
		$event_repo->shouldReceive( 'get_by_report' )->with( null )->andReturn( array() );
		$aggregated_repo->shouldReceive( 'get_sum_for_report' )->andReturn( 0 );
		$aggregated_repo->shouldReceive( 'get_rows_for_report' )->andReturn( array() );

		$ref = new \ReflectionMethod( Dashboard_Widget::class, 'render_widget' );
		$ref->setAccessible( true );
		ob_start();
		$ref->invokeArgs( $widget_no_ai, [] );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'sybgo-widget-ai-btn', $output );
		$this->assertStringContainsString( 'disabled', $output );
		$this->assertStringContainsString( 'AI summaries require WordPress 7', $output );
	}

	/**
	 * "Get AI Summary" button must appear before the filter buttons.
	 */
	public function test_render_widget_ai_button_appears_before_filter_buttons(): void {
		$this->report_repo->shouldReceive( 'get_active' )->andReturn( null );
		$this->report_repo->shouldReceive( 'get_last_frozen' )->andReturn( null );
		$this->event_repo->shouldReceive( 'get_by_report' )->with( null )->andReturn( array() );
		$this->aggregated_repo->shouldReceive( 'get_sum_for_report' )->andReturn( 0 );
		$this->aggregated_repo->shouldReceive( 'get_rows_for_report' )->andReturn( array() );

		$output = $this->capture( 'render_widget' );

		$ai_btn_pos    = strpos( $output, 'sybgo-widget-ai-btn' );
		$filter_pos    = strpos( $output, 'sybgo-filters' );

		$this->assertNotFalse( $ai_btn_pos, 'AI button not found in widget output' );
		$this->assertNotFalse( $filter_pos, 'Filter buttons not found in widget output' );
		$this->assertLessThan( $filter_pos, $ai_btn_pos, 'AI button should appear before filter buttons' );
	}

	// -------------------------------------------------------------------------
	// ajax_preview_digest() — no longer calls AI
	// -------------------------------------------------------------------------

	// -------------------------------------------------------------------------
	// ajax_widget_ai_summary() — persistence
	// -------------------------------------------------------------------------

	/**
	 * Widget AJAX must persist summary via save_summary_data() when active report exists.
	 */
	public function test_ajax_widget_ai_summary_persists_summary_when_active_report_exists(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_send_json_success' )->justReturn();
		Functions\when( 'wp_send_json_error' )->justReturn();
		Functions\when( '__' )->returnArg();

		$active_report = array( 'id' => '5', 'status' => 'active' );
		$live_summary  = array(
			'totals'       => array( 'post_published' => 2 ),
			'trends'       => array(),
			'highlights'   => array(),
			'top_authors'  => array(),
			'total_events' => 2,
			'ai_summary'   => null,
		);

		$this->event_repo->shouldReceive( 'get_by_report' )->with( null )->andReturn( array() );
		$this->report_repo->shouldReceive( 'get_active' )->andReturn( $active_report );
		$this->report_generator->shouldReceive( 'generate_live_summary' )
			->with( array(), 5 )
			->andReturn( $live_summary );
		$this->ai_summarizer->shouldReceive( 'generate_summary' )->andReturn( 'Widget summary.' );

		// Must persist the full object.
		$this->report_repo->shouldReceive( 'save_summary_data' )
			->once()
			->with(
				5,
				Mockery::on(
					function ( $data ) {
						return isset( $data['ai_summary'] ) && 'Widget summary.' === $data['ai_summary']
							&& isset( $data['totals'] );
					}
				)
			)
			->andReturn( true );

		$this->widget->ajax_widget_ai_summary();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Widget AJAX must NOT call save_summary_data() when no active report exists.
	 */
	public function test_ajax_widget_ai_summary_does_not_persist_when_no_active_report(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_send_json_success' )->justReturn();
		Functions\when( 'wp_send_json_error' )->justReturn();
		Functions\when( '__' )->returnArg();

		$live_summary = array(
			'totals'       => array(),
			'trends'       => array(),
			'highlights'   => array(),
			'top_authors'  => array(),
			'total_events' => 0,
			'ai_summary'   => null,
		);

		$this->event_repo->shouldReceive( 'get_by_report' )->with( null )->andReturn( array() );
		$this->report_repo->shouldReceive( 'get_active' )->andReturn( null );
		$this->report_generator->shouldReceive( 'generate_live_summary' )
			->with( array(), 0 )
			->andReturn( $live_summary );
		$this->ai_summarizer->shouldReceive( 'generate_summary' )->andReturn( 'No active report summary.' );

		// Must NOT persist.
		$this->report_repo->shouldNotReceive( 'save_summary_data' );

		$this->widget->ajax_widget_ai_summary();

		$this->addToAssertionCount( 1 );
	}

	// -------------------------------------------------------------------------
	// render_widget() — report detail links
	// -------------------------------------------------------------------------

	/**
	 * Widget must render links to active and last frozen report detail pages when both exist.
	 */
	public function test_render_widget_shows_links_when_reports_exist(): void {
		$this->report_repo->shouldReceive( 'get_active' )->andReturn( array( 'id' => '3', 'status' => 'active' ) );
		$this->report_repo->shouldReceive( 'get_last_frozen' )->andReturn( array( 'id' => '1', 'status' => 'frozen' ) );
		$this->event_repo->shouldReceive( 'get_by_report' )->with( null )->andReturn( array() );
		$this->aggregated_repo->shouldReceive( 'get_sum_for_report' )->andReturn( 0 );
		$this->aggregated_repo->shouldReceive( 'get_rows_for_report' )->andReturn( array() );

		$output = $this->capture( 'render_widget' );

		$this->assertStringContainsString( 'report_id=3', $output );
		$this->assertStringContainsString( 'report_id=1', $output );
		$this->assertStringContainsString( "View This Week's Details", $output );
		$this->assertStringContainsString( "View Last Week's Details", $output );
	}

	/**
	 * Widget must not render report detail links when no reports exist.
	 */
	public function test_render_widget_hides_links_when_no_reports(): void {
		$this->report_repo->shouldReceive( 'get_active' )->andReturn( null );
		$this->report_repo->shouldReceive( 'get_last_frozen' )->andReturn( null );
		$this->event_repo->shouldReceive( 'get_by_report' )->with( null )->andReturn( array() );
		$this->aggregated_repo->shouldReceive( 'get_sum_for_report' )->andReturn( 0 );
		$this->aggregated_repo->shouldReceive( 'get_rows_for_report' )->andReturn( array() );

		$output = $this->capture( 'render_widget' );

		$this->assertStringNotContainsString( 'sybgo-reports', $output );
		$this->assertStringNotContainsString( 'report_id=', $output );
	}
}
