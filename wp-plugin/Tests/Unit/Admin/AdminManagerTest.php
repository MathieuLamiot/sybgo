<?php
/**
 * Admin Manager Unit Tests.
 *
 * @package Sybgo\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Sybgo\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Sybgo\Admin\Admin_Manager;

/**
 * Unit tests for Admin_Manager.
 */
class AdminManagerTest extends TestCase {

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
	 * init() calls init() on every registered page.
	 *
	 * @return void
	 */
	public function test_init_calls_init_on_each_page(): void {
		$page_a = Mockery::mock( 'page_a' );
		$page_a->shouldReceive( 'init' )->once();

		$page_b = Mockery::mock( 'page_b' );
		$page_b->shouldReceive( 'init' )->once();

		$manager = new Admin_Manager();
		$manager->register_page( $page_a );
		$manager->register_page( $page_b );
		$manager->init();

		$this->assertTrue( true );
	}

	/**
	 * init() wires the registered cleanup handler on admin_post_sybgo_run_cleanup.
	 *
	 * @return void
	 */
	public function test_init_registers_cleanup_handler(): void {
		$manager = new Admin_Manager();
		$manager->register_cleanup_handler( static function (): void {} );

		Actions\expectAdded( 'admin_post_sybgo_run_cleanup' )->once();

		$manager->init();

		$this->assertTrue( true );
	}

	/**
	 * init() wires the registered asset enqueuer on admin_enqueue_scripts.
	 *
	 * @return void
	 */
	public function test_init_registers_asset_enqueuer(): void {
		$manager = new Admin_Manager();
		$manager->register_asset_enqueuer( static function ( string $hook ): void {} );

		Actions\expectAdded( 'admin_enqueue_scripts' )->once();

		$manager->init();

		$this->assertTrue( true );
	}

	/**
	 * init() with no cleanup handler or asset enqueuer does not wire those actions.
	 *
	 * @return void
	 */
	public function test_init_skips_optional_hooks_when_not_registered(): void {
		$manager = new Admin_Manager();

		Actions\expectAdded( 'admin_post_sybgo_run_cleanup' )->never();
		Actions\expectAdded( 'admin_enqueue_scripts' )->never();

		$manager->init();

		$this->assertTrue( true );
	}

	/**
	 * init() with no registered pages does not throw.
	 *
	 * @return void
	 */
	public function test_init_with_no_pages_does_not_throw(): void {
		$manager = new Admin_Manager();
		$manager->init();

		$this->assertTrue( true );
	}
}
