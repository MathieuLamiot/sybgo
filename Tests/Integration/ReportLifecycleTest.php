<?php
/**
 * Report Lifecycle Integration Tests
 *
 * Tests the complete flow from event tracking to report generation.
 *
 * @package Rocket\Sybgo\Tests\Integration
 */

namespace Rocket\Sybgo\Tests\Integration;

use WP_UnitTestCase;

/**
 * Report Lifecycle Test Case
 *
 * @group integration
 * @group report
 */
class ReportLifecycleTest extends WP_UnitTestCase {

	/**
	 * Factory instance.
	 *
	 * @var object
	 */
	private $factory_instance;

	/**
	 * Event repository.
	 *
	 * @var object
	 */
	private $event_repo;

	/**
	 * Report repository.
	 *
	 * @var object
	 */
	private $report_repo;

	/**
	 * Report manager.
	 *
	 * @var object
	 */
	private $report_manager;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load plugin files.
		require_once dirname( dirname( __DIR__ ) ) . '/class-factory.php';
		require_once dirname( dirname( __DIR__ ) ) . '/database/class-databasemanager.php';
		require_once dirname( dirname( __DIR__ ) ) . '/database/class-event-repository.php';
		require_once dirname( dirname( __DIR__ ) ) . '/database/class-report-repository.php';
		require_once dirname( dirname( __DIR__ ) ) . '/reports/class-report-generator.php';
		require_once dirname( dirname( __DIR__ ) ) . '/reports/class-report-manager.php';

		// Define constants.
		if ( ! defined( 'SYBGO_PLUGIN_DIR' ) ) {
			define( 'SYBGO_PLUGIN_DIR', dirname( dirname( __DIR__ ) ) . '/' );
		}

		// Create factory.
		$this->factory_instance = new \Rocket\Sybgo\Factory();

		// Create database tables.
		$db_manager = $this->factory_instance->create_database_manager();

		// Get repositories.
		$this->event_repo  = $this->factory_instance->create_event_repository();
		$this->report_repo = $this->factory_instance->create_report_repository();

		// Get report manager.
		$this->report_manager = $this->factory_instance->create_report_manager();

