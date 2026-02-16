<?php
/**
 * Report Generator Unit Tests
 *
 * @package Rocket\Sybgo\Tests\Unit\Reports
 */

namespace Rocket\Sybgo\Tests\Unit\Reports;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Rocket\Sybgo\Reports\Report_Generator;

/**
 * Report Generator Test Case
 */
class ReportGeneratorTest extends TestCase {

	/**
	 * Report generator instance.
	 *
	 * @var Report_Generator
	 */
	private $generator;

	/**
	 * Mock event repository.
	 *
	 * @var Mockery\MockInterface
	 */
	private $event_repo;

	/**
	 * Mock report repository.
	 *
	 * @var Mockery\MockInterface
	 */
	private $report_repo;

	/**
	 * Mock AI summarizer.
	 *
	 * @var Mockery\MockInterface
	 */
	private $ai_summarizer;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->event_repo    = Mockery::mock( 'Rocket\Sybgo\Database\Event_Repository' );
		$this->report_repo   = Mockery::mock( 'Rocket\Sybgo\Database\Report_Repository' );
		$this->ai_summarizer = Mockery::mock( 'Rocket\Sybgo\AI\AI_Summarizer' );

		// Mock AI summarizer to return null (no API key configured).
		$this->ai_summarizer->shouldReceive( 'generate_summary' )->andReturn( null );

		$this->generator = new Report_Generator( $this->event_repo, $this->report_repo, $this->ai_summarizer );

		// Mock WordPress filters - apply_filters returns the value being filtered.
		Functions\when( 'apply_filters' )->alias( function( $hook, $value ) {
			return $value;
		} );
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
	 * Test generate_summary creates complete summary.
	 */
	public function test_generate_summary() {
		$events = array(
			array(
				'id'         => 1,
				'event_type' => 'post_published',
				'event_data' => json_encode( array(
					'context' => array( 'user_name' => 'John' ),
				) ),
			),
			array(
				'id'         => 2,
				'event_type' => 'post_published',
				'event_data' => json_encode( array(
					'context' => array( 'user_name' => 'John' ),
				) ),
			),
			array(
				'id'         => 3,
				'event_type' => 'user_registered',
				'event_data' => '{}',
			),
		);

		$this->event_repo->shouldReceive( 'get_by_report' )
			->once()
			->with( 1 )
			->andReturn( $events );

		$this->report_repo->shouldReceive( 'get_all_frozen' )
			->once()
			->with( 2 )
			->andReturn( array() ); // No previous report for first test.

		$summary = $this->generator->generate_summary( 1 );

		$this->assertIsArray( $summary );
		$this->assertArrayHasKey( 'totals', $summary );
		$this->assertArrayHasKey( 'trends', $summary );
		$this->assertArrayHasKey( 'highlights', $summary );
		$this->assertArrayHasKey( 'top_authors', $summary );
		$this->assertArrayHasKey( 'total_events', $summary );

		$this->assertEquals( 3, $summary['total_events'] );
		$this->assertEquals( 2, $summary['totals']['post_published'] );
		$this->assertEquals( 1, $summary['totals']['user_registered'] );
	}

	/**
	 * Test trend calculation with increase.
	 */
	public function test_get_trend_comparison_increase() {
		$current_totals = array(
			'post_published'  => 12,
			'user_registered' => 5,
		);

		$previous_report = array(
			'id'           => 1,
			'summary_data' => json_encode( array(
				'totals' => array(
					'post_published'  => 10,
					'user_registered' => 4,
				),
			) ),
		);

		$this->report_repo->shouldReceive( 'get_all_frozen' )
			->once()
			->with( 2 )
			->andReturn( array( $previous_report ) );

		$trends = $this->generator->get_trend_comparison( 2, $current_totals );

		$this->assertIsArray( $trends );

		// Check post_published trend (10 -> 12 = 20% increase).
		$this->assertEquals( 12, $trends['post_published']['current'] );
		$this->assertEquals( 10, $trends['post_published']['previous'] );
		$this->assertEquals( 20.0, $trends['post_published']['change_percent'] );
		$this->assertEquals( 'up', $trends['post_published']['direction'] );

		// Check user_registered trend (4 -> 5 = 25% increase).
		$this->assertEquals( 5, $trends['user_registered']['current'] );
		$this->assertEquals( 4, $trends['user_registered']['previous'] );
		$this->assertEquals( 25.0, $trends['user_registered']['change_percent'] );
		$this->assertEquals( 'up', $trends['user_registered']['direction'] );
	}

