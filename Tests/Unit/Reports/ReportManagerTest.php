<?php
/**
 * Report Manager Test
 *
 * @package Rocket\Sybgo\Tests\Unit\Reports
 */

declare(strict_types=1);

namespace Rocket\Sybgo\Tests\Unit\Reports;

use Rocket\Sybgo\Reports\Report_Manager;
use Rocket\Sybgo\Database\Event_Repository;
use Rocket\Sybgo\Database\Report_Repository;
use Rocket\Sybgo\Reports\Report_Generator;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Test Report_Manager class.
 */
class ReportManagerTest extends TestCase {
	/**
	 * Event repository mock.
	 *
	 * @var Event_Repository
	 */
	private $event_repo;

	/**
	 * Report repository mock.
	 *
	 * @var Report_Repository
	 */
	private $report_repo;

	/**
	 * Report generator mock.
	 *
	 * @var Report_Generator
	 */
	private $report_generator;

	/**
	 * Report manager instance.
	 *
	 * @var Report_Manager
	 */
	private Report_Manager $report_manager;

	/**
	 * Set up test environment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->event_repo       = Mockery::mock( Event_Repository::class );
		$this->report_repo      = Mockery::mock( Report_Repository::class );
		$this->report_generator = Mockery::mock( Report_Generator::class );

		$this->report_manager = new Report_Manager(
			$this->event_repo,
			$this->report_repo,
			$this->report_generator
		);

		// Mock WordPress functions.
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'current_time' )->alias( function( $type ) {
			return ( $type === 'mysql' ) ? '2026-02-16 23:55:00' : time();
		} );
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
	 * Test freeze_current_report() successfully freezes active report.
	 *
	 * @return void
	 */
	public function test_freeze_current_report_success() {
		$active_report = [
			'id'           => 1,
			'period_start' => '2026-02-10 00:00:00',
			'period_end'   => null,
			'status'       => 'active',
		];

		$summary_data = [
			'totals'     => [ 'posts_published' => 5 ],
			'highlights' => [ '5 new posts published' ],
		];

		$this->report_repo->shouldReceive( 'get_active' )
			->once()
			->andReturn( $active_report );

		Functions\expect( 'do_action' )
			->once()
			->with( 'sybgo_before_report_freeze', 1 );

		$this->report_generator->shouldReceive( 'generate_summary' )
			->once()
			->with( 1 )
			->andReturn( $summary_data );

		// Note: assign_to_report takes 3 parameters (report_id, start, end).
		$this->event_repo->shouldReceive( 'assign_to_report' )
			->once()
			->with( 1, '2026-02-10 00:00:00', '2026-02-16 23:55:00' )
			->andReturn( 10 );

		$this->report_repo->shouldReceive( 'update' )
			->once()
			->with(
				1,
				Mockery::on( function( $data ) use ( $summary_data ) {
					return $data['period_end'] === '2026-02-16 23:55:00'
						&& $data['summary_data'] === $summary_data
						&& $data['status'] === 'frozen'
						&& $data['event_count'] === 10;
				} )
			)
			->andReturn( true );

		Functions\expect( 'do_action' )
			->once()
			->with( 'sybgo_after_report_freeze', 1, $summary_data );

		// After freeze, creates new active report.
		$this->report_repo->shouldReceive( 'create' )
			->once()
			->andReturn( 2 );

		$result = $this->report_manager->freeze_current_report();

		$this->assertEquals( 1, $result );
	}

	/**
	 * Test freeze_current_report() returns false when no active report.
	 *
	 * @return void
	 */
	public function test_freeze_current_report_no_active_report() {
		$this->report_repo->shouldReceive( 'get_active' )
			->once()
			->andReturn( null );

		$result = $this->report_manager->freeze_current_report();

		$this->assertFalse( $result );
	}

	/**
	 * Test create_new_active_report() creates active report.
	 *
	 * @return void
	 */
	public function test_create_new_active_report() {
		$this->report_repo->shouldReceive( 'create' )
			->once()
			->with( Mockery::on( function( $data ) {
				return $data['period_start'] === '2026-02-16 23:55:00'
					&& $data['status'] === 'active';
			} ) )
			->andReturn( 2 );

		$result = $this->report_manager->create_new_active_report();

		$this->assertEquals( 2, $result );
	}

	/**
	 * Test get_or_create_active_report() returns existing active report.
	 *
	 * @return void
	 */
	public function test_get_or_create_active_report_existing() {
		$active_report = [
			'id'           => 1,
			'period_start' => '2026-02-10 00:00:00',
			'status'       => 'active',
		];

		$this->report_repo->shouldReceive( 'get_active' )
			->once()
			->andReturn( $active_report );

		$result = $this->report_manager->get_or_create_active_report();

		$this->assertEquals( $active_report, $result );
	}

	/**
	 * Test get_or_create_active_report() creates when none exists.
	 *
	 * @return void
	 */
	public function test_get_or_create_active_report_creates_new() {
		$new_report = [
			'id'           => 2,
			'period_start' => '2026-02-16 23:55:00',
			'status'       => 'active',
		];

		$this->report_repo->shouldReceive( 'get_active' )
			->once()
			->andReturn( null );

		$this->report_repo->shouldReceive( 'create' )
			->once()
			->andReturn( 2 );

		$this->report_repo->shouldReceive( 'get_by_id' )
			->once()
			->with( 2 )
			->andReturn( $new_report );

		$result = $this->report_manager->get_or_create_active_report();

		$this->assertEquals( $new_report, $result );
	}

	/**
	 * Test get_last_frozen_report() returns frozen report.
	 *
	 * @return void
	 */
	public function test_get_last_frozen_report() {
		$frozen_report = [
			'id'           => 1,
			'period_start' => '2026-02-03 00:00:00',
			'period_end'   => '2026-02-09 23:55:00',
			'status'       => 'frozen',
		];

		$this->report_repo->shouldReceive( 'get_last_frozen' )
			->once()
			->andReturn( $frozen_report );

		$result = $this->report_manager->get_last_frozen_report();

		$this->assertEquals( $frozen_report, $result );
	}

	/**
	 * Test get_report() returns report by ID.
	 *
	 * @return void
	 */
	public function test_get_report() {
		$report = [
			'id'           => 1,
			'period_start' => '2026-02-10 00:00:00',
			'status'       => 'frozen',
		];

		$this->report_repo->shouldReceive( 'get_by_id' )
			->once()
			->with( 1 )
			->andReturn( $report );

		$result = $this->report_manager->get_report( 1 );

		$this->assertEquals( $report, $result );
	}

	/**
	 * Test get_active_event_count() returns count.
	 *
	 * @return void
	 */
	public function test_get_active_event_count() {
		$this->event_repo->shouldReceive( 'get_by_report' )
			->once()
			->with( null, 1000 )
			->andReturn( array_fill( 0, 15, [] ) ); // Return 15 events

		$result = $this->report_manager->get_active_event_count();

		$this->assertEquals( 15, $result );
	}

	/**
	 * Test mark_report_emailed() updates status.
	 *
	 * @return void
	 */
	public function test_mark_report_emailed() {
		Functions\when( 'current_time' )->alias( function( $type ) {
			return '2026-02-16 12:00:00';
		} );

		$this->report_repo->shouldReceive( 'update' )
			->once()
			->with( 1, Mockery::on( function( $data ) {
				return $data['status'] === 'emailed'
					&& isset( $data['emailed_at'] );
			} ) )
			->andReturn( true );

		$result = $this->report_manager->mark_report_emailed( 1 );

		$this->assertTrue( $result );
	}
}
