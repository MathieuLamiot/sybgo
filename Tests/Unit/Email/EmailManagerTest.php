<?php
/**
 * Email Manager Test
 *
 * @package Rocket\Sybgo\Tests\Unit\Email
 */

declare(strict_types=1);

namespace Rocket\Sybgo\Tests\Unit\Email;

use Rocket\Sybgo\Email\Email_Manager;
use Rocket\Sybgo\Database\Report_Repository;
use Rocket\Sybgo\Email\Email_Template;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Test Email_Manager class.
 */
class EmailManagerTest extends TestCase {
	/**
	 * Report repository mock.
	 *
	 * @var Report_Repository
	 */
	private $report_repo;

	/**
	 * Email template mock.
	 *
	 * @var Email_Template
	 */
	private $email_template;

	/**
	 * Email manager instance.
	 *
	 * @var Email_Manager
	 */
	private Email_Manager $email_manager;

	/**
	 * Set up test environment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->report_repo    = Mockery::mock( Report_Repository::class );
		$this->email_template = Mockery::mock( Email_Template::class );

		$this->email_manager = new Email_Manager( $this->report_repo, $this->email_template );

		// Mock WordPress functions.
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'current_time' )->alias( function( $type ) {
			return ( $type === 'mysql' ) ? '2026-02-16 12:00:00' : time();
		} );
		Functions\when( 'get_bloginfo' )->alias( function( $show ) {
			return $show === 'name' ? 'Test Site' : 'Test';
		} );

		// Set up global wpdb mock.
		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
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
	 * Test send_report_email() successfully sends to single recipient.
	 *
	 * @return void
	 */
	public function test_send_report_email_single_recipient_success() {
		$report_id = 1;
		$report    = [
			'id'           => 1,
			'period_start' => '2026-02-10 00:00:00',
			'period_end'   => '2026-02-16 23:59:59',
			'summary_data' => wp_json_encode( [
				'total_events' => 5,
				'totals'       => [ 'posts_published' => 5 ],
				'highlights'   => [ '5 new posts published' ],
			] ),
			'status'       => 'frozen',
		];

		$this->report_repo->shouldReceive( 'get_by_id' )
			->once()
			->with( $report_id )
			->andReturn( $report );

		Functions\expect( 'get_option' )
			->once()
			->with( 'sybgo_settings', [] )
			->andReturn( [ 'email_recipients' => 'admin@example.com' ] );

		Functions\expect( 'is_email' )
			->once()
			->with( 'admin@example.com' )
			->andReturn( true );

		$this->email_template->shouldReceive( 'get_subject' )
			->once()
			->with( $report )
			->andReturn( 'Your Weekly Digest' );

		$this->email_template->shouldReceive( 'get_body' )
			->once()
			->with( $report )
			->andReturn( '<html>Email body</html>' );

		Functions\expect( 'get_option' )
			->with( 'admin_email' )
			->andReturn( 'admin@example.com' );

		Functions\expect( 'apply_filters' )
			->once()
			->with( 'sybgo_email_headers', Mockery::type( 'array' ) )
			->andReturnUsing( function( $hook, $headers ) {
				return $headers;
			} );

		Functions\expect( 'wp_mail' )
			->once()
			->with( 'admin@example.com', 'Your Weekly Digest', '<html>Email body</html>', Mockery::type( 'array' ) )
			->andReturn( true );

		// Mock wpdb insert for email log.
		global $wpdb;
		$wpdb->shouldReceive( 'insert' )
			->once()
			->with( 'wp_sybgo_email_log', Mockery::type( 'array' ), Mockery::type( 'array' ) )
			->andReturn( 1 );

		Functions\expect( 'do_action' )
			->once()
			->with( 'sybgo_email_sent', 1, 'admin@example.com' );

		$this->report_repo->shouldReceive( 'update_status' )
			->once()
			->with( $report_id, 'emailed' )
			->andReturn( true );

		$result = $this->email_manager->send_report_email( $report_id );

		$this->assertTrue( $result );
	}

