# Sybgo - Monorepo

**Since You've Been Gone** — Sybgo plugin and its PHP library, managed as a monorepo.

## Repository Structure

```
sybgo/
├── wp-plugin/    WordPress plugin (wp-media/sybgo)
└── lib/          PHP library (wp-media/sybgo-lib)
```

## Packages

### wp-plugin — WordPress Plugin

Tracks meaningful changes on a WordPress site and sends weekly email digests.

- **[Development Guide](wp-plugin/docs/development.md)** — local setup, running tests, contributing
- **Requirements:** WordPress 5.0+, PHP 7.4+, Composer

```bash
cd wp-plugin
composer install

# Code standards
composer phpcs
composer phpcs:fix

# Tests
composer test-unit
composer test-integration
```

### lib — PHP Library

Core event tracking, report generation, email delivery, and AI summarization logic.

- **[Event Tracking](lib/docs/event-tracking.md)** — tracked event types and architecture
- **[Report Lifecycle](lib/docs/report-lifecycle.md)** — report generation and delivery
- **[Extension API](lib/docs/extension-api.md)** — integrate third-party plugins

```bash
cd lib
composer install

# Code standards
composer phpcs

# Tests
composer test-unit
composer test-integration
```

## CI

Six scoped workflows in `.github/workflows/`, each triggered only on changes to its package:

| Workflow | Scope | Trigger path |
|----------|-------|--------------|
| `phpcs-plugin.yml` | Plugin code style | `wp-plugin/**` |
| `phpcs-lib.yml` | Lib code style | `lib/**` |
| `phpstan-plugin.yml` | Plugin static analysis | `wp-plugin/**` |
| `phpstan-lib.yml` | Lib static analysis | `lib/**` |
| `phpunit-plugin.yml` | Plugin tests + coverage | `wp-plugin/**` |
| `phpunit-lib.yml` | Lib tests + coverage | `lib/**` |

## License

GPL-2.0-or-later
