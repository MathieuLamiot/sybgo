# mcp-helper-lib

A standalone WordPress admin helper that makes it easy for non-technical users to connect their WordPress site's MCP endpoint to AI tools (Claude Desktop, GitHub Copilot, etc.).

## What it does

When a WordPress admin user creates an Application Password on their profile page, a **"Connect to AI"** button appears next to the new password. Clicking it opens a modal where the user selects their AI tool and receives a ready-to-paste JSON config block, along with the exact file path where it should go and step-by-step instructions.

JSON generation happens entirely in the browser — the Application Password is never sent back to the server after creation.

## Usage

Any plugin can activate the feature with a single call:

```php
\WPMedia\MCPHelper\MCP_Helper::init();
```

`init()` is **idempotent**: multiple plugins calling it in the same request register the hooks exactly once.

## Integration via Sybgo

In the sybgo plugin, `MCP_Module::boot()` calls `MCP_Helper::init()`. No further configuration is required.

## MCP endpoint

The lib targets `{site_url}/wp-json/mcp/mcp-adapter-default-server`, the default endpoint exposed by the `wordpress/mcp-adapter` Composer package.

## Supported tools

| Tool | Config file |
|------|------------|
| Claude Desktop | `~/Library/Application Support/Claude/claude_desktop_config.json` (macOS) / `%APPDATA%\Claude\claude_desktop_config.json` (Windows) |
| GitHub Copilot (VS Code) | Search `mcp.json` via Cmd/Ctrl+P in VS Code |

Add new tools by extending `MCP_Config_Provider` and overriding the `TOOLS` constant.

## Key classes

| Class | Responsibility |
|-------|---------------|
| `MCP_Helper` | WordPress hook wiring, asset enqueuing, AJAX handler, idempotency guard |
| `MCP_Config_Provider` | Tool metadata registry and JSON config generation |

See `wp-plugin/docs/development.md` for architectural context within the sybgo plugin.
