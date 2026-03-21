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

		Functions\when( 'wp_json_encode' )->alias(
			function ( $data, int $flags = 0 ) {
				return json_encode( $data, $flags );
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

	/**
	 * Test upsert() returns true on success.
	 */
	public function test_upsert_returns_true_on_success() {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'PREPARED SQL' );
		$this->wpdb->shouldReceive( 'query' )->once()->with( 'PREPARED SQL' )->andReturn( 1 );

		$result = $this->repo->upsert( 'page_view', '2026-03-08' );

		$this->assertTrue( $result );
	}

	/**
	 * Test upsert() returns false when query fails.
	 */
	public function test_upsert_returns_false_on_failure() {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'PREPARED SQL' );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( false );

		$result = $this->repo->upsert( 'page_view', '2026-03-08' );

		$this->assertFalse( $result );
	}

	/**
	 * Test upsert() passes event_type, date, and value to prepare().
	 */
	public function test_upsert_passes_event_type_date_value_to_prepare() {
		$this->wpdb
			->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::type( 'string' ),
				'woo_sale_revenue',
				Mockery::type( 'string' ), // dimensions JSON
				249.95,
				'2026-03-08',
				Mockery::type( 'string' )  // meta JSON
			)
			->andReturn( 'PREPARED SQL' );

		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		$this->repo->upsert( 'woo_sale_revenue', '2026-03-08', 249.95 );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test upsert() with empty dimensions encodes as '{}' (empty JSON object).
	 */
	public function test_upsert_with_empty_dimensions_encodes_as_empty_json_object() {
		$this->wpdb
			->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::type( 'string' ),
				Mockery::type( 'string' ), // event_type
				'{}',                      // empty dimensions → '{}'
				Mockery::type( 'float' ),
				Mockery::type( 'string' ),
				Mockery::type( 'string' )
			)
			->andReturn( 'PREPARED SQL' );

		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		$this->repo->upsert( 'page_view', '2026-03-08' );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test upsert() sorts dimension keys alphabetically for canonical JSON.
	 */
	public function test_upsert_sorts_dimension_keys_canonically() {
		// Pass keys in reverse order; expect them sorted alphabetically.
		$dimensions          = array( 'role' => 'editor', 'product_id' => 99 );
		$expected_dimensions = '{"product_id":99,"role":"editor"}';

		$this->wpdb
			->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::type( 'string' ),
				Mockery::type( 'string' ),
				$expected_dimensions,
				Mockery::type( 'float' ),
				Mockery::type( 'string' ),
				Mockery::type( 'string' )
			)
			->andReturn( 'PREPARED SQL' );

		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		$this->repo->upsert( 'user_registered', '2026-03-08', 1.0, $dimensions );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test upsert() with meta passes JSON-encoded meta.
	 */
	public function test_upsert_with_meta_passes_json_encoded_meta() {
		$meta      = array( 'product_name' => 'Widget' );
		$meta_json = json_encode( $meta );

		$this->wpdb
			->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::type( 'string' ),
				Mockery::type( 'string' ),
				Mockery::type( 'string' ),
				Mockery::type( 'float' ),
				Mockery::type( 'string' ),
				$meta_json
			)
			->andReturn( 'PREPARED SQL' );

		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		$this->repo->upsert( 'woo_sale_units', '2026-03-08', 1.0, array(), $meta );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test count_distinct_dimensions_for_date_range() returns the integer from wpdb.
	 */
	public function test_count_distinct_dimensions_for_date_range_returns_int(): void {
		$this->wpdb
			->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					function ( $sql ) {
						return false !== strpos( $sql, 'BETWEEN' )
							&& false !== strpos( $sql, 'COUNT(DISTINCT dimensions_hash)' );
					}
				),
				'php_error',
				'2026-03-15',
				'2026-03-21'
			)
			->andReturn( 'PREPARED SQL' );

		$this->wpdb->shouldReceive( 'get_var' )->once()->with( 'PREPARED SQL' )->andReturn( '3' );

		$count = $this->repo->count_distinct_dimensions_for_date_range( 'php_error', '2026-03-15', '2026-03-21' );

		$this->assertSame( 3, $count );
	}

	/**
	 * Test count_distinct_dimensions_for_date_range() returns zero when wpdb returns null.
	 */
	public function test_count_distinct_dimensions_for_date_range_returns_zero_when_null(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'PREPARED SQL' );
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( null );

		$count = $this->repo->count_distinct_dimensions_for_date_range( 'php_error', '2026-03-15', '2026-03-21' );

		$this->assertSame( 0, $count );
	}

	/**
	 * Test upsert() SQL uses value accumulation, not a fixed count increment.
	 */
	public function test_upsert_sql_accumulates_value() {
		$captured_sql = '';

		$this->wpdb
			->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					function ( $sql ) use ( &$captured_sql ) {
						$captured_sql = $sql;
						return true;
					}
				),
				Mockery::any(),
				Mockery::any(),
				Mockery::any(),
				Mockery::any(),
				Mockery::any()
			)
			->andReturn( 'PREPARED SQL' );

		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		$this->repo->upsert( 'page_view', '2026-03-08' );

		$this->assertStringContainsString( 'value = value + VALUES(value)', $captured_sql );
		$this->assertStringNotContainsString( 'count = count + 1', $captured_sql );
	}
}
