<?php
/**
 * Unit Tests for MCP_Config_Provider.
 *
 * Validates that the pure data class returns correct tool metadata
 * and JSON config structures for each supported AI tool.
 *
 * @package Sybgo\Tests\Unit
 */

declare(strict_types=1);

namespace Sybgo\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use WPMedia\MCPHelper\MCP_Config_Provider;

/**
 * Tests for MCP_Config_Provider.
 */
class MCPConfigProviderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// get_tools_metadata()
	// -------------------------------------------------------------------------

	/** Metadata returns both tools. */
	public function test_get_tools_metadata_returns_both_tools(): void {
		$tools = MCP_Config_Provider::get_tools_metadata();

		$this->assertArrayHasKey( 'claude-desktop', $tools );
		$this->assertArrayHasKey( 'github-copilot', $tools );
	}

	public function test_claude_desktop_metadata_has_required_keys(): void {
		$tools = MCP_Config_Provider::get_tools_metadata();
		$cd    = $tools['claude-desktop'];

		$this->assertSame( 'Claude Desktop', $cd['label'] );
		$this->assertArrayHasKey( 'mac', $cd['config_paths'] );
		$this->assertArrayHasKey( 'windows', $cd['config_paths'] );
		$this->assertNotEmpty( $cd['instructions'] );
	}

	public function test_github_copilot_metadata_has_required_keys(): void {
		$tools = MCP_Config_Provider::get_tools_metadata();
		$gc    = $tools['github-copilot'];

		$this->assertArrayHasKey( 'label', $gc );
		$this->assertArrayHasKey( 'config_paths', $gc );
		$this->assertArrayHasKey( 'instructions', $gc );
	}

	// -------------------------------------------------------------------------
	// get_tool_config() — Claude Desktop (AC3)
	// -------------------------------------------------------------------------

	/** AC3: Claude Desktop JSON has mcpServers["my-plugin"] with correct keys. */
	public function test_claude_desktop_config_has_mcp_servers_key(): void {
		$config = MCP_Config_Provider::get_tool_config(
			'claude-desktop',
			'http://example.com',
			'admin',
			'test-pass'
		);

		$this->assertArrayHasKey( 'mcpServers', $config );
		$this->assertArrayHasKey( 'my-plugin', $config['mcpServers'] );
	}

	/** AC3: WP_API_URL must end with /wp-json/mcp/mcp-adapter-default-server. */
	public function test_claude_desktop_wp_api_url_format(): void {
		$config = MCP_Config_Provider::get_tool_config(
			'claude-desktop',
			'http://example.com',
			'admin',
			'test-pass'
		);

		$env = $config['mcpServers']['my-plugin']['env'];
		$this->assertSame(
			'http://example.com/wp-json/mcp/mcp-adapter-default-server',
			$env['WP_API_URL']
		);
	}

	/** AC3: Trailing slash on site URL is stripped before appending the path. */
	public function test_claude_desktop_wp_api_url_strips_trailing_slash_from_site_url(): void {
		$with_slash    = MCP_Config_Provider::get_tool_config( 'claude-desktop', 'http://example.com/', 'admin', 'p' );
		$without_slash = MCP_Config_Provider::get_tool_config( 'claude-desktop', 'http://example.com', 'admin', 'p' );

		$this->assertSame(
			$without_slash['mcpServers']['my-plugin']['env']['WP_API_URL'],
			$with_slash['mcpServers']['my-plugin']['env']['WP_API_URL'],
			'URL should be identical whether or not site URL has a trailing slash.'
		);
	}

	/** AC3: WP_API_USERNAME equals the provided username. */
	public function test_claude_desktop_username_in_config(): void {
		$config = MCP_Config_Provider::get_tool_config(
			'claude-desktop',
			'http://example.com',
			'myuser',
			'test-pass'
		);

		$env = $config['mcpServers']['my-plugin']['env'];
		$this->assertSame( 'myuser', $env['WP_API_USERNAME'] );
	}

	/** AC3: WP_API_PASSWORD equals the provided application password. */
	public function test_claude_desktop_password_in_config(): void {
		$config = MCP_Config_Provider::get_tool_config(
			'claude-desktop',
			'http://example.com',
			'admin',
			'xxxx yyyy zzzz'
		);

		$env = $config['mcpServers']['my-plugin']['env'];
		$this->assertSame( 'xxxx yyyy zzzz', $env['WP_API_PASSWORD'] );
	}

	// -------------------------------------------------------------------------
	// get_tool_config() — GitHub Copilot (AC4)
	// -------------------------------------------------------------------------

	/** AC4: GitHub Copilot JSON uses github.copilot.chat.mcp.servers key. */
	public function test_github_copilot_config_has_correct_top_level_key(): void {
		$config = MCP_Config_Provider::get_tool_config(
			'github-copilot',
			'http://example.com',
			'admin',
			'test-pass'
		);

		$this->assertArrayHasKey( 'github.copilot.chat.mcp.servers', $config );
		$this->assertArrayHasKey( 'my-plugin', $config['github.copilot.chat.mcp.servers'] );
	}

	/** AC4: GitHub Copilot config does NOT have mcpServers key. */
	public function test_github_copilot_config_does_not_have_mcp_servers_key(): void {
		$config = MCP_Config_Provider::get_tool_config(
			'github-copilot',
			'http://example.com',
			'admin',
			'test-pass'
		);

		$this->assertArrayNotHasKey( 'mcpServers', $config );
	}

	/** AC4: GitHub Copilot config has the enabled flag set to true. */
	public function test_github_copilot_config_has_enabled_flag(): void {
		$config = MCP_Config_Provider::get_tool_config(
			'github-copilot',
			'http://example.com',
			'admin',
			'test-pass'
		);

		$this->assertArrayHasKey( 'github.copilot.chat.mcp.enabled', $config );
		$this->assertTrue( $config['github.copilot.chat.mcp.enabled'] );
	}

	// -------------------------------------------------------------------------
	// get_tool_config() — unknown tool
	// -------------------------------------------------------------------------

	public function test_unknown_tool_returns_empty_array(): void {
		$config = MCP_Config_Provider::get_tool_config(
			'unknown-tool',
			'http://example.com',
			'admin',
			'test-pass'
		);

		$this->assertSame( [], $config );
	}
}
