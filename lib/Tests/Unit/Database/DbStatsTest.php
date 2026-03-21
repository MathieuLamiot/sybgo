<?php
/**
 * DB Stats Unit Tests
 *
 * @package Sybgo\Tests\Unit\Database
 */

declare(strict_types=1);

namespace Sybgo\Tests\Unit\Database;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Sybgo\Database\DatabaseManager;
use Sybgo\Database\DB_Stats;

/**
 * DB Stats Test Case
 */
class DbStatsTest extends TestCase {

	/**
	 * DB Stats instance.
	 *
	 * @var DB_Stats
	 */
	private DB_Stats $db_stats;

	/**
	 * Mock DatabaseManager.
	 *
	 * @var Mockery\MockInterface
	 */
	private $db_manager;

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
		$this->wpdb->dbname = 'wordpress_test';
		$GLOBALS['wpdb']    = $this->wpdb;

		// Mock DatabaseManager.
		$this->db_manager = Mockery::mock( DatabaseManager::class );
		$this->db_manager->shouldReceive( 'get_table_names' )->andReturn( array(
			'events'            => 'wp_sybgo_events',
			'reports'           => 'wp_sybgo_reports',
			'email_log'         => 'wp_sybgo_email_log',
			'aggregated_events' => 'wp_sybgo_aggregated_events',
		) );

		$this->db_stats = new DB_Stats( $this->db_manager );

		// Define DB_NAME if not defined.
		if ( ! defined( 'DB_NAME' ) ) {
			define( 'DB_NAME', 'wordpress_test' );
		}
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
	 * Test that get_table_stats returns all four tables.
	 */
	public function test_get_table_stats_returns_all_four_tables(): void {
		// Mock row count queries.
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '100', '0.5', '50', '0.2', '10', '0.1', '200', '1.5' );

		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function( $query ) {
			return $query;
		} );

		$stats = $this->db_stats->get_table_stats();

		$this->assertArrayHasKey( 'events', $stats );
		$this->assertArrayHasKey( 'reports', $stats );
		$this->assertArrayHasKey( 'email_log', $stats );
		$this->assertArrayHasKey( 'aggregated_events', $stats );

		foreach ( $stats as $table_stats ) {
			$this->assertArrayHasKey( 'table_name', $table_stats );
			$this->assertArrayHasKey( 'row_count', $table_stats );
			$this->assertArrayHasKey( 'size_mb', $table_stats );
		}
	}

	/**
	 * Test that get_table_stats row_count is always an int.
	 */
	public function test_get_row_count_returns_integer(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function( $query ) {
			return $query;
		} );
		// Return string '42' from get_var (as MySQL would return it).
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '42', null, '0', null, '0', null, '0', null );

		$stats = $this->db_stats->get_table_stats();

		$this->assertIsInt( $stats['events']['row_count'] );
		$this->assertSame( 42, $stats['events']['row_count'] );
	}

	/**
	 * Test that get_table_size_mb returns a float when data is available.
	 */
	public function test_get_table_size_mb_returns_float(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function( $query ) {
			return $query;
		} );
		// row count = '100', size = '2.5' for first table, then 0 for rest.
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '100', '2.5', '0', '0', '0', '0', '0', '0' );

		$stats = $this->db_stats->get_table_stats();

		$this->assertIsFloat( $stats['events']['size_mb'] );
		$this->assertSame( 2.5, $stats['events']['size_mb'] );
	}

	/**
	 * Test that get_table_size_mb returns null when information_schema is unavailable.
	 */
	public function test_get_table_size_mb_returns_null_on_failure(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function( $query ) {
			return $query;
		} );
		// row count = '100', size query returns null (restricted host).
		$this->wpdb->shouldReceive( 'get_var' )->andReturn( '100', null, '0', null, '0', null, '0', null );

		$stats = $this->db_stats->get_table_stats();

		$this->assertNull( $stats['events']['size_mb'] );
	}

	/**
	 * Test that get_total_size_mb sums all table sizes.
	 */
	public function test_get_total_size_mb_sums_all_tables(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function( $query ) {
			return $query;
		} );
		// row counts: 100, 50, 10, 200; sizes: 1.0, 0.5, 0.25, 2.0 => total = 3.75
		$this->wpdb->shouldReceive( 'get_var' )->andReturn(
			'100', '1.0',
			'50',  '0.5',
			'10',  '0.25',
			'200', '2.0'
		);

		$total = $this->db_stats->get_total_size_mb();

		$this->assertSame( 3.75, $total );
	}

	/**
	 * Test that get_total_size_mb treats null sizes as 0.
	 */
	public function test_get_total_size_mb_treats_null_size_as_zero(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function( $query ) {
			return $query;
		} );
		// sizes: 1.0, null, 0.5, null => total = 1.5
		$this->wpdb->shouldReceive( 'get_var' )->andReturn(
			'0', '1.0',
			'0', null,
			'0', '0.5',
			'0', null
		);

		$total = $this->db_stats->get_total_size_mb();

		$this->assertSame( 1.5, $total );
	}
}
