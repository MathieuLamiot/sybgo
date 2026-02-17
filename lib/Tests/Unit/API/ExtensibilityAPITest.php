<?php
/**
 * API Functions Test
 *
 * @package Rocket\Sybgo\Tests\Unit\API
 */

declare(strict_types=1);

namespace Rocket\Sybgo\Tests\Unit\API;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Test standalone API functions (sybgo_track_event, etc.).
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

		// Load the API functions file.
		require_once dirname( __DIR__, 3 ) . '/api/functions.php';

		$this->event_repo = Mockery::mock( 'Rocket\Sybgo\Database\Event_Repository' );

		// Initialize API with mock repo.
		sybgo_init_api( $this->event_repo );

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
		// Reset the API.
		global $sybgo_api_event_repo;
		$sybgo_api_event_repo = null;

		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * Test sybgo_track_event() successfully tracks valid event.
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

		$result = sybgo_track_event( $event_type, $event_data );

		$this->assertEquals( 1, $result );
	}

	/**
	 * Test sybgo_track_event() with source plugin.
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
				return $data['source_plugin'] === 'woocommerce';
			} ) )
			->andReturn( 1 );

		Functions\expect( 'do_action' );

		$result = sybgo_track_event( $event_type, $event_data, 'woocommerce' );

		$this->assertEquals( 1, $result );
	}

	/**
	 * Test sybgo_track_event() returns false when missing action.
	 *
	 * @return void
	 */
	public function test_track_event_missing_action() {
		$event_data = [
			'object' => [ 'type' => 'order', 'id' => 123 ],
		];

		$result = sybgo_track_event( 'test_event', $event_data );

		$this->assertFalse( $result );
	}

	/**
	 * Test sybgo_track_event() returns false when missing object.
	 *
	 * @return void
	 */
	public function test_track_event_missing_object() {
		$event_data = [
			'action' => 'created',
		];

		$result = sybgo_track_event( 'test_event', $event_data );

		$this->assertFalse( $result );
	}

	/**
	 * Test sybgo_track_event() returns false when missing object type.
	 *
	 * @return void
	 */
	public function test_track_event_missing_object_type() {
		$event_data = [
			'action' => 'created',
			'object' => [ 'id' => 123 ],
		];

		$result = sybgo_track_event( 'test_event', $event_data );

		$this->assertFalse( $result );
	}

	/**
	 * Test sybgo_track_event() returns false when object not array.
	 *
	 * @return void
	 */
	public function test_track_event_invalid_object_type() {
		$event_data = [
			'action' => 'created',
			'object' => 'not an array',
		];

		$result = sybgo_track_event( 'test_event', $event_data );

		$this->assertFalse( $result );
	}

	/**
	 * Test sybgo_track_event() returns false when context not array.
	 *
	 * @return void
	 */
	public function test_track_event_invalid_context_type() {
		$event_data = [
			'action' => 'created',
			'object' => [ 'type' => 'order', 'id' => 123 ],
			'context' => 'not an array',
		];

		$result = sybgo_track_event( 'test_event', $event_data );

		$this->assertFalse( $result );
	}

	/**
	 * Test sybgo_track_event() returns false when metadata not array.
	 *
	 * @return void
	 */
	public function test_track_event_invalid_metadata_type() {
		$event_data = [
			'action' => 'created',
			'object' => [ 'type' => 'order', 'id' => 123 ],
			'metadata' => 'not an array',
		];

		$result = sybgo_track_event( 'test_event', $event_data );

		$this->assertFalse( $result );
	}

	/**
	 * Test sybgo_track_event() returns false when API not initialized.
	 *
	 * @return void
	 */
	public function test_track_event_not_initialized() {
		global $sybgo_api_event_repo;
		$sybgo_api_event_repo = null;

		$event_data = [
			'action' => 'created',
			'object' => [ 'type' => 'order', 'id' => 123 ],
		];

		$result = sybgo_track_event( 'test_event', $event_data );

		$this->assertFalse( $result );
	}

	/**
	 * Test sybgo_is_active() returns true when API is initialized.
	 *
	 * @return void
	 */
	public function test_sybgo_is_active() {
		$this->assertTrue( sybgo_is_active() );
	}

	/**
	 * Test sybgo_is_active() returns false when API is not initialized.
	 *
	 * @return void
	 */
	public function test_sybgo_is_not_active_when_not_initialized() {
		global $sybgo_api_event_repo;
		$sybgo_api_event_repo = null;

		$this->assertFalse( sybgo_is_active() );
	}
}
