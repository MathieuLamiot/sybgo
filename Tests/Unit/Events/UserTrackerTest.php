<?php
/**
 * User Tracker Test
 *
 * @package Rocket\Sybgo\Tests\Unit\Events
 */

declare(strict_types=1);

namespace Rocket\Sybgo\Tests\Unit\Events;

use Rocket\Sybgo\Events\Trackers\User_Tracker;
use Rocket\Sybgo\Database\Event_Repository;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Test User_Tracker class.
 */
class UserTrackerTest extends TestCase {
	/**
	 * Event repository mock.
	 *
	 * @var Event_Repository
	 */
	private $event_repo;

	/**
	 * User tracker instance.
	 *
	 * @var User_Tracker
	 */
	private User_Tracker $user_tracker;

	/**
	 * Set up test environment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->event_repo   = Mockery::mock( Event_Repository::class );
		$this->user_tracker = new User_Tracker( $this->event_repo );

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
	 * Test track_user_registration() tracks new user registration.
	 *
	 * @return void
	 */
	public function test_track_user_registration() {
		$user_id = 5;
		$user    = (object) [
			'ID'         => 5,
			'user_login' => 'john_doe',
			'user_email' => 'john@example.com',
			'roles'      => [ 'subscriber' ],
		];

		Functions\expect( 'get_userdata' )
			->once()
			->with( $user_id )
			->andReturn( $user );

		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$this->event_repo->shouldReceive( 'create' )
			->once()
			->with( Mockery::on( function( $data ) {
				$event_data = $data['event_data'];
				return $data['event_type'] === 'user_registered'
					&& $event_data['action'] === 'registered'
					&& $event_data['object']['type'] === 'user'
					&& $event_data['object']['id'] === 5
					&& $event_data['object']['username'] === 'john_doe'
					&& $event_data['object']['email'] === 'john@example.com'
					&& $event_data['metadata']['role'] === 'subscriber'
					&& $event_data['metadata']['registration_method'] === 'admin_created';
			} ) )
			->andReturn( 1 );

		$this->user_tracker->track_user_registration( $user_id );

		$this->assertTrue( true );
	}

	/**
	 * Test track_user_registration() handles self-signup.
	 *
	 * @return void
	 */
	public function test_track_user_registration_self_signup() {
		$user_id = 5;
		$user    = (object) [
			'ID'         => 5,
			'user_login' => 'john_doe',
			'user_email' => 'john@example.com',
			'roles'      => [ 'subscriber' ],
		];

		Functions\expect( 'get_userdata' )
			->once()
			->with( $user_id )
			->andReturn( $user );

		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		$this->event_repo->shouldReceive( 'create' )
			->once()
			->with( Mockery::on( function( $data ) {
				$event_data = $data['event_data'];
				return $data['event_type'] === 'user_registered'
					&& $event_data['metadata']['registration_method'] === 'self_signup'
					&& $event_data['context']['created_by_id'] === null;
			} ) )
			->andReturn( 1 );

		$this->user_tracker->track_user_registration( $user_id );

		$this->assertTrue( true );
	}

	/**
	 * Test track_user_role_change() tracks role changes.
	 *
	 * @return void
	 */
	public function test_track_user_role_change() {
		$user_id  = 5;
		$new_role = 'editor';
		$old_roles = [ 'subscriber' ];

		$user = (object) [
			'ID'         => 5,
			'user_login' => 'john_doe',
			'user_email' => 'john@example.com',
			'roles'      => [ 'editor' ],
		];

		Functions\expect( 'get_userdata' )
			->once()
			->with( $user_id )
			->andReturn( $user );

		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$this->event_repo->shouldReceive( 'create' )
			->once()
			->with( Mockery::on( function( $data ) use ( $old_roles, $new_role ) {
				$event_data = $data['event_data'];
				return $data['event_type'] === 'user_role_changed'
					&& $event_data['action'] === 'role_changed'
					&& $event_data['object']['type'] === 'user'
					&& $event_data['object']['id'] === 5
					&& $event_data['metadata']['old_role'] === $old_roles[0]
					&& $event_data['metadata']['new_role'] === $new_role;
			} ) )
			->andReturn( 1 );

		$this->user_tracker->track_role_change( $user_id, $new_role, $old_roles );

		$this->assertTrue( true );
	}

	/**
	 * Test track_user_deletion() tracks user deletion.
	 *
	 * @return void
	 */
	public function test_track_user_deletion() {
		$user_id = 5;
		$user    = (object) [
			'ID'         => 5,
			'user_login' => 'john_doe',
			'user_email' => 'john@example.com',
			'roles'      => [ 'subscriber' ],
		];

		Functions\expect( 'get_userdata' )
			->once()
			->with( $user_id )
			->andReturn( $user );

		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$this->event_repo->shouldReceive( 'create' )
			->once()
			->with( Mockery::on( function( $data ) {
				$event_data = $data['event_data'];
				return $data['event_type'] === 'user_deleted'
					&& $event_data['action'] === 'deleted'
					&& $event_data['object']['type'] === 'user'
					&& $event_data['object']['id'] === 5;
			} ) )
			->andReturn( 1 );

		$this->user_tracker->track_user_deletion( $user_id, null );

		$this->assertTrue( true );
	}

	/**
	 * Test user tracker handles invalid user data gracefully.
	 *
	 * @return void
	 */
	public function test_handles_invalid_user_gracefully() {
		Functions\expect( 'get_userdata' )
			->once()
			->with( 999 )
			->andReturn( false );

		$this->event_repo->shouldNotReceive( 'create' );

		$this->user_tracker->track_user_registration( 999 );

		$this->assertTrue( true );
	}
}
