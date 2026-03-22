<?php
/**
 * Abstract Singular Event Unit Tests
 *
 * @package Sybgo\Tests\Unit\Events
 */

namespace Sybgo\Tests\Unit\Events;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Sybgo\Events\Abstracts\Abstract_Singular_Event;

/**
 * Concrete test double for Abstract_Singular_Event.
 */
class Concrete_Singular_Event extends Abstract_Singular_Event {
	public function register_hooks(): void {}

	public function register_event_types( array $types ): array {
		return $types;
	}

	/**
	 * Expose record() publicly for testing.
	 *
	 * @param string               $event_type Event type.
	 * @param array<string, mixed> $event_data Event data.
	 * @param string               $source     Source plugin.
	 */
	public function test_record( string $event_type, array $event_data, string $source = 'core' ): void {
		$this->record( $event_type, $event_data, $source );
	}

	/**
	 * Expose is_throttled() publicly for testing.
	 *
	 * @param string $event_type Event type.
	 * @param int    $object_id  Object ID.
	 * @param int    $seconds    Throttle window.
	 */
	public function test_is_throttled( string $event_type, int $object_id, int $seconds ): bool {
		return $this->is_throttled( $event_type, $object_id, $seconds );
	}
}

/**
 * Abstract Singular Event Test Case
 */
class AbstractSingularEventTest extends TestCase {

	/**
	 * Concrete event instance.
	 *
	 * @var Concrete_Singular_Event
	 */
	private $event;

	/**
	 * Mock event repository.
	 *
	 * @var Mockery\MockInterface
	 */
	private $event_repo;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->event_repo = Mockery::mock( 'Sybgo\Database\Event_Repository' );
		$this->event      = new Concrete_Singular_Event( $this->event_repo );
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
	 * Test record() calls event_repo->create() with the correct data.
	 */
	public function test_record_calls_create() {
		$event_data = array( 'action' => 'published' );

		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'do_action' )->justReturn( null );

		$this->event_repo
			->shouldReceive( 'create' )
			->once()
			->with( Mockery::on( function ( $args ) use ( $event_data ) {
				$this->assertSame( 'post_published', $args['event_type'] );
				$this->assertSame( $event_data, $args['event_data'] );
				$this->assertSame( 'core', $args['source_plugin'] );
				return true;
			} ) )
			->andReturn( 1 );

		$this->event->test_record( 'post_published', $event_data );
	}

	/**
	 * Test record() with custom source plugin.
	 */
	public function test_record_with_custom_source() {
		$event_data = array( 'action' => 'custom' );

		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'do_action' )->justReturn( null );

		$this->event_repo
			->shouldReceive( 'create' )
			->once()
			->with( Mockery::on( function ( $args ) {
				$this->assertSame( 'my-plugin', $args['source_plugin'] );
				return true;
			} ) )
			->andReturn( 1 );

		$this->event->test_record( 'custom_event', $event_data, 'my-plugin' );
	}

	/**
	 * Test record() fires sybgo_event_recorded action after creation.
	 */
	public function test_record_fires_event_recorded_action() {
		$event_data = array( 'action' => 'published' );

		Functions\when( 'apply_filters' )->returnArg( 2 );

		$this->event_repo
			->shouldReceive( 'create' )
			->once()
			->andReturn( 42 );

		Actions\expectDone( 'sybgo_event_recorded' )
			->once()
			->with( 42, 'post_published', Mockery::any() );

		$this->event->test_record( 'post_published', $event_data );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test record() skips create when sybgo_should_track_event returns false.
	 */
	public function test_record_skips_when_should_not_track() {
		$event_data = array( 'action' => 'published' );

		Functions\when( 'apply_filters' )->alias( function( $tag, $value ) {
			if ( 'sybgo_should_track_event' === $tag ) {
				return false;
			}
			return $value;
		} );

		$this->event_repo->shouldNotReceive( 'create' );

		$this->event->test_record( 'post_published', $event_data );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test record() does not fire sybgo_event_recorded when create returns false.
	 */
	public function test_record_does_not_fire_action_on_create_failure() {
		$event_data = array( 'action' => 'published' );

		Functions\when( 'apply_filters' )->returnArg( 2 );

		$this->event_repo
			->shouldReceive( 'create' )
			->once()
			->andReturn( false );

		Actions\expectDone( 'sybgo_event_recorded' )->never();

		$this->event->test_record( 'post_published', $event_data );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test is_throttled() returns false when no prior event exists.
	 */
	public function test_is_throttled_false_when_no_prior_event() {
		$this->event_repo
			->shouldReceive( 'get_last_event_for_object' )
			->once()
			->with( 'post_published', 123 )
			->andReturn( null );

		$result = $this->event->test_is_throttled( 'post_published', 123, 3600 );

		$this->assertFalse( $result );
	}

	/**
	 * Test is_throttled() returns true when within the throttle window.
	 */
	public function test_is_throttled_true_within_window() {
		$recent_timestamp = gmdate( 'Y-m-d H:i:s', time() - 1800 ); // 30 minutes ago.

		$this->event_repo
			->shouldReceive( 'get_last_event_for_object' )
			->once()
			->with( 'post_published', 123 )
			->andReturn( array( 'event_timestamp' => $recent_timestamp ) );

		$result = $this->event->test_is_throttled( 'post_published', 123, 3600 );

		$this->assertTrue( $result );
	}

	/**
	 * Test is_throttled() returns false when outside the throttle window.
	 */
	public function test_is_throttled_false_outside_window() {
		$old_timestamp = gmdate( 'Y-m-d H:i:s', time() - 7200 ); // 2 hours ago.

		$this->event_repo
			->shouldReceive( 'get_last_event_for_object' )
			->once()
			->with( 'post_published', 123 )
			->andReturn( array( 'event_timestamp' => $old_timestamp ) );

		$result = $this->event->test_is_throttled( 'post_published', 123, 3600 );

		$this->assertFalse( $result );
	}
}
