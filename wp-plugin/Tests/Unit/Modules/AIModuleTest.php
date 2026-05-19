<?php
/**
 * AI Module Unit Tests.
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
use Sybgo\Factory;
use Sybgo\Modules\AI_Module;

/**
 * Unit tests for AI_Module.
 */
class AIModuleTest extends TestCase {

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
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// boot — ability registration deferred to init hook
	// -------------------------------------------------------------------------

	/**
	 * boot() adds an 'init' action at priority 5 to register the ability.
	 *
	 * @return void
	 */
	public function test_boot_defers_ability_registration_to_init_hook(): void {
		Actions\expectAdded( 'init' )
			->once()
			->with( Mockery::type( 'callable' ), 5 );

		$module = new AI_Module( $this->factory, $this->abilities );
		$module->boot();

		$this->assertTrue( true );
	}

	/**
	 * The deferred callback registers the sybgo/generate-summary ability.
	 *
	 * @return void
	 */
	public function test_deferred_callback_registers_generate_summary_ability(): void {
		$captured_callback = null;

		Actions\expectAdded( 'init' )
			->once()
			->whenHappen(
				function ( callable $callback ) use ( &$captured_callback ): void {
					$captured_callback = $callback;
				}
			);

		$module = new AI_Module( $this->factory, $this->abilities );
		$module->boot();

		$this->abilities
			->shouldReceive( 'register' )
			->once()
			->with(
				'sybgo/generate-summary',
				Mockery::on(
					static function ( array $args ): bool {
						return isset( $args['label'], $args['execute_callback'], $args['permission_callback'] )
							&& is_callable( $args['execute_callback'] )
							&& is_callable( $args['permission_callback'] );
					}
				)
			);

		$this->assertIsCallable( $captured_callback );
		$captured_callback();

		$this->assertTrue( true );
	}

	// -------------------------------------------------------------------------
	// execute_callback — null-summarizer path
	// -------------------------------------------------------------------------

	/**
	 * execute_callback returns null when the AI summarizer is unavailable.
	 *
	 * @return void
	 */
	public function test_execute_callback_returns_null_when_no_summarizer(): void {
		$execute_callback  = null;
		$captured_callback = null;

		Actions\expectAdded( 'init' )
			->once()
			->whenHappen(
				function ( callable $callback ) use ( &$captured_callback ): void {
					$captured_callback = $callback;
				}
			);

		$module = new AI_Module( $this->factory, $this->abilities );
		$module->boot();

		$this->abilities
			->shouldReceive( 'register' )
			->once()
			->andReturnUsing(
				function ( string $name, array $args ) use ( &$execute_callback ): void {
					$execute_callback = $args['execute_callback'];
				}
			);

		$captured_callback();

		$this->factory->shouldReceive( 'create_ai_summarizer' )->once()->andReturn( null );

		$result = $execute_callback();

		$this->assertNull( $result );
	}

	/**
	 * execute_callback returns null when no frozen report exists.
	 *
	 * @return void
	 */
	public function test_execute_callback_returns_null_when_no_frozen_report(): void {
		$execute_callback  = null;
		$captured_callback = null;

		Actions\expectAdded( 'init' )
			->once()
			->whenHappen(
				function ( callable $callback ) use ( &$captured_callback ): void {
					$captured_callback = $callback;
				}
			);

		$module = new AI_Module( $this->factory, $this->abilities );
		$module->boot();

		$this->abilities
			->shouldReceive( 'register' )
			->once()
			->andReturnUsing(
				function ( string $name, array $args ) use ( &$execute_callback ): void {
					$execute_callback = $args['execute_callback'];
				}
			);

		$captured_callback();

		// Summarizer is available, but no frozen report.
		$ai_summarizer = Mockery::mock( \Sybgo\AI\AI_Summarizer::class );
		$this->factory->shouldReceive( 'create_ai_summarizer' )->once()->andReturn( $ai_summarizer );

		$report_repo = Mockery::mock( \Sybgo\Database\Report_Repository::class );
		$report_repo->shouldReceive( 'get_last_frozen' )->once()->andReturn( null );
		$this->factory->shouldReceive( 'create_report_repository' )->once()->andReturn( $report_repo );

		$result = $execute_callback();

		$this->assertNull( $result );
	}
}