		// Clean up any events/reports from plugin initialization or previous tests.
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}sybgo_events" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}sybgo_reports" );
	}

	/**
	 * Test complete report lifecycle.
	 */
	public function test_complete_report_lifecycle() {
		// Step 1: Create active report.
		$active_report_id = $this->report_manager->create_new_active_report();
		$this->assertGreaterThan( 0, $active_report_id );

		// Verify active report exists.
		$active = $this->report_repo->get_active();
		$this->assertNotNull( $active );
		$this->assertEquals( 'active', $active['status'] );

		// Step 2: Create some events (unassigned to report).
		$event1_id = $this->event_repo->create( array(
			'event_type' => 'post_published',
			'event_data' => array(
				'action' => 'published',
				'object' => array( 'type' => 'post', 'id' => 123, 'title' => 'Test Post' ),
			),
		) );

		$event2_id = $this->event_repo->create( array(
			'event_type' => 'user_registered',
			'event_data' => array(
				'action' => 'registered',
				'object' => array( 'type' => 'user', 'id' => 456, 'username' => 'testuser' ),
			),
		) );

		$this->assertGreaterThan( 0, $event1_id );
		$this->assertGreaterThan( 0, $event2_id );

		// Step 3: Freeze the report.
		$frozen_id = $this->report_manager->freeze_current_report();
		$this->assertEquals( $active_report_id, $frozen_id );

		// Step 4: Verify report is frozen.
		$frozen_report = $this->report_repo->get_by_id( $frozen_id );
		$this->assertEquals( 'frozen', $frozen_report['status'] );
		$this->assertNotNull( $frozen_report['period_end'] );
		$this->assertNotNull( $frozen_report['frozen_at'] );
		$this->assertNotNull( $frozen_report['summary_data'] );

		// Step 5: Verify summary data.
		$summary = json_decode( $frozen_report['summary_data'], true );
		$this->assertIsArray( $summary );
		$this->assertArrayHasKey( 'totals', $summary );
		$this->assertArrayHasKey( 'highlights', $summary );
		$this->assertEquals( 2, $summary['total_events'] );
		$this->assertEquals( 1, $summary['totals']['post_published'] );
		$this->assertEquals( 1, $summary['totals']['user_registered'] );

		// Step 6: Verify events are assigned to report.
		$report_events = $this->event_repo->get_by_report( $frozen_id );
		$this->assertCount( 2, $report_events );

		// Step 7: Verify new active report was created.
		$new_active = $this->report_repo->get_active();
		$this->assertNotNull( $new_active );
		$this->assertNotEquals( $frozen_id, $new_active['id'] );
		$this->assertEquals( 'active', $new_active['status'] );
	}

	/**
	 * Test trend calculation across reports.
	 */
	public function test_trend_calculation_across_reports() {
		// Create first report with events.
		$report1_id = $this->report_manager->create_new_active_report();

		$this->event_repo->create( array(
			'event_type' => 'post_published',
			'event_data' => array( 'action' => 'published' ),
		) );

		$this->event_repo->create( array(
			'event_type' => 'post_published',
			'event_data' => array( 'action' => 'published' ),
		) );

		// Freeze first report.
		$frozen1_id = $this->report_manager->freeze_current_report();

		// Create second report with more events.
		$report2_id = $this->report_repo->get_active()['id'];

		$this->event_repo->create( array(
			'event_type' => 'post_published',
			'event_data' => array( 'action' => 'published' ),
		) );

		$this->event_repo->create( array(
			'event_type' => 'post_published',
			'event_data' => array( 'action' => 'published' ),
		) );

		$this->event_repo->create( array(
			'event_type' => 'post_published',
			'event_data' => array( 'action' => 'published' ),
		) );

		// Freeze second report.
		$frozen2_id = $this->report_manager->freeze_current_report();

		// Get second report summary.
		$report2 = $this->report_repo->get_by_id( $frozen2_id );
		$summary2 = json_decode( $report2['summary_data'], true );

		// Verify trends.
		$this->assertArrayHasKey( 'trends', $summary2 );
		$this->assertArrayHasKey( 'post_published', $summary2['trends'] );

		$trend = $summary2['trends']['post_published'];
		$this->assertEquals( 3, $trend['current'] );
		$this->assertEquals( 2, $trend['previous'] );
		$this->assertEquals( 50.0, $trend['change_percent'] ); // 2 -> 3 = 50% increase.
		$this->assertEquals( 'up', $trend['direction'] );
	}

	/**
	 * Test event count accuracy.
	 */
	public function test_event_count_accuracy() {
		// Create active report.
		$report_id = $this->report_manager->create_new_active_report();

		// Create 10 events.
		for ( $i = 1; $i <= 10; $i++ ) {
			$this->event_repo->create( array(
				'event_type' => 'post_published',
				'event_data' => array( 'action' => 'published', 'object' => array( 'type' => 'post', 'id' => $i ) ),
			) );
		}

		// Freeze report.
		$frozen_id = $this->report_manager->freeze_current_report();

		// Verify event count.
		$report = $this->report_repo->get_by_id( $frozen_id );
		$this->assertEquals( 10, $report['event_count'] );

		$summary = json_decode( $report['summary_data'], true );
		$this->assertEquals( 10, $summary['total_events'] );
		$this->assertEquals( 10, $summary['totals']['post_published'] );
	}

	/**
	 * Test highlights generation.
	 */
	public function test_highlights_generation() {
		// Create active report.
		$report_id = $this->report_manager->create_new_active_report();

		// Create various event types.
		$this->event_repo->create( array(
			'event_type' => 'post_published',
			'event_data' => array( 'action' => 'published' ),
		) );

		$this->event_repo->create( array(
			'event_type' => 'user_registered',
			'event_data' => array( 'action' => 'registered' ),
		) );

		$this->event_repo->create( array(
			'event_type' => 'comment_posted',
			'event_data' => array( 'action' => 'posted' ),
		) );

		// Freeze report.
		$frozen_id = $this->report_manager->freeze_current_report();

		// Verify highlights.
		$report = $this->report_repo->get_by_id( $frozen_id );
		$summary = json_decode( $report['summary_data'], true );

		$this->assertArrayHasKey( 'highlights', $summary );
		$this->assertIsArray( $summary['highlights'] );
		$this->assertCount( 3, $summary['highlights'] );

		// Check highlight content.
		$highlights_text = implode( ' ', $summary['highlights'] );
		$this->assertStringContainsString( 'new posts published', $highlights_text );
		$this->assertStringContainsString( 'new users registered', $highlights_text );
		$this->assertStringContainsString( 'new comments', $highlights_text );
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		global $wpdb;

		// Clean up test data.
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}sybgo_events" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}sybgo_reports" );

		parent::tearDown();
	}
}
