<?php
/**
 * Email Template Test
 *
 * @package Rocket\Sybgo\Tests\Unit\Email
 */

declare(strict_types=1);

namespace Rocket\Sybgo\Tests\Unit\Email;

use Rocket\Sybgo\Email\Email_Template;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Test Email_Template class.
 */
class EmailTemplateTest extends TestCase {
	/**
	 * Email template instance.
	 *
	 * @var Email_Template
	 */
	private Email_Template $email_template;

	/**
	 * Set up test environment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->email_template = new Email_Template();

		// Mock WordPress functions.
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function( $text ) {
			echo $text;
		} );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( function( $data ) {
			return json_encode( $data );
		} );
		Functions\when( 'number_format_i18n' )->alias( function( $number ) {
			return (string) $number;
		} );
		Functions\when( 'admin_url' )->alias( function( $path ) {
			return 'https://example.com/wp-admin/' . $path;
		} );
		Functions\when( 'get_bloginfo' )->alias( function( $show ) {
			return 'Test Site';
		} );
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
	 * Test get_subject() returns proper subject line.
	 *
	 * @return void
	 */
	public function test_get_subject() {
		$report = [
			'id'           => 1,
			'period_start' => '2026-02-10 00:00:00',
			'period_end'   => '2026-02-16 23:59:59',
		];

		Functions\expect( 'apply_filters' )
			->once()
			->andReturnUsing( function( $hook, $subject ) {
				return $subject;
			} );

		$subject = $this->email_template->get_subject( $report );

		$this->assertIsString( $subject );
		$this->assertStringContainsString( 'Test Site', $subject );
		$this->assertStringContainsString( 'Weekly', $subject );
	}

	/**
	 * Test get_body() returns HTML.
	 *
	 * @return void
	 */
	public function test_get_body_returns_html() {
		$report = [
			'id'           => 1,
			'period_start' => '2026-02-10 00:00:00',
			'period_end'   => '2026-02-16 23:59:59',
			'summary_data' => wp_json_encode( [
				'total_events' => 25,
				'totals'       => [
					'posts_published'  => 5,
					'posts_edited'     => 10,
					'users_registered' => 2,
					'comments_new'     => 8,
				],
				'highlights'   => [
					'5 new posts published ↑ 20%',
					'10 posts edited',
				],
			] ),
		];

		Functions\expect( 'apply_filters' )
			->andReturnUsing( function( $hook, $value ) {
				return $value;
			} );

		Functions\expect( 'do_action' );

		$body = $this->email_template->get_body( $report );

		$this->assertIsString( $body );
		$this->assertStringContainsString( '<html', $body );
		$this->assertStringContainsString( '</html>', $body );
		$this->assertStringContainsString( '5 new posts published', $body );
	}

	/**
	 * Test get_body() with empty report.
	 *
	 * @return void
	 */
	public function test_get_body_empty_report() {
		$report = [
			'id'           => 1,
			'period_start' => '2026-02-10 00:00:00',
			'period_end'   => '2026-02-16 23:59:59',
			'summary_data' => wp_json_encode( [
				'total_events' => 0,
				'totals'       => [
					'posts_published'  => 0,
					'posts_edited'     => 0,
					'users_registered' => 0,
					'comments_new'     => 0,
				],
			] ),
		];

		Functions\when( 'apply_filters' )->alias( function( $hook, $value ) {
			return $value;
		} );
		Functions\when( 'do_action' )->justReturn( null );

		$body = $this->email_template->get_body( $report );

		$this->assertIsString( $body );
		$this->assertStringContainsString( '<html', $body );
		$this->assertStringContainsString( 'All Quiet', $body );
	}

	/**
	 * Test get_body() includes statistics.
	 *
	 * @return void
	 */
	public function test_get_body_includes_statistics() {
		$report = [
			'id'           => 1,
			'period_start' => '2026-02-10 00:00:00',
			'period_end'   => '2026-02-16 23:59:59',
			'summary_data' => wp_json_encode( [
				'total_events' => 88,
				'totals'       => [
					'posts_published'  => 12,
					'posts_edited'     => 45,
					'users_registered' => 3,
					'comments_new'     => 28,
				],
			] ),
		];

		Functions\expect( 'apply_filters' )
			->andReturnUsing( function( $hook, $value ) {
				return $value;
			} );

		Functions\expect( 'do_action' );

		$body = $this->email_template->get_body( $report );

		$this->assertStringContainsString( '12', $body );
		$this->assertStringContainsString( '45', $body );
		$this->assertStringContainsString( '3', $body );
		$this->assertStringContainsString( '28', $body );
	}

	/**
	 * Test get_body() applies filter hook.
	 *
	 * @return void
	 */
	public function test_get_body_applies_filter() {
		$report = [
			'id'           => 1,
			'period_start' => '2026-02-10 00:00:00',
			'period_end'   => '2026-02-16 23:59:59',
			'summary_data' => wp_json_encode( [
				'total_events' => 5,
				'totals'       => [ 'posts_published' => 5 ],
			] ),
		];

		Functions\expect( 'apply_filters' )
			->andReturnUsing( function( $hook, $value ) {
				if ( $hook === 'sybgo_email_body' ) {
					return '<html>Custom body</html>';
				}
				return $value;
			} );

		Functions\expect( 'do_action' );

		$body = $this->email_template->get_body( $report );

		$this->assertEquals( '<html>Custom body</html>', $body );
	}

	/**
	 * Test get_body() fires action hook for custom sections.
	 *
	 * @return void
	 */
	public function test_get_body_fires_custom_section_action() {
		$report = [
			'id'           => 1,
			'period_start' => '2026-02-10 00:00:00',
			'period_end'   => '2026-02-16 23:59:59',
			'summary_data' => wp_json_encode( [
				'total_events' => 3,
				'totals'       => [ 'posts_published' => 3 ],
			] ),
		];

		Functions\expect( 'apply_filters' )
			->andReturnUsing( function( $hook, $value ) {
				return $value;
			} );

		Functions\expect( 'do_action' )
			->once()
			->with( 'sybgo_email_custom_section', $report, Mockery::type( 'array' ) );

		$body = $this->email_template->get_body( $report );

		$this->assertIsString( $body );
	}

	/**
	 * Test header styling is included.
	 *
	 * @return void
	 */
	public function test_includes_header_styling() {
		$report = [
			'id'           => 1,
			'period_start' => '2026-02-10 00:00:00',
			'period_end'   => '2026-02-16 23:59:59',
			'summary_data' => wp_json_encode( [ 'total_events' => 2, 'totals' => [ 'posts_published' => 2 ] ] ),
		];

		Functions\expect( 'apply_filters' )
			->andReturnUsing( function( $hook, $value ) {
				return $value;
			} );

		Functions\expect( 'do_action' );

		$body = $this->email_template->get_body( $report );

		$this->assertStringContainsString( '<style', $body );
		$this->assertStringContainsString( 'background', $body );
	}
}
