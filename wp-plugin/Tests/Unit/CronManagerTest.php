<?php
/**
 * Cron Manager Unit Tests
 *
 * @package Sybgo\Tests\Unit
 */

declare(strict_types=1);

namespace Sybgo\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Sybgo\Cron_Manager;

/**
 * Tests for Cron_Manager.
 */
class CronManagerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_html__' )->returnArg();
		// Stub scheduling functions used by init().
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->justReturn( null );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// get_hooks
	// -------------------------------------------------------------------------

	public function test_get_hooks_returns_four_hooks(): void {
		$hooks = Cron_Manager::get_hooks();

		$this->assertCount( 4, $hooks );
		$this->assertContains( 'sybgo_freeze_weekly_report', $hooks );
		$this->assertContains( 'sybgo_send_report_emails', $hooks );
		$this->assertContains( 'sybgo_cleanup_old_events', $hooks );
		$this->assertContains( 'sybgo_retry_failed_emails', $hooks );
	}

	// -------------------------------------------------------------------------
	// register / init — hook wiring
	// -------------------------------------------------------------------------

	public function test_register_adds_to_registrations(): void {
		$manager  = new Cron_Manager();
		$callback = static function (): void {};

		$manager->register( 'my_hook', 'daily', $callback, 'tomorrow 9:00' );

		Actions\expectAdded( 'my_hook' )->once();

		$manager->init();

		$this->assertTrue( true );
	}

	public function test_init_registers_cron_schedules_filter(): void {
		$manager = new Cron_Manager();

		Filters\expectAdded( 'cron_schedules' )->once();

		$manager->init();

		$this->assertTrue( true );
	}

	public function test_init_wires_all_registered_hooks(): void {
		$manager  = new Cron_Manager();
		$callback = static function (): void {};

		foreach ( Cron_Manager::get_hooks() as $hook ) {
			$manager->register( $hook, 'daily', $callback, 'tomorrow 3:00' );
			Actions\expectAdded( $hook )->once();
		}

		$manager->init();

		$this->assertTrue( true );
	}

	// -------------------------------------------------------------------------
	// deactivate
	// -------------------------------------------------------------------------

	public function test_deactivate_clears_all_hooks(): void {
		Functions\expect( 'wp_clear_scheduled_hook' )->times( 4 );

		Cron_Manager::deactivate();

		$this->assertTrue( true );
	}

	// -------------------------------------------------------------------------
	// add_cron_intervals
	// -------------------------------------------------------------------------

	public function test_add_cron_intervals_adds_weekly(): void {
		$manager = new Cron_Manager();
		$result  = $manager->add_cron_intervals( array() );

		$this->assertArrayHasKey( 'weekly', $result );
		$this->assertSame( 604800, $result['weekly']['interval'] );
	}

	public function test_add_cron_intervals_does_not_overwrite_existing_weekly(): void {
		$manager  = new Cron_Manager();
		$existing = array( 'weekly' => array( 'interval' => 999, 'display' => 'Custom' ) );

		$result = $manager->add_cron_intervals( $existing );

		$this->assertSame( 999, $result['weekly']['interval'] );
	}
}
