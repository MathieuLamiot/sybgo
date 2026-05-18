<?php
/**
 * Ability Manager Unit Tests.
 *
 * @package Sybgo\Tests\Unit
 */

declare(strict_types=1);

namespace Sybgo\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use PHPUnit\Framework\TestCase;
use Sybgo\Ability_Manager;

/**
 * Unit tests for Ability_Manager.
 */
class AbilityManagerTest extends TestCase {

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
		parent::tearDown();
	}

	/**
	 * init() hooks the registration closure on wp_abilities_api_init when WP7 is available.
	 *
	 * wp_register_ability() is defined as a no-op stub in bootstrap-unit.php, so
	 * function_exists('wp_register_ability') returns true in unit tests — this
	 * exercises the WP7-available path.
	 *
	 * @return void
	 */
	public function test_init_registers_wp_abilities_api_init_action(): void {
		$manager = new Ability_Manager();
		$manager->register( 'sybgo/test', array( 'label' => 'Test' ) );

		Actions\expectAdded( 'wp_abilities_api_init' )->once();

		$manager->init();

		$this->assertTrue( true );
	}

	/**
	 * init() with no registered abilities still hooks the action (WP7 available).
	 *
	 * The registration loop runs on the action callback — having zero abilities is fine.
	 *
	 * @return void
	 */
	public function test_init_with_no_registered_abilities_still_hooks_action(): void {
		$manager = new Ability_Manager();

		Actions\expectAdded( 'wp_abilities_api_init' )->once();

		$manager->init();

		$this->assertTrue( true );
	}

	/**
	 * Registering multiple abilities and calling init() once hooks the action once.
	 *
	 * @return void
	 */
	public function test_multiple_registrations_hook_action_once(): void {
		$manager = new Ability_Manager();
		$manager->register( 'sybgo/generate-summary', array( 'label' => 'Summary' ) );
		$manager->register( 'sybgo/track-events', array( 'label' => 'Events' ) );

		Actions\expectAdded( 'wp_abilities_api_init' )->once();

		$manager->init();

		$this->assertTrue( true );
	}
}
