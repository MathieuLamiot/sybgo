<?php
/**
 * Cron Callbacks Regression Tests
 *
 * Pins the contract for send_report_emails_callback on Cron_Manager.
 * Previously on Sybgo — migrated as part of #87.
 *
 * @package Sybgo\Tests\Unit
 */

declare(strict_types=1);

namespace Sybgo\Tests\Unit;

use Brain\Monkey;
use Mockery;
use PHPUnit\Framework\TestCase;
use Sybgo\Cron_Manager;
use Sybgo\Database\DatabaseManager;
use Sybgo\Email\Email_Manager;
use Sybgo\Reports\Report_Manager;

/**
 * Regression tests for cron callbacks on Cron_Manager.
 */
class SybgoCronCallbacksTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * Regression test for #68.
	 *
	 * Email_Manager::send_report_email() is strictly typed (int $report_id).
	 * $wpdb returns column values as strings. Without the explicit cast, the
	 * call fatals under declare(strict_types=1).
	 *
	 * @return void
	 */
	public function test_send_report_emails_callback_casts_string_id_to_int(): void {
		$report_manager = Mockery::mock( Report_Manager::class );
		$email_manager  = Mockery::mock( Email_Manager::class );
		$db_manager     = Mockery::mock( DatabaseManager::class );

		// Simulate the $wpdb behavior: numeric column returned as string.
		$report_manager->shouldReceive( 'get_last_frozen_report' )
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

		$manager = new Cron_Manager( $report_manager, $email_manager, $db_manager );
		$manager->send_report_emails_callback();

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
		$report_manager = Mockery::mock( Report_Manager::class );
		$email_manager  = Mockery::mock( Email_Manager::class );
		$db_manager     = Mockery::mock( DatabaseManager::class );

		$report_manager->shouldReceive( 'get_last_frozen_report' )
			->once()
			->andReturn( null );

		$email_manager->shouldNotReceive( 'send_report_email' );

		$manager = new Cron_Manager( $report_manager, $email_manager, $db_manager );
		$manager->send_report_emails_callback();

		$this->assertTrue( true );
	}
}
