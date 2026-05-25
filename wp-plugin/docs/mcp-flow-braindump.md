# MCP OAuth Flow — Brain Dump

This document captures every HTTP request/response in the Claude → Sybgo → MCP flow, which Sybgo code handles each step, what payloads are exchanged, and where failures have been observed. It exists so AI tools can be dropped into a debugging session with full context.

---

## Architecture overview

```
Claude (browser / desktop)
        │
        │  OAuth 2.1 + PKCE  (steps 1-6)
        ▼
WordPress site (mathieulamiot.com)
  ├── /.well-known/oauth-protected-resource  ← DiscoveryEndpoints
  ├── /.well-known/oauth-authorization-server ← DiscoveryEndpoints
  ├── /oauth/register                        ← ClientRegistration
  ├── /oauth/authorize                       ← AuthorizeEndpoint
  ├── /oauth/authorize-callback              ← AuthorizeCallback
  ├── /oauth/token                           ← TokenEndpoint
  └── /wp-json/mcp/mcp-adapter-default-server ← MCP library HttpTransport
                                                  guarded by RequestValidator
```

All OAuth endpoints go through WordPress rewrite rules (not the REST API). The MCP tool execution endpoint IS the REST API.

---

## Step 1 — Protected-resource discovery

**Request**
```
GET /.well-known/oauth-protected-resource
```
No body, no auth.

**Handler**: `DiscoveryEndpoints::handle_request()` → `discovery === 'protected-resource'`

**Expected response** (200 JSON):
```json
{
  "resource": "https://mathieulamiot.com/wp-json/mcp/mcp-adapter-default-server",
  "authorization_servers": ["https://mathieulamiot.com"],
  "bearer_methods_supported": ["header"],
  "scopes_supported": ["mcp"]
}
```

**Purpose**: tells Claude where the resource server is and which authorization server protects it.

---

## Step 2 — Authorization-server metadata discovery

**Request**
```
GET /.well-known/oauth-authorization-server
```
No body, no auth.

**Handler**: `DiscoveryEndpoints::handle_request()` → `discovery === 'authorization-server'`

**Expected response** (200 JSON):
```json
{
  "issuer": "https://mathieulamiot.com",
  "authorization_endpoint": "https://mathieulamiot.com/oauth/authorize",
  "token_endpoint": "https://mathieulamiot.com/oauth/token",
  "response_types_supported": ["code"],
  "grant_types_supported": ["authorization_code", "refresh_token"],
  "code_challenge_methods_supported": ["S256"],
  "scopes_supported": ["mcp"],
  "registration_endpoint": "https://mathieulamiot.com/oauth/register"
}
```

**Purpose**: tells Claude all OAuth endpoints and supported features (PKCE S256, dynamic registration).

---

## Step 3 — Dynamic client registration (RFC 7591)

**Request**
```
POST /oauth/register
Content-Type: application/json

{
  "client_name": "Claude",
  "redirect_uris": ["https://claude.ai/api/mcp/auth_callback"],
  "grant_types": ["authorization_code", "refresh_token"]
}
```
No auth required.

**Handler**: `ClientRegistration::handle_request()`

**Expected response** (201 JSON):
```json
{
  "client_id": "<uuid4>",
  "client_secret": "<64-hex-chars>",
  "client_name": "Claude",
  "redirect_uris": ["https://claude.ai/api/mcp/auth_callback"],
  "grant_types": ["authorization_code", "refresh_token"],
  "client_id_issued_at": 1716000000
}
```

**Storage**: `wp_options` under key `mcp_oauth_clients` → `{client_id: {...}}`.

**Note**: client_secret is stored but currently unused — we don't verify it at token exchange (public client PKCE flow).

---

## Step 4 — Authorization request

**Request**
```
GET /oauth/authorize
  ?response_type=code
  &client_id=<registered-client-id>
  &redirect_uri=https://claude.ai/api/mcp/auth_callback
  &code_challenge=<BASE64URL(SHA256(verifier))>
  &code_challenge_method=S256
  &state=<random>
  &scope=mcp
```
This opens in the user's browser.

**Handler**: `AuthorizeEndpoint::handle_request()`

**What it does**:
1. Validates `response_type=code`, required params present, `code_challenge_method=S256`.
2. Looks up client by `client_id` in `mcp_oauth_clients`.
3. Validates `redirect_uri` matches registered value.
4. Stores state transient `mcp_oauth_state_{state}` (60s TTL) containing:
   ```php
   [
     'client_id', 'redirect_uri',
     'code_challenge', 'code_challenge_method', 'state'
   ]
   ```
5. Redirects browser to `wp_login_url(/oauth/authorize-callback?state=...)`.

**Expected**: browser is redirected to `wp-login.php` with `redirect_to=/oauth/authorize-callback?state=...`.

