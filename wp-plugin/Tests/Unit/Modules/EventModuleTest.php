<?php
/**
 * Event Module Unit Tests.
 *
 * @package Sybgo\Tests\Unit\Modules
 */

declare(strict_types=1);

namespace Sybgo\Tests\Unit\Modules;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Sybgo\Ability_Manager;
use Sybgo\Database\Aggregated_Event_Repository;
use Sybgo\Database\Event_Repository;
use Sybgo\Events\Event_Tracker;
use Sybgo\Factory;
use Sybgo\Modules\Event_Module;

/**
 * Unit tests for Event_Module.
 */
class EventModuleTest extends TestCase {

	/**
	 * @var Factory&\Mockery\MockInterface
	 */
	private $factory;

	/**
	 * @var Ability_Manager&\Mockery\MockInterface
	 */
	private $abilities;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->factory   = Mockery::mock( Factory::class );
		$this->abilities = Mockery::mock( Ability_Manager::class );

		Functions\when( '__' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( true );
		// sybgo_init_api() is a real function loaded via the lib autoloader;
		// Patchwork cannot intercept it. Let it run — its only side-effect is
		// setting the $sybgo_api_event_repo global, which we verify directly.
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * Build a module with shared mock infrastructure pre-wired.
	 *
	 * Sets up Factory to return mocked repositories and expects set_event_tracker().
	 *
	 * @param Event_Repository|null            $event_repo      Optional: specific repo mock to use.
	 * @param Aggregated_Event_Repository|null $aggregated_repo Optional: specific aggregated repo mock.
	 * @return array{module: Event_Module, event_repo: Event_Repository}
	 */
	private function build_module( $event_repo = null, $aggregated_repo = null ): array {
		$event_repo      = $event_repo      ?? Mockery::mock( Event_Repository::class );
		$aggregated_repo = $aggregated_repo ?? Mockery::mock( Aggregated_Event_Repository::class );

		$this->factory->shouldReceive( 'create_event_repository' )->andReturn( $event_repo );
		$this->factory->shouldReceive( 'create_aggregated_event_repository' )->andReturn( $aggregated_repo );
		$this->factory->shouldReceive( 'set_event_tracker' )->once()->with( Mockery::type( Event_Tracker::class ) );
		$this->abilities->shouldReceive( 'register' )->byDefault();

		return array(
			'module'     => new Event_Module( $this->factory, $this->abilities ),
			'event_repo' => $event_repo,
		);
	}

	// -------------------------------------------------------------------------
	// boot — event tracking initialisation
	// -------------------------------------------------------------------------

	/**
	 * boot() creates an Event_Tracker and stores it on the factory.
	 *
	 * @return void
	 */
	public function test_boot_stores_event_tracker_on_factory(): void {
		[  'module' => $module ] = $this->build_module();
		$module->boot();

		// Assertion is satisfied by the ->once() expectation on set_event_tracker above.
		$this->assertTrue( true );
	}

	// -------------------------------------------------------------------------
	// boot — public extensibility API
	// -------------------------------------------------------------------------

	/**
	 * boot() calls sybgo_init_api() — verified via global side-effect.
	 *
	 * sybgo_init_api() sets $sybgo_api_event_repo globally; we check that.
	 *
	 * @return void
	 */
	public function test_boot_exposes_extensibility_api(): void {
		[  'module' => $module, 'event_repo' => $event_repo ] = $this->build_module();
		$module->boot();

		global $sybgo_api_event_repo;
		$this->assertSame( $event_repo, $sybgo_api_event_repo );
	}

	// -------------------------------------------------------------------------
	// boot — ability registration
	// -------------------------------------------------------------------------

	/**
	 * boot() schedules sybgo/track-events ability registration on 'init' at priority 5.
	 *
	 * @return void
	 */
	public function test_boot_registers_track_events_on_init_hook_priority_5(): void {
		[  'module' => $module ] = $this->build_module();

		Actions\expectAdded( 'init' )->once()->with( Mockery::type( 'Closure' ), 5, Mockery::any() );

		$module->boot();

		$this->assertTrue( true );
	}

	/**
	 * The deferred init callback calls Ability_Manager::register() for 'sybgo/track-events'.
	 *
	 * @return void
	 */
	public function test_deferred_callback_registers_track_events_ability(): void {
		$captured_callback = null;

		Actions\expectAdded( 'init' )->once()->whenHappen(
			function ( $callback ) use ( &$captured_callback ): void {
				$captured_callback = $callback;
			}
		);

		$this->abilities
			->shouldReceive( 'register' )
			->once()
			->with(
				'sybgo/track-events',
				Mockery::on(
					function ( array $args ): bool {
						return 'sybgo' === ( $args['category'] ?? '' )
							&& isset( $args['execute_callback'] )
							&& isset( $args['permission_callback'] );
					}
				)
			);

		[  'module' => $module ] = $this->build_module();
		$module->boot();

		$this->assertNotNull( $captured_callback );
		( $captured_callback )();
	}
}
