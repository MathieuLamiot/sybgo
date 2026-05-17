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

		// Use a partial mock so tests can stub protected helper methods
		// (get_daily_cap, get_last_error) without touching PHP built-ins that
		// Patchwork cannot redefine after they have already been loaded.
		$this->tracker = Mockery::mock( Error_Tracker::class, array( $this->aggregated_repo ) )
			->makePartial()
			->shouldAllowMockingProtectedMethods();

		// Stub get_daily_cap() to return the default cap of 5 for all tests.
		// Individual tests that verify filtering behaviour override this stub.
		$this->tracker->shouldReceive( 'get_daily_cap' )->andReturn( 5 )->byDefault();

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
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->aggregated_repo
			->shouldReceive( 'dimensions_hash_exists_for_report' )
			->once()
			->andReturn( false );

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
		$this->aggregated_repo->shouldNotReceive( 'dimensions_hash_exists_for_report' );
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
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->aggregated_repo
			->shouldReceive( 'dimensions_hash_exists_for_report' )
			->once()
			->andReturn( false );

		$this->aggregated_repo
			->shouldReceive( 'count_distinct_dimensions_for_report' )
			->once()
			->with( 'php_error', null )
			->andReturn( 5 ); // At cap.

		// No eviction possible (all same priority as incoming warning).
		$this->aggregated_repo
			->shouldReceive( 'get_lowest_priority_row_for_report' )
			->once()
			->andReturn(
				array(
					'dimensions_hash' => 'aaaabbbbccccddddaaaabbbbccccdddd',
					'level'           => 'warning',
				)
			);

		$this->aggregated_repo->shouldNotReceive( 'upsert' );
		$this->aggregated_repo->shouldNotReceive( 'delete_by_dimensions_hash_and_report' );

		$result = $this->tracker->handle_error( E_WARNING, 'New error type', '/file.php', 1 );

		$this->assertFalse( $result );
	}

	/**
	 * Test that errors are tracked when the count is under the per-period cap.
	 *
	 * @return void
	 */
	public function test_handle_error_allows_when_under_cap(): void {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->aggregated_repo
			->shouldReceive( 'dimensions_hash_exists_for_report' )
			->once()
			->andReturn( false );

		$this->aggregated_repo
			->shouldReceive( 'count_distinct_dimensions_for_report' )
			->once()
			->with( 'php_error', null )
			->andReturn( 4 ); // One slot remaining.

		$this->aggregated_repo
			->shouldReceive( 'upsert' )
			->once()
			->andReturn( true );

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

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->aggregated_repo
			->shouldReceive( 'dimensions_hash_exists_for_report' )
			->once()
			->andReturn( false );

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

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->aggregated_repo
			->shouldReceive( 'dimensions_hash_exists_for_report' )
			->once()
			->andReturn( false );

		$this->aggregated_repo
			->shouldReceive( 'count_distinct_dimensions_for_report' )
			->once()
			->with( 'php_error', null )
			->andReturn( 0 );

		$this->aggregated_repo
			->shouldReceive( 'upsert' )
			->once()
			->andReturn( true );

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
			->shouldReceive( 'dimensions_hash_exists_for_report' )
			->once()
			->andReturn( false );

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

	// -------------------------------------------------------------------------
	// Tests for #56: filterable daily cap
	// -------------------------------------------------------------------------

	/**
	 * Test that handle_error() uses the default cap of 5 when no filter overrides it.
	 *
	 * @return void
	 */
	public function test_handle_error_uses_default_cap_without_filter(): void {
		// The default byDefault() stub already returns 5; no override needed.
		// This test verifies the default cap of 5 is respected.
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->aggregated_repo
			->shouldReceive( 'dimensions_hash_exists_for_report' )
			->once()
			->andReturn( false );

		// count = 5 → at default cap → event should be dropped.
		$this->aggregated_repo
			->shouldReceive( 'count_distinct_dimensions_for_report' )
			->once()
			->with( 'php_error', null )
			->andReturn( 5 );

		$this->aggregated_repo
			->shouldReceive( 'get_lowest_priority_row_for_report' )
			->once()
			->andReturn( null ); // No eviction possible.

		$this->aggregated_repo->shouldNotReceive( 'upsert' );

		$result = $this->tracker->handle_error( E_WARNING, 'Overflow error', '/file.php', 1 );

		$this->assertFalse( $result );
	}

	/**
	 * Test that a filter override for the cap is honoured.
	 *
	 * With a filtered cap of 3 and 3 existing signatures, the event is dropped.
	 *
	 * @return void
	 */
	public function test_handle_error_uses_filtered_cap(): void {
		// Override the default byDefault() stub to simulate a filter lowering the cap to 3.
		$this->tracker->shouldReceive( 'get_daily_cap' )->andReturn( 3 );

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->aggregated_repo
			->shouldReceive( 'dimensions_hash_exists_for_report' )
			->once()
			->andReturn( false );

		// count = 3 → at filtered cap → event should be dropped.
		$this->aggregated_repo
			->shouldReceive( 'count_distinct_dimensions_for_report' )
			->once()
			->with( 'php_error', null )
			->andReturn( 3 );

		$this->aggregated_repo
			->shouldReceive( 'get_lowest_priority_row_for_report' )
			->once()
			->andReturn( null ); // No eviction possible.

		$this->aggregated_repo->shouldNotReceive( 'upsert' );

		$result = $this->tracker->handle_error( E_WARNING, 'Filtered cap test', '/file.php', 1 );

		$this->assertFalse( $result );
	}

	/**
	 * Test that with a filtered cap of 3 and only 2 existing signatures, the event is stored.
	 *
	 * @return void
	 */
	public function test_handle_error_allows_when_under_filtered_cap(): void {
		// Override the default byDefault() stub to simulate a filter lowering the cap to 3.
		$this->tracker->shouldReceive( 'get_daily_cap' )->andReturn( 3 );

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->aggregated_repo
			->shouldReceive( 'dimensions_hash_exists_for_report' )
			->once()
			->andReturn( false );

		// count = 2 → under filtered cap → event should be stored.
		$this->aggregated_repo
			->shouldReceive( 'count_distinct_dimensions_for_report' )
			->once()
			->with( 'php_error', null )
			->andReturn( 2 );

		$this->aggregated_repo
			->shouldReceive( 'upsert' )
			->once()
			->andReturn( true );

		$this->tracker->handle_error( E_WARNING, 'Under filtered cap', '/file.php', 1 );

		$this->addToAssertionCount( 1 );
	}

	// -------------------------------------------------------------------------
	// Tests for #57: priority-based eviction
	// -------------------------------------------------------------------------

	/**
	 * Test that a known signature bypasses the cap check and is simply incremented.
	 *
	 * When the signature is already stored, handle_error() must call upsert without
	 * touching count_distinct_dimensions_for_report or the eviction methods.
	 *
	 * @return void
	 */
	public function test_known_signature_skips_cap_check(): void {
		$this->aggregated_repo
			->shouldReceive( 'dimensions_hash_exists_for_report' )
			->once()
			->andReturn( true ); // Signature already stored.

		$this->aggregated_repo->shouldNotReceive( 'count_distinct_dimensions_for_report' );
		$this->aggregated_repo->shouldNotReceive( 'get_lowest_priority_row_for_report' );
		$this->aggregated_repo->shouldNotReceive( 'delete_by_dimensions_hash_and_report' );

		$this->aggregated_repo
			->shouldReceive( 'upsert' )
			->once()
			->andReturn( true );

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->tracker->handle_error( E_WARNING, 'Known warning', '/file.php', 1 );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test that an error evicts a stored warning when the cap is full.
	 *
	 * An incoming user_error (priority 2) should evict a stored warning (priority 2)...
	 * actually a warning also has priority 2, so let's use notice (priority 1) as the
	 * stored event and user_error (priority 2) as the incoming event.
	 *
	 * @return void
	 */
	public function test_eviction_occurs_when_incoming_has_higher_priority(): void {

		$this->aggregated_repo
			->shouldReceive( 'dimensions_hash_exists_for_report' )
			->once()
			->andReturn( false );

		$this->aggregated_repo
			->shouldReceive( 'count_distinct_dimensions_for_report' )
			->once()
			->with( 'php_error', null )
			->andReturn( 5 ); // Cap full.

		// Stored lowest-priority row is a notice (priority 1).
		$this->aggregated_repo
			->shouldReceive( 'get_lowest_priority_row_for_report' )
			->once()
			->andReturn(
				array(
					'dimensions_hash' => 'abc123def456abc123def456abc123de',
					'level'           => 'notice',
				)
			);

		// Eviction must happen.
		$this->aggregated_repo
			->shouldReceive( 'delete_by_dimensions_hash_and_report' )
			->once()
			->with( 'php_error', 'abc123def456abc123def456abc123de', null )
			->andReturn( true );

		// Incoming event (user_error, priority 2) is then stored.
		$this->aggregated_repo
			->shouldReceive( 'upsert' )
			->once()
			->andReturn( true );

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$result = $this->tracker->handle_error( E_USER_ERROR, 'User error arrives', '/file.php', 10 );

		$this->assertFalse( $result ); // No previous handler.
	}

	/**
	 * Test that no eviction occurs when the incoming event has the same priority as the stored minimum.
	 *
	 * @return void
	 */
	public function test_no_eviction_when_incoming_has_same_priority(): void {

		$this->aggregated_repo
			->shouldReceive( 'dimensions_hash_exists_for_report' )
			->once()
			->andReturn( false );

		$this->aggregated_repo
			->shouldReceive( 'count_distinct_dimensions_for_report' )
			->once()
			->with( 'php_error', null )
			->andReturn( 5 ); // Cap full.

		// Stored lowest-priority row is also a warning (priority 2) — same as incoming.
		$this->aggregated_repo
			->shouldReceive( 'get_lowest_priority_row_for_report' )
			->once()
			->andReturn(
				array(
					'dimensions_hash' => 'abc123def456abc123def456abc123de',
					'level'           => 'warning',
				)
			);

		$this->aggregated_repo->shouldNotReceive( 'delete_by_dimensions_hash_and_report' );
		$this->aggregated_repo->shouldNotReceive( 'upsert' );

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$result = $this->tracker->handle_error( E_WARNING, 'Same-priority warning', '/file.php', 20 );

		$this->assertFalse( $result );
	}

	/**
	 * Test that no eviction occurs when the incoming event has a lower priority than the stored minimum.
	 *
	 * @return void
	 */
	public function test_no_eviction_when_incoming_has_lower_priority(): void {

		$this->aggregated_repo
			->shouldReceive( 'dimensions_hash_exists_for_report' )
			->once()
			->andReturn( false );

		$this->aggregated_repo
			->shouldReceive( 'count_distinct_dimensions_for_report' )
			->once()
			->with( 'php_error', null )
			->andReturn( 5 ); // Cap full of user_errors (priority 2).

		// Stored minimum is user_error (priority 2); incoming is deprecated (priority 1).
		$this->aggregated_repo
			->shouldReceive( 'get_lowest_priority_row_for_report' )
			->once()
			->andReturn(
				array(
					'dimensions_hash' => 'abc123def456abc123def456abc123de',
					'level'           => 'user_error',
				)
			);

		$this->aggregated_repo->shouldNotReceive( 'delete_by_dimensions_hash_and_report' );
		$this->aggregated_repo->shouldNotReceive( 'upsert' );

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$result = $this->tracker->handle_error( E_DEPRECATED, 'A deprecation notice', '/file.php', 30 );

		$this->assertFalse( $result );
	}

	/**
	 * Test that when get_lowest_priority_row_for_report returns null, the incoming event is discarded.
	 *
	 * This handles the edge case where the cap is full but no rows are returned by the
	 * eviction query (should not happen in practice but is a defensive check).
	 *
	 * @return void
	 */
	public function test_no_eviction_when_no_stored_rows_returned(): void {

		$this->aggregated_repo
			->shouldReceive( 'dimensions_hash_exists_for_report' )
			->once()
			->andReturn( false );

		$this->aggregated_repo
			->shouldReceive( 'count_distinct_dimensions_for_report' )
			->once()
			->andReturn( 5 );

		$this->aggregated_repo
			->shouldReceive( 'get_lowest_priority_row_for_report' )
			->once()
			->andReturn( null );

		$this->aggregated_repo->shouldNotReceive( 'delete_by_dimensions_hash_and_report' );
		$this->aggregated_repo->shouldNotReceive( 'upsert' );

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$result = $this->tracker->handle_error( E_USER_ERROR, 'High priority but no rows', '/file.php', 1 );

		$this->assertFalse( $result );
	}
}
