<?php
/**
 * MCP Config Provider class file.
 *
 * Pure data layer: tool metadata and JSON config generation.
 * No WordPress hooks here — only callable data methods.
 *
 * @package WPMedia\MCPHelper
 * @since   1.0.0
 */

declare(strict_types=1);

namespace WPMedia\MCPHelper;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides tool metadata and generates MCP JSON config arrays.
 *
 * Add new AI tools by extending the TOOLS constant.
 *
 * @since 1.0.0
 */
class MCP_Config_Provider {

	/**
	 * Registry of supported AI tools.
	 *
	 * Each entry defines display metadata and the config-file locations.
	 * The JSON structure is tool-specific and assembled in get_tool_config().
	 */
	private const TOOLS = [
		'claude-desktop' => [
			'label'        => 'Claude Desktop',
			'config_paths' => [
				'mac'     => '~/Library/Application Support/Claude/claude_desktop_config.json',
				'windows' => '%APPDATA%\\Claude\\claude_desktop_config.json',
			],
			'instructions' => [
				'Locate the config file for your OS (paths shown below).',
				'If the file does not exist, create it with the JSON block shown.',
				'If it already exists, merge the "mcpServers" key into the existing object.',
				'Save the file, then fully quit and reopen Claude Desktop.',
				'A hammer/tools icon in the interface confirms the connection.',
			],
		],
		'github-copilot' => [
			'label'        => 'GitHub Copilot (VS Code)',
			'config_paths' => [
				'vscode' => 'Open VS Code → Cmd/Ctrl+P → type "mcp.json" → select the file',
			],
			'instructions' => [
				'Open VS Code and press Cmd (macOS) or Ctrl (Windows/Linux) + P.',
				'Type "mcp.json" and select the file from the list.',
				'Replace the file content with the JSON block shown (or merge the keys if it already has content).',
				'Reload the VS Code window: Cmd/Ctrl+Shift+P → "Reload Window".',
				'Open Copilot Chat — a tools icon confirms MCP is active.',
			],
		],
	];

	/**
	 * Returns all tool metadata for JavaScript consumption.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_tools_metadata(): array {
		return self::TOOLS;
	}

	/**
	 * Builds the config array for a given tool.
	 *
	 * @param string $tool_id     One of the keys in TOOLS.
	 * @param string $site_url    WordPress site URL (no trailing slash).
	 * @param string $username    WordPress username.
	 * @param string $app_password Application password (plain text, no spaces).
	 * @return array<string, mixed> Config structure ready for json_encode.
	 */
	public static function get_tool_config( string $tool_id, string $site_url, string $username, string $app_password ): array {
		$wp_api_url = rtrim( $site_url, '/' ) . '/wp-json/mcp/mcp-adapter-default-server';

		$server_entry = [
			'type'        => 'stdio',
			'command'     => 'npx',
			'args'        => [ '-y', '@automattic/mcp-wordpress-remote@latest' ],
			'env'         => [
				'WP_API_URL'      => $wp_api_url,
				'WP_API_USERNAME' => $username,
				'WP_API_PASSWORD' => $app_password,
				'OAUTH_ENABLED'   => 'false',
			],
			'description' => 'WordPress MCP connection',
		];

		if ( 'claude-desktop' === $tool_id ) {
			return [
				'mcpServers' => [
					'my-plugin' => $server_entry,
				],
			];
		}

		if ( 'github-copilot' === $tool_id ) {
			return [
				'github.copilot.chat.mcp.enabled' => true,
				'github.copilot.chat.mcp.servers' => [
					'my-plugin' => $server_entry,
				],
			];
		}

		return [];
	}
}
