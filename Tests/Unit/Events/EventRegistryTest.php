<?php
/**
 * Event Registry Unit Tests
 *
 * @package Rocket\Sybgo\Tests\Unit\Events
 */

namespace Rocket\Sybgo\Tests\Unit\Events;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use Rocket\Sybgo\Events\Event_Registry;

/**
 * Event Registry Test Case
 */
class EventRegistryTest extends TestCase {

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Clear static registry between tests.
		$reflection = new \ReflectionClass( 'Rocket\Sybgo\Events\Event_Registry' );
		$property   = $reflection->getProperty( 'event_types' );
		$property->setAccessible( true );
		$property->setValue( array() );
	}

	/**
	 * Tear down test environment.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Test register_event_type stores callback.
	 */
	public function test_register_event_type() {
		$callback = function( $event_data ) {
			return 'Test description';
		};

		Event_Registry::register_event_type( 'test_event', $callback );

		$this->assertTrue( Event_Registry::is_registered( 'test_event' ) );
	}

	/**
	 * Test describe_event calls registered callback.
	 */
	public function test_describe_event_calls_callback() {
		$event_data = array( 'test' => 'data' );

		$callback = function( $data ) {
			return 'Description: ' . $data['test'];
		};

		Event_Registry::register_event_type( 'test_event', $callback );

		$description = Event_Registry::describe_event( 'test_event', $event_data );

		$this->assertEquals( 'Description: data', $description );
	}

	/**
	 * Test describe_event with unregistered type.
	 */
	public function test_describe_event_unregistered() {
		$description = Event_Registry::describe_event( 'nonexistent', array() );

		$this->assertStringContainsString( 'Unknown event type', $description );
		$this->assertStringContainsString( 'nonexistent', $description );
	}

	/**
	 * Test get_registered_types returns all types.
	 */
	public function test_get_registered_types() {
		Event_Registry::register_event_type( 'type1', function() {} );
		Event_Registry::register_event_type( 'type2', function() {} );
		Event_Registry::register_event_type( 'type3', function() {} );

		$types = Event_Registry::get_registered_types();

		$this->assertIsArray( $types );
		$this->assertCount( 3, $types );
		$this->assertContains( 'type1', $types );
		$this->assertContains( 'type2', $types );
		$this->assertContains( 'type3', $types );
	}

	/**
	 * Test is_registered returns correct boolean.
	 */
	public function test_is_registered() {
		Event_Registry::register_event_type( 'existing_event', function() {} );

		$this->assertTrue( Event_Registry::is_registered( 'existing_event' ) );
		$this->assertFalse( Event_Registry::is_registered( 'nonexistent_event' ) );
	}

	/**
	 * Test get_ai_context_for_events generates context.
	 */
	public function test_get_ai_context_for_events() {
		// Register test event type.
		Event_Registry::register_event_type( 'post_published', function( $event_data ) {
			return "Event Type: Post Published\nDescription: Test description";
		} );

		Event_Registry::register_event_type( 'user_registered', function( $event_data ) {
			return "Event Type: User Registered\nDescription: User joined";
		} );

		// Create test events.
		$events = array(
			array(
				'event_type' => 'post_published',
				'event_data' => json_encode( array( 'title' => 'Test Post' ) ),
			),
			array(
				'event_type' => 'post_published',
				'event_data' => json_encode( array( 'title' => 'Another Post' ) ),
			),
			array(
				'event_type' => 'user_registered',
				'event_data' => json_encode( array( 'username' => 'testuser' ) ),
			),
		);

		$context = Event_Registry::get_ai_context_for_events( $events );

		$this->assertIsString( $context );
		$this->assertStringContainsString( 'Event Types Reference', $context );
		$this->assertStringContainsString( 'Post Published', $context );
		$this->assertStringContainsString( 'User Registered', $context );
		$this->assertStringContainsString( '---', $context ); // Separator.
	}

	/**
	 * Test AI context includes only unique event types.
	 */
	public function test_get_ai_context_unique_types() {
		Event_Registry::register_event_type( 'post_published', function() {
			return 'Post description';
		} );

		// 3 events of same type.
		$events = array(
			array(
				'event_type' => 'post_published',
				'event_data' => '{}',
			),
			array(
				'event_type' => 'post_published',
				'event_data' => '{}',
			),
			array(
				'event_type' => 'post_published',
				'event_data' => '{}',
			),
		);

		$context = Event_Registry::get_ai_context_for_events( $events );

		// Should only describe post_published once.
		$count = substr_count( $context, 'Post description' );
		$this->assertEquals( 1, $count );
	}

	/**
	 * Test describe callback receives event data.
	 */
	public function test_describe_callback_receives_data() {
		$received_data = null;

		$callback = function( $event_data ) use ( &$received_data ) {
			$received_data = $event_data;
			return 'Description';
		};

		Event_Registry::register_event_type( 'test_event', $callback );

		$test_data = array(
			'action'   => 'published',
			'metadata' => array( 'key' => 'value' ),
		);

		Event_Registry::describe_event( 'test_event', $test_data );

		$this->assertEquals( $test_data, $received_data );
	}
}