---

## Step 5 — Authorization callback (post-login)

**Request** (after WordPress login completes)
```
GET /oauth/authorize-callback?state=<same-state>
```
User must be logged in. WordPress cookie is present.

**Handler**: `AuthorizeCallback::handle_request()`

**What it does**:
1. Checks `is_user_logged_in()`.
2. Reads + deletes state transient `mcp_oauth_state_{state}`.
3. Generates random `auth_code = bin2hex(random_bytes(32))`.
4. Stores code transient `mcp_oauth_code_{auth_code}` (60s TTL) containing:
   ```php
   [
     'user_id', 'client_id',
     'code_challenge', 'redirect_uri'
   ]
   ```
5. Redirects browser to `https://claude.ai/api/mcp/auth_callback?code=...&state=...`.

**Key timing issue**: both the state transient (step 4) and the code transient (step 5) have a 60-second TTL. If the user takes more than 60 seconds to log in, the state transient expires. If Claude takes more than 60 seconds to exchange the code, the code transient expires.

---

## Step 6 — Token exchange

**Request**
```
POST /oauth/token
Content-Type: application/json   (or application/x-www-form-urlencoded)

{
  "grant_type": "authorization_code",
  "code": "<auth_code_from_step_5>",
  "code_verifier": "<original_random_string>",
  "redirect_uri": "https://claude.ai/api/mcp/auth_callback"
}
```
No auth header required (PKCE replaces client secret).

**Handler**: `TokenEndpoint::handle_authorization_code()`

**What it does**:
1. Reads + deletes code transient `mcp_oauth_code_{code}`.
2. Verifies PKCE: `BASE64URL(SHA256(code_verifier)) == stored_code_challenge`.
3. Checks optional `redirect_uri` match.
4. Calls `WP_Application_Passwords::create_new_application_password($user_id, ['name' => 'MCP Session – Y-m-d H:i'])`.
5. Discards the raw password. Keeps only the UUID from `$result[1]['uuid']`.
6. Calls `issue_token_pair($user_id, $app_pass_uuid)`.

**Token pair issued** (200 JSON):
```json
{
  "access_token": "<JWT>",
  "token_type": "Bearer",
  "expires_in": 3600,
  "refresh_token": "<JWT>",
  "scope": "mcp"
}
```

**Access token JWT claims**:
```json
{
  "iss": "https://mathieulamiot.com",
  "aud": "https://mathieulamiot.com/wp-json/mcp/mcp-adapter-default-server",
  "sub": "<user_id>",
  "app_pass_id": "<app_password_uuid>",
  "scope": "mcp",
  "iat": 1716000000,
  "exp": 1716003600
}
```

**Refresh token JWT claims** (same but `type=refresh`, no `aud`, 30-day expiry):
```json
{
  "iss": "https://mathieulamiot.com",
  "sub": "<user_id>",
  "app_pass_id": "<app_password_uuid>",
  "type": "refresh",
  "iat": 1716000000,
  "exp": 1718592000
}
```

**Token refresh** (`grant_type=refresh_token`):
Handler: `TokenEndpoint::handle_refresh_token()`. Decodes the refresh JWT, checks `type=refresh`, looks up the Application Password to verify it hasn't been revoked, issues a new token pair (both access and refresh are rotated).

---

## Step 7 — MCP tool requests

**Initial request** (unauthenticated — used for capability discovery / 401 challenge)
```
POST /wp-json/mcp/mcp-adapter-default-server
Content-Type: application/json

{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"Claude","version":"1.0.0"}}}
```

**Authenticated request**
```
POST /wp-json/mcp/mcp-adapter-default-server
Authorization: Bearer <access_token>
Content-Type: application/json

{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}
```

**Handler chain**:
1. `rest_pre_dispatch` filter in `McpAuth::boot()` → `RequestValidator::validate_request()`:
   - Extracts `Bearer` token from `Authorization` header.
   - Decodes JWT (HS256), checks `exp`, `aud`.
   - Calls `WP_Application_Passwords::get_user_application_password($user_id, $uuid)` — revocation check.
   - Calls `wp_set_current_user($user_id)` — **critical**: the mcp-adapter library's `HttpSessionValidator::create_session()` calls `get_current_user_id()` internally, which returns 0 without this call.
   - Returns `WP_User` on success, `WP_Error` on failure (with `WWW-Authenticate` header in error data).
2. If validator returns `WP_Error` → `rest_pre_dispatch` returns 401 `WP_REST_Response` with `WWW-Authenticate` header immediately, bypassing all permission checks.
3. If validator returns `WP_User` → `$result` stays `null`, WordPress continues normal dispatch.
4. `HttpTransport::check_permission()` in the mcp-adapter library is called next as the route's `permission_callback`. It receives our closure which calls `Request_Validator::validate_request()` a second time (redundant but harmless).
5. If permission passes → `HttpTransport::handle_request()` parses the JSON-RPC body and routes to the appropriate MCP method handler.

