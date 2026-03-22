<?php
/**
 * Reports Page Test
 *
 * @package Sybgo\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Sybgo\Tests\Unit\Admin;

use Sybgo\Admin\Reports_Page;
use Sybgo\Database\Aggregated_Event_Repository;
use Sybgo\Database\Event_Repository;
use Sybgo\Database\Report_Repository;
use Sybgo\Reports\Report_Manager;
use Sybgo\Reports\Report_Generator;
use Sybgo\Email\Email_Manager;
use Sybgo\Events\Event_Registry;
use Sybgo\AI\AI_Summarizer;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Test Reports_Page rendering methods.
 */
class ReportsPageTest extends TestCase {

	/**
	 * @var \Mockery\MockInterface&Report_Repository
	 */
	private $report_repo;

	/**
	 * @var \Mockery\MockInterface&Event_Repository
	 */
	private $event_repo;

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
	 * Reports_Page instance.
	 *
	 * @var Reports_Page
	 */
	private Reports_Page $reports_page;

	/**
	 * Set up test environment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->report_repo      = Mockery::mock( Report_Repository::class );
		$this->event_repo       = Mockery::mock( Event_Repository::class );
		$this->report_generator = Mockery::mock( Report_Generator::class );
		$this->ai_summarizer   = Mockery::mock( AI_Summarizer::class );
		$this->aggregated_repo = Mockery::mock( Aggregated_Event_Repository::class );

		$this->reports_page = new Reports_Page(
			$this->event_repo,
			$this->report_repo,
			Mockery::mock( Report_Manager::class ),
			$this->report_generator,
			Mockery::mock( Email_Manager::class ),
			Mockery::mock( Event_Registry::class ),
			$this->aggregated_repo,
			$this->ai_summarizer
		);

		// Mock WordPress output/escaping functions.
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias(
			function ( string $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'number_format_i18n' )->returnArg();
		Functions\when( 'esc_sql' )->returnArg();
		Functions\when( 'human_time_diff' )->justReturn( '3 days' );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'wp_nonce_url' )->returnArg();
		Functions\when( 'wp_nonce_field' )->justReturn();
		Functions\when( 'esc_attr_e' )->alias(
			function ( string $text ) {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'esc_js' )->returnArg();
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'absint' )->alias( function ( $v ) { return (int) abs( (int) $v ); } );
		Functions\when( 'wp_send_json_success' )->justReturn();
		Functions\when( 'wp_send_json_error' )->justReturn();
	}

	/**
	 * Tear down test environment.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * Call a private method via reflection.
	 *
	 * @param string  $method_name Method name.
	 * @param mixed[] $args        Arguments.
	 * @return mixed
	 */
	private function call_private( string $method_name, array $args = [] ) {
		$method = new \ReflectionMethod( Reports_Page::class, $method_name );
		$method->setAccessible( true );
		return $method->invokeArgs( $this->reports_page, $args );
	}

