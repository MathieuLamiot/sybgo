<?php
/**
 * AI Summarizer Unit Tests
 *
 * @package Rocket\Sybgo\Tests\Unit\AI
 */

namespace Rocket\Sybgo\Tests\Unit\AI;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Rocket\Sybgo\AI\AI_Summarizer;

/**
 * AI Summarizer Test Case
 */
class AISummarizerTest extends TestCase {

	/**
	 * AI summarizer instance.
	 *
	 * @var AI_Summarizer
	 */
	private $summarizer;

	/**
	 * Mock report repository.
	 *
	 * @var Mockery\MockInterface
	 */
	private $report_repo;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Load the AI_Summarizer class.
		require_once dirname( __DIR__, 3 ) . '/ai/class-ai-summarizer.php';

		$this->report_repo    = Mockery::mock( 'Rocket\Sybgo\Database\Report_Repository' );
		$event_registry       = Mockery::mock( 'Rocket\Sybgo\Events\Event_Registry' );
		$event_registry->shouldReceive( 'get_ai_description' )->andReturn( '' );
		$event_registry->shouldReceive( 'get_ai_context_for_events' )->andReturn( '' );
		$this->summarizer     = new AI_Summarizer( $this->report_repo, $event_registry );

		// Mock WordPress functions.
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
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
	 * Test generate_summary returns null when no API key configured.
	 */
	public function test_generate_summary_returns_null_without_api_key() {
		// Mock Settings_Page static method.
		require_once dirname( __DIR__, 3 ) . '/admin/class-settings-page.php';

		Functions\expect( 'get_option' )
			->with( 'sybgo_settings', array() )
			->andReturn( array() );

		$events = array(
			array( 'event_type' => 'post_published' ),
		);
		$totals = array( 'post_published' => 1 );
		$trends = array();

		$result = $this->summarizer->generate_summary( $events, $totals, $trends );

		$this->assertNull( $result );
	}

	/**
	 * Test generate_summary returns null when events are empty.
	 */
	public function test_generate_summary_returns_null_with_empty_events() {
		require_once dirname( __DIR__, 3 ) . '/admin/class-settings-page.php';

		Functions\expect( 'get_option' )
			->with( 'sybgo_settings', array() )
			->andReturn( array( 'anthropic_api_key' => 'test-key' ) );

		$events = array();
		$totals = array();
		$trends = array();

		$result = $this->summarizer->generate_summary( $events, $totals, $trends );

		$this->assertNull( $result );
	}

	/**
	 * Test build_prompt creates proper prompt structure.
	 */
	public function test_build_prompt_creates_proper_structure() {
		$events = array(
			array(
				'event_type' => 'post_published',
				'event_data' => json_encode( array(
					'context' => array(
						'post_title' => 'Test Post',
						'user_name'  => 'John Doe',
					),
				) ),
			),
		);
		$totals = array( 'post_published' => 1 );
		$trends = array(
			'post_published' => array(
				'current'        => 1,
				'previous'       => 0,
				'change_percent' => 100,
				'direction'      => 'up',
			),
		);

		// Use reflection to access private method.
		$reflection = new \ReflectionClass( $this->summarizer );
		$method     = $reflection->getMethod( 'build_prompt' );
		$method->setAccessible( true );

		$prompt = $method->invoke( $this->summarizer, $events, $totals, $trends );

		$this->assertStringContainsString( 'WordPress site activity', $prompt );
		$this->assertStringContainsString( 'conversational summary', $prompt );
		$this->assertStringContainsString( 'Post Published: 1', $prompt );
	}

	/**
	 * Test that AI summarizer class exists and can be instantiated.
	 */
	public function test_ai_summarizer_can_be_instantiated() {
		$this->assertInstanceOf( AI_Summarizer::class, $this->summarizer );
	}
}
