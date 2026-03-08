<?php
/**
 * Aggregated Event Repository Unit Tests
 *
 * @package Sybgo\Tests\Unit\Database
 */

namespace Sybgo\Tests\Unit\Database;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Sybgo\Database\Aggregated_Event_Repository;

/**
 * Aggregated Event Repository Test Case
 */
class AggregatedEventRepositoryTest extends TestCase {

	/**
	 * Repository instance.
	 *
	 * @var Aggregated_Event_Repository
	 */
	private $repo;

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

		$this->wpdb         = Mockery::mock( '\wpdb' );
		$this->wpdb->prefix = 'wp_';
		$GLOBALS['wpdb']    = $this->wpdb;

		$this->repo = new Aggregated_Event_Repository( 'wp_sybgo_aggregated_events' );

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
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
	 * Test upsert_count() returns true on success.
	 */
	public function test_upsert_count_returns_true_on_success() {
		$this->wpdb
			->shouldReceive( 'prepare' )
			->once()
			->andReturn( 'PREPARED SQL' );

		$this->wpdb
			->shouldReceive( 'query' )
			->once()
			->with( 'PREPARED SQL' )
			->andReturn( 1 );

		$result = $this->repo->upsert_count( 'page_view', '2026-03-08' );

		$this->assertTrue( $result );
	}

	/**
	 * Test upsert_count() returns false when query fails.
	 */
	public function test_upsert_count_returns_false_on_failure() {
		$this->wpdb
			->shouldReceive( 'prepare' )
			->once()
			->andReturn( 'PREPARED SQL' );

		$this->wpdb
			->shouldReceive( 'query' )
			->once()
			->andReturn( false );

		$result = $this->repo->upsert_count( 'page_view', '2026-03-08' );

		$this->assertFalse( $result );
	}

	/**
	 * Test upsert_count() passes event_type and date to prepare().
	 */
	public function test_upsert_count_passes_correct_arguments_to_prepare() {
		$this->wpdb
			->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::type( 'string' ),
				'login_attempt',
				'2026-03-08',
				Mockery::type( 'string' ),
				Mockery::type( 'string' )
			)
			->andReturn( 'PREPARED SQL' );

		$this->wpdb
			->shouldReceive( 'query' )
			->once()
			->andReturn( 1 );

		$this->repo->upsert_count( 'login_attempt', '2026-03-08' );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test upsert_count() with empty meta uses empty JSON object.
	 */
	public function test_upsert_count_with_empty_meta() {
		$this->wpdb
			->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::type( 'string' ),
				Mockery::type( 'string' ),
				Mockery::type( 'string' ),
				'[]',
				'[]'
			)
			->andReturn( 'PREPARED SQL' );

		$this->wpdb
			->shouldReceive( 'query' )
			->once()
			->andReturn( 1 );

		$this->repo->upsert_count( 'page_view', '2026-03-08' );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test upsert_count() with meta passes JSON-encoded meta.
	 */
	public function test_upsert_count_with_meta() {
		$meta      = array( 'source' => 'homepage' );
		$meta_json = json_encode( $meta );

		$this->wpdb
			->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::type( 'string' ),
				Mockery::type( 'string' ),
				Mockery::type( 'string' ),
				$meta_json,
				$meta_json
			)
			->andReturn( 'PREPARED SQL' );

		$this->wpdb
			->shouldReceive( 'query' )
			->once()
			->andReturn( 1 );

		$this->repo->upsert_count( 'page_view', '2026-03-08', $meta );

		$this->addToAssertionCount( 1 );
	}
}
