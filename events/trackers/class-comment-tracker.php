<?php
/**
 * Comment Tracker class file.
 *
 * This file defines the Comment Tracker for tracking comment-related events.
 *
 * @package Rocket\Sybgo\Events\Trackers
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Rocket\Sybgo\Events\Trackers;

use Rocket\Sybgo\Database\Event_Repository;
use Rocket\Sybgo\Events\Event_Registry;

/**
 * Comment Tracker class.
 *
 * Tracks new comments and comment status changes.
 *
 * @package Rocket\Sybgo\Events\Trackers
 * @since   1.0.0
 */
class Comment_Tracker {
	/**
	 * Event repository instance.
	 *
	 * @var Event_Repository
	 */
	private Event_Repository $event_repo;

	/**
	 * Constructor.
	 *
	 * @param Event_Repository $event_repo Event repository instance.
	 */
	public function __construct( Event_Repository $event_repo ) {
		$this->event_repo = $event_repo;

		// Register event types with descriptions.
		$this->register_event_types();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Track new comments.
		add_action( 'comment_post', array( $this, 'track_new_comment' ), 10, 3 );

		// Track comment status changes.
		add_action( 'wp_set_comment_status', array( $this, 'track_comment_status_change' ), 10, 2 );
	}

	/**
	 * Register event types with AI-friendly descriptions.
	 *
	 * @return void
	 */
	private function register_event_types(): void {
		// Comment Posted event.
		Event_Registry::register_event_type(
			'comment_posted',
			function ( array $event_data ): string {
				$description  = "Event Type: Comment Posted\n";
				$description .= "Description: A new comment was submitted on a post.\n\n";
				$description .= "Data Structure:\n";
				$description .= "  - object.id: The comment ID\n";
				$description .= "  - object.post_id: ID of the post being commented on\n";
				$description .= "  - object.post_title: Title of the post\n";
				$description .= "  - metadata.author_name: Name of the commenter\n";
				$description .= "  - metadata.author_email: Email of the commenter\n";
				$description .= "  - metadata.status: Comment status (approved, pending, spam)\n";
				$description .= "  - metadata.word_count: Length of the comment\n";

				return $description;
			}
		);

		// Comment Approved event.
		Event_Registry::register_event_type(
			'comment_approved',
			function ( array $event_data ): string {
				$description  = "Event Type: Comment Approved\n";
				$description .= "Description: A pending comment was approved by a moderator.\n\n";
				$description .= "Data Structure:\n";
				$description .= "  - object.id: The comment ID\n";
				$description .= "  - object.post_id: ID of the post\n";
				$description .= "  - metadata.author_name: Name of the commenter\n";
				$description .= "  - context.approved_by_id: ID of moderator who approved\n";

				return $description;
			}
		);

		// Comment Marked as Spam event.
		Event_Registry::register_event_type(
			'comment_spam',
			function ( array $event_data ): string {
				$description  = "Event Type: Comment Marked as Spam\n";
				$description .= "Description: A comment was marked as spam by a moderator.\n\n";
				$description .= "Data Structure:\n";
				$description .= "  - object.id: The comment ID\n";
				$description .= "  - object.post_id: ID of the post\n";
				$description .= "  - context.marked_by_id: ID of moderator who marked as spam\n";

				return $description;
			}
		);

		// Comment Trashed event.
		Event_Registry::register_event_type(
			'comment_trashed',
			function ( array $event_data ): string {
				$description  = "Event Type: Comment Trashed\n";
				$description .= "Description: A comment was moved to trash by a moderator.\n\n";
				$description .= "Data Structure:\n";
				$description .= "  - object.id: The comment ID\n";
				$description .= "  - object.post_id: ID of the post\n";
				$description .= "  - context.trashed_by_id: ID of moderator who trashed it\n";

				return $description;
			}
		);
	}

	/**
	 * Track new comment.
	 *
	 * @param int        $comment_id Comment ID.
	 * @param int|string $comment_approved Comment approval status.
	 * @param array      $commentdata Comment data array.
	 * @return void
	 */
	public function track_new_comment( int $comment_id, $comment_approved, array $commentdata ): void {
		$comment = get_comment( $comment_id );

		if ( ! $comment ) {
			return;
		}

		// Get post info.
		$post = get_post( $comment->comment_post_ID );
		if ( ! $post ) {
			return;
		}

		// Determine status.
		$status = '1' === $comment_approved ? 'approved' : ( '0' === $comment_approved ? 'pending' : 'spam' );

		// Build event data.
		$event_data = array(
			'action'   => 'posted',
			'object'   => array(
				'type'       => 'comment',
				'id'         => $comment_id,
				'post_id'    => $comment->comment_post_ID,
				'post_title' => $post->post_title,
			),
			'context'  => array(
				'comment_type' => $comment->comment_type,
			),
			'metadata' => array(
				'author_name'  => $comment->comment_author,
				'author_email' => $comment->comment_author_email,
				'author_url'   => $comment->comment_author_url,
				'status'       => $status,
				'word_count'   => str_word_count( wp_strip_all_tags( $comment->comment_content ) ),
			),
		);

		// Create event.
		$this->event_repo->create(
			array(
				'event_type'   => 'comment_posted',
				'event_subtype' => 'comment',
				'object_id'    => $comment_id,
				'user_id'      => $comment->user_id > 0 ? $comment->user_id : null,
				'event_data'   => $event_data,
			)
		);
	}

	/**
	 * Track comment status change.
	 *
	 * @param int    $comment_id Comment ID.
	 * @param string $comment_status New comment status.
	 * @return void
	 */
	public function track_comment_status_change( int $comment_id, string $comment_status ): void {
		$comment = get_comment( $comment_id );

		if ( ! $comment ) {
			return;
		}

		// Only track specific status changes.
		$tracked_statuses = array( 'approve', 'spam', 'trash' );
		if ( ! in_array( $comment_status, $tracked_statuses, true ) ) {
			return;
		}

		// Map status to event type.
		$event_type_map = array(
			'approve' => 'comment_approved',
			'spam'    => 'comment_spam',
			'trash'   => 'comment_trashed',
		);

		$event_type = $event_type_map[ $comment_status ];

		// Get post info.
		$post = get_post( $comment->comment_post_ID );

		// Build event data.
		$event_data = array(
			'action'   => str_replace( 'comment_', '', $event_type ),
			'object'   => array(
				'type'       => 'comment',
				'id'         => $comment_id,
				'post_id'    => $comment->comment_post_ID,
				'post_title' => $post ? $post->post_title : '',
			),
			'context'  => array(
				'modified_by_id' => get_current_user_id(),
			),
			'metadata' => array(
				'author_name' => $comment->comment_author,
				'new_status'  => $comment_status,
			),
		);

		// Create event.
		$this->event_repo->create(
			array(
				'event_type'   => $event_type,
				'event_subtype' => 'comment',
				'object_id'    => $comment_id,
				'user_id'      => get_current_user_id(),
				'event_data'   => $event_data,
			)
		);
	}
}
