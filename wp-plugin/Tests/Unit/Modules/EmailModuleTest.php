<?php
/**
 * Email Module Unit Tests.
 *
 * @package Sybgo\Tests\Unit\Modules
 */

declare(strict_types=1);

namespace Sybgo\Tests\Unit\Modules;

use Brain\Monkey;
use Mockery;
use PHPUnit\Framework\TestCase;
use Sybgo\Cron_Manager;
use Sybgo\Database\Report_Repository;
use Sybgo\Email\Email_Manager;
use Sybgo\Factory;
use Sybgo\Modules\Email_Module;

/**
 * Unit tests for Email_Module.
 */
class EmailModuleTest extends TestCase {

	/**
	 * @var Factory&\Mockery\MockInterface
	 */
	private $factory;

	/**
	 * @var Cron_Manager&\Mockery\MockInterface
	 */
	private $cron;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->factory = Mockery::mock( Factory::class );
		$this->cron    = Mockery::mock( Cron_Manager::class );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// boot — cron registration
	// -------------------------------------------------------------------------

	/**
	 * boot() registers both email cron hooks via CronManager.
	 *
	 * @return void
	 */
	public function test_boot_registers_both_email_crons(): void {
		$this->cron
			->shouldReceive( 'register' )
			->twice()
			->with(
				Mockery::on( static function ( string $hook ): bool {
					return in_array( $hook, array( 'sybgo_send_report_emails', 'sybgo_retry_failed_emails' ), true );
				} ),
				Mockery::type( 'string' ),
				Mockery::type( 'array' ),
				Mockery::type( 'string' )
			);

		$module = new Email_Module( $this->factory, $this->cron );
		$module->boot();

		$this->assertTrue( true );
	}

	/**
	 * boot() wires the send-emails callback to the named module method.
	 *
	 * @return void
	 */
	public function test_boot_send_emails_cron_points_to_module_method(): void {
		$send_callback  = null;
		$retry_callback = null;

		$this->cron
			->shouldReceive( 'register' )
			->twice()
			->andReturnUsing(
				function ( string $hook, string $schedule, callable $callback ) use ( &$send_callback, &$retry_callback ): void {
					if ( 'sybgo_send_report_emails' === $hook ) {
						$send_callback = $callback;
					}
					if ( 'sybgo_retry_failed_emails' === $hook ) {
						$retry_callback = $callback;
					}
				}
			);

		$module = new Email_Module( $this->factory, $this->cron );
		$module->boot();

		$this->assertSame( array( $module, 'send_report_emails_callback' ), $send_callback );
		$this->assertSame( array( $module, 'retry_failed_emails_callback' ), $retry_callback );
	}

	// -------------------------------------------------------------------------
	// send_report_emails_callback — regression tests for issue #68
	// -------------------------------------------------------------------------

	/**
	 * Regression test for #68: callback must cast the string report id to int.
	 *
	 * Email_Manager::send_report_email() declares int $report_id under
	 * declare(strict_types=1). The repository returns rows from $wpdb where
	 * the id column comes back as a string. Without the explicit cast the
	 * strict-typed call fataled.
	 *
	 * @return void
	 */
	public function test_send_emails_callback_casts_string_id_to_int(): void {
		$report_repo   = Mockery::mock( Report_Repository::class );
		$email_manager = Mockery::mock( Email_Manager::class );

		// Simulate $wpdb behaviour: numeric column returned as string.
		$report_repo->shouldReceive( 'get_last_frozen' )
			->once()
			->andReturn( array( 'id' => '42' ) );

		// The fix: callback must pass an int, never a string.
		$email_manager->shouldReceive( 'send_report_email' )
			->once()
			->with(
				Mockery::on(
					static function ( $arg ): bool {
						return is_int( $arg ) && 42 === $arg;
					}
				)
			)
			->andReturn( true );

		$this->factory->shouldReceive( 'create_report_repository' )->andReturn( $report_repo );
		$this->factory->shouldReceive( 'create_email_manager' )->andReturn( $email_manager );

		$module = new Email_Module( $this->factory, $this->cron );
		$module->send_report_emails_callback();

		$this->assertTrue( true );
	}

	/**
	 * Regression test for #68 — early return path.
	 *
	 * When there is no frozen report the callback must not call the email
	 * manager and must not fatal.
	 *
	 * @return void
	 */
	public function test_send_emails_callback_noops_when_no_frozen_report(): void {
		$report_repo   = Mockery::mock( Report_Repository::class );
		$email_manager = Mockery::mock( Email_Manager::class );

		$report_repo->shouldReceive( 'get_last_frozen' )->once()->andReturn( null );
		$email_manager->shouldNotReceive( 'send_report_email' );

		$this->factory->shouldReceive( 'create_report_repository' )->andReturn( $report_repo );
		$this->factory->shouldReceive( 'create_email_manager' )->andReturn( $email_manager );

		$module = new Email_Module( $this->factory, $this->cron );
		$module->send_report_emails_callback();

		$this->assertTrue( true );
	}

	// -------------------------------------------------------------------------
	// retry_failed_emails_callback
	// -------------------------------------------------------------------------

	/**
	 * retry_failed_emails_callback() calls retry_failed_emails() on the email manager.
	 *
	 * @return void
	 */
	public function test_retry_callback_invokes_email_manager(): void {
		$email_manager = Mockery::mock( Email_Manager::class );
		$email_manager->shouldReceive( 'retry_failed_emails' )->once()->andReturn( 3 );

		$this->factory->shouldReceive( 'create_email_manager' )->andReturn( $email_manager );

		$module = new Email_Module( $this->factory, $this->cron );
		$module->retry_failed_emails_callback();

		$this->assertTrue( true );
	}
}