**MCP methods handled by the library**:
- `initialize` → handshake; server returns `serverInfo`, `capabilities`
- `tools/list` → returns all registered abilities with `mcp.public=true` and `type=tool` as MCP tools
- `tools/call` → executes the named ability's `execute_callback` via `mcp-adapter/execute-ability`
- `resources/list`, `prompts/list` → other MCP primitives (return empty lists)

**Expected `initialize` response**:
```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "protocolVersion": "2024-11-05",
    "capabilities": {"tools": {}},
    "serverInfo": {"name": "MCP Adapter Default Server", "version": "v1.0.0"}
  }
}
```

**Expected `tools/list` response** (with sybgo/generate-summary registered):
```json
{
  "jsonrpc": "2.0",
  "id": 2,
  "result": {
    "tools": [
      {
        "name": "sybgo-generate-summary",
        "description": "Generates an AI-powered summary of the weekly site activity report.",
        "inputSchema": {"type": "object", "properties": {}}
      }
    ]
  }
}
```

---

## WordPress 7 Abilities API — registration rules

The WP Abilities API (added in WP 6.9 / "WP7" era) enforces strict hook timing:

| Function | Must be called on |
|----------|-------------------|
| `wp_register_ability_category()` | `wp_abilities_api_categories_init` action |
| `wp_register_ability()` | `wp_abilities_api_init` action |

Both of these actions fire **after** the `init` action. Calling either function outside these hooks results in a `_doing_it_wrong` notice and the ability/category is **silently not registered** — `wp_get_abilities()` will return an empty list for those abilities.

### Sybgo's registration flow

```
plugins_loaded
  └── Sybgo::init() (via add_action)
        └── module->boot() for each module
              ├── Event_Module::boot()  → $abilities->register('sybgo/track-events', ...)  ← immediate
              ├── AI_Module::boot()    → $abilities->register('sybgo/generate-summary', ...) ← immediate
              └── Ability_Manager::init() → add_action('wp_abilities_api_categories_init', ...)
                                         → add_action('wp_abilities_api_init', ...)

init (WordPress core)
  └── (text domain loads here; __() resolves correctly from this point)

wp_abilities_api_categories_init
  └── wp_register_ability_category('sybgo', [...])

wp_abilities_api_init
  └── wp_register_ability('sybgo/track-events', ...)
  └── wp_register_ability('sybgo/generate-summary', ...)

rest_api_init
  └── mcp-adapter registers REST route

[REST request arrives]
  └── wp_get_abilities() → returns all registered abilities including sybgo/*
```

**Key**: modules call `$abilities->register()` during `plugins_loaded` (in `boot()`), which populates `Ability_Manager::$abilities`. The manager's hooks on `wp_abilities_api_init` then loop over that pre-populated cache and call `wp_register_ability()` at the correct time.

The `__()` label/description strings are evaluated at `boot()` time (before `init`), so they resolve to English. This is a known limitation — translations are a nice-to-have and the abilities function correctly regardless.

---

## MCP tool discovery — how `tools/list` works

When Claude calls `tools/list`, the library executes the `mcp-adapter/discover-abilities` ability, which calls `DiscoverAbilitiesAbility::execute()`. This:

1. Calls `wp_get_abilities()` — returns all registered `WP_Ability` objects.
2. Filters by `$ability->get_meta()['mcp']['public'] === true`.
3. Further filters by `$ability->get_meta()['mcp']['type'] === 'tool'`.
4. Returns the matching abilities as MCP tools.

For `sybgo/generate-summary` to appear in `tools/list`, its registration args must include:
```php
'meta' => array(
    'mcp' => array(
        'public' => true,
        'type'   => 'tool',
    ),
),
```

---

## Known issues and past failures