	/**
	 * Capture output from a private rendering method.
	 *
	 * @param string  $method_name Method name.
	 * @param mixed[] $args        Arguments.
	 * @return string
	 */
	private function capture( string $method_name, array $args = [] ): string {
		ob_start();
		$this->call_private( $method_name, $args );
		return (string) ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// render_report_details()
	// -------------------------------------------------------------------------

	/**
	 * Viewing the details of an active report (summary_data = null) must not throw.
	 */
	public function test_render_report_details_handles_null_summary_data(): void {
		$report = array(
			'id'           => 5,
			'status'       => 'active',
			'period_start' => '2026-03-01 00:00:00',
			'period_end'   => null,
			'summary_data' => null,
		);

		$this->report_repo->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( $report );
		// Active reports: events are fetched as unassigned (null), not by report ID.
		$this->event_repo->shouldReceive( 'get_by_report' )->with( null )->andReturn( array() );
		$this->report_generator->shouldReceive( 'generate_live_summary' )->with( array(), 5 )->andReturn(
			array(
				'totals'       => array(),
				'trends'       => array(),
				'highlights'   => array(),
				'total_events' => 0,
			)
		);
		$this->aggregated_repo->shouldReceive( 'get_rows_for_report' )->andReturn( array() );

		Functions\when( 'current_user_can' )->justReturn( true );

		$output = $this->capture( 'render_report_details', array( 5 ) );

		$this->assertStringContainsString( 'Back to Reports', $output );
		// Heading should show "Now" as the end date when period_end is null.
		$this->assertStringContainsString( 'Now', $output );
	}

	/**
	 * Viewing the active report details must show a live summary (stats cards + highlights).
	 */
	public function test_render_report_details_active_report_shows_live_summary(): void {
		$report = array(
			'id'           => 5,
			'status'       => 'active',
			'period_start' => '2026-03-01 00:00:00',
			'period_end'   => null,
			'summary_data' => null,
		);

		$live_summary = array(
			'totals'       => array( 'post_published' => 3 ),
			'trends'       => array(),
			'highlights'   => array( '3 new posts published' ),
			'total_events' => 3,
		);

		$this->report_repo->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( $report );
		$this->event_repo->shouldReceive( 'get_by_report' )->with( null )->andReturn( array() );
		$this->report_generator->shouldReceive( 'generate_live_summary' )->with( array(), 5 )->andReturn( $live_summary );
		$this->aggregated_repo->shouldReceive( 'get_rows_for_report' )->andReturn( array() );

		Functions\when( 'current_user_can' )->justReturn( true );

		$output = $this->capture( 'render_report_details', array( 5 ) );

		$this->assertStringContainsString( '<div class="sybgo-summary-cards">', $output );
		$this->assertStringContainsString( '3 new posts published', $output );
	}

	// -------------------------------------------------------------------------
	// render_reports_list()
	// -------------------------------------------------------------------------

	/**
	 * When an active report exists, the table must show "Now" as the end date.
	 */
	public function test_render_reports_list_with_active_report_shows_now_as_end_date(): void {
		global $wpdb;

		$active_report = array(
			'id'           => 7,
			'status'       => 'active',
			'period_start' => '2026-03-01 00:00:00',
			'period_end'   => null,
			'summary_data' => null,
		);

		$this->report_repo->shouldReceive( 'get_table_name' )->andReturn( 'wp_sybgo_reports' );
		$this->report_repo->shouldReceive( 'get_active' )->andReturn( $active_report );
		$this->event_repo->shouldReceive( 'get_by_report' )->with( null )->andReturn( array( 1, 2, 3 ) );

		// Mock $wpdb.
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SELECT * FROM wp_sybgo_reports ...' );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		$output = $this->capture( 'render_reports_list' );

		$this->assertStringContainsString( 'Now', $output );
		$this->assertStringContainsString( 'Freeze & Send Now', $output );
		$this->assertStringContainsString( 'View Details', $output );
		$this->assertStringNotContainsString( 'Resend Email', $output );
	}

	/**
	 * When no active report exists, "Now" must not appear in the table.
	 */
	public function test_render_reports_list_without_active_report_shows_no_active_row(): void {
		global $wpdb;

		$this->report_repo->shouldReceive( 'get_table_name' )->andReturn( 'wp_sybgo_reports' );
		$this->report_repo->shouldReceive( 'get_active' )->andReturn( null );

		$frozen_report = array(
			'id'           => 3,
			'status'       => 'frozen',
			'period_start' => '2026-02-01 00:00:00',
			'period_end'   => '2026-02-07 23:59:59',
			'summary_data' => '{"total_events":10,"totals":{},"trends":{},"highlights":[]}',
		);

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SELECT ...' );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array( $frozen_report ) );

		$output = $this->capture( 'render_reports_list' );

		$this->assertStringNotContainsString( 'Now', $output );
		$this->assertStringNotContainsString( 'Freeze & Send Now', $output );
		$this->assertStringContainsString( 'View Details', $output );
	}

	/**
	 * When there are no reports at all, the placeholder message must appear.
	 */
	public function test_render_reports_list_empty_shows_placeholder(): void {
		global $wpdb;

		$this->report_repo->shouldReceive( 'get_table_name' )->andReturn( 'wp_sybgo_reports' );
		$this->report_repo->shouldReceive( 'get_active' )->andReturn( null );

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SELECT ...' );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		$output = $this->capture( 'render_reports_list' );

		$this->assertStringContainsString( 'No reports found', $output );
	}

	// -------------------------------------------------------------------------
	// render_report_details() — AI summary block
	// -------------------------------------------------------------------------

	/**
	 * Frozen report with no AI summary should show "Generate AI Summary" button.
	 */
	public function test_render_report_details_frozen_without_ai_summary_shows_generate_button(): void {
		$summary = array(
			'totals'       => array( 'post_published' => 2 ),
			'trends'       => array(),
			'highlights'   => array( '2 new posts published' ),
			'top_authors'  => array(),
			'total_events' => 2,
			'ai_summary'   => null,
		);

		$report = array(
			'id'           => 10,
			'status'       => 'frozen',
			'period_start' => '2026-03-01 00:00:00',
			'period_end'   => '2026-03-07 23:59:59',
			'summary_data' => json_encode( $summary ),
		);

		$this->report_repo->shouldReceive( 'get_by_id' )->with( 10 )->andReturn( $report );
		$this->event_repo->shouldReceive( 'get_by_report' )->with( 10 )->andReturn( array() );
		$this->aggregated_repo->shouldReceive( 'get_rows_for_report' )->andReturn( array() );

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'esc_js' )->returnArg();

		$output = $this->capture( 'render_report_details', array( 10 ) );

		// Button label should be "Generate AI Summary" (no prior summary).
		$this->assertStringContainsString( 'Generate AI Summary', $output );
		// The summary box should be hidden (display:none) since ai_summary is null.
		$this->assertStringContainsString( 'display:none;', $output );
	}

