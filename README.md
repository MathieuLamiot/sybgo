# Sybgo - WordPress Activity Digest Plugin

**Since You've Been Gone** - Stay informed about what's happening on your WordPress site with automated weekly digests.

## What is Sybgo?

Sybgo automatically tracks meaningful changes on your WordPress site and sends weekly email digests.

**Track:**
- 📝 Posts and pages (published, edited, deleted)
- 👥 Users (registrations, role changes)
- 🔄 Updates (WordPress core, plugins, themes)
- 💬 Comments (new, approved, moderation)

**Features:**
- ⚡ Smart throttling (max 1 event/hour per object)
- 📊 Trend indicators (↑↓ % vs last week)
- 🎯 Edit magnitude tracking (% of content changed)
- 🤖 AI-ready (structured JSON data for future integration)
- 🔌 Extensible API for other plugins

## Quick Start

```bash
# 1. Install
cd wp-content/plugins
git clone <repo-url> sybgo
cd sybgo
composer install

# 2. Activate in WordPress admin
# WP Admin → Plugins → Activate "Sybgo"

# 3. Configure
# WP Admin → Settings → Sybgo
# - Add email recipients (one per line)
# - Choose which event types to track
```

## How It Works

```
WordPress Events → Event Trackers → Database → Weekly Report → Email Digest
```

**Weekly Cycle:**
- Monday-Sunday: Events are collected
- Sunday 23:55: Report is frozen with trends calculated
- Monday 00:05: Email digest sent to configured recipients

## Documentation

## For Users

- **[Event Tracking](event-tracking.md)** - What events are tracked and how to configure them
- **[Report Lifecycle](report-lifecycle.md)** - How weekly reports are generated and delivered

## For Developers

- **[Extension API](extension-api.md)** - Integrate your plugin with Sybgo
- **[Development Guide](development.md)** - Setup, testing, and contributing

## Quick Links

- [Main README](../README.md) - Project overview and quick start
- [GitHub Issues](https://github.com/your-org/sybgo/issues) - Report bugs or request features

## Plugin Integration Example

```php
// Track custom events from your plugin
$tracker = \Rocket\Sybgo\Factory::get_instance()->get_event_tracker();
$tracker->track_custom_event('woocommerce_order', [
    'action' => 'created',
    'object' => [
        'type' => 'order',
        'id' => $order_id,
        'total' => $order->get_total()
    ]
], 'woocommerce');

// Add custom sections to email digest
add_action('sybgo_email_custom_section', function($report_id) {
    echo '<div class="custom-section">Your plugin content</div>';
});
```

See [Extension API documentation](docs/extension-api.md) for more details.

## Development

```bash
# Code standards
composer phpcs              # Check code standards
composer phpcs:fix          # Auto-fix issues

# Testing
composer run-tests          # Run all tests
composer test-unit          # Unit tests only
composer test-integration   # Integration tests only

# Manual testing
wp cron event run sybgo_freeze_weekly_report  # Trigger report freeze
wp cron event run sybgo_send_report_emails    # Trigger email send
```

## Requirements

- WordPress 5.0+
- PHP 7.4+
- Composer

## License

GPL-2.0-or-later

## Support

- **Documentation:** [docs/](docs/)
- **Issues:** [GitHub Issues](https://github.com/your-org/sybgo/issues)
- **Standards:** Follows [GroupOne WordPress Standards](https://gopen.groupone.dev/technical_standards/php/wordpress/)