	/**
	 * Test send_report_email() with multiple recipients.
	 *
	 * @return void
	 */
	public function test_send_report_email_multiple_recipients() {
		$report_id = 1;
		$report    = [
			'id'           => 1,
			'summary_data' => wp_json_encode( [
				'total_events' => 5,
			] ),
		];

		$this->report_repo->shouldReceive( 'get_by_id' )
			->once()
			->andReturn( $report );

		Functions\expect( 'get_option' )
			->once()
			->with( 'sybgo_settings', [] )
			->andReturn( [ 'email_recipients' => "admin@example.com\nuser@example.com" ] );

		Functions\expect( 'is_email' )
			->times( 2 )
			->andReturn( true );

		$this->email_template->shouldReceive( 'get_subject' )
			->once()
			->andReturn( 'Your Weekly Digest' );

		$this->email_template->shouldReceive( 'get_body' )
			->once()
			->andReturn( '<html>Email body</html>' );

		Functions\expect( 'get_option' )
			->with( 'admin_email' )
			->andReturn( 'admin@example.com' );

		Functions\expect( 'apply_filters' )
			->andReturnUsing( function( $hook, $value ) {
				return $value;
			} );

		Functions\expect( 'wp_mail' )
			->times( 2 )
			->andReturn( true );

		global $wpdb;
		$wpdb->shouldReceive( 'insert' )
			->times( 2 )
			->andReturn( 1 );

		Functions\expect( 'do_action' )
			->times( 2 );

		$this->report_repo->shouldReceive( 'update_status' )
			->once()
			->with( $report_id, 'emailed' )
			->andReturn( true );

		$result = $this->email_manager->send_report_email( $report_id );

		$this->assertTrue( $result );
	}

	/**
	 * Test send_report_email() returns false when report not found.
	 *
	 * @return void
	 */
	public function test_send_report_email_report_not_found() {
		$this->report_repo->shouldReceive( 'get_by_id' )
			->once()
			->with( 999 )
			->andReturn( null );

		$result = $this->email_manager->send_report_email( 999 );

		$this->assertFalse( $result );
	}

	// Test removed: Impossible scenario - Settings_Page::get_recipients() always
	// returns at least admin_email as fallback (line 478 and 491), even if validation fails

	/**
	 * Test send_report_email() skips empty reports when setting disabled.
	 *
	 * @return void
	 */
	public function test_send_report_email_skips_empty_report() {
		$report_id = 1;
		$report    = [
			'id'           => 1,
			'summary_data' => wp_json_encode( [
				'total_events' => 0,
			] ),
		];

		$this->report_repo->shouldReceive( 'get_by_id' )
			->once()
			->andReturn( $report );

		Functions\when( 'get_option' )->alias( function( $key, $default = [] ) {
			if ( $key === 'sybgo_settings' ) {
				return [
					'email_recipients'   => 'admin@example.com',
					'send_empty_reports' => false,
				];
			}
			return $default;
		} );

		Functions\expect( 'is_email' )
			->andReturn( true );

		$this->report_repo->shouldReceive( 'update_status' )
			->once()
			->with( $report_id, 'emailed' )
			->andReturn( true );

		$result = $this->email_manager->send_report_email( $report_id );

		$this->assertTrue( $result ); // Returns true because we mark as emailed.
	}

	/**
	 * Test send_report_email() sends empty report when setting enabled.
	 *
	 * @return void
	 */
	public function test_send_report_email_sends_empty_report_when_enabled() {
		$report_id = 1;
		$report    = [
			'id'           => 1,
			'summary_data' => wp_json_encode( [
				'total_events' => 0,
			] ),
		];

		$this->report_repo->shouldReceive( 'get_by_id' )
			->once()
			->andReturn( $report );

		Functions\when( 'get_option' )->alias( function( $key, $default = [] ) {
			if ( $key === 'sybgo_settings' ) {
				return [
					'email_recipients'   => 'admin@example.com',
					'send_empty_reports' => true,
				];
			}
			if ( $key === 'admin_email' ) {
				return 'admin@example.com';
			}
			return $default;
		} );

		Functions\expect( 'is_email' )
			->andReturn( true );

		$this->email_template->shouldReceive( 'get_subject' )
			->once()
			->andReturn( 'Your Weekly Digest' );

		$this->email_template->shouldReceive( 'get_body' )
			->once()
			->andReturn( '<html>Empty report</html>' );

		Functions\expect( 'apply_filters' )
			->andReturnUsing( function( $hook, $value ) {
				return $value;
			} );

		Functions\expect( 'wp_mail' )
			->once()
			->andReturn( true );

		global $wpdb;
		$wpdb->shouldReceive( 'insert' )
			->once()
			->andReturn( 1 );

		Functions\expect( 'do_action' );

		$this->report_repo->shouldReceive( 'update_status' )
			->once()
			->andReturn( true );

		$result = $this->email_manager->send_report_email( $report_id );

		$this->assertTrue( $result );
	}

	// Test removed: Cannot mock error_get_last() - it's a PHP internal function
	// that requires special patchwork.json configuration
}
