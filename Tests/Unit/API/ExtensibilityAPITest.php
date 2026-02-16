<?php
/**
 * Extensibility API Test
 *
 * @package Rocket\Sybgo\Tests\Unit\API
 */

declare(strict_types=1);

namespace Rocket\Sybgo\Tests\Unit\API;

use Rocket\Sybgo\API\Extensibility_API;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Test Extensibility_API class.
 */
class ExtensibilityAPITest extends TestCase {
	/**
	 * Event repository mock.
	 *
	 * @var object
	 */
	private $event_repo;

	/**
	 * Set up test environment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->event_repo = Mockery::mock( 'stdClass' );

		// Initialize API with mock repo.
		Extensibility_API::init( $this->event_repo );

		// Mock WordPress functions.
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
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
	 * Test track_event() successfully tracks valid event.
	 *
	 * @return void
	 */
	public function test_track_event_success() {
		$event_type = 'woocommerce_order';
		$event_data = [
			'action' => 'created',
			'object' => [
				'type'  => 'order',
				'id'    => 123,
				'total' => 99.99,
			],
			'context' => [
				'user_id'   => 1,
				'user_name' => 'john',
			],
			'metadata' => [
				'status' => 'pending',
				'items'  => 5,
			],
		];

		Functions\expect( 'apply_filters' )
			->once()
			->with( 'sybgo_before_track_event', $event_data, $event_type )
			->andReturn( $event_data );

		$this->event_repo->shouldReceive( 'create' )
			->once()
			->with( Mockery::on( function( $data ) use ( $event_type, $event_data ) {
				return $data['event_type'] === $event_type
					&& $data['event_data'] === $event_data;
			} ) )
			->andReturn( 1 );

		Functions\expect( 'do_action' )
			->once()
			->with( 'sybgo_event_tracked', 1, $event_type, $event_data );

		$result = Extensibility_API::track_event( $event_type, $event_data );

		$this->assertEquals( 1, $result );
	}

	/**
	 * Test track_event() with source plugin.
	 *
	 * @return void
	 */
	public function test_track_event_with_source_plugin() {
		$event_type = 'woocommerce_order';
		$event_data = [
			'action' => 'created',
			'object' => [ 'type' => 'order', 'id' => 123 ],
		];

		Functions\expect( 'apply_filters' )
			->once()
			->andReturnUsing( function( $hook, $data ) {
				return $data;
			} );

		$this->event_repo->shouldReceive( 'create' )
			->once()
			->with( Mockery::on( function( $data ) {
				return $data['event_data']['source_plugin'] === 'woocommerce';
			} ) )
			->andReturn( 1 );

		Functions\expect( 'do_action' );

		$result = Extensibility_API::track_event( $event_type, $event_data, 'woocommerce' );

		$this->assertEquals( 1, $result );
	}

	/**
	 * Test track_event() returns false when missing action.
	 *
	 * @return void
	 */
	public function test_track_event_missing_action() {
		$event_data = [
			'object' => [ 'type' => 'order', 'id' => 123 ],
		];

		$result = Extensibility_API::track_event( 'test_event', $event_data );

		$this->assertFalse( $result );
	}

	/**
	 * Test track_event() returns false when missing object.
	 *
	 * @return void
	 */
	public function test_track_event_missing_object() {
		$event_data = [
			'action' => 'created',
		];

		$result = Extensibility_API::track_event( 'test_event', $event_data );

		$this->assertFalse( $result );
	}

	/**
	 * Test track_event() returns false when missing object type.
	 *
	 * @return void
	 */
	public function test_track_event_missing_object_type() {
		$event_data = [
			'action' => 'created',
			'object' => [ 'id' => 123 ],
		];

		$result = Extensibility_API::track_event( 'test_event', $event_data );

		$this->assertFalse( $result );
	}

	/**
	 * Test track_event() returns false when object not array.
	 *
	 * @return void
	 */
	public function test_track_event_invalid_object_type() {
		$event_data = [
			'action' => 'created',
			'object' => 'not an array',
		];

		$result = Extensibility_API::track_event( 'test_event', $event_data );

		$this->assertFalse( $result );
	}

