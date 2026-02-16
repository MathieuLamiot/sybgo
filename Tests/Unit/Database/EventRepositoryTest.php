<?php
/**
 * Event Repository Unit Tests
 *
 * @package Rocket\Sybgo\Tests\Unit\Database
 */

namespace Rocket\Sybgo\Tests\Unit\Database;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Rocket\Sybgo\Database\Event_Repository;

/**
 * Event Repository Test Case
 */
class EventRepositoryTest extends TestCase {

	/**
	 * Event repository instance.
	 *
	 * @var Event_Repository
	 */
	private $event_repo;

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
		$this->wpdb = Mockery::mock( '\wpdb' );
		$this->wpdb->prefix = 'wp_';
		$GLOBALS['wpdb'] = $this->wpdb;

		// Create repository instance.
		$this->event_repo = new Event_Repository( 'wp_sybgo_events' );

		// Mock WordPress functions.
		Functions\when( 'current_time' )->justReturn( '2026-02-16 12:00:00' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_parse_args' )->alias( function( $args, $defaults ) {
			return array_merge( $defaults, $args );
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
	 * Test create method.
	 */
	public function test_create_event() {
		$event_data = array(
			'event_type' => 'post_published',
			'object_id'  => 123,
			'event_data' => array( 'action' => 'published' ),
		);

		// Mock wpdb->insert.
		$this->wpdb->insert_id = 456;
		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->with(
				'wp_sybgo_events',
				Mockery::on( function( $data ) {
					$this->assertEquals( 'post_published', $data['event_type'] );
					$this->assertEquals( 123, $data['object_id'] );
					$this->assertStringContainsString( 'published', $data['event_data'] );
					return true;
				} )
			)
			->andReturn( 1 );

		$result = $this->event_repo->create( $event_data );

		$this->assertEquals( 456, $result );
	}

	/**
	 * Test create with array event_data converts to JSON.
	 */
	public function test_create_converts_event_data_to_json() {
		$event_data = array(
			'event_type' => 'post_published',
			'event_data' => array( 'action' => 'published', 'test' => 'value' ),
		);

		$this->wpdb->insert_id = 1;
		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->with(
				'wp_sybgo_events',
				Mockery::on( function( $data ) {
					// Verify event_data is JSON string.
					$this->assertIsString( $data['event_data'] );
					$decoded = json_decode( $data['event_data'], true );
					$this->assertEquals( 'published', $decoded['action'] );
					$this->assertEquals( 'value', $decoded['test'] );
					return true;
				} )
			)
			->andReturn( 1 );

		$this->event_repo->create( $event_data );
	}

	/**
	 * Test get_by_report with null report_id (unassigned events).
	 */
	public function test_get_by_report_unassigned() {
		$expected_events = array(
			array( 'id' => 1, 'event_type' => 'post_published', 'report_id' => null ),
			array( 'id' => 2, 'event_type' => 'user_registered', 'report_id' => null ),
		);

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturn( 'PREPARED SQL' );

		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->with( 'PREPARED SQL', ARRAY_A )
			->andReturn( $expected_events );

		$result = $this->event_repo->get_by_report( null, 100, 0 );

		$this->assertEquals( $expected_events, $result );
		$this->assertCount( 2, $result );
	}

	/**
	 * Test get_by_report with specific report_id.
	 */
	public function test_get_by_report_assigned() {
		$expected_events = array(
			array( 'id' => 1, 'event_type' => 'post_published', 'report_id' => 5 ),
		);

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturn( 'PREPARED SQL' );

		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->with( 'PREPARED SQL', ARRAY_A )
			->andReturn( $expected_events );

		$result = $this->event_repo->get_by_report( 5, 100, 0 );

		$this->assertEquals( $expected_events, $result );
	}

	/**
	 * Test get_recent returns cached results.
	 */
	public function test_get_recent_uses_cache() {
		$cached_events = array(
			array( 'id' => 1, 'event_type' => 'post_published' ),
		);

		Functions\when( 'wp_cache_get' )
			->justReturn( $cached_events );

		// Should not call database if cached.
		$this->wpdb->shouldNotReceive( 'get_results' );

		$result = $this->event_repo->get_recent( 5 );

		$this->assertEquals( $cached_events, $result );
	}

	/**
	 * Test count_by_type aggregates correctly.
	 */
	public function test_count_by_type() {
		$db_results = array(
			array( 'event_type' => 'post_published', 'count' => 12 ),
			array( 'event_type' => 'user_registered', 'count' => 3 ),
			array( 'event_type' => 'comment_posted', 'count' => 28 ),
		);

		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn( $db_results );

		$result = $this->event_repo->count_by_type( null );

		$this->assertIsArray( $result );
		$this->assertEquals( 12, $result['post_published'] );
		$this->assertEquals( 3, $result['user_registered'] );
		$this->assertEquals( 28, $result['comment_posted'] );
	}

	/**
	 * Test assign_to_report updates events.
	 */
	public function test_assign_to_report() {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturn( 'PREPARED SQL' );

		$this->wpdb->shouldReceive( 'query' )
			->once()
			->with( 'PREPARED SQL' )
			->andReturn( 15 ); // 15 events updated.

		$result = $this->event_repo->assign_to_report(
			5,
			'2026-02-10 00:00:00',
			'2026-02-16 23:59:59'
		);

		$this->assertEquals( 15, $result );
	}

	/**
	 * Test get_last_event_for_object for throttling.
	 */
	public function test_get_last_event_for_object() {
		$expected_event = array(
			'id'              => 1,
			'event_type'      => 'post_edited',
			'object_id'       => 123,
			'event_timestamp' => '2026-02-16 11:00:00',
		);

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturn( 'PREPARED SQL' );

		$this->wpdb->shouldReceive( 'get_row' )
			->once()
			->with( 'PREPARED SQL', ARRAY_A )
			->andReturn( $expected_event );

		$result = $this->event_repo->get_last_event_for_object( 'post_edited', 123 );

		$this->assertEquals( $expected_event, $result );
		$this->assertEquals( 123, $result['object_id'] );
	}

	/**
	 * Test get_last_event_for_object returns null when not found.
	 */
	public function test_get_last_event_for_object_not_found() {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturn( 'PREPARED SQL' );

		$this->wpdb->shouldReceive( 'get_row' )
			->once()
			->andReturn( null );

		$result = $this->event_repo->get_last_event_for_object( 'post_edited', 999 );

		$this->assertNull( $result );
	}
}
