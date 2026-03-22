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

		// Seed normal_error_reporting with the ambient mask so the suppression
		// check works in tests that simulate @ by calling error_reporting(0).
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting
		$ambient_mask = error_reporting();
		$ref_normal   = new \ReflectionProperty( Error_Tracker::class, 'normal_error_reporting' );
		$ref_normal->setAccessible( true );
		$ref_normal->setValue( $this->tracker, $ambient_mask );

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
	 * Test that register_hooks() installs a PHP error handler and a shutdown function.
	 *
	 * We call register_hooks() for real and then immediately restore the
	 * previous handler with set_error_handler(null), which returns whatever
	 * was registered last. That should be the Error_Tracker callback.
	 *
	 * @return void
	 */
	public function test_register_hooks_registers_error_handler(): void {
		// Call register_hooks() for real — this installs the error handler and
		// registers a shutdown function. Both are safe to run in a test context.
		$this->tracker->register_hooks();

		// Capture the registered error handler by replacing it with null,
		// then immediately restoring so we leave the handler stack clean.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		$registered = set_error_handler( null );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_restore_error_handler
		restore_error_handler();

		$this->assertSame( array( $this->tracker, 'handle_error' ), $registered );
	}

	/**
	 * Test that a warning is tracked when under the per-period cap.
	 *
	 * @return void
	 */
	public function test_handle_error_tracks_warning(): void {
		$this->aggregated_repo
			->shouldReceive( 'count_distinct_dimensions_for_report' )
			->once()
			->with( 'php_error', null )
			->andReturn( 0 );

		$this->aggregated_repo
			->shouldReceive( 'upsert' )
			->once()
			->with(
				'php_error',
				Mockery::type( 'string' ),
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
	 * We use the real error_reporting(0) to simulate the PHP < 8.0 behaviour
	 * for @-suppressed errors, then restore the original mask afterwards.
	 * This avoids mocking a PHP built-in, which is unreliable on PHP 7.4.
	 *
	 * @return void
	 */
	public function test_handle_error_skips_suppressed_error(): void {
		$this->aggregated_repo->shouldNotReceive( 'count_distinct_dimensions_for_report' );
		$this->aggregated_repo->shouldNotReceive( 'upsert' );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting
		$original_mask = error_reporting( 0 );
		try {
			$result = $this->tracker->handle_error( E_WARNING, 'Suppressed warning', '/file.php', 1 );
		} finally {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting
			error_reporting( $original_mask );
		}

		$this->assertFalse( $result );
	}

	/**
	 * Test that new error signatures are dropped once the per-period cap is reached.
	 *
	 * @return void
	 */
	public function test_handle_error_drops_when_cap_reached(): void {
		$this->aggregated_repo
			->shouldReceive( 'count_distinct_dimensions_for_report' )
			->once()
			->with( 'php_error', null )
			->andReturn( 5 ); // At cap.

		$this->aggregated_repo->shouldNotReceive( 'upsert' );

		$result = $this->tracker->handle_error( E_WARNING, 'New error type', '/file.php', 1 );

		$this->assertFalse( $result );
	}

	/**
	 * Test that errors are tracked when the count is under the per-period cap.
	 *
	 * @return void
	 */
	public function test_handle_error_allows_when_under_cap(): void {
		$this->aggregated_repo
			->shouldReceive( 'count_distinct_dimensions_for_report' )
			->once()
			->with( 'php_error', null )
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
		$this->aggregated_repo->shouldNotReceive( 'count_distinct_dimensions_for_report' );
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
			->shouldReceive( 'count_distinct_dimensions_for_report' )
			->once()
			->with( 'php_error', null )
			->andReturn( 0 );

		$this->aggregated_repo
			->shouldReceive( 'upsert' )
			->once()
			->with(
				'php_error',
				Mockery::type( 'string' ),
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

		$this->aggregated_repo->shouldNotReceive( 'count_distinct_dimensions_for_report' );
		$this->aggregated_repo->shouldNotReceive( 'upsert' );

		$result = $this->tracker->handle_error( E_WARNING, 'Re-entrant error', '/file.php', 1 );

		$this->assertFalse( $result );
	}

	/**
	 * Test that handle_shutdown() records a fatal error.
	 *
	 * @return void
	 */
	public function test_handle_shutdown_records_fatal_error(): void {
		$tracker = Mockery::mock( Error_Tracker::class, array( $this->aggregated_repo ) )
			->makePartial()
			->shouldAllowMockingProtectedMethods();

		$tracker->shouldReceive( 'get_last_error' )
			->once()
			->andReturn(
				array(
					'type'    => E_ERROR,
					'message' => 'Call to undefined function foo()',
					'file'    => '/var/www/html/wp-content/plugins/test/file.php',
					'line'    => 99,
				)
			);

		$this->aggregated_repo
			->shouldReceive( 'upsert' )
			->once()
			->with(
				'php_error',
				Mockery::any(),
				1.0,
				Mockery::on(
					function ( $dimensions ) {
						return 'fatal_error' === $dimensions['level']
							&& 32 === strlen( $dimensions['signature'] );
					}
				),
				Mockery::on(
					function ( $meta ) {
						return '/var/www/html/wp-content/plugins/test/file.php' === $meta['file']
							&& 99 === $meta['line']
							&& 0 === strpos( $meta['message'], 'Call to undefined function' );
					}
				)
			)
			->andReturn( true );

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$tracker->handle_shutdown();

		$this->addToAssertionCount( 1 ); // Mockery verifies upsert in tearDown.
	}

	/**
	 * Test that handle_shutdown() ignores non-fatal errors.
	 *
	 * @return void
	 */
	public function test_handle_shutdown_ignores_non_fatal(): void {
		$tracker = Mockery::mock( Error_Tracker::class, array( $this->aggregated_repo ) )
			->makePartial()
			->shouldAllowMockingProtectedMethods();

		$tracker->shouldReceive( 'get_last_error' )
			->once()
			->andReturn(
				array(
					'type'    => E_WARNING,
					'message' => 'A regular warning',
					'file'    => '/file.php',
					'line'    => 1,
				)
			);

		$this->aggregated_repo->shouldNotReceive( 'upsert' );

		$tracker->handle_shutdown();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test that handle_shutdown() does nothing when there is no last error.
	 *
	 * @return void
	 */
	public function test_handle_shutdown_ignores_null(): void {
		$tracker = Mockery::mock( Error_Tracker::class, array( $this->aggregated_repo ) )
			->makePartial()
			->shouldAllowMockingProtectedMethods();

		$tracker->shouldReceive( 'get_last_error' )
			->once()
			->andReturn( null );

		$this->aggregated_repo->shouldNotReceive( 'upsert' );

		$tracker->handle_shutdown();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test that the previous error handler is called after tracking.
	 *
	 * @return void
	 */
	public function test_handle_error_calls_previous_handler(): void {
		$called_with  = null;
		$mock_handler = function ( int $errno, string $errstr ) use ( &$called_with ): bool {
			$called_with = array( $errno, $errstr );
			return true;
		};

		// Inject the mock previous handler via reflection.
		$ref = new \ReflectionProperty( Error_Tracker::class, 'previous_handler' );
		$ref->setAccessible( true );
		$ref->setValue( $this->tracker, $mock_handler );

		$this->aggregated_repo
			->shouldReceive( 'count_distinct_dimensions_for_report' )
			->once()
			->with( 'php_error', null )
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

	/**
	 * Test that the cap check uses report_id IS NULL (current unassigned period).
	 *
	 * The cap is scoped to the current period via report_id IS NULL, which automatically
	 * resets after a freeze without any date arithmetic.
	 *
	 * @return void
	 */
	public function test_cap_uses_report_scoped_query(): void {
		$this->aggregated_repo
			->shouldReceive( 'count_distinct_dimensions_for_report' )
			->once()
			->with( 'php_error', null )
			->andReturn( 0 );

		$this->aggregated_repo
			->shouldReceive( 'upsert' )
			->once()
			->andReturn( true );

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->tracker->handle_error( E_WARNING, 'A warning', '/file.php', 1 );

		// Mockery verifies the count_distinct_dimensions_for_report expectation in tearDown.
		$this->addToAssertionCount( 1 );
	}
}
