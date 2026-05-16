# Sybgo End-to-End Tests

Playwright suite that drives the WordPress admin UI through the same flows you
would test by hand. Runs against the wp-env stack booted via the repo-root
[`bin/dev-up.sh`](../../bin/dev-up.sh).

## Run locally

```bash
bin/dev-up.sh                  # one-time boot of WP + sybgo + seed data
cd tests/e2e
npm install
npx playwright install --with-deps chromium
npm test                       # headless
npm run test:headed            # see the browser
npm run test:ui                # interactive runner
```

## Configuration

| Env var               | Default                   | Purpose                                  |
| --------------------- | ------------------------- | ---------------------------------------- |
| `SYBGO_BASE_URL`      | `http://localhost:8888`   | Where the WP install is reachable.       |
| `SYBGO_ADMIN_USER`    | `admin`                   | Admin user for the login fixture.        |
| `SYBGO_ADMIN_PASS`    | `password`                | Admin password for the login fixture.    |

## Layout

```
tests/e2e/
├── fixtures/        Reusable helpers (login, WP-CLI shell-out)
├── page-objects/    One class per major admin area (POM pattern)
├── specs/           Test files — each focused on one user flow
├── playwright.config.ts
├── package.json
└── tsconfig.json
```

## Conventions

- One worker, no parallelism: tests share the wp-env DB and isolate via
  `bin/dev-seed.sh` in `beforeAll`. If you need true isolation, snapshot the
  DB and reset between tests with `wp db reset`.
- Page objects expose locators as readonly properties and methods for the
  actions a user takes — never raw selectors in the specs.
- Selector preference: roles + accessible names → ids → CSS selectors. The
  source-of-truth comment above each POM class points at the PHP file the
  locators reference, so they survive renames if you grep for the comment.
- Tests do not assert against specific report ids; they query by row index.
