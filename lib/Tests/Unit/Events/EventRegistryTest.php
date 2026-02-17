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
	 * Event registry instance.
	 *
	 * @var Event_Registry
	 */
	private Event_Registry $registry;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->registry = new Event_Registry();
	}

	/**
	 * Tear down test environment.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Inject event types directly into the registry's cached property
	 * to bypass the wpm_apply_filters_typesafe call (which can't be mocked
	 * by Brain\Monkey because it's already defined via composer autoload).
	 *
	 * @param array $types Event type definitions.
	 */
	private function inject_event_types( array $types ): void {
		$reflection = new \ReflectionClass( $this->registry );
		$property   = $reflection->getProperty( 'event_types' );
		$property->setAccessible( true );
		$property->setValue( $this->registry, $types );
	}

	/**
	 * Test is_registered returns correct boolean.
	 */
	public function test_is_registered() {
		$this->inject_event_types( array(
			'existing_event' => array( 'icon' => '📝' ),
		) );

		$this->assertTrue( $this->registry->is_registered( 'existing_event' ) );
		$this->assertFalse( $this->registry->is_registered( 'nonexistent_event' ) );
	}

	/**
	 * Test get_registered_types returns all types.
	 */
	public function test_get_registered_types() {
		$this->inject_event_types( array(
			'type1' => array( 'icon' => '📝' ),
			'type2' => array( 'icon' => '👤' ),
			'type3' => array( 'icon' => '💬' ),
		) );

		$types = $this->registry->get_registered_types();

		$this->assertIsArray( $types );
		$this->assertCount( 3, $types );
		$this->assertContains( 'type1', $types );
		$this->assertContains( 'type2', $types );
		$this->assertContains( 'type3', $types );
	}

	/**
	 * Test describe_event calls registered callback.
	 */
	public function test_describe_event_calls_callback() {
		$this->inject_event_types( array(
			'test_event' => array(
				'describe' => function( $data ) {
					return 'Description: ' . $data['test'];
				},
			),
		) );

		$event_data = array( 'test' => 'data' );
		$description = $this->registry->describe_event( 'test_event', $event_data );

		$this->assertEquals( 'Description: data', $description );
	}

	/**
	 * Test describe_event with unregistered type.
	 */
	public function test_describe_event_unregistered() {
		$this->inject_event_types( array() );

		$description = $this->registry->describe_event( 'nonexistent', array() );

		$this->assertStringContainsString( 'Unknown event type', $description );
		$this->assertStringContainsString( 'nonexistent', $description );
	}

	/**
	 * Test get_icon returns registered icon.
	 */
	public function test_get_icon() {
		$this->inject_event_types( array(
			'test_event' => array( 'icon' => '📝' ),
		) );

		$this->assertEquals( '📝', $this->registry->get_icon( 'test_event' ) );
	}

	/**
	 * Test get_icon returns fallback for unregistered type.
	 */
	public function test_get_icon_fallback() {
		$this->inject_event_types( array() );

		$this->assertEquals( '•', $this->registry->get_icon( 'nonexistent' ) );
	}

	/**
	 * Test get_stat_label returns registered label.
	 */
	public function test_get_stat_label() {
		$this->inject_event_types( array(
			'post_published' => array( 'stat_label' => 'Posts Published' ),
		) );

		$this->assertEquals( 'Posts Published', $this->registry->get_stat_label( 'post_published' ) );
	}

	/**
	 * Test get_stat_label fallback for unregistered type.
	 */
	public function test_get_stat_label_fallback() {
		$this->inject_event_types( array() );

		$this->assertEquals( 'Post Published', $this->registry->get_stat_label( 'post_published' ) );
	}

	/**
	 * Test get_short_title calls callable.
	 */
	public function test_get_short_title() {
		$this->inject_event_types( array(
			'post_published' => array(
				'short_title' => function( $data ) {
					return 'Published: ' . ( $data['object']['title'] ?? 'Unknown' );
				},
			),
		) );

		$event_data = array( 'object' => array( 'title' => 'Test Post' ) );
		$title = $this->registry->get_short_title( 'post_published', $event_data );

		$this->assertEquals( 'Published: Test Post', $title );
	}

	/**
	 * Test get_short_title falls back to detailed_title.
	 */
	public function test_get_short_title_fallback_to_detailed() {
		$this->inject_event_types( array(
			'post_published' => array(
				'detailed_title' => function( $data ) {
					return 'Detailed: ' . ( $data['object']['title'] ?? 'Unknown' );
				},
			),
		) );

		$event_data = array( 'object' => array( 'title' => 'Test Post' ) );
		$title = $this->registry->get_short_title( 'post_published', $event_data );

		$this->assertEquals( 'Detailed: Test Post', $title );
	}

	/**
	 * Test get_detailed_title calls callable.
	 */
	public function test_get_detailed_title() {
		$this->inject_event_types( array(
			'post_published' => array(
				'detailed_title' => function( $data ) {
					return 'Post published: ' . ( $data['object']['title'] ?? 'Unknown' );
				},
			),
		) );

		$event_data = array( 'object' => array( 'title' => 'Test Post' ) );
		$title = $this->registry->get_detailed_title( 'post_published', $event_data );

		$this->assertEquals( 'Post published: Test Post', $title );
	}

	/**
	 * Test get_detailed_title fallback for unregistered type.
	 */
	public function test_get_detailed_title_fallback() {
		$this->inject_event_types( array() );

		$title = $this->registry->get_detailed_title( 'post_published', array() );

		$this->assertEquals( 'Post Published', $title );
	}

	/**
	 * Test get_ai_description calls callable.
	 */
	public function test_get_ai_description() {
		$this->inject_event_types( array(
			'post_published' => array(
				'ai_description' => function( $object, $metadata ) {
					return sprintf( 'Published "%s"', $object['title'] ?? 'Unknown' );
				},
			),
		) );

		$description = $this->registry->get_ai_description(
			'post_published',
			array( 'title' => 'Test Post' ),
			array()
		);

		$this->assertEquals( 'Published "Test Post"', $description );
	}

	/**
	 * Test get_ai_description returns empty for unregistered type.
	 */
	public function test_get_ai_description_fallback() {
		$this->inject_event_types( array() );

		$description = $this->registry->get_ai_description( 'nonexistent', array(), array() );

		$this->assertEquals( '', $description );
	}

	/**
	 * Test get_ai_context_for_events generates context.
	 */
	public function test_get_ai_context_for_events() {
		$this->inject_event_types( array(
			'post_published' => array(
				'describe' => function( $event_data ) {
					return "Event Type: Post Published\nDescription: Test description";
				},
			),
			'user_registered' => array(
				'describe' => function( $event_data ) {
					return "Event Type: User Registered\nDescription: User joined";
				},
			),
		) );

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

		$context = $this->registry->get_ai_context_for_events( $events );

		$this->assertIsString( $context );
		$this->assertStringContainsString( 'Event Types Reference', $context );
		$this->assertStringContainsString( 'Post Published', $context );
		$this->assertStringContainsString( 'User Registered', $context );
		$this->assertStringContainsString( '---', $context );
	}

	/**
	 * Test AI context includes only unique event types.
	 */
	public function test_get_ai_context_unique_types() {
		$this->inject_event_types( array(
			'post_published' => array(
				'describe' => function() {
					return 'Post description';
				},
			),
		) );

		$events = array(
			array( 'event_type' => 'post_published', 'event_data' => '{}' ),
			array( 'event_type' => 'post_published', 'event_data' => '{}' ),
			array( 'event_type' => 'post_published', 'event_data' => '{}' ),
		);

		$context = $this->registry->get_ai_context_for_events( $events );

		// Should only describe post_published once.
		$count = substr_count( $context, 'Post description' );
		$this->assertEquals( 1, $count );
	}

	/**
	 * Test describe callback receives event data.
	 */
	public function test_describe_callback_receives_data() {
		$received_data = null;

		$this->inject_event_types( array(
			'test_event' => array(
				'describe' => function( $event_data ) use ( &$received_data ) {
					$received_data = $event_data;
					return 'Description';
				},
			),
		) );

		$test_data = array(
			'action'   => 'published',
			'metadata' => array( 'key' => 'value' ),
		);

		$this->registry->describe_event( 'test_event', $test_data );

		$this->assertEquals( $test_data, $received_data );
	}
}
