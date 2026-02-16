<?php
/**
 * Comment Tracker Test
 *
 * @package Rocket\Sybgo\Tests\Unit\Events
 */

declare(strict_types=1);

namespace Rocket\Sybgo\Tests\Unit\Events;

use Rocket\Sybgo\Events\Trackers\Comment_Tracker;
use Rocket\Sybgo\Database\Event_Repository;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Test Comment_Tracker class.
 */
class CommentTrackerTest extends TestCase {
	/**
	 * Event repository mock.
	 *
	 * @var Event_Repository
	 */
	private $event_repo;

	/**
	 * Comment tracker instance.
	 *
	 * @var Comment_Tracker
	 */
	private Comment_Tracker $comment_tracker;

	/**
	 * Set up test environment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->event_repo      = Mockery::mock( Event_Repository::class );
		$this->comment_tracker = new Comment_Tracker( $this->event_repo );

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
	 * Test track_new_comment() tracks approved comment.
	 *
	 * @return void
	 */
	public function test_track_new_comment_approved() {
		$comment_id = 10;
		$commentdata = [
			'comment_ID'      => 10,
			'comment_post_ID' => 5,
			'comment_author'  => 'John Doe',
			'comment_content' => 'Great post!',
			'user_id'         => 0,
		];

		$comment = (object) [
			'comment_ID'           => 10,
			'comment_post_ID'      => 5,
			'comment_author'       => 'John Doe',
			'comment_author_email' => 'john@example.com',
			'comment_author_url'   => '',
			'comment_content'      => 'Great post!',
			'comment_approved'     => '1',
			'comment_type'         => '',
			'user_id'              => 0,
		];

		$post = (object) [
			'ID'         => 5,
			'post_title' => 'My Blog Post',
		];

		Functions\expect( 'get_comment' )
			->once()
			->with( $comment_id )
			->andReturn( $comment );

		Functions\expect( 'get_post' )
			->once()
			->with( 5 )
			->andReturn( $post );

		Functions\expect( 'wp_strip_all_tags' )
			->once()
			->with( 'Great post!' )
			->andReturn( 'Great post!' );

		$this->event_repo->shouldReceive( 'create' )
			->once()
			->with( Mockery::on( function( $data ) {
				$event_data = $data['event_data'];
				return $data['event_type'] === 'comment_posted'
					&& $data['event_subtype'] === 'comment'
					&& $data['object_id'] === 10
					&& $data['user_id'] === null
					&& $event_data['action'] === 'posted'
					&& $event_data['object']['type'] === 'comment'
					&& $event_data['object']['id'] === 10
					&& $event_data['object']['post_id'] === 5
					&& $event_data['object']['post_title'] === 'My Blog Post'
					&& $event_data['metadata']['status'] === 'approved'
					&& $event_data['metadata']['author_name'] === 'John Doe';
			} ) )
			->andReturn( 1 );

		$this->comment_tracker->track_new_comment( $comment_id, '1', $commentdata );

		$this->assertTrue( true );
	}

	/**
	 * Test track_new_comment() tracks pending comment.
	 *
	 * @return void
	 */
	public function test_track_new_comment_pending() {
		$comment_id = 10;
		$commentdata = [];

		$comment = (object) [
			'comment_ID'           => 10,
			'comment_post_ID'      => 5,
			'comment_author'       => 'John Doe',
			'comment_author_email' => 'john@example.com',
			'comment_author_url'   => '',
			'comment_content'      => 'Great post!',
			'comment_approved'     => '0',
			'comment_type'         => '',
			'user_id'              => 0,
		];

		$post = (object) [
			'ID'         => 5,
			'post_title' => 'My Blog Post',
		];

		Functions\expect( 'get_comment' )
			->once()
			->andReturn( $comment );

		Functions\expect( 'get_post' )
			->once()
			->andReturn( $post );

		Functions\expect( 'wp_strip_all_tags' )
			->once()
			->andReturn( 'Great post!' );

		$this->event_repo->shouldReceive( 'create' )
			->once()
			->with( Mockery::on( function( $data ) {
				return $data['event_data']['metadata']['status'] === 'pending';
			} ) )
			->andReturn( 1 );

		$this->comment_tracker->track_new_comment( $comment_id, '0', $commentdata );

		$this->assertTrue( true );
	}

	/**
	 * Test track_comment_status_change() tracks approval.
	 *
	 * @return void
	 */
	public function test_track_comment_status_change_approved() {
		$comment_id = 10;

		$comment = (object) [
			'comment_ID'           => 10,
			'comment_post_ID'      => 5,
			'comment_author'       => 'John Doe',
			'comment_content'      => 'Great post!',
			'comment_approved'     => '1',
			'user_id'              => 1,
		];

		$post = (object) [
			'ID'         => 5,
			'post_title' => 'My Blog Post',
		];

		Functions\expect( 'get_comment' )
			->once()
			->with( $comment_id )
			->andReturn( $comment );

		Functions\expect( 'get_post' )
			->once()
			->with( 5 )
			->andReturn( $post );

		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$this->event_repo->shouldReceive( 'create' )
			->once()
			->with( Mockery::on( function( $data ) {
				$event_data = $data['event_data'];
				return $data['event_type'] === 'comment_approved'
					&& $data['event_subtype'] === 'comment'
					&& $data['object_id'] === 10
					&& $data['user_id'] === 1
					&& $event_data['action'] === 'approved'
					&& $event_data['metadata']['new_status'] === 'approve';
			} ) )
			->andReturn( 1 );

		$this->comment_tracker->track_comment_status_change( $comment_id, 'approve' );

		$this->assertTrue( true );
	}

	/**
	 * Test track_comment_status_change() tracks spam marking.
	 *
	 * @return void
	 */
	public function test_track_comment_status_change_spam() {
		$comment_id = 10;

		$comment = (object) [
			'comment_ID'      => 10,
			'comment_post_ID' => 5,
			'comment_author'  => 'Spammer',
			'user_id'         => 0,
		];

		$post = (object) [
			'ID'         => 5,
			'post_title' => 'My Blog Post',
		];

		Functions\expect( 'get_comment' )
			->once()
			->andReturn( $comment );

		Functions\expect( 'get_post' )
			->once()
			->andReturn( $post );

		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$this->event_repo->shouldReceive( 'create' )
			->once()
			->with( Mockery::on( function( $data ) {
				return $data['event_type'] === 'comment_spam'
					&& $data['event_data']['action'] === 'spam';
			} ) )
			->andReturn( 1 );

		$this->comment_tracker->track_comment_status_change( $comment_id, 'spam' );

		$this->assertTrue( true );
	}

	/**
	 * Test handles invalid comment gracefully.
	 *
	 * @return void
	 */
	public function test_handles_invalid_comment_gracefully() {
		Functions\expect( 'get_comment' )
			->once()
			->with( 999 )
			->andReturn( false );

		$this->event_repo->shouldNotReceive( 'create' );

		$this->comment_tracker->track_comment_status_change( 999, 'approve' );

		$this->assertTrue( true );
	}
}
