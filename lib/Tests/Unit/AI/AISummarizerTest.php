<?php
/**
 * AI Summarizer Unit Tests
 *
 * @package Sybgo\Tests\Unit\AI
 */

namespace Sybgo\Tests\Unit\AI;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Sybgo\AI\AI_Summarizer;

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
	 * Mock AI transport.
	 *
	 * @var Mockery\MockInterface
	 */
	private $transport;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Load the AI transport interface and AI_Summarizer class.
		require_once dirname( __DIR__, 3 ) . '/ai/interface-ai-transport.php';
		require_once dirname( __DIR__, 3 ) . '/ai/class-ai-summarizer.php';

		$this->report_repo = Mockery::mock( 'Sybgo\Database\Report_Repository' );
		$event_registry    = Mockery::mock( 'Sybgo\Events\Event_Registry' );
		$event_registry->shouldReceive( 'get_ai_description' )->andReturn( '' );
		$event_registry->shouldReceive( 'get_ai_context_for_events' )->andReturn( '' );

		$this->transport = Mockery::mock( 'Sybgo\AI\AI_Transport_Interface' );

		$this->summarizer = new AI_Summarizer(
			$this->report_repo,
			$event_registry,
			$this->transport
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
	 * Test generate_summary returns null when transport throws RuntimeException.
	 */
	public function test_generate_summary_returns_null_when_transport_throws() {
		$this->transport->shouldReceive( 'complete' )
			->once()
			->andThrow( new \RuntimeException( 'Transport error' ) );

		$events = array(
			array( 'event_type' => 'post_published', 'event_data' => '{}' ),
		);
		$totals = array( 'post_published' => 1 );
		$trends = array();

		$result = $this->summarizer->generate_summary( $events, $totals, $trends );

		$this->assertNull( $result );
	}

	/**
	 * Test generate_summary returns string from transport.
	 */
	public function test_generate_summary_returns_string_from_transport() {
		$this->transport->shouldReceive( 'complete' )
			->once()
			->andReturn( 'Generated summary' );

		$result = $this->summarizer->generate_summary( array(), array(), array() );

		$this->assertSame( 'Generated summary', $result );
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
