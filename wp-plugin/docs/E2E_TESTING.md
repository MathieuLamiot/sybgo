# E2E Testing Guide for Sybgo

This guide covers the Sybgo E2E test suite using Playwright, including architecture, running tests efficiently, and best practices.

## Quick Start

### Run All E2E Tests
```bash
cd tests/e2e
npx playwright test
```

### Run Specific Tests (Faster for Development)

**Run a single test file** (~1–2 minutes):
```bash
npx playwright test dashboard-widget.spec.ts
npx playwright test settings.spec.ts
npx playwright test reports-lifecycle.spec.ts
```

**Run tests by name pattern** (~15–30 seconds):
```bash
# Run all tests matching "dashboard"
npx playwright test -g "dashboard"

# Run a specific test by exact name
npx playwright test -g "appears on the WP dashboard"

# Run tests NOT matching a pattern
npx playwright test --grep-invert "freezing"
```

**Interactive debugging**:
```bash
# UI mode for step-by-step execution
npx playwright test -g "freezing" --ui

# Headed browser for visual debugging
npx playwright test dashboard-widget.spec.ts --headed

# Debug mode with DevTools
npx playwright test -g "appears on the WP dashboard" --debug
```

**Rerun failed tests**:
```bash
npx playwright test --last-failed
```

### Time Estimates

| Scenario | Time | Use Case |
|----------|------|----------|
| Full suite (11 tests) | 4–6 min | CI pipeline, pre-commit |
| Single test file | 1–2 min | Testing one feature area |
| Single test | 15–30 sec | Debugging specific test |
| Settings tests only | 30–45 sec | No seed data needed (fastest) |

**Development recommendation**: Use single test files or grep patterns for rapid iteration. Settings tests are fastest since they don't require seed data.

---

## Test Architecture

### File Structure
```
tests/e2e/
├── specs/
│   ├── dashboard-widget.spec.ts    # 4 tests, requires seed
│   ├── settings.spec.ts             # 3 tests, no seed needed
│   └── reports-lifecycle.spec.ts    # 4 tests, requires seed
├── fixtures/
│   ├── auth.ts                      # Admin login helper
│   └── wp-cli.ts                    # WP-CLI runner for setup/assertions
├── page-objects/
│   ├── dashboard-widget.ts
│   ├── settings.ts
│   ├── reports-list.ts
│   └── [other pages...]
└── playwright.config.ts             # Playwright configuration
```

### Test Organization

Tests are grouped using `test.describe()` blocks:

| Describe Block | File | Tests | Seed Required? |
|---|---|---|---|
| "Dashboard widget" | dashboard-widget.spec.ts | 4 | Yes (`bin/dev-seed.sh`) |
| "Settings page" | settings.spec.ts | 3 | No |
| "Reports lifecycle" | reports-lifecycle.spec.ts | 4 | Yes (`bin/dev-seed.sh`) |

### Page Object Model (POM)

Selectors are centralized in `page-objects/` for maintainability:

```typescript
export class DashboardWidgetPage {
  constructor(page: Page) { ... }
  
  async goto(): Promise<void> { ... }
  async getEventCount(): Promise<number> { ... }
  async clickFilterTab(name: string): Promise<void> { ... }
}
```

**Why**: 
- Decouples tests from DOM structure
- Makes selector updates easier
- Improves test readability

---

## Running Tests Locally

### Prerequisites
```bash
cd tests/e2e
npm install
```

### Boot WordPress Environment
```bash
# From repo root
npx @wordpress/env start
```

### Run Tests
```bash
cd tests/e2e
npx playwright test [options]
```

### Cleanup
```bash
npx @wordpress/env stop
```

---

## Key Patterns

### Admin Authentication

Located in `fixtures/auth.ts`. Sybgo uses the idempotent auth pattern:

```typescript
export async function loginAsAdmin(page: Page): Promise<void> {
  await page.goto('/wp-login.php', { waitUntil: 'networkidle' });
  
  // Already logged in? Early return
  if (page.url().includes('/wp-admin/')) {
    return;
  }
  
  // Perform login...
}
```

**Note**: This fixture should follow WP Rocket's layered auth approach for robustness (see "Best Practices" section below).

### Database Setup

Tests that need seed data use:

```typescript
beforeAll(async () => {
  // Create test data once per describe block
  await runFromRepoRoot('bin/dev-seed.sh');
});
```

### WP-CLI for Assertions

Verify state or trigger operations:

