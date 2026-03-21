<?php
/**
 * DatabaseManager Cleanup Unit Tests
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

/**
 * DatabaseManager Cleanup Test Case
 */
class DatabaseManagerCleanupTest extends TestCase {

	/**
	 * DatabaseManager instance.
	 *
	 * @var DatabaseManager
	 */
	private DatabaseManager $db_manager;

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

		$this->db_manager = new DatabaseManager();
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
	 * Test that cleanup uses the provided days parameter to build the cutoff date.
	 */
	public function test_cleanup_uses_provided_days_parameter(): void {
		$days        = 30;
		$cutoff      = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$cutoff_date = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with( Mockery::type( 'string' ), $cutoff )
			->andReturn( "DELETE FROM wp_sybgo_events WHERE event_timestamp < '{$cutoff}'" );

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with( Mockery::type( 'string' ), $cutoff_date )
			->andReturn( "DELETE FROM wp_sybgo_aggregated_events WHERE date < '{$cutoff_date}'" );

		$this->wpdb->shouldReceive( 'query' )->twice()->andReturn( 5, 3 );

		$this->db_manager->cleanup_old_events( $days );

		// Assert Mockery expectations were satisfied (implicit via tearDown).
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test that cleanup defaults to 90 days when no argument is provided.
	 */
	public function test_cleanup_defaults_to_90_days(): void {
		$cutoff      = gmdate( 'Y-m-d H:i:s', strtotime( '-90 days' ) );
		$cutoff_date = gmdate( 'Y-m-d', strtotime( '-90 days' ) );

		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with( Mockery::type( 'string' ), $cutoff )
			->andReturn( "DELETE FROM wp_sybgo_events WHERE event_timestamp < '{$cutoff}'" );

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with( Mockery::type( 'string' ), $cutoff_date )
			->andReturn( "DELETE FROM wp_sybgo_aggregated_events WHERE date < '{$cutoff_date}'" );

		$this->wpdb->shouldReceive( 'query' )->twice()->andReturn( 0, 0 );

		$this->db_manager->cleanup_old_events();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test that cleanup runs queries against both the events and aggregated_events tables.
	 */
	public function test_cleanup_deletes_from_both_tables(): void {
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$this->wpdb->shouldReceive( 'prepare' )->twice()->andReturnUsing( function( $q ) { return $q; } );
		$this->wpdb->shouldReceive( 'query' )->twice()->andReturn( 5, 3 );

		$this->db_manager->cleanup_old_events( 90 );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test that cleanup returns the total row count deleted from both tables.
	 */
	public function test_cleanup_returns_total_deleted_count(): void {
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$this->wpdb->shouldReceive( 'prepare' )->twice()->andReturnUsing( function( $q ) { return $q; } );
		$this->wpdb->shouldReceive( 'query' )->twice()->andReturn( 5, 3 );

		$result = $this->db_manager->cleanup_old_events( 90 );

		$this->assertSame( 8, $result );
	}

	/**
	 * Test that cleanup clears cache for both events and aggregated_events.
	 */
	public function test_cleanup_calls_cache_delete_for_both_tables(): void {
		$this->wpdb->shouldReceive( 'prepare' )->twice()->andReturnUsing( function( $q ) { return $q; } );
		$this->wpdb->shouldReceive( 'query' )->twice()->andReturn( 0, 0 );

		Functions\expect( 'wp_cache_delete' )
			->once()
			->with( 'sybgo_events', 'sybgo_cache' );

		Functions\expect( 'wp_cache_delete' )
			->once()
			->with( 'sybgo_aggregated_events', 'sybgo_cache' );

		$this->db_manager->cleanup_old_events( 90 );

		// Assertions are verified by Brain Monkey expectations above.
		$this->addToAssertionCount( 2 );
	}
}
