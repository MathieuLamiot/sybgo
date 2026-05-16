<?php
/**
 * Sybgo Cron Callbacks Unit Tests
 *
 * Regression tests for the cron callbacks exposed by the Sybgo singleton.
 *
 * @package Sybgo\Tests\Unit
 */

declare(strict_types=1);

namespace Sybgo\Tests\Unit;

use Brain\Monkey;
use Mockery;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Sybgo\Database\Report_Repository;
use Sybgo\Email\Email_Manager;
use Sybgo\Factory;
use Sybgo\Sybgo;

/**
 * Regression tests for cron callbacks on the main plugin class.
 */
class SybgoCronCallbacksTest extends TestCase {

	/**
	 * Set up test environment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
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
	 * Regression test for #68.
	 *
	 * `Email_Manager::send_report_email()` declares `int $report_id` under
	 * `declare(strict_types=1)`. The repository returns rows from $wpdb where
	 * the `id` column comes back as a string. Without an explicit cast in the
	 * cron callback, the strict-typed call fataled.
	 *
	 * This test pins the callback contract: when the report repository hands
	 * back a string id (as $wpdb does), the email manager must receive an int.
	 *
	 * @return void
	 */
	public function test_send_report_emails_callback_casts_string_id_to_int(): void {
		$report_repo   = Mockery::mock( Report_Repository::class );
		$email_manager = Mockery::mock( Email_Manager::class );

		// Simulate the $wpdb behavior: numeric column returned as string.
		$report_repo->shouldReceive( 'get_last_frozen' )
			->once()
			->andReturn( array( 'id' => '42' ) );

		// The fix: callback must pass an int, never a string.
		$email_manager->shouldReceive( 'send_report_email' )
			->once()
			->with( Mockery::on(
				static function ( $arg ): bool {
					return is_int( $arg ) && 42 === $arg;
				}
			) )
			->andReturn( true );

		$factory = Mockery::mock( Factory::class );
		$factory->shouldReceive( 'create_report_repository' )->andReturn( $report_repo );
		$factory->shouldReceive( 'create_email_manager' )->andReturn( $email_manager );

		$sybgo = $this->build_sybgo_with_factory( $factory );

		// Should not raise a TypeError.
		$sybgo->send_report_emails_callback();

		// If we got here, the cast is in place.
		$this->assertTrue( true );
	}

	/**
	 * Regression test for #68 — early return path.
	 *
	 * When there is no frozen report, the callback must not call the email
	 * manager (and must not fatal).
	 *
	 * @return void
	 */
	public function test_send_report_emails_callback_noops_when_no_frozen_report(): void {
		$report_repo   = Mockery::mock( Report_Repository::class );
		$email_manager = Mockery::mock( Email_Manager::class );

		$report_repo->shouldReceive( 'get_last_frozen' )
			->once()
			->andReturn( null );

		$email_manager->shouldNotReceive( 'send_report_email' );

		$factory = Mockery::mock( Factory::class );
		$factory->shouldReceive( 'create_report_repository' )->andReturn( $report_repo );
		$factory->shouldReceive( 'create_email_manager' )->andReturn( $email_manager );

		$sybgo = $this->build_sybgo_with_factory( $factory );
		$sybgo->send_report_emails_callback();

		$this->assertTrue( true );
	}

	/**
	 * Build a Sybgo instance bypassing the singleton & private constructor,
	 * with the Factory replaced by a mock.
	 *
	 * @param Factory $factory Factory mock.
	 * @return Sybgo
	 */
	private function build_sybgo_with_factory( Factory $factory ): Sybgo {
		$reflection = new ReflectionClass( Sybgo::class );
		$instance   = $reflection->newInstanceWithoutConstructor();

		$factory_prop = $reflection->getProperty( 'factory' );
		$factory_prop->setAccessible( true );
		$factory_prop->setValue( $instance, $factory );

		return $instance;
	}
}
