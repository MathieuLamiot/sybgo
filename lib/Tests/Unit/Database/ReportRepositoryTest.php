<?php
/**
 * Report Repository Unit Tests
 *
 * @package Sybgo\Tests\Unit\Database
 */

namespace Sybgo\Tests\Unit\Database;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Sybgo\Database\Report_Repository;

/**
 * Report Repository Test Case
 */
class ReportRepositoryTest extends TestCase {

	/**
	 * Report repository instance.
	 *
	 * @var Report_Repository
	 */
	private $report_repo;

	/**
	 * Mock wpdb instance.
	 *
	 * @var Mockery\MockInterface
	 */
	private $wpdb;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Mock wpdb.
		$this->wpdb         = Mockery::mock( '\wpdb' );
		$this->wpdb->prefix = 'wp_';
		$GLOBALS['wpdb']    = $this->wpdb;

		// Create repository instance.
		$this->report_repo = new Report_Repository( 'wp_sybgo_reports' );

		// Mock WordPress functions.
		Functions\when( 'current_time' )->justReturn( '2026-03-01 00:00:00' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_parse_args' )->alias(
			function ( $args, $defaults ) {
				return array_merge( $defaults, $args );
			}
		);
	}

	/**
	 * Tear down test environment.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// save_summary_data()
	// -------------------------------------------------------------------------

	/**
	 * save_summary_data() should persist the full summary array via update() and return true.
	 */
	public function test_save_summary_data_persists_full_array(): void {
		$full_summary = array(
			'totals'       => array( 'post_published' => 5 ),
			'trends'       => array(),
			'highlights'   => array( '5 new posts published' ),
			'top_authors'  => array(),
			'total_events' => 5,
			'ai_summary'   => 'A great week!',
		);

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with(
				'wp_sybgo_reports',
				Mockery::on(
					function ( $data ) use ( $full_summary ) {
						$decoded = json_decode( $data['summary_data'], true );
						return isset( $decoded['ai_summary'] )
							&& 'A great week!' === $decoded['ai_summary']
							&& isset( $decoded['totals'] )
							&& 5 === $decoded['totals']['post_published'];
					}
				),
				array( 'id' => 55 )
			)
			->andReturn( 1 );

		$result = $this->report_repo->save_summary_data( 55, $full_summary );

		$this->assertTrue( $result );
	}

	// -------------------------------------------------------------------------
	// set_ai_summary()
	// -------------------------------------------------------------------------

	/**
	 * set_ai_summary() should merge ai_summary into existing summary_data and return true.
	 */
	public function test_set_ai_summary_merges_into_existing_summary_data(): void {
		$existing_summary = array(
			'totals'       => array( 'post_published' => 3 ),
			'trends'       => array(),
			'highlights'   => array( '3 new posts published' ),
			'top_authors'  => array(),
			'total_events' => 3,
			'ai_summary'   => null,
		);

		$report = array(
			'id'           => 42,
			'status'       => 'frozen',
			'summary_data' => json_encode( $existing_summary ),
		);

		// get_by_id() call inside set_ai_summary().
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with( Mockery::pattern( '/SELECT \* FROM/' ), 42 )
			->andReturn( 'SELECT * FROM wp_sybgo_reports WHERE id = 42' );

		$this->wpdb->shouldReceive( 'get_row' )
			->once()
			->andReturn( $report );

		// update() call — expect ai_summary merged in.
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with(
				'wp_sybgo_reports',
				Mockery::on(
					function ( $data ) {
						$decoded = json_decode( $data['summary_data'], true );
						return isset( $decoded['ai_summary'] ) && 'Great week!' === $decoded['ai_summary']
							&& isset( $decoded['totals'] );
					}
				),
				array( 'id' => 42 )
			)
			->andReturn( 1 );

		$result = $this->report_repo->set_ai_summary( 42, 'Great week!' );

		$this->assertTrue( $result );
	}

	/**
	 * set_ai_summary() should return false when report does not exist.
	 */
	public function test_set_ai_summary_returns_false_when_report_not_found(): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturn( 'SELECT * FROM wp_sybgo_reports WHERE id = 99' );

		$this->wpdb->shouldReceive( 'get_row' )
			->once()
			->andReturn( null );

		$result = $this->report_repo->set_ai_summary( 99, 'Some summary' );

		$this->assertFalse( $result );
	}

	/**
	 * set_ai_summary() should work when summary_data is null (no existing data).
	 */
	public function test_set_ai_summary_works_when_summary_data_is_null(): void {
		$report = array(
			'id'           => 7,
			'status'       => 'frozen',
			'summary_data' => null,
		);

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturn( 'SELECT * FROM wp_sybgo_reports WHERE id = 7' );

		$this->wpdb->shouldReceive( 'get_row' )
			->once()
			->andReturn( $report );

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with(
				'wp_sybgo_reports',
				Mockery::on(
					function ( $data ) {
						$decoded = json_decode( $data['summary_data'], true );
						return isset( $decoded['ai_summary'] ) && 'Hello!' === $decoded['ai_summary'];
					}
				),
				array( 'id' => 7 )
			)
			->andReturn( 1 );

		$result = $this->report_repo->set_ai_summary( 7, 'Hello!' );

		$this->assertTrue( $result );
	}
}