| Symptom | Root cause | Fix applied |
|---------|-----------|-------------|
| 404 on `/wp-json/mcp/mcp-adapter-default-server` | Library not loaded / server not created | Added `wordpress/mcp-adapter` as Composer dep; `create_server()` in `mcp_adapter_init` |
| `vendor/wordpress/mcp-adapter/vendor/autoload.php` not found | Library expects its own Composer install | `define('WP_MCP_AUTOLOAD', false)` before `require_once mcp-adapter.php` |
| `non_static_method` WP_Error from `create_server` | `McpAdapter::class` used instead of `McpAdapter::instance()` for hook callbacks | Fixed to `McpAdapter::instance()` |
| Default server created without JWT permission callback | `DefaultServerFactory` doesn't expose `permission_callback` | Suppress default server with filter, recreate via `mcp_adapter_init` |
| 403 without `WWW-Authenticate` on unauthenticated requests | `HttpTransport::check_permission()` swallows `WP_Error`, returns `false` | `rest_pre_dispatch` filter intercepts early, returns 401 + header |
| `-32010 "User authentication required for session creation"` | JWT validation passed but `get_current_user_id()` returned 0 inside `HttpSessionValidator::create_session()` | Added `wp_set_current_user($user_id)` in `RequestValidator` before returning `WP_User` |
| `mcp-adapter-discover-abilities` returns `{"abilities":[]}` (first attempt) | `Ability_Manager::init()` deferred to `wp_abilities_api_init` hook via `add_action` inside `init@20` — the hook had already fired by then | Rewrote `Ability_Manager::init()` to register hooks on `wp_abilities_api_categories_init` and `wp_abilities_api_init` directly |
| `mcp-adapter-discover-abilities` still returns `{"abilities":[]}` (second attempt) | `wp_abilities_api_init` fires before `init@5`, so modules hadn't yet called `$abilities->register()` when the hook ran | Moved `$abilities->register()` calls into `boot()` (no `init@5` deferral), so the cache is populated before `wp_abilities_api_init` fires |
| `_load_textdomain_just_in_time` notice | `__()` called in `boot()` which runs on `plugins_loaded`, before text domain loads on `init` | Known/accepted: ability labels are English; abilities work correctly regardless |
| `wp_register_ability_category` / `wp_register_ability` called incorrectly notice | Called outside the required actions | Fixed by binding to `wp_abilities_api_categories_init` / `wp_abilities_api_init` |
| `vendor/wp-media/sybgo-lib` symlink broken on server | Composer path repository uses symlink | Replaced with real directory copy; tickets #113/#114 for build script |

---

## Logging guide

All `error_log` calls are prefixed `[MCP]` for easy grepping:
```bash
grep '\[MCP\]' /path/to/debug.log
```

### Prefix conventions
- `[MCP][BOOT]` — plugin bootstrap, adapter init, server creation
- `[MCP][ABILITIES]` — ability registration flow (category + per-ability)
- `[MCP][DISCOVERY]` — `.well-known` endpoints
- `[MCP][REG]` — dynamic client registration
- `[MCP][AUTHORIZE]` — `/oauth/authorize`
- `[MCP][CALLBACK]` — `/oauth/authorize-callback`
- `[MCP][TOKEN]` — `/oauth/token`
- `[MCP][VALIDATOR]` — `RequestValidator` JWT checks
- `[MCP][DISPATCH]` — `rest_pre_dispatch` / `rest_post_dispatch` filters
- `[MCP][DISCOVER]` — temporary diagnostic: `wp_get_abilities()` dump in vendor file (remove after confirming abilities work)

### Security note
Logs include request headers and claim fields but **never log raw JWT strings or Application Password raw values**. Authorization header credentials are truncated to `scheme first12chars...last6chars`.

---

## Sequence diagram

```
Claude                  Sybgo / WordPress               MCP Library
  │                           │                               │
  │── GET /.well-known/opr ──>│[DISCOVERY] log + respond     │
  │<─ 200 resource metadata ──│                               │
  │                           │                               │
  │── GET /.well-known/oas ──>│[DISCOVERY] log + respond     │
  │<─ 200 AS metadata ────────│                               │
  │                           │                               │
  │── POST /oauth/register ──>│[REG] log body + respond      │
  │<─ 201 client_id/secret ───│                               │
  │                           │                               │
  │── GET /oauth/authorize ──>│[AUTHORIZE] log params        │
  │<─ 302 → wp-login.php ─────│  store state transient       │
  │                           │                               │
  │ [user logs in]            │                               │
  │                           │                               │
  │── GET /authorize-callback>│[CALLBACK] log state+user     │
  │<─ 302 → claude.ai?code ───│  store code transient        │
  │                           │                               │
  │── POST /oauth/token ─────>│[TOKEN] log grant_type+code   │
  │<─ 200 access+refresh JWT ─│  create Application Password │
  │                           │                               │
  │── POST /wp-json/mcp/ ────>│                          [DISPATCH] log headers
  │   Authorization: Bearer   │                          [VALIDATOR] decode+check
  │<─ 401 WWW-Authenticate ───│  (if no/bad token)           │
  │   OR                      │                               │
  │── POST /wp-json/mcp/ ────>│  wp_set_current_user()  HttpTransport handles
  │   Authorization: Bearer   │  [VALIDATOR] success    JSON-RPC routing
  │<─ 200 JSON-RPC result ────│                               │
  │                           │                               │
  │   tools/list calls        │                          DiscoverAbilitiesAbility
  │   discover-abilities ────>│                          wp_get_abilities()
  │<─ [{sybgo-generate-summary}]                         filter mcp.public=true
```