	/**
	 * Frozen report with an existing AI summary should show "Regenerate AI Summary" button.
	 */
	public function test_render_report_details_frozen_with_ai_summary_shows_regenerate_button(): void {
		$summary = array(
			'totals'       => array( 'post_published' => 2 ),
			'trends'       => array(),
			'highlights'   => array( '2 new posts published' ),
			'top_authors'  => array(),
			'total_events' => 2,
			'ai_summary'   => 'You had a great week!',
		);

		$report = array(
			'id'           => 11,
			'status'       => 'frozen',
			'period_start' => '2026-03-01 00:00:00',
			'period_end'   => '2026-03-07 23:59:59',
			'summary_data' => json_encode( $summary ),
		);

		$this->report_repo->shouldReceive( 'get_by_id' )->with( 11 )->andReturn( $report );
		$this->event_repo->shouldReceive( 'get_by_report' )->with( 11 )->andReturn( array() );
		$this->aggregated_repo->shouldReceive( 'get_rows_for_report' )->andReturn( array() );

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'esc_js' )->returnArg();

		$output = $this->capture( 'render_report_details', array( 11 ) );

		$this->assertStringContainsString( 'Regenerate AI Summary', $output );
		$this->assertStringContainsString( 'You had a great week!', $output );
	}

	/**
	 * Active report detail view must show the AI summary section with a Generate button.
	 */
	public function test_render_report_details_active_report_shows_ai_section(): void {
		$report = array(
			'id'           => 12,
			'status'       => 'active',
			'period_start' => '2026-03-01 00:00:00',
			'period_end'   => null,
			'summary_data' => null,
		);

		$this->report_repo->shouldReceive( 'get_by_id' )->with( 12 )->andReturn( $report );
		$this->event_repo->shouldReceive( 'get_by_report' )->with( null )->andReturn( array() );
		$this->report_generator->shouldReceive( 'generate_live_summary' )->with( array(), 12 )->andReturn(
			array(
				'totals'       => array(),
				'trends'       => array(),
				'highlights'   => array(),
				'total_events' => 0,
			)
		);

		$this->aggregated_repo->shouldReceive( 'get_rows_for_report' )->andReturn( array() );

		Functions\when( 'current_user_can' )->justReturn( true );

		$output = $this->capture( 'render_report_details', array( 12 ) );

		// Section and button must be present for active reports.
		$this->assertStringContainsString( 'sybgo-ai-summary-section', $output );
		$this->assertStringContainsString( 'sybgo-generate-ai-btn', $output );

		// Button label must be "Generate" (no existing summary).
		$this->assertStringContainsString( 'Generate AI Summary', $output );
		$this->assertStringNotContainsString( 'Regenerate AI Summary', $output );

		// Summary box must be hidden since there is no AI summary yet.
		$this->assertStringContainsString( 'display:none;', $output );
	}

	// -------------------------------------------------------------------------
	// ajax_generate_ai_summary()
	// -------------------------------------------------------------------------

	/**
	 * Successful AJAX call should call set_ai_summary with the generated text.
	 *
	 * We verify the handler's logic rather than intercepting wp_send_json_success,
	 * since patchwork closures that throw cause memory exhaustion in this environment.
	 */
	public function test_ajax_generate_ai_summary_calls_set_ai_summary(): void {
		$report = array(
			'id'           => 20,
			'status'       => 'frozen',
			'period_start' => '2026-03-01 00:00:00',
			'period_end'   => '2026-03-07 23:59:59',
			'summary_data' => '{"totals":{},"trends":{},"highlights":[],"total_events":0,"ai_summary":null}',
		);

		$_POST['nonce']     = 'valid_nonce';
		$_POST['report_id'] = '20';

		$this->report_repo->shouldReceive( 'get_by_id' )->with( 20 )->andReturn( $report );
		$this->event_repo->shouldReceive( 'get_by_report' )->with( 20 )->andReturn( array() );
		$this->report_generator->shouldReceive( 'generate_live_summary' )
			->with( array(), 20 )
			->andReturn( array( 'totals' => array(), 'trends' => array() ) );
		$this->ai_summarizer->shouldReceive( 'generate_summary' )
			->with( array(), array(), array() )
			->andReturn( 'A great week!' );

		// The key assertion: set_ai_summary must be called with the generated text.
		$this->report_repo->shouldReceive( 'set_ai_summary' )
			->once()
			->with( 20, 'A great week!' )
			->andReturn( true );

		$this->reports_page->ajax_generate_ai_summary();

		// Mockery ->once() expectation is the assertion; add count so PHPUnit doesn't flag as risky.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * For an active report, AJAX must fetch events via get_by_report(null) and persist
	 * the full summary (stats + AI text) via save_summary_data().
	 */
	public function test_ajax_generate_ai_summary_active_report_uses_null_and_saves_full_summary(): void {
		$report = array(
			'id'           => 30,
			'status'       => 'active',
			'period_start' => '2026-03-15 00:00:00',
			'period_end'   => null,
			'summary_data' => null,
		);

		$live_summary = array(
			'totals'       => array( 'post_published' => 4 ),
			'trends'       => array(),
			'highlights'   => array( '4 new posts published' ),
			'top_authors'  => array(),
			'total_events' => 4,
			'ai_summary'   => null,
		);

		$_POST['nonce']     = 'valid_nonce';
		$_POST['report_id'] = '30';

		$this->report_repo->shouldReceive( 'get_by_id' )->with( 30 )->andReturn( $report );

		// Must use null (unassigned events), NOT 30.
		$this->event_repo->shouldReceive( 'get_by_report' )->with( null )->andReturn( array() );

		$this->report_generator->shouldReceive( 'generate_live_summary' )
			->with( array(), 30 )
			->andReturn( $live_summary );

		$this->ai_summarizer->shouldReceive( 'generate_summary' )
			->andReturn( 'Active week summary.' );

		// Must call save_summary_data (full object), not set_ai_summary.
		$this->report_repo->shouldReceive( 'save_summary_data' )
			->once()
			->with(
				30,
				Mockery::on(
					function ( $data ) {
						return isset( $data['ai_summary'] ) && 'Active week summary.' === $data['ai_summary']
							&& isset( $data['totals'] );
					}
				)
			)
			->andReturn( true );
		$this->report_repo->shouldNotReceive( 'set_ai_summary' );

		$this->reports_page->ajax_generate_ai_summary();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * AJAX handler should not call set_ai_summary when AI summarizer is null.
	 */
	public function test_ajax_generate_ai_summary_skips_set_when_no_summarizer(): void {
		$page_no_ai = new Reports_Page(
			$this->event_repo,
			$this->report_repo,
			Mockery::mock( Report_Manager::class ),
			$this->report_generator,
			Mockery::mock( Email_Manager::class ),
			Mockery::mock( Event_Registry::class ),
			$this->aggregated_repo,
			null
		);

		$_POST['nonce']     = 'valid_nonce';
		$_POST['report_id'] = '1';

		// set_ai_summary must never be called.
		$this->report_repo->shouldNotReceive( 'set_ai_summary' );

		$page_no_ai->ajax_generate_ai_summary();

		// Mockery expectation is the assertion; add count so PHPUnit doesn't flag as risky.
		$this->addToAssertionCount( 1 );
	}
}
