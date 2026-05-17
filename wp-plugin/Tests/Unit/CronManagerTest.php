<?php
/**
 * Cron Manager Unit Tests
 *
 * @package Sybgo\Tests\Unit
 */

declare(strict_types=1);

namespace Sybgo\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Sybgo\Cron_Manager;
use Sybgo\Database\DatabaseManager;
use Sybgo\Email\Email_Manager;
use Sybgo\Reports\Report_Manager;

/**
 * Tests for Cron_Manager.
 */
class CronManagerTest extends TestCase {

	/** @var Report_Manager&\Mockery\MockInterface */
	private $report_manager;
	/** @var Email_Manager&\Mockery\MockInterface */
	private $email_manager;
	/** @var DatabaseManager&\Mockery\MockInterface */
	private $db_manager;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_html__' )->returnArg();
		$this->report_manager = Mockery::mock( Report_Manager::class );
		$this->email_manager  = Mockery::mock( Email_Manager::class );
		$this->db_manager     = Mockery::mock( DatabaseManager::class );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	private function make_manager(): Cron_Manager {
		return new Cron_Manager( $this->report_manager, $this->email_manager, $this->db_manager );
	}

	// -------------------------------------------------------------------------
	// get_hooks
	// -------------------------------------------------------------------------

	public function test_get_hooks_returns_four_hook_names(): void {
		$hooks = Cron_Manager::get_hooks();

		$this->assertCount( 4, $hooks );
		$this->assertContains( 'sybgo_freeze_weekly_report', $hooks );
		$this->assertContains( 'sybgo_send_report_emails', $hooks );
		$this->assertContains( 'sybgo_cleanup_old_events', $hooks );
		$this->assertContains( 'sybgo_retry_failed_emails', $hooks );
	}

	// -------------------------------------------------------------------------
	// init — schedule registration
	// -------------------------------------------------------------------------

	public function test_init_registers_cron_schedules_filter(): void {
		Functions\expect( 'wp_next_scheduled' )->andReturn( true );

		Filters\expectAdded( 'cron_schedules' )->once();

		$this->make_manager()->init();

		$this->assertTrue( true );
	}

	public function test_init_schedules_events_when_not_yet_scheduled(): void {
		Functions\expect( 'wp_next_scheduled' )->times( 4 )->andReturn( false );
		Functions\expect( 'wp_schedule_event' )->times( 4 );

		$this->make_manager()->init();

		$this->assertTrue( true );
	}

	public function test_init_skips_scheduling_when_already_scheduled(): void {
		Functions\expect( 'wp_next_scheduled' )->times( 4 )->andReturn( 1234567890 );
		Functions\expect( 'wp_schedule_event' )->never();

		$this->make_manager()->init();

		$this->assertTrue( true );
	}

	// -------------------------------------------------------------------------
	// deactivate
	// -------------------------------------------------------------------------

	public function test_deactivate_clears_all_four_hooks(): void {
		foreach ( Cron_Manager::get_hooks() as $hook ) {
			Functions\expect( 'wp_clear_scheduled_hook' )
				->once()
				->with( $hook );
		}

		Cron_Manager::deactivate();

		$this->assertTrue( true );
	}

	// -------------------------------------------------------------------------
	// add_cron_intervals
	// -------------------------------------------------------------------------

	public function test_add_cron_intervals_adds_weekly_schedule(): void {
		$result = $this->make_manager()->add_cron_intervals( array() );

		$this->assertArrayHasKey( 'weekly', $result );
		$this->assertSame( 604800, $result['weekly']['interval'] );
	}

	public function test_add_cron_intervals_does_not_overwrite_existing_weekly(): void {
		$existing = array( 'weekly' => array( 'interval' => 999, 'display' => 'Custom' ) );

		$result = $this->make_manager()->add_cron_intervals( $existing );

		$this->assertSame( 999, $result['weekly']['interval'] );
	}
}
