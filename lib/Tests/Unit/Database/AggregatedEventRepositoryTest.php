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

	/**
	 * Test assign_to_report() issues an UPDATE targeting sentinel rows (report_id = 0) in date range.
	 */
	public function test_assign_to_report_updates_sentinel_rows_in_date_range(): void {
		$this->wpdb
			->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					function ( $sql ) {
						return false !== strpos( $sql, 'report_id = 0' )
							&& false !== strpos( $sql, 'SET report_id' )
							&& false !== strpos( $sql, 'BETWEEN' );
					}
				),
				7,
				'2026-02-10',
				'2026-02-16'
			)
			->andReturn( 'PREPARED SQL' );

		$this->wpdb->shouldReceive( 'query' )->once()->with( 'PREPARED SQL' )->andReturn( 5 );

		$this->repo->assign_to_report( 7, '2026-02-10', '2026-02-16' );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test assign_to_report() SQL does not reference is_assigned or report_id IS NULL.
	 *
	 * Regression guard: the old schema used IS NULL + is_assigned which caused a unique key
	 * collision on the second freeze for same-day signatures. The new schema uses report_id=0
	 * as a sentinel so the unique key (event_type, dimensions_hash, date, report_id) can
	 * accommodate multiple freezes on the same calendar day without collision.
	 */
	public function test_assign_to_report_sql_uses_sentinel_not_null(): void {
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
				Mockery::any()
			)
			->andReturn( 'PREPARED SQL' );

		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		$this->repo->assign_to_report( 1, '2026-03-21', '2026-03-21' );

		$this->assertStringContainsString( 'report_id = 0', $captured_sql );
		$this->assertStringNotContainsString( 'IS NULL', $captured_sql );
		$this->assertStringNotContainsString( 'is_assigned', $captured_sql );
	}

	/**
	 * Test count_distinct_dimensions_for_report() with null uses report_id = 0 sentinel.
	 */
	public function test_count_distinct_dimensions_for_report_null_uses_sentinel(): void {
		$this->wpdb
			->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					function ( $sql ) {
						return false !== strpos( $sql, 'report_id = 0' )
							&& false !== strpos( $sql, 'COUNT(DISTINCT dimensions_hash)' );
					}
				),
				'php_error'
			)
			->andReturn( 'PREPARED SQL' );

		$this->wpdb->shouldReceive( 'get_var' )->once()->with( 'PREPARED SQL' )->andReturn( '3' );

		$count = $this->repo->count_distinct_dimensions_for_report( 'php_error', null );

		$this->assertSame( 3, $count );
	}

	/**
	 * Test count_distinct_dimensions_for_report() with int uses report_id = N.
	 */
	public function test_count_distinct_dimensions_for_report_int_uses_equals(): void {
		$this->wpdb
			->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					function ( $sql ) {
						return false !== strpos( $sql, 'report_id = %d' )
							&& false !== strpos( $sql, 'COUNT(DISTINCT dimensions_hash)' );
					}
				),
				'php_error',
				42
			)
			->andReturn( 'PREPARED SQL' );

		$this->wpdb->shouldReceive( 'get_var' )->once()->with( 'PREPARED SQL' )->andReturn( '2' );

		$count = $this->repo->count_distinct_dimensions_for_report( 'php_error', 42 );

		$this->assertSame( 2, $count );
	}

	/**
	 * Test get_sum_for_report() with null uses report_id = 0 sentinel.
	 */
	public function test_get_sum_for_report_null_uses_sentinel(): void {
		$this->wpdb
			->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					function ( $sql ) {
						return false !== strpos( $sql, 'report_id = 0' )
							&& false !== strpos( $sql, 'SUM(value)' );
					}
				),
				'php_error'
			)
			->andReturn( 'PREPARED SQL' );

		$this->wpdb->shouldReceive( 'get_var' )->once()->with( 'PREPARED SQL' )->andReturn( '7.0000' );

		$sum = $this->repo->get_sum_for_report( 'php_error', null );

		$this->assertSame( 7.0, $sum );
	}

	/**
	 * Test get_sum_for_report() with int uses report_id = N.
	 */
	public function test_get_sum_for_report_int_uses_equals(): void {
		$this->wpdb
			->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					function ( $sql ) {
						return false !== strpos( $sql, 'report_id = %d' )
							&& false !== strpos( $sql, 'SUM(value)' );
					}
				),
				'php_error',
				99
			)
			->andReturn( 'PREPARED SQL' );

		$this->wpdb->shouldReceive( 'get_var' )->once()->with( 'PREPARED SQL' )->andReturn( '15.0000' );

		$sum = $this->repo->get_sum_for_report( 'php_error', 99 );

		$this->assertSame( 15.0, $sum );
	}

	/**
	 * Test get_rows_for_report() with null uses report_id = 0 sentinel.
	 */
	public function test_get_rows_for_report_null_uses_sentinel(): void {
		$expected_rows = array(
			array( 'dimensions' => '{"level":"warning"}', 'total' => '5', 'meta' => '{}' ),
		);

		$this->wpdb
			->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					function ( $sql ) {
						return false !== strpos( $sql, 'report_id = 0' )
							&& false !== strpos( $sql, 'GROUP BY dimensions_hash' );
					}
				),
				'php_error'
			)
			->andReturn( 'PREPARED SQL' );

		$this->wpdb->shouldReceive( 'get_results' )->once()->with( 'PREPARED SQL', ARRAY_A )->andReturn( $expected_rows );

		$rows = $this->repo->get_rows_for_report( 'php_error', null );

		$this->assertSame( $expected_rows, $rows );
	}

	/**
	 * Test get_rows_for_report() with int uses report_id = N.
	 */
	public function test_get_rows_for_report_int_uses_equals(): void {
		$expected_rows = array(
			array( 'dimensions' => '{"level":"notice"}', 'total' => '3', 'meta' => '{}' ),
		);

		$this->wpdb
			->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					function ( $sql ) {
						return false !== strpos( $sql, 'report_id = %d' )
							&& false !== strpos( $sql, 'GROUP BY dimensions_hash' );
					}
				),
				'php_error',
				12
			)
			->andReturn( 'PREPARED SQL' );

		$this->wpdb->shouldReceive( 'get_results' )->once()->with( 'PREPARED SQL', ARRAY_A )->andReturn( $expected_rows );

		$rows = $this->repo->get_rows_for_report( 'php_error', 12 );

		$this->assertSame( $expected_rows, $rows );
	}

	/**
	 * Test get_rows_for_report() returns empty array when wpdb returns null.
	 */
	public function test_get_rows_for_report_returns_empty_array_when_null(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'PREPARED SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( null );

		$rows = $this->repo->get_rows_for_report( 'php_error', null );

		$this->assertSame( array(), $rows );
	}
}
