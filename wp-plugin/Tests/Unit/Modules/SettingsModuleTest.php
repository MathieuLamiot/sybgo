<?php
/**
 * Settings Module Unit Tests.
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
use Sybgo\Admin\Settings_Page;
use Sybgo\Cron_Manager;
use Sybgo\Database\DatabaseManager;
use Sybgo\Database\Db_Stats;
use Sybgo\Events\Event_Registry;
use Sybgo\Factory;
use Sybgo\Modules\Settings_Module;

/**
 * Unit tests for Settings_Module.
 */
class SettingsModuleTest extends TestCase {

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

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * Wire factory to return required mocks.
	 *
	 * @return void
	 */
	private function wire_factory(): void {
		$this->factory->shouldReceive( 'create_event_registry' )
			->andReturn( Mockery::mock( Event_Registry::class ) );
		$this->factory->shouldReceive( 'create_db_stats' )
			->andReturn( Mockery::mock( Db_Stats::class ) );
	}

	// -------------------------------------------------------------------------
	// boot — admin registration
	// -------------------------------------------------------------------------

	/**
	 * boot() registers Settings_Page with Admin_Manager.
	 *
	 * @return void
	 */
	public function test_boot_registers_settings_page(): void {
		$this->wire_factory();

		$this->admin
			->shouldReceive( 'register_page' )
			->once()
			->with( Mockery::type( Settings_Page::class ) );

		$this->admin->shouldReceive( 'register_cleanup_handler' )->once();
		$this->admin->shouldReceive( 'register_asset_enqueuer' )->once();
		$this->cron->shouldReceive( 'register' )->once();

		$module = new Settings_Module( $this->factory, $this->cron, $this->admin );
		$module->boot();

		$this->assertTrue( true );
	}

	/**
	 * boot() registers the cleanup handler pointing to the module method.
	 *
	 * @return void
	 */
	public function test_boot_cleanup_handler_points_to_module_method(): void {
		$this->wire_factory();

		$this->admin->shouldReceive( 'register_page' )->once();
		$this->admin->shouldReceive( 'register_asset_enqueuer' )->once();
		$this->cron->shouldReceive( 'register' )->once();

		$captured_callback = null;
		$this->admin
			->shouldReceive( 'register_cleanup_handler' )
			->once()
			->andReturnUsing(
				function ( callable $callback ) use ( &$captured_callback ): void {
					$captured_callback = $callback;
				}
			);

		$module = new Settings_Module( $this->factory, $this->cron, $this->admin );
		$module->boot();

		$this->assertSame( array( $module, 'handle_manual_cleanup' ), $captured_callback );
	}

	/**
	 * boot() registers the asset enqueuer pointing to the module method.
	 *
	 * @return void
	 */
	public function test_boot_asset_enqueuer_points_to_module_method(): void {
		$this->wire_factory();

		$this->admin->shouldReceive( 'register_page' )->once();
		$this->admin->shouldReceive( 'register_cleanup_handler' )->once();
		$this->cron->shouldReceive( 'register' )->once();

		$captured_callback = null;
		$this->admin
			->shouldReceive( 'register_asset_enqueuer' )
			->once()
			->andReturnUsing(
				function ( callable $callback ) use ( &$captured_callback ): void {
					$captured_callback = $callback;
				}
			);

		$module = new Settings_Module( $this->factory, $this->cron, $this->admin );
		$module->boot();

		$this->assertSame( array( $module, 'enqueue_admin_assets' ), $captured_callback );
	}

	// -------------------------------------------------------------------------
	// boot — cron registration
	// -------------------------------------------------------------------------

	/**
	 * boot() registers the sybgo_cleanup_old_events cron hook.
	 *
	 * @return void
	 */
	public function test_boot_registers_cleanup_cron(): void {
		$this->wire_factory();

		$this->admin->shouldReceive( 'register_page' )->once();
		$this->admin->shouldReceive( 'register_cleanup_handler' )->once();
		$this->admin->shouldReceive( 'register_asset_enqueuer' )->once();

		$this->cron
			->shouldReceive( 'register' )
			->once()
			->with(
				'sybgo_cleanup_old_events',
				'daily',
				Mockery::type( 'array' ),
				'tomorrow 3:00'
			);

		$module = new Settings_Module( $this->factory, $this->cron, $this->admin );
		$module->boot();

		$this->assertTrue( true );
	}

	/**
	 * boot() wires the cleanup cron callback to the module method.
	 *
	 * @return void
	 */
	public function test_boot_cleanup_cron_callback_points_to_module_method(): void {
		$this->wire_factory();

		$this->admin->shouldReceive( 'register_page' )->once();
		$this->admin->shouldReceive( 'register_cleanup_handler' )->once();
		$this->admin->shouldReceive( 'register_asset_enqueuer' )->once();

		$captured_callback = null;
		$this->cron
			->shouldReceive( 'register' )
			->once()
			->andReturnUsing(
				function ( string $hook, string $schedule, callable $callback ) use ( &$captured_callback ): void {
					$captured_callback = $callback;
				}
			);

		$module = new Settings_Module( $this->factory, $this->cron, $this->admin );
		$module->boot();

		$this->assertSame( array( $module, 'cleanup_old_events_callback' ), $captured_callback );
	}

	// -------------------------------------------------------------------------
	// cleanup_old_events_callback
	// -------------------------------------------------------------------------

	/**
	 * cleanup_old_events_callback() calls cleanup_old_events() and logs when rows deleted.
	 *
	 * @return void
	 */
	public function test_cleanup_callback_invokes_db_manager(): void {
		Functions\when( 'get_option' )->justReturn( '30' );

		$db_manager = Mockery::mock( DatabaseManager::class );
		$db_manager->shouldReceive( 'cleanup_old_events' )
			->once()
			->with( Mockery::type( 'int' ) )
			->andReturn( 5 );

		$this->factory->shouldReceive( 'create_database_manager' )->andReturn( $db_manager );

		$module = new Settings_Module( $this->factory, $this->cron, $this->admin );
		$module->cleanup_old_events_callback();

		$this->assertTrue( true );
	}

	/**
	 * cleanup_old_events_callback() is silent when no rows are deleted.
	 *
	 * @return void
	 */
	public function test_cleanup_callback_silent_when_no_rows_deleted(): void {
		Functions\when( 'get_option' )->justReturn( '30' );

		$db_manager = Mockery::mock( DatabaseManager::class );
		$db_manager->shouldReceive( 'cleanup_old_events' )
			->once()
			->andReturn( 0 );

		$this->factory->shouldReceive( 'create_database_manager' )->andReturn( $db_manager );

		$module = new Settings_Module( $this->factory, $this->cron, $this->admin );
		$module->cleanup_old_events_callback();

		$this->assertTrue( true );
	}
}