	/**
	 * Test track_event() returns false when context not array.
	 *
	 * @return void
	 */
	public function test_track_event_invalid_context_type() {
		$event_data = [
			'action' => 'created',
			'object' => [ 'type' => 'order', 'id' => 123 ],
			'context' => 'not an array',
		];

		$result = Extensibility_API::track_event( 'test_event', $event_data );

		$this->assertFalse( $result );
	}

	/**
	 * Test track_event() returns false when metadata not array.
	 *
	 * @return void
	 */
	public function test_track_event_invalid_metadata_type() {
		$event_data = [
			'action' => 'created',
			'object' => [ 'type' => 'order', 'id' => 123 ],
			'metadata' => 'not an array',
		];

		$result = Extensibility_API::track_event( 'test_event', $event_data );

		$this->assertFalse( $result );
	}

	/**
	 * Test register_event_type() registers successfully.
	 *
	 * @return void
	 */
	public function test_register_event_type() {
		$callback = function( $event_data ) {
			return 'Event description';
		};

		Functions\expect( 'do_action' )
			->once()
			->with( 'sybgo_event_type_registered', 'custom_event', $callback );

		Extensibility_API::register_event_type( 'custom_event', $callback );

		// Verify registration via Event_Registry.
		$this->assertTrue( Extensibility_API::is_event_type_registered( 'custom_event' ) );
	}

	/**
	 * Test is_event_type_registered() returns correct status.
	 *
	 * @return void
	 */
	public function test_is_event_type_registered() {
		$callback = function( $event_data ) {
			return 'Description';
		};

		Functions\expect( 'do_action' );

		Extensibility_API::register_event_type( 'test_event', $callback );

		$this->assertTrue( Extensibility_API::is_event_type_registered( 'test_event' ) );
		$this->assertFalse( Extensibility_API::is_event_type_registered( 'nonexistent_event' ) );
	}

	/**
	 * Test get_registered_event_types() returns all types.
	 *
	 * @return void
	 */
	public function test_get_registered_event_types() {
		Functions\expect( 'do_action' );

		Extensibility_API::register_event_type( 'event1', function() {
			return 'Desc1';
		} );
		Extensibility_API::register_event_type( 'event2', function() {
			return 'Desc2';
		} );

		$types = Extensibility_API::get_registered_event_types();

		$this->assertContains( 'event1', $types );
		$this->assertContains( 'event2', $types );
	}

	/**
	 * Test add_event_filter() helper method.
	 *
	 * @return void
	 */
	public function test_add_event_filter() {
		$callback = function( $data, $type ) {
			return $data;
		};

		Functions\expect( 'add_filter' )
			->once()
			->with( 'sybgo_before_track_event', $callback, 10, 2 );

		Extensibility_API::add_event_filter( $callback );

		$this->assertTrue( true ); // Assertion to avoid risky test.
	}

	/**
	 * Test add_event_action() helper method.
	 *
	 * @return void
	 */
	public function test_add_event_action() {
		$callback = function( $event_id, $type, $data ) {
			// Do something.
		};

		Functions\expect( 'add_action' )
			->once()
			->with( 'sybgo_event_tracked', $callback, 10, 3 );

		Extensibility_API::add_event_action( $callback );

		$this->assertTrue( true ); // Assertion to avoid risky test.
	}

	/**
	 * Test add_report_summary_filter() helper method.
	 *
	 * @return void
	 */
	public function test_add_report_summary_filter() {
		$callback = function( $summary, $report_id ) {
			return $summary;
		};

		Functions\expect( 'add_filter' )
			->once()
			->with( 'sybgo_report_summary', $callback, 10, 2 );

		Extensibility_API::add_report_summary_filter( $callback );

		$this->assertTrue( true ); // Assertion to avoid risky test.
	}

	/**
	 * Test add_email_recipients_filter() helper method.
	 *
	 * @return void
	 */
	public function test_add_email_recipients_filter() {
		$callback = function( $recipients, $report ) {
			return $recipients;
		};

		Functions\expect( 'add_filter' )
			->once()
			->with( 'sybgo_email_recipients', $callback, 10, 2 );

		Extensibility_API::add_email_recipients_filter( $callback );

		$this->assertTrue( true ); // Assertion to avoid risky test.
	}
}
