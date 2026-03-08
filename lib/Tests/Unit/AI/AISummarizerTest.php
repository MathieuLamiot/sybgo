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
	 * API key used in tests.
	 *
	 * @var string
	 */
	private $api_key = '';

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

		$this->api_key = '';

		$this->summarizer = new AI_Summarizer(
			$this->report_repo,
			$event_registry,
			function () {
				return $this->api_key;
			}
		);

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
		$this->api_key = '';

		$events = array(
			array( 'event_type' => 'post_published' ),
		);
		$totals = array( 'post_published' => 1 );
		$trends = array();

		$result = $this->summarizer->generate_summary( $events, $totals, $trends );

		$this->assertNull( $result );
	}

	/**
	 * Test generate_summary calls API when key is provided, even with empty events.
	 */
	public function test_generate_summary_calls_api_with_empty_events() {
		$this->api_key = 'test-key';

		$mock_error = Mockery::mock( 'WP_Error' );
		$mock_error->shouldReceive( 'get_error_message' )->andReturn( 'Connection refused' );

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturn( $mock_error );

		Functions\expect( 'is_wp_error' )
			->once()
			->andReturn( true );

		Functions\expect( 'wp_json_encode' )
			->once()
			->andReturnUsing( 'json_encode' );

		$events = array();
		$totals = array();
		$trends = array();

		// Returns null because the API call fails gracefully.
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
