<?php
/**
 * Uninstaller Test
 *
 * @package Sybgo\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Sybgo\Tests\Unit\Admin;

use Sybgo\Admin\Uninstaller;
use Sybgo\Admin\Settings_Page;
use Sybgo\Database\DatabaseManager;
use Sybgo\Sybgo;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Test Uninstaller class.
 */
class UninstallerTest extends TestCase {
	use MockeryPHPUnitIntegration;

	/**
	 * Uninstaller instance under test.
	 *
	 * @var Uninstaller
	 */
	private Uninstaller $uninstaller;

	/**
	 * Set up test environment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->uninstaller = new Uninstaller();

		// Provide a $wpdb global mock with a known prefix.
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$GLOBALS['wpdb'] = $wpdb;
	}

	/**
	 * Tear down test environment.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	/**
	 * Test drop_tables() issues DROP TABLE for all four plugin tables.
	 *
	 * @return void
	 */
	public function test_drop_tables_drops_all_four_tables(): void {
		$table_names = array_values( ( new DatabaseManager() )->get_table_names() );

		$GLOBALS['wpdb']->shouldReceive( 'query' )
			->times( 4 )
			->withArgs( function( string $sql ) use ( $table_names ): bool {
				foreach ( $table_names as $table ) {
					if ( false !== strpos( $sql, $table ) ) {
						return true;
					}
				}
				return false;
			} );

		$this->uninstaller->drop_tables();
	}

	/**
	 * Test clear_cron_hooks() calls wp_clear_scheduled_hook for every registered hook.
	 *
	 * @return void
	 */
	public function test_clear_cron_hooks_clears_all_hooks(): void {
		$hooks = Sybgo::get_cron_hooks();

		Functions\expect( 'wp_clear_scheduled_hook' )
			->times( count( $hooks ) )
			->withArgs( function( string $hook ) use ( $hooks ): bool {
				return in_array( $hook, $hooks, true );
			} );

		$this->uninstaller->clear_cron_hooks();
	}

	/**
	 * Test delete_options() calls delete_option for every plugin option.
	 *
	 * @return void
	 */
	public function test_delete_options_deletes_all_options(): void {
		$options = Settings_Page::get_option_names();

		Functions\expect( 'delete_option' )
			->times( count( $options ) )
			->withArgs( function( string $option ) use ( $options ): bool {
				return in_array( $option, $options, true );
			} );

		$this->uninstaller->delete_options();
	}

	/**
	 * Test run() executes the full cleanup sequence.
	 *
	 * @return void
	 */
	public function test_run_executes_full_cleanup(): void {
		$GLOBALS['wpdb']->shouldReceive( 'query' )
			->times( count( ( new DatabaseManager() )->get_table_names() ) );

		Functions\expect( 'wp_clear_scheduled_hook' )
			->times( count( Sybgo::get_cron_hooks() ) );

		Functions\expect( 'delete_option' )
			->times( count( Settings_Page::get_option_names() ) );

		$this->uninstaller->run();
	}
}
