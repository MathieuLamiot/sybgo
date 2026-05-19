<?php
/**
 * Report Module Unit Tests.
 *
 * @package Sybgo\Tests\Unit\Modules
 */

declare(strict_types=1);

namespace Sybgo\Tests\Unit\Modules;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Sybgo\Admin\Admin_Manager;
use Sybgo\Admin\Dashboard_Widget;
use Sybgo\Admin\Reports_Page;
use Sybgo\Cron_Manager;
use Sybgo\Database\Aggregated_Event_Repository;
use Sybgo\Database\Event_Repository;
use Sybgo\Database\Report_Repository;
use Sybgo\Email\Email_Manager;
use Sybgo\Events\Event_Registry;
use Sybgo\Factory;
use Sybgo\Modules\Report_Module;
use Sybgo\Reports\Report_Manager;

/**
 * Unit tests for Report_Module.
 */
class ReportModuleTest extends TestCase {

	/**
	 * @var Factory&\Mockery\MockInterface
	 */
	private $factory;

	/**
	 * @var Cron_Manager&\Mockery\MockInterface
	 */
	private $cron;

	/**
	 * @var Admin_Manager&\Mockery\MockInterface
	 */
	private $admin;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->factory = Mockery::mock( Factory::class );
		$this->cron    = Mockery::mock( Cron_Manager::class );
		$this->admin   = Mockery::mock( Admin_Manager::class );

		// Stub WordPress functions used by factory calls.
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * Wire the factory mock to return typed repository mocks.
	 *
	 * @return void
	 */
	private function wire_factory(): void {
		$this->factory->shouldReceive( 'create_event_repository' )
			->andReturn( Mockery::mock( Event_Repository::class ) );
		$this->factory->shouldReceive( 'create_report_repository' )
			->andReturn( Mockery::mock( Report_Repository::class ) );
		$this->factory->shouldReceive( 'create_event_registry' )
			->andReturn( Mockery::mock( Event_Registry::class ) );
		$this->factory->shouldReceive( 'create_ai_summarizer' )
			->andReturn( null );
		$this->factory->shouldReceive( 'create_aggregated_event_repository' )
			->andReturn( Mockery::mock( Aggregated_Event_Repository::class ) );
		$this->factory->shouldReceive( 'create_report_manager' )
			->andReturn( Mockery::mock( Report_Manager::class ) );
		$this->factory->shouldReceive( 'create_email_manager' )
			->andReturn( Mockery::mock( Email_Manager::class ) );
	}

	// -------------------------------------------------------------------------
	// boot — admin page registration
	// -------------------------------------------------------------------------

	/**
	 * boot() registers Dashboard_Widget and Reports_Page with Admin_Manager.
	 *
	 * @return void
	 */
	public function test_boot_registers_dashboard_widget_and_reports_page(): void {
		$this->wire_factory();

		$this->admin
			->shouldReceive( 'register_page' )
			->twice()
			->with(
				Mockery::on(
					function ( object $page ): bool {
						return $page instanceof Dashboard_Widget || $page instanceof Reports_Page;
					}
				)
			);

		$this->cron->shouldReceive( 'register' )->once();

		$module = new Report_Module( $this->factory, $this->cron, $this->admin );
		$module->boot();

		$this->assertTrue( true );
	}

	// -------------------------------------------------------------------------
	// boot — cron registration
	// -------------------------------------------------------------------------

	/**
	 * boot() registers the sybgo_freeze_weekly_report cron hook.
	 *
	 * @return void
	 */
	public function test_boot_registers_freeze_cron(): void {
		$this->wire_factory();
		$this->admin->shouldReceive( 'register_page' )->twice();

		$this->cron
			->shouldReceive( 'register' )
			->once()
			->with(
				'sybgo_freeze_weekly_report',
				'weekly',
				Mockery::type( 'array' ),
				'next Sunday 23:55'
			);

		$module = new Report_Module( $this->factory, $this->cron, $this->admin );
		$module->boot();

		$this->assertTrue( true );
	}

	/**
	 * boot() wires the freeze cron callback to Report_Module::freeze_report_callback().
	 *
	 * @return void
	 */
	public function test_boot_freeze_cron_callback_points_to_module_method(): void {
		$this->wire_factory();
		$this->admin->shouldReceive( 'register_page' )->twice();

		$captured_callback = null;
		$this->cron
			->shouldReceive( 'register' )
			->once()
			->andReturnUsing(
				function ( string $hook, string $schedule, callable $callback ) use ( &$captured_callback ): void {
					$captured_callback = $callback;
				}
			);

		$module = new Report_Module( $this->factory, $this->cron, $this->admin );
		$module->boot();

		$this->assertIsArray( $captured_callback );
		$this->assertSame( $module, $captured_callback[0] );
		$this->assertSame( 'freeze_report_callback', $captured_callback[1] );
	}

	// -------------------------------------------------------------------------
	// freeze_report_callback
	// -------------------------------------------------------------------------

	/**
	 * freeze_report_callback() calls freeze_current_report() and logs success.
	 *
	 * @return void
	 */
	public function test_freeze_callback_invokes_report_manager_when_report_exists(): void {
		$report_manager = Mockery::mock( Report_Manager::class );
		$report_manager->shouldReceive( 'freeze_current_report' )->once()->andReturn( 42 );

		$this->factory->shouldReceive( 'create_report_manager' )->andReturn( $report_manager );

		$module = new Report_Module( $this->factory, $this->cron, $this->admin );
		$module->freeze_report_callback();

		$this->assertTrue( true );
	}

	/**
	 * freeze_report_callback() handles the case where no active report exists.
	 *
	 * @return void
	 */
	public function test_freeze_callback_handles_no_active_report(): void {
		$report_manager = Mockery::mock( Report_Manager::class );
		$report_manager->shouldReceive( 'freeze_current_report' )->once()->andReturn( null );

		$this->factory->shouldReceive( 'create_report_manager' )->andReturn( $report_manager );

		$module = new Report_Module( $this->factory, $this->cron, $this->admin );
		$module->freeze_report_callback();

		$this->assertTrue( true );
	}
}