	/**
	 * Test trend calculation with decrease.
	 */
	public function test_get_trend_comparison_decrease() {
		$current_totals = array(
			'post_published' => 8,
		);

		$previous_report = array(
			'id'           => 1,
			'summary_data' => json_encode( array(
				'totals' => array(
					'post_published' => 10,
				),
			) ),
		);

		$this->report_repo->shouldReceive( 'get_all_frozen' )
			->once()
			->with( 2 )
			->andReturn( array( $previous_report ) );

		$trends = $this->generator->get_trend_comparison( 2, $current_totals );

		// Check post_published trend (10 -> 8 = -20% decrease).
		$this->assertEquals( 8, $trends['post_published']['current'] );
		$this->assertEquals( 10, $trends['post_published']['previous'] );
		$this->assertEquals( -20.0, $trends['post_published']['change_percent'] );
		$this->assertEquals( 'down', $trends['post_published']['direction'] );
	}

	/**
	 * Test trend with no change.
	 */
	public function test_get_trend_comparison_same() {
		$current_totals = array(
			'post_published' => 10,
		);

		$previous_report = array(
			'id'           => 1,
			'summary_data' => json_encode( array(
				'totals' => array(
					'post_published' => 10,
				),
			) ),
		);

		$this->report_repo->shouldReceive( 'get_all_frozen' )
			->once()
			->andReturn( array( $previous_report ) );

		$trends = $this->generator->get_trend_comparison( 2, $current_totals );

		$this->assertEquals( 0.0, $trends['post_published']['change_percent'] );
		$this->assertEquals( 'same', $trends['post_published']['direction'] );
	}

	/**
	 * Test trend with first report (no previous).
	 */
	public function test_get_trend_comparison_no_previous() {
		$current_totals = array(
			'post_published' => 12,
		);

		$this->report_repo->shouldReceive( 'get_all_frozen' )
			->once()
			->andReturn( array() );

		$trends = $this->generator->get_trend_comparison( 1, $current_totals );

		$this->assertEmpty( $trends );
	}

	/**
	 * Test highlights generation includes trend arrows.
	 */
	public function test_generate_highlights_with_trends() {
		// Use reflection to test private method.
		$reflection = new \ReflectionClass( $this->generator );
		$method     = $reflection->getMethod( 'generate_highlights' );
		$method->setAccessible( true );

		$totals = array(
			'post_published'  => 12,
			'user_registered' => 3,
		);

		$trends = array(
			'post_published'  => array(
				'change_percent' => 20.0,
				'direction'      => 'up',
			),
			'user_registered' => array(
				'change_percent' => -25.0,
				'direction'      => 'down',
			),
		);

		$highlights = $method->invoke( $this->generator, $totals, $trends );

		$this->assertIsArray( $highlights );
		$this->assertCount( 2, $highlights );

		// Check for trend indicators in highlights.
		$this->assertStringContainsString( '12 new posts published', $highlights[0] );
		$this->assertStringContainsString( '↑', $highlights[0] );
		$this->assertStringContainsString( '20.0%', $highlights[0] );

		$this->assertStringContainsString( '3 new users registered', $highlights[1] );
		$this->assertStringContainsString( '↓', $highlights[1] );
		$this->assertStringContainsString( '25.0%', $highlights[1] );
	}

	/**
	 * Test top authors extraction.
	 */
	public function test_get_top_authors() {
		$events = array(
			array(
				'event_type' => 'post_published',
				'event_data' => json_encode( array(
					'context' => array( 'user_name' => 'John' ),
				) ),
			),
			array(
				'event_type' => 'post_published',
				'event_data' => json_encode( array(
					'context' => array( 'user_name' => 'John' ),
				) ),
			),
			array(
				'event_type' => 'post_published',
				'event_data' => json_encode( array(
					'context' => array( 'user_name' => 'Jane' ),
				) ),
			),
			array(
				'event_type' => 'user_registered', // Should be ignored.
				'event_data' => json_encode( array(
					'context' => array( 'user_name' => 'Bob' ),
				) ),
			),
		);

		// Use reflection to test private method.
		$reflection = new \ReflectionClass( $this->generator );
		$method     = $reflection->getMethod( 'get_top_authors' );
		$method->setAccessible( true );

		$top_authors = $method->invoke( $this->generator, $events );

		$this->assertIsArray( $top_authors );
		$this->assertCount( 2, $top_authors );

		// John should be first with 2 posts.
		$this->assertEquals( 'John', $top_authors[0]['name'] );
		$this->assertEquals( 2, $top_authors[0]['count'] );

		// Jane should be second with 1 post.
		$this->assertEquals( 'Jane', $top_authors[1]['name'] );
		$this->assertEquals( 1, $top_authors[1]['count'] );
	}
}
