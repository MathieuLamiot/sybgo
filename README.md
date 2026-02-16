# Sybgo - WordPress Activity Digest Plugin

**Since You've Been Gone** - Stay informed about what's happening on your WordPress site with automated weekly digests.

## 📧 What is Sybgo?

Sybgo automatically tracks meaningful changes on your WordPress site and sends you weekly email digests.

**Track:**
- 📝 Posts and pages (published, edited with % changed, deleted)
- 👥 Users (registrations, role changes, deletions)
- 🔄 Updates (WordPress core, plugins, themes)
- 💬 Comments (new, approved, spam, trashed)

**Features:**
- ⚡ Smart Throttling (max 1 event/hour per object)
- 📊 Trend Indicators (↑↓ % vs last week)
- 🎯 Edit Magnitude (% of content changed)
- 🤖 AI-Ready (structured JSON data)
- 🔌 Extensible (public API for other plugins)

## 🚀 Quick Start

```bash
# Install
cd wp-content/plugins
git clone <repo-url> sybgo
cd sybgo
composer install

# Activate in WordPress admin
# Configure: Settings → Sybgo
```

## 📚 Documentation

**Start here:** [docs/](docs/)

- **[Architecture](docs/ARCHITECTURE.md)** - System design and data flow
- **[Implementation](docs/IMPLEMENTATION.md)** - Code patterns and development
- **[API](docs/README.md)** - Documentation index

## 🛠️ Development

```bash
composer phpcs              # Check code standards
composer run-tests          # Run all tests
wp cron event run sybgo_freeze_weekly_report  # Manual freeze
```

## 🏗️ Architecture

```
WordPress Events → Trackers → Database → Report Manager → Email/Dashboard
```

**11 Event Types:** post_published, post_edited, user_registered, core_updated, comment_posted, etc.

**Weekly Cycle:** Monday-Sunday collect → Sunday freeze → Monday email

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for details.

## 🔌 Plugin Integration

```php
// Track custom events
$tracker = \Rocket\Sybgo\Factory::get_instance()->get_event_tracker();
$tracker->track_custom_event('your_event', $data, 'your-plugin');

// Add email sections
add_action('sybgo_email_custom_section', function($report_id) {
    echo '<div>Custom content</div>';
});
```

## 🚧 Roadmap

- [ ] BerlinDB Migration (in progress)
- [ ] AI Integration (OpenAI/Claude)
- [ ] Export Reports (PDF/CSV)
- [ ] Slack Integration

## 📝 License

GPL-2.0-or-later

