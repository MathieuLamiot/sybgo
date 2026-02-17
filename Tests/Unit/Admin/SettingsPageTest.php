<?php
/**
 * Settings Page Test
 *
 * @package Rocket\Sybgo\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Rocket\Sybgo\Tests\Unit\Admin;

use Rocket\Sybgo\Admin\Settings_Page;
use Rocket\Sybgo\Events\Event_Registry;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Test Settings_Page class.
 */
class SettingsPageTest extends TestCase {
	/**
	 * Settings page instance.
	 *
	 * @var Settings_Page
	 */
	private Settings_Page $settings_page;

	/**
	 * Event registry mock.
	 *
	 * @var Event_Registry
	 */
	private $event_registry;

	/**
	 * Set up test environment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->event_registry = Mockery::mock( Event_Registry::class );
		$this->settings_page  = new Settings_Page( $this->event_registry );

		// Mock WordPress functions.
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( '__' )->returnArg();
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
	 * Test get_recipients() returns array from settings.
	 *
	 * @return void
	 */
	public function test_get_recipients_returns_array() {
		$settings = [
			'email_recipients' => "admin@example.com\nuser@example.com\neditor@example.com",
		];

		Functions\expect( 'get_option' )
			->once()
			->with( 'sybgo_settings', [] )
			->andReturn( $settings );

		Functions\expect( 'is_email' )
			->times( 3 )
			->andReturn( true );

		$recipients = Settings_Page::get_recipients();

		$this->assertIsArray( $recipients );
		$this->assertCount( 3, $recipients );
		$this->assertContains( 'admin@example.com', $recipients );
		$this->assertContains( 'user@example.com', $recipients );
		$this->assertContains( 'editor@example.com', $recipients );
	}

	/**
	 * Test get_recipients() filters empty lines.
	 *
	 * @return void
	 */
	public function test_get_recipients_filters_empty_lines() {
		$settings = [
			'email_recipients' => "admin@example.com\n\nuser@example.com\n  \n",
		];

		Functions\expect( 'get_option' )
			->once()
			->with( 'sybgo_settings', [] )
			->andReturn( $settings );

		Functions\expect( 'is_email' )
			->andReturnUsing( function( $email ) {
				$trimmed = trim( $email );
				return ! empty( $trimmed ) && filter_var( $trimmed, FILTER_VALIDATE_EMAIL ) !== false;
			} );

		$recipients = Settings_Page::get_recipients();

		$this->assertCount( 2, $recipients );
		$this->assertContains( 'admin@example.com', $recipients );
		$this->assertContains( 'user@example.com', $recipients );
	}

	/**
	 * Test get_recipients() returns admin email when no recipients configured.
	 *
	 * @return void
	 */
	public function test_get_recipients_defaults_to_admin_email() {
		Functions\expect( 'get_option' )
			->once()
			->with( 'sybgo_settings', [] )
			->andReturn( [] );

		Functions\expect( 'get_option' )
			->once()
			->with( 'admin_email' )
			->andReturn( 'admin@example.com' );

		Functions\expect( 'is_email' )
			->andReturn( true );

		$recipients = Settings_Page::get_recipients();

		$this->assertIsArray( $recipients );
		$this->assertCount( 1, $recipients );
		$this->assertContains( 'admin@example.com', $recipients );
	}

	/**
	 * Test get_recipients() validates email addresses.
	 *
	 * @return void
	 */
	public function test_get_recipients_validates_emails() {
		$settings = [
			'email_recipients' => "admin@example.com\ninvalid-email\nuser@example.com",
		];

		Functions\expect( 'get_option' )
			->once()
			->with( 'sybgo_settings', [] )
			->andReturn( $settings );

		Functions\expect( 'is_email' )
			->andReturnUsing( function( $email ) {
				return filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false;
			} );

		$recipients = Settings_Page::get_recipients();

		$this->assertIsArray( $recipients );
		// Should have 2 valid emails (invalid-email is filtered out)
		$this->assertCount( 2, $recipients );
		$this->assertContains( 'admin@example.com', $recipients );
		$this->assertContains( 'user@example.com', $recipients );
		$this->assertNotContains( 'invalid-email', $recipients );
	}

	/**
	 * Test is_event_type_enabled() returns true for enabled types.
	 *
	 * @return void
	 */
	public function test_is_event_type_enabled_true() {
		$settings = [
			'enabled_event_types' => [ 'posts', 'users', 'comments' ],
		];

		Functions\expect( 'get_option' )
			->once()
			->with( 'sybgo_settings', [] )
			->andReturn( $settings );

		$this->assertTrue( Settings_Page::is_event_type_enabled( 'posts' ) );
	}

	/**
	 * Test is_event_type_enabled() returns false for disabled types.
	 *
	 * @return void
	 */
	public function test_is_event_type_enabled_false() {
		$settings = [
			'enabled_event_types' => [ 'posts', 'users' ],
		];

		Functions\expect( 'get_option' )
			->once()
			->with( 'sybgo_settings', [] )
			->andReturn( $settings );

		$this->assertFalse( Settings_Page::is_event_type_enabled( 'comments' ) );
	}

	/**
	 * Test is_event_type_enabled() defaults to true when no settings.
	 *
	 * @return void
	 */
	public function test_is_event_type_enabled_defaults_true() {
		Functions\expect( 'get_option' )
			->once()
			->with( 'sybgo_settings', [] )
			->andReturn( [] );

		$this->assertTrue( Settings_Page::is_event_type_enabled( 'posts' ) );
	}

	/**
	 * Test get_edit_threshold() returns configured value.
	 *
	 * @return void
	 */
	public function test_get_edit_threshold() {
		$settings = [
			'edit_magnitude_threshold' => 10,
		];

		Functions\expect( 'get_option' )
			->once()
			->with( 'sybgo_settings', [] )
			->andReturn( $settings );

		$threshold = Settings_Page::get_edit_threshold();

		$this->assertEquals( 10, $threshold );
	}

	/**
	 * Test get_edit_threshold() returns default 5.
	 *
	 * @return void
	 */
	public function test_get_edit_threshold_default() {
		Functions\expect( 'get_option' )
			->once()
			->with( 'sybgo_settings', [] )
			->andReturn( [] );

		$threshold = Settings_Page::get_edit_threshold();

		$this->assertEquals( 5, $threshold );
	}

	/**
	 * Test sanitize_settings() validates email recipients.
	 *
	 * @return void
	 */
	public function test_sanitize_settings() {
		$input = [
			'email_recipients'          => "admin@example.com\nuser@example.com",
			'edit_magnitude_threshold'  => 15,
			'enabled_event_types'       => [ 'posts', 'users' ],
			'from_name'                 => 'Test Site',
			'from_email'                => 'noreply@example.com',
		];

		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'is_email' )->justReturn( true );
		Functions\when( 'absint' )->alias( function( $value ) {
			return abs( (int) $value );
		} );

		$result = $this->settings_page->sanitize_settings( $input );

		$this->assertIsArray( $result );
		$this->assertEquals( 15, $result['edit_magnitude_threshold'] );
		$this->assertArrayHasKey( 'email_recipients', $result );
		$this->assertArrayHasKey( 'enabled_event_types', $result );
	}
}
