<?php
/**
 * Post Tracker Unit Tests
 *
 * @package Rocket\Sybgo\Tests\Unit\Events
 */

namespace Rocket\Sybgo\Tests\Unit\Events;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Rocket\Sybgo\Events\Trackers\Post_Tracker;

/**
 * Post Tracker Test Case
 */
class PostTrackerTest extends TestCase {

	/**
	 * Post tracker instance.
	 *
	 * @var Post_Tracker
	 */
	private $tracker;

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

		$this->event_repo = Mockery::mock( 'Rocket\Sybgo\Database\Event_Repository' );
		$this->tracker    = new Post_Tracker( $this->event_repo );

		// Mock WordPress functions.
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_get_current_user' )->alias( function() {
			return (object) array( 'display_name' => 'Admin' );
		} );
		Functions\when( 'get_permalink' )->alias( function( $id ) {
			return 'https://example.com/post-' . $id;
		} );
		Functions\when( 'current_time' )->justReturn( time() );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_option' )->justReturn( 5 ); // Min edit threshold.
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
	 * Test calculate_edit_magnitude with identical content.
	 */
	public function test_calculate_edit_magnitude_no_change() {
		$reflection = new \ReflectionClass( $this->tracker );
		$method     = $reflection->getMethod( 'calculate_edit_magnitude' );
		$method->setAccessible( true );

		$old_content = 'This is some test content.';
		$new_content = 'This is some test content.';

		$magnitude = $method->invoke( $this->tracker, $old_content, $new_content );

		$this->assertEquals( 0, $magnitude );
	}

	/**
	 * Test calculate_edit_magnitude with complete rewrite.
	 */
	public function test_calculate_edit_magnitude_complete_rewrite() {
		$reflection = new \ReflectionClass( $this->tracker );
		$method     = $reflection->getMethod( 'calculate_edit_magnitude' );
		$method->setAccessible( true );

		$old_content = 'This is the original content.';
		$new_content = 'Completely different text here.';

		$magnitude = $method->invoke( $this->tracker, $old_content, $new_content );

		// Should be high percentage (close to 100).
		$this->assertGreaterThan( 50, $magnitude );
	}

	/**
	 * Test calculate_edit_magnitude with minor change.
	 */
	public function test_calculate_edit_magnitude_minor_change() {
		$reflection = new \ReflectionClass( $this->tracker );
		$method     = $reflection->getMethod( 'calculate_edit_magnitude' );
		$method->setAccessible( true );

		$old_content = 'This is some test content with many words.';
		$new_content = 'This is some test content with many more words.';

		$magnitude = $method->invoke( $this->tracker, $old_content, $new_content );

		// Should be low percentage (small change).
		$this->assertLessThan( 30, $magnitude );
		$this->assertGreaterThan( 0, $magnitude );
	}

	/**
	 * Test calculate_edit_magnitude with HTML content.
	 */
	public function test_calculate_edit_magnitude_strips_html() {
		$reflection = new \ReflectionClass( $this->tracker );
		$method     = $reflection->getMethod( 'calculate_edit_magnitude' );
		$method->setAccessible( true );

		$old_content = '<p>This is <strong>some</strong> content.</p>';
		$new_content = '<div>This is <em>some</em> content.</div>';

		$magnitude = $method->invoke( $this->tracker, $old_content, $new_content );

		// After stripping HTML, content is nearly identical.
		$this->assertLessThan( 10, $magnitude );
	}

	/**
	 * Test calculate_edit_magnitude with empty old content.
	 */
	public function test_calculate_edit_magnitude_new_content() {
		$reflection = new \ReflectionClass( $this->tracker );
		$method     = $reflection->getMethod( 'calculate_edit_magnitude' );
		$method->setAccessible( true );

		$old_content = '';
		$new_content = 'Brand new content here.';

		$magnitude = $method->invoke( $this->tracker, $old_content, $new_content );

		$this->assertEquals( 100, $magnitude );
	}

	/**
	 * Test calculate_edit_magnitude range is 0-100.
	 */
	public function test_calculate_edit_magnitude_range() {
		$reflection = new \ReflectionClass( $this->tracker );
		$method     = $reflection->getMethod( 'calculate_edit_magnitude' );
		$method->setAccessible( true );

		// Test various content changes.
		$test_cases = array(
			array( 'Same text', 'Same text' ),
			array( 'Short', 'Much longer text with many words' ),
			array( 'One two three', 'One two four' ),
		);

		foreach ( $test_cases as $case ) {
			$magnitude = $method->invoke( $this->tracker, $case[0], $case[1] );

			$this->assertGreaterThanOrEqual( 0, $magnitude, 'Magnitude should be >= 0' );
			$this->assertLessThanOrEqual( 100, $magnitude, 'Magnitude should be <= 100' );
		}
	}

	/**
	 * Test get_categories returns array.
	 */
	public function test_get_categories() {
		$categories = array(
			(object) array( 'name' => 'Technology' ),
			(object) array( 'name' => 'WordPress' ),
		);

		Functions\when( 'get_the_category' )->justReturn( $categories );

		$reflection = new \ReflectionClass( $this->tracker );
		$method     = $reflection->getMethod( 'get_categories' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->tracker, 123 );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
		$this->assertEquals( 'Technology', $result[0] );
		$this->assertEquals( 'WordPress', $result[1] );
	}

	/**
	 * Test get_categories with no categories.
	 */
	public function test_get_categories_empty() {
		Functions\when( 'get_the_category' )->justReturn( false );

		$reflection = new \ReflectionClass( $this->tracker );
		$method     = $reflection->getMethod( 'get_categories' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->tracker, 123 );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test get_tags returns array.
	 */
	public function test_get_tags() {
		$tags = array(
			(object) array( 'name' => 'php' ),
			(object) array( 'name' => 'development' ),
		);

		Functions\when( 'get_the_tags' )->justReturn( $tags );

		$reflection = new \ReflectionClass( $this->tracker );
		$method     = $reflection->getMethod( 'get_tags' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->tracker, 123 );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
		$this->assertEquals( 'php', $result[0] );
		$this->assertEquals( 'development', $result[1] );
	}
}
