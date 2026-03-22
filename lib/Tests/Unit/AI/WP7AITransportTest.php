<?php
/**
 * WP7 AI Transport Unit Tests
 *
 * @package Sybgo\Tests\Unit\AI
 */

namespace Sybgo\Tests\Unit\AI;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Sybgo\AI\WP7_AI_Transport;

/**
 * WP7 AI Transport Test Case
 */
class WP7AITransportTest extends TestCase {

	/**
	 * WP7 AI transport instance.
	 *
	 * @var WP7_AI_Transport
	 */
	private $transport;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		require_once dirname( __DIR__, 3 ) . '/ai/interface-ai-transport.php';
		require_once dirname( __DIR__, 3 ) . '/ai/class-wp7-ai-transport.php';

		$this->transport = new WP7_AI_Transport();
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
	 * Test complete() returns string on success.
	 */
	public function test_complete_returns_string_on_success() {
		$mock_builder = Mockery::mock( 'Prompt_Builder_With_WP_Error' );
		$mock_builder->shouldReceive( 'generate_text' )->once()->andReturn( 'Generated summary text.' );

		Functions\expect( 'wp_ai_client_prompt' )
			->once()
			->with( 'My test prompt' )
			->andReturn( $mock_builder );

		Functions\expect( 'is_wp_error' )
			->once()
			->andReturn( false );

		$result = $this->transport->complete( 'My test prompt', 500 );

		$this->assertSame( 'Generated summary text.', $result );
	}

	/**
	 * Test complete() throws RuntimeException on WP_Error.
	 */
	public function test_complete_throws_runtime_exception_on_wp_error() {
		$mock_error = Mockery::mock( 'WP_Error' );
		$mock_error->shouldReceive( 'get_error_message' )->once()->andReturn( 'AI provider unavailable' );

		$mock_builder = Mockery::mock( 'Prompt_Builder_With_WP_Error' );
		$mock_builder->shouldReceive( 'generate_text' )->once()->andReturn( $mock_error );

		Functions\expect( 'wp_ai_client_prompt' )
			->once()
			->andReturn( $mock_builder );

		Functions\expect( 'is_wp_error' )
			->once()
			->andReturn( true );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'AI provider unavailable' );

		$this->transport->complete( 'My test prompt', 500 );
	}

	/**
	 * Test complete() passes prompt correctly to wp_ai_client_prompt.
	 */
	public function test_complete_passes_prompt_to_wp_ai_client_prompt() {
		$expected_prompt = 'Specific prompt text for testing';

		$mock_builder = Mockery::mock( 'Prompt_Builder_With_WP_Error' );
		$mock_builder->shouldReceive( 'generate_text' )->once()->andReturn( 'Result' );

		Functions\expect( 'wp_ai_client_prompt' )
			->once()
			->with( $expected_prompt )
			->andReturn( $mock_builder );

		Functions\expect( 'is_wp_error' )
			->once()
			->andReturn( false );

		$result = $this->transport->complete( $expected_prompt, 200 );

		$this->assertSame( 'Result', $result );
	}
}
