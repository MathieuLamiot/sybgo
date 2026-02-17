<?php
/**
 * Post Tracker class file.
 *
 * This file defines the Post Tracker for tracking post and page events.
 *
 * @package Rocket\Sybgo\Events\Trackers
 * @since   1.0.0
 */

declare(strict_types=1);

namespace Rocket\Sybgo\Events\Trackers;

use Rocket\Sybgo\Database\Event_Repository;

/**
 * Post Tracker class.
 *
 * Tracks post and page publish, edit, and delete events with edit magnitude calculation.
 *
 * @package Rocket\Sybgo\Events\Trackers
 * @since   1.0.0
 */
class Post_Tracker {
	/**
	 * Event repository instance.
	 *
	 * @var Event_Repository
	 */
	private Event_Repository $event_repo;

	/**
	 * Throttle period in seconds (1 hour).
	 *
	 * @var int
	 */
	private int $throttle_period = 3600;

	/**
	 * Constructor.
	 *
	 * @param Event_Repository $event_repo Event repository instance.
	 */
	public function __construct( Event_Repository $event_repo ) {
		$this->event_repo = $event_repo;

		// Register event types via filter.
		add_filter( 'sybgo_event_types', array( $this, 'register_event_types' ) );
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Track when posts/pages are published.
		add_action( 'transition_post_status', array( $this, 'track_post_status_change' ), 10, 3 );

		// Track when published posts/pages are edited.
		add_action( 'post_updated', array( $this, 'track_post_edit' ), 10, 3 );

		// Track when posts/pages are deleted.
		add_action( 'before_delete_post', array( $this, 'track_post_delete' ), 10, 2 );
	}

	/**
	 * Register post event types via filter.
	 *
	 * @param array $types Existing event types.
	 * @return array Modified event types.
	 */
	public function register_event_types( array $types ): array {
		$types['post_published'] = array(
			'icon'           => '📝',
			'stat_label'     => __( 'Posts Published', 'sybgo' ),
			'short_title'    => function ( array $event_data ): string {
				$object = $event_data['object'] ?? array();
				return sprintf( 'New %s: %s', $object['type'] ?? 'post', $object['title'] ?? 'Untitled' );
			},
			'detailed_title' => function ( array $event_data ): string {
				$object = $event_data['object'] ?? array();
				return sprintf( 'New %s published: %s', $object['type'] ?? 'post', $object['title'] ?? 'Untitled' );
			},
			'ai_description' => function ( array $object, array $metadata ): string {
				return sprintf( 'Published post: "%s"', $object['title'] ?? 'Untitled' );
			},
			'describe'       => function ( array $event_data ): string {
				$description  = "Event Type: Post Published\n";
				$description .= "Description: A new post or page was published on the site.\n\n";
				$description .= "Data Structure:\n";
				$description .= "  - object.type: The post type (post or page)\n";
				$description .= "  - object.id: The post ID\n";
				$description .= "  - object.title: The post title\n";
				$description .= "  - object.url: The permalink URL\n";
				$description .= "  - context.user_id: ID of the user who published\n";
				$description .= "  - context.user_name: Name of the user who published\n";
				$description .= "  - metadata.categories: Array of category names\n";
				$description .= "  - metadata.tags: Array of tag names\n";
				$description .= "  - metadata.word_count: Total word count\n";
				$description .= "  - metadata.edit_magnitude: Always 100 for new publishes\n";
				return $description;
			},
		);

		$types['post_edited'] = array(
			'icon'           => '✏️',
			'stat_label'     => __( 'Posts Edited', 'sybgo' ),
			'short_title'    => function ( array $event_data ): string {
				$object    = $event_data['object'] ?? array();
				$magnitude = $event_data['metadata']['edit_magnitude'] ?? 0;
				return sprintf( '%s edited (%d%% changed)', $object['title'] ?? 'Post', $magnitude );
			},
			'detailed_title' => function ( array $event_data ): string {
				$object    = $event_data['object'] ?? array();
				$magnitude = $event_data['metadata']['edit_magnitude'] ?? 0;
				return sprintf( '%s edited (%d%% of content changed)', $object['title'] ?? 'Post', $magnitude );
			},
			'ai_description' => function ( array $object, array $metadata ): string {
				$magnitude = $metadata['edit_magnitude'] ?? 0;
				return sprintf( 'Edited post "%s" (%d%% changed)', $object['title'] ?? 'Untitled', $magnitude );
			},
			'describe'       => function ( array $event_data ): string {
				$description  = "Event Type: Post Edited\n";
				$description .= "Description: An existing published post or page was updated.\n\n";
				$description .= "Data Structure:\n";
				$description .= "  - object.type: The post type (post or page)\n";
				$description .= "  - object.id: The post ID\n";
				$description .= "  - object.title: The post title\n";
				$description .= "  - object.url: The permalink URL\n";
				$description .= "  - metadata.edit_magnitude: Percentage of content changed (0-100)\n";
				$description .= "  - metadata.word_count: New word count after edit\n";
				return $description;
			},
		);

		$types['post_deleted'] = array(
			'icon'           => '🗑️',
			'stat_label'     => __( 'Posts Deleted', 'sybgo' ),
			'short_title'    => function ( array $event_data ): string {
				$object = $event_data['object'] ?? array();
				return sprintf( '%s deleted', $object['title'] ?? 'Post' );
			},
			'detailed_title' => function ( array $event_data ): string {
				$object = $event_data['object'] ?? array();
				return sprintf( '%s "%s" was deleted', ucfirst( $object['type'] ?? 'Post' ), $object['title'] ?? 'Untitled' );
			},
			'ai_description' => function ( array $object, array $metadata ): string {
				return sprintf( 'Deleted post: "%s"', $object['title'] ?? 'Untitled' );
			},
			'describe'       => function ( array $event_data ): string {
				$description  = "Event Type: Post Deleted\n";
				$description .= "Description: A post or page was permanently deleted.\n\n";
				$description .= "Data Structure:\n";
				$description .= "  - object.type: The post type (post or page)\n";
				$description .= "  - object.id: The post ID\n";
				$description .= "  - object.title: The post title (before deletion)\n";
				$description .= "  - context.user_id: ID of the user who deleted it\n";
				return $description;
			},
		);

		return $types;
	}