```typescript
import { wpCli } from '../fixtures/wp-cli';

// Trigger cron
wpCli('cron event run sybgo_freeze_weekly_report');

// Query database
const result = wpCli('db query "SELECT COUNT(*) FROM wp_sybgo_reports"');
```

---

## Best Practices

### 1. Assertion Before Action

Assert field values *before* clicking submit to catch silent failures:

```typescript
// Good: verify field was populated
const usernameInput = page.locator('#user_login');
await usernameInput.fill('admin');
await expect(usernameInput).toHaveValue('admin');  // ✓ Catches silent failures
await page.locator('#wp-submit').click();

// Avoid: click without verifying
await page.locator('#user_login').fill('admin');
await page.locator('#wp-submit').click();  // ✗ Silent failure if fill didn't work
```

### 2. Explicit Timeouts with Fallback Verification

Don't rely on a single timeout check:

```typescript
// Good: timeout + URL verification
await page.waitForURL('**/wp-admin/**', { timeout: 5000 });
if (!page.url().includes('/wp-admin')) {
  throw new Error(`Login failed: Expected wp-admin, got ${page.url()}`);
}

// Avoid: timeout alone
await page.waitForURL('**/wp-admin/**');  // ✗ Could redirect to error page matching glob
```

### 3. Idempotent Fixtures

Auth fixture should detect existing sessions:

```typescript
if (await page.locator('#loginform').isVisible()) {
  // Perform login
} else {
  // Already logged in, skip
}
```

### 4. Clear Error Messages

Include context in errors for debugging:

```typescript
if (page.url().includes('wp-login.php?loggedout=true')) {
  throw new Error(`Login redirected to logout page. Check credentials and cookies.`);
}
```

### 5. Handle Edge Cases

E.g., email verification forms (WP 5.9+):

```typescript
if (await page.getByText('Administration email verification').isVisible()) {
  await page.locator('#correct-admin-email').click();
}
```

---

## Reference: WP Rocket E2E Testing

Sybgo's E2E patterns are inspired by **[WP Rocket's well-maintained E2E test suite](https://github.com/wp-media/wp-rocket-e2e)**. 

Key reference points in WP Rocket:
- **Auth fixture** (`/utils/page-utils.ts`) — Layered auth approach with field assertions
- **Error handling** — Clear messages with actual URLs and states
- **Page objects** — Centralized selectors with conditional visibility checks
- **Multi-user support** — Optional parameters for different test users

When improving Sybgo's E2E tests, refer to WP Rocket's implementation for inspiration and alignment with WP Media best practices.

---

## Troubleshooting

### Test Fails with "Environment not initialized"
```
Error: 'bash: bin/dev-up.sh: No such file or directory'
```

**Solution**: Ensure `wp-env` is running:
```bash
npx @wordpress/env start
```

### Playwright Can't Find Element
Check that:
1. Page has loaded (use `waitForLoadState('networkidle')`)
2. Element is visible (not hidden by CSS or `display: none`)
3. Selector is correct (inspect in browser DevTools)

**Debug**: Use UI mode to step through test:
```bash
npx playwright test -g "test name" --ui
```

### Tests Pass Locally, Fail in CI
Common causes:
- Different WordPress version in CI vs. local
- Timing issues (use explicit waits instead of `sleep`)
- Missing env vars (check GitHub Actions workflow)

**Debug**: Check CI logs in GitHub Actions tab.

---

## CI Integration

The E2E test suite runs automatically in GitHub Actions on all PRs:

```yaml
# .github/workflows/e2e.yml
- name: Run E2E Tests
  run: npx playwright test
```

**Required for merge**: All E2E tests must pass.

---

## Adding New Tests

When implementing a new feature:

1. **Create a page object** (`page-objects/new-feature.ts`) for selectors
2. **Create a spec file** (`specs/new-feature.spec.ts`) with test describe block
3. **Add acceptance criteria** tests that validate user workflows
4. **Test edge cases**: empty states, errors, boundary conditions
5. **Update this guide** if new patterns emerge

See "E2E Test Coverage: Close Gaps from PR QA Validation" (EPIC #74) for current coverage gaps and recommended tests.

---

## Resources

- [Playwright Documentation](https://playwright.dev)
- [WP Rocket E2E Tests](https://github.com/wp-media/wp-rocket-e2e) — Reference implementation
- [Sybgo E2E Coverage EPIC](https://github.com/MathieuLamiot/sybgo/issues/74) — Current test coverage improvements
