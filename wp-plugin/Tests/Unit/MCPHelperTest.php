<?php
/**
 * Unit Tests for MCP_Helper.
 *
 * Validates hook registration idempotency and asset enqueuing guards.
 *
 * @package Sybgo\Tests\Unit
 */

declare(strict_types=1);

namespace Sybgo\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WPMedia\MCPHelper\MCP_Helper;

/**
 * Tests for MCP_Helper.
 */
class MCPHelperTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Reset the static guard so each test starts fresh.
		$ref = new \ReflectionProperty( MCP_Helper::class, 'initialized' );
		$ref->setAccessible( true );
		$ref->setValue( null, false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// init() — hook registration (AC8)
	// -------------------------------------------------------------------------

	/** AC8: First call registers all four hooks. */
	public function test_init_registers_all_four_hooks(): void {
		Actions\expectAdded( 'show_user_profile' )->once();
		Actions\expectAdded( 'edit_user_profile' )->once();
		Actions\expectAdded( 'admin_enqueue_scripts' )->once();
		Actions\expectAdded( 'wp_ajax_mcp_helper_get_tools' )->once();

		MCP_Helper::init();

		$this->addToAssertionCount( 4 ); // Brain Monkey expectations count as assertions.
	}

	/** AC8: Second call is a no-op — no hooks registered twice. */
	public function test_init_is_idempotent_on_second_call(): void {
		Actions\expectAdded( 'show_user_profile' )->once();
		Actions\expectAdded( 'edit_user_profile' )->once();
		Actions\expectAdded( 'admin_enqueue_scripts' )->once();
		Actions\expectAdded( 'wp_ajax_mcp_helper_get_tools' )->once();

		MCP_Helper::init();
		MCP_Helper::init(); // second call — expectations are "once", so no second registration allowed.

		$this->addToAssertionCount( 4 );
	}

	/** AC8: Third call is also a no-op. */
	public function test_init_is_idempotent_on_multiple_calls(): void {
		Actions\expectAdded( 'show_user_profile' )->once();
		Actions\expectAdded( 'edit_user_profile' )->once();
		Actions\expectAdded( 'admin_enqueue_scripts' )->once();
		Actions\expectAdded( 'wp_ajax_mcp_helper_get_tools' )->once();

		MCP_Helper::init();
		MCP_Helper::init();
		MCP_Helper::init();

		$this->addToAssertionCount( 4 );
	}

	// -------------------------------------------------------------------------
	// enqueue_assets() — page guard
	// -------------------------------------------------------------------------

	/** Asset enqueuing skips non-profile pages without touching wp_register_style. */
	public function test_enqueue_assets_skips_non_profile_pages(): void {
		Functions\when( 'wp_script_is' )->justReturn( false );
		Functions\expect( 'wp_register_style' )->never();
		Functions\expect( 'wp_enqueue_style' )->never();

		MCP_Helper::enqueue_assets( 'index.php' );

		$this->addToAssertionCount( 2 );
	}

	/** Asset enqueuing also skips settings pages. */
	public function test_enqueue_assets_skips_settings_page(): void {
		Functions\when( 'wp_script_is' )->justReturn( false );
		Functions\expect( 'wp_register_style' )->never();
		Functions\expect( 'wp_enqueue_style' )->never();

		MCP_Helper::enqueue_assets( 'options-general.php' );

		$this->addToAssertionCount( 2 );
	}

	/** Double-enqueue guard: if script already registered, skip everything. */
	public function test_enqueue_assets_skips_if_already_registered(): void {
		// wp_script_is returns true → already registered → should short-circuit.
		Functions\when( 'wp_script_is' )->justReturn( true );
		Functions\expect( 'wp_register_style' )->never();
		Functions\expect( 'wp_enqueue_style' )->never();
		Functions\expect( 'wp_register_script' )->never();

		MCP_Helper::enqueue_assets( 'profile.php' );

		$this->addToAssertionCount( 3 );
	}
}