	/**
	 * Track post status changes (draft -> published, etc.).
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Old post status.
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public function track_post_status_change( string $new_status, string $old_status, \WP_Post $post ): void {
		// Only track posts and pages.
		if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}

		// Only track when transitioning TO publish.
		if ( 'publish' !== $new_status ) {
			return;
		}

		// Skip if already published (this is an edit, not a new publish).
		if ( 'publish' === $old_status ) {
			return;
		}

		// Check throttling.
		$last_event = $this->event_repo->get_last_event_for_object( 'post_published', $post->ID );
		if ( $last_event ) {
			$time_since = time() - strtotime( $last_event['event_timestamp'] );
			if ( $time_since < $this->throttle_period ) {
				return; // Skip, within throttle period.
			}
		}

		// Build event data.
		$event_data = array(
			'action'   => 'published',
			'object'   => array(
				'type'  => $post->post_type,
				'id'    => $post->ID,
				'title' => $post->post_title,
				'url'   => get_permalink( $post->ID ),
			),
			'context'  => array(
				'user_id'   => get_current_user_id(),
				'user_name' => wp_get_current_user()->display_name,
			),
			'metadata' => array(
				'categories'     => $this->get_categories( $post->ID ),
				'tags'           => $this->get_tags( $post->ID ),
				'word_count'     => str_word_count( wp_strip_all_tags( $post->post_content ) ),
				'edit_magnitude' => 100, // New publish is always 100%.
			),
		);

		// Create event.
		$this->event_repo->create(
			array(
				'event_type' => 'post_published',
				'event_data' => $event_data,
			)
		);
	}

	/**
	 * Track post edits.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post_after Post object after update.
	 * @param \WP_Post $post_before Post object before update.
	 * @return void
	 */
	public function track_post_edit( int $post_id, \WP_Post $post_after, \WP_Post $post_before ): void {
		// Only track posts and pages.
		if ( ! in_array( $post_after->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}

		// Only track if currently published.
		if ( 'publish' !== $post_after->post_status ) {
			return;
		}

		// Only track if was already published (not a new publish).
		if ( 'publish' !== $post_before->post_status ) {
			return;
		}

		// Check throttling (1 event per post per hour).
		$last_event = $this->event_repo->get_last_event_for_object( 'post_edited', $post_id );
		if ( $last_event ) {
			$time_since = time() - strtotime( $last_event['event_timestamp'] );
			if ( $time_since < $this->throttle_period ) {
				return; // Skip, within throttle period.
			}
		}

		// Calculate edit magnitude.
		$edit_magnitude = $this->calculate_edit_magnitude( $post_before->post_content, $post_after->post_content );

		// Get minimum edit threshold from settings (default 5%).
		$min_threshold = (int) get_option( 'sybgo_min_edit_magnitude', 5 );

		// Skip if edit magnitude is below threshold.
		if ( $edit_magnitude < $min_threshold ) {
			return;
		}

		// Build event data.
		$event_data = array(
			'action'   => 'edited',
			'object'   => array(
				'type'  => $post_after->post_type,
				'id'    => $post_id,
				'title' => $post_after->post_title,
				'url'   => get_permalink( $post_id ),
			),
			'context'  => array(
				'user_id'   => get_current_user_id(),
				'user_name' => wp_get_current_user()->display_name,
			),
			'metadata' => array(
				'categories'     => $this->get_categories( $post_id ),
				'tags'           => $this->get_tags( $post_id ),
				'word_count'     => str_word_count( wp_strip_all_tags( $post_after->post_content ) ),
				'edit_magnitude' => $edit_magnitude,
			),
		);

		// Create event.
		$this->event_repo->create(
			array(
				'event_type' => 'post_edited',
				'event_data' => $event_data,
			)
		);
	}

	/**
	 * Track post deletion.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public function track_post_delete( int $post_id, \WP_Post $post ): void {
		// Only track posts and pages.
		if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}

		// Build event data.
		$event_data = array(
			'action'  => 'deleted',
			'object'  => array(
				'type'  => $post->post_type,
				'id'    => $post_id,
				'title' => $post->post_title,
			),
			'context' => array(
				'user_id'   => get_current_user_id(),
				'user_name' => wp_get_current_user()->display_name,
			),
		);

		// Create event.
		$this->event_repo->create(
			array(
				'event_type' => 'post_deleted',
				'event_data' => $event_data,
			)
		);
	}

	/**
	 * Calculate edit magnitude (percentage of content changed).
	 *
	 * Uses similar_text() to compare old and new content.
	 *
	 * @param string $old_content Old post content.
	 * @param string $new_content New post content.
	 * @return int Percentage changed (0-100).
	 */
	private function calculate_edit_magnitude( string $old_content, string $new_content ): int {
		// Strip HTML and normalize whitespace.
		$old_clean = trim( wp_strip_all_tags( $old_content ) );
		$new_clean = trim( wp_strip_all_tags( $new_content ) );

		// If both empty, no change.
		if ( empty( $old_clean ) && empty( $new_clean ) ) {
			return 0;
		}

		// If old was empty, complete rewrite.
		if ( empty( $old_clean ) ) {
			return 100;
		}

		// Calculate similarity percentage.
		$similarity = 0.0;
		similar_text( $old_clean, $new_clean, $similarity );

		// Convert to change percentage (inverse of similarity).
		$change = 100 - (int) round( $similarity );

		return max( 0, min( 100, $change ) ); // Ensure 0-100 range.
	}

	/**
	 * Get category names for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array Array of category names.
	 */
	private function get_categories( int $post_id ): array {
		$categories = get_the_category( $post_id );

		if ( ! $categories ) {
			return array();
		}

		return array_map(
			function ( $cat ) {
				return $cat->name;
			},
			$categories
		);
	}

	/**
	 * Get tag names for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array Array of tag names.
	 */
	private function get_tags( int $post_id ): array {
		$tags = get_the_tags( $post_id );

		if ( ! $tags ) {
			return array();
		}

		return array_map(
			function ( $tag ) {
				return $tag->name;
			},
			$tags
		);
	}
}
