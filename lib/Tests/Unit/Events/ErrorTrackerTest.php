<?php
/**
 * Error Tracker Unit Tests
 *
 * @package Sybgo\Tests\Unit\Events
 */

declare(strict_types=1);

namespace Sybgo\Tests\Unit\Events;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Sybgo\Database\Aggregated_Event_Repository;
use Sybgo\Events\Trackers\Error_Tracker;

/**
 * Test Error_Tracker class.
 */
class ErrorTrackerTest extends TestCase {

	/**
	 * Aggregated event repository mock.
	 *
	 * @var Aggregated_Event_Repository&\Mockery\MockInterface
	 */
	private $aggregated_repo;

	/**
	 * Error tracker instance.
	 *
	 * @var Error_Tracker
	 */
	private Error_Tracker $tracker;

	/**
	 * Set up test environment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->aggregated_repo = Mockery::mock( Aggregated_Event_Repository::class );
		$this->tracker         = new Error_Tracker( $this->aggregated_repo );

		// Default: error_reporting returns full mask (errors not suppressed).
		Functions\when( 'error_reporting' )->justReturn( E_ALL );

		// Default gmdate stub for consistent date in cap checks.
		Functions\when( 'gmdate' )->justReturn( '2026-03-21' );
	}

	/**
	 * Tear down test environment.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		// Reset static re-entrancy guard to prevent test pollution.
		$ref = new \ReflectionProperty( Error_Tracker::class, 'handling' );
		$ref->setAccessible( true );
		$ref->setValue( null, false );

		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * Test that register_hooks() installs a PHP error handler.
	 *
	 * @return void
	 */
	public function test_register_hooks_registers_error_handler(): void {
		Functions\expect( 'set_error_handler' )
			->once()
			->with( array( $this->tracker, 'handle_error' ) )
			->andReturn( null );

		$this->tracker->register_hooks();

		// Mockery verifies the expectation in tearDown; add assertion count so
		// PHPUnit does not mark this test as risky.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test that a warning is tracked when under the daily cap.
	 *
	 * @return void
	 */
	public function test_handle_error_tracks_warning(): void {
		$this->aggregated_repo
			->shouldReceive( 'count_distinct_dimensions_for_date' )
			->once()
			->with( 'php_error', '2026-03-21' )
			->andReturn( 0 );

		$this->aggregated_repo
			->shouldReceive( 'upsert' )
			->once()
			->with(
				'php_error',
				'2026-03-21',
				1.0,
				Mockery::on(
					function ( $dimensions ) {
						return isset( $dimensions['level'], $dimensions['signature'] )
							&& 'warning' === $dimensions['level']
							&& 32 === strlen( $dimensions['signature'] ); // md5 is 32 hex chars.
					}
				),
				Mockery::on(
					function ( $meta ) {
						return isset( $meta['file'], $meta['line'], $meta['message'] )
							&& '/var/www/html/wp-content/plugins/test/file.php' === $meta['file']
							&& 42 === $meta['line']
							&& 'Something went wrong' === $meta['message'];
					}
				)
			)
			->andReturn( true );

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$result = $this->tracker->handle_error( E_WARNING, 'Something went wrong', '/var/www/html/wp-content/plugins/test/file.php', 42 );

		$this->assertFalse( $result ); // No previous handler → returns false.
	}

	/**
	 * Test that errors suppressed with @ are not tracked.
	 *
	 * When error_reporting() returns 0 (PHP < 8.0 behaviour for @-suppressed errors),
	 * the tracker should skip recording and defer to the previous handler.
	 *
	 * @return void
	 */
	public function test_handle_error_skips_suppressed_error(): void {
		// Override the default stub to return 0 (suppressed).
		Functions\when( 'error_reporting' )->justReturn( 0 );

		$this->aggregated_repo->shouldNotReceive( 'count_distinct_dimensions_for_date' );
		$this->aggregated_repo->shouldNotReceive( 'upsert' );

		$result = $this->tracker->handle_error( E_WARNING, 'Suppressed warning', '/file.php', 1 );

		$this->assertFalse( $result );
	}

	/**
	 * Test that new error signatures are dropped once the daily cap is reached.
	 *
	 * @return void
	 */
	public function test_handle_error_drops_when_cap_reached(): void {
		$this->aggregated_repo
			->shouldReceive( 'count_distinct_dimensions_for_date' )
			->once()
			->andReturn( 5 ); // At cap.

		$this->aggregated_repo->shouldNotReceive( 'upsert' );

		$result = $this->tracker->handle_error( E_WARNING, 'New error type', '/file.php', 1 );

		$this->assertFalse( $result );
	}

	/**
	 * Test that errors are tracked when the count is under the daily cap.
	 *
	 * @return void
	 */
	public function test_handle_error_allows_when_under_cap(): void {
		$this->aggregated_repo
			->shouldReceive( 'count_distinct_dimensions_for_date' )
			->once()
			->andReturn( 4 ); // One slot remaining.

		$this->aggregated_repo
			->shouldReceive( 'upsert' )
			->once()
			->andReturn( true );

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->tracker->handle_error( E_NOTICE, 'A notice', '/file.php', 10 );

		// Mockery verifies the upsert expectation in tearDown.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test that fatal error levels are not tracked.
	 *
	 * E_ERROR cannot be caught by user-defined error handlers, but even if
	 * the handler is called, it should skip levels not in its capture list.
	 *
	 * @return void
	 */
	public function test_handle_error_skips_fatal_errors(): void {
		$this->aggregated_repo->shouldNotReceive( 'count_distinct_dimensions_for_date' );
		$this->aggregated_repo->shouldNotReceive( 'upsert' );

		$result = $this->tracker->handle_error( E_ERROR, 'Fatal error', '/file.php', 1 );

		$this->assertFalse( $result );
	}

	/**
	 * Test that error messages are truncated to 100 characters.
	 *
	 * @return void
	 */
	public function test_handle_error_truncates_message_to_100_chars(): void {
		$long_message = str_repeat( 'x', 200 );

		$this->aggregated_repo
			->shouldReceive( 'count_distinct_dimensions_for_date' )
			->once()
			->andReturn( 0 );

		$this->aggregated_repo
			->shouldReceive( 'upsert' )
			->once()
			->with(
				'php_error',
				'2026-03-21',
				1.0,
				Mockery::any(),
				Mockery::on(
					function ( $meta ) {
						return isset( $meta['message'] ) && 100 === strlen( $meta['message'] );
					}
				)
			)
			->andReturn( true );

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->tracker->handle_error( E_WARNING, $long_message, '/file.php', 1 );

		// Mockery verifies the upsert expectation in tearDown.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test that the re-entrancy guard prevents recursive calls.
	 *
	 * If handle_error() is called while already inside handle_error(), the
	 * second call should skip tracking entirely.
	 *
	 * @return void
	 */
	public function test_reentrancy_guard_prevents_recursive_calls(): void {
		// Simulate being already inside the handler.
		$ref = new \ReflectionProperty( Error_Tracker::class, 'handling' );
		$ref->setAccessible( true );
		$ref->setValue( null, true );

		$this->aggregated_repo->shouldNotReceive( 'count_distinct_dimensions_for_date' );
		$this->aggregated_repo->shouldNotReceive( 'upsert' );

		$result = $this->tracker->handle_error( E_WARNING, 'Re-entrant error', '/file.php', 1 );

		$this->assertFalse( $result );
	}

	/**
	 * Test that the previous error handler is called after tracking.
	 *
	 * @return void
	 */
	public function test_handle_error_calls_previous_handler(): void {
		$called_with = null;
		$mock_handler = function ( int $errno, string $errstr ) use ( &$called_with ): bool {
			$called_with = array( $errno, $errstr );
			return true;
		};

		// Inject the mock previous handler via reflection.
		$ref = new \ReflectionProperty( Error_Tracker::class, 'previous_handler' );
		$ref->setAccessible( true );
		$ref->setValue( $this->tracker, $mock_handler );

		$this->aggregated_repo
			->shouldReceive( 'count_distinct_dimensions_for_date' )
			->once()
			->andReturn( 0 );

		$this->aggregated_repo
			->shouldReceive( 'upsert' )
			->once()
			->andReturn( true );

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$result = $this->tracker->handle_error( E_WARNING, 'Test warning', '/file.php', 5 );

		$this->assertTrue( $result ); // Previous handler returned true.
		$this->assertNotNull( $called_with );
		$this->assertSame( E_WARNING, $called_with[0] );
		$this->assertSame( 'Test warning', $called_with[1] );
	}
}
