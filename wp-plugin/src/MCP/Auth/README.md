# MCP Auth — OAuth 2.1 JWT Façade

Self-contained OAuth 2.1 implementation for WordPress MCP plugins.
Uses **Option B: JWT Façade over Application Passwords** — Claude sees only
short-lived, rotating JWTs; Application Passwords never leave the server.

## Quick start (sybgo)

Already wired in `class-sybgo.php` via `Mcp_Auth::boot()`.  Nothing else
needed unless you are extracting the module.

## Architecture

```
Discovery    /.well-known/oauth-protected-resource    (RFC 9728)
             /.well-known/oauth-authorization-server  (RFC 8414)

OAuth flow   POST /oauth/register    → Client_Registration
             GET  /oauth/authorize   → Authorize_Endpoint
             GET  /oauth/authorize-callback → Authorize_Callback
             POST /oauth/token       → Token_Endpoint (authorization_code + refresh_token)

Middleware   Request_Validator::validate_request()   (use in REST permission callbacks)
Admin UI     Admin_Page (Settings → MCP Sessions)
```

All endpoints are registered as WordPress rewrite rules (no `/wp-json/` prefix).

### Token structure

```json
// Access token (1 hour)
{ "iss": "…", "aud": "…/wp-json/mcp/mcp-adapter-default-server",
  "sub": "42", "app_pass_id": "<uuid>", "scope": "mcp", "iat": …, "exp": … }

// Refresh token (30 days)
{ "iss": "…", "sub": "42", "app_pass_id": "<uuid>",
  "type": "refresh", "iat": …, "exp": … }
```

### Revocation

Every token embeds `app_pass_id` (the UUID of a WordPress Application Password).
Deleting that Application Password immediately revokes all tokens that reference it.
Calling `Secret_Manager::regenerate()` (the "Revoke all" button) rotates the HMAC
secret and invalidates every outstanding token site-wide.

## Extracting this module into a standalone plugin

1. Copy the entire `src/MCP/Auth/` directory into your plugin.
2. Find-and-replace the namespace root:
   `Sybgo\MCP\Auth` → `YourPlugin\MCP\Auth`
3. In `McpAuth.php`, update the text domain `'sybgo'` to your plugin's text domain.
4. Add the directory to your composer.json `autoload.classmap` (or PSR-4 if you
   rename files to match PSR-4 conventions).
5. In your main plugin file:
   ```php
   use YourPlugin\MCP\Auth\Mcp_Auth;
   add_action( 'plugins_loaded', [ Mcp_Auth::class, 'boot' ] );
   register_activation_hook( __FILE__, [ Mcp_Auth::class, 'on_activation' ] );
   ```
6. Make sure your activation hook also calls `flush_rewrite_rules()` **after**
   `Mcp_Auth::on_activation()`.

No other sybgo code is referenced from within this directory.

## Security notes

- JWT signing uses HS256 (HMAC-SHA256) with a 256-bit random secret stored in `wp_options`.
- The raw Application Password is discarded immediately after creation; only
  the UUID is retained in the JWT for revocation checks.
- PKCE (S256) is enforced on every authorization code exchange.
- Auth codes and state tokens expire after 60 seconds (single-use transients).
- Refresh tokens expire after 30 days and are rotated on every use.
