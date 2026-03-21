<?php
/**
 * Settings Page Database Section Tests
 *
 * @package Sybgo\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Sybgo\Tests\Unit\Admin;

use Sybgo\Admin\Settings_Page;
use Sybgo\Events\Event_Registry;
use Sybgo\Database\DB_Stats;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Test Settings_Page database management section.
 */
class SettingsPageDatabaseSectionTest extends TestCase {

	/**
	 * Settings page instance.
	 *
	 * @var Settings_Page
	 */
	private Settings_Page $settings_page;

	/**
	 * Event registry mock.
	 *
	 * @var Mockery\MockInterface
	 */
	private $event_registry;

	/**
	 * DB Stats mock.
	 *
	 * @var Mockery\MockInterface
	 */
	private $db_stats;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->event_registry = Mockery::mock( Event_Registry::class );
		$this->db_stats       = Mockery::mock( DB_Stats::class );
		$this->settings_page  = new Settings_Page( $this->event_registry, $this->db_stats );

		// Mock WordPress functions.
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'absint' )->alias( function( $value ) {
			return abs( (int) $value );
		} );
	}

	/**
	 * Tear down test environment.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * Test that sanitize_settings defaults retention_days to 90 when not provided.
	 */
	public function test_sanitize_settings_defaults_retention_days_to_90(): void {
		$result = $this->settings_page->sanitize_settings( array() );

		$this->assertSame( 90, $result['retention_days'] );
	}

	/**
	 * Test that sanitize_settings clamps retention_days to minimum of 1.
	 */
	public function test_sanitize_settings_clamps_retention_days_minimum_to_1(): void {
		$result = $this->settings_page->sanitize_settings( array( 'retention_days' => '0' ) );

		$this->assertSame( 1, $result['retention_days'] );
	}

	/**
	 * Test that sanitize_settings preserves a valid retention_days value.
	 */
	public function test_sanitize_settings_preserves_valid_retention_days(): void {
		$result = $this->settings_page->sanitize_settings( array( 'retention_days' => '180' ) );

		$this->assertSame( 180, $result['retention_days'] );
	}

	/**
	 * Test that get_retention_days returns default when option is missing.
	 */
	public function test_get_retention_days_returns_default_when_option_missing(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$result = Settings_Page::get_retention_days();

		$this->assertSame( 90, $result );
	}

	/**
	 * Test that get_retention_days returns stored value.
	 */
	public function test_get_retention_days_returns_stored_value(): void {
		Functions\when( 'get_option' )->justReturn( array( 'retention_days' => 180 ) );

		$result = Settings_Page::get_retention_days();

		$this->assertSame( 180, $result );
	}
}
