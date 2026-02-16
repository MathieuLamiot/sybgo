# Sybgo Documentation

**Since You've Been Gone** - WordPress Activity Digest Plugin

## Documentation Index

### For New Developers
1. **[ARCHITECTURE.md](./ARCHITECTURE.md)** - Start here! Understand the system design, data flow, and key concepts.
2. **[IMPLEMENTATION.md](./IMPLEMENTATION.md)** - Implementation details, code patterns, and development workflow.

### Quick Start

```bash
# Install dependencies
composer install

# Check code
composer phpcs

# Run tests
composer run-tests
```

## What is Sybgo?

Sybgo automatically monitors your WordPress site and sends you weekly email digests of important activity:
- 📝 Posts and pages published or edited
- 👥 New user registrations
- 🔄 WordPress, plugin, and theme updates
- 💬 New comments and moderation

**Key Features:**
- **Smart Throttling**: Max 1 event per post per hour (prevents spam)
- **Edit Magnitude**: Tracks % of content changed (5-100%)
- **Trend Indicators**: Compare this week vs last week (↑↓)
- **AI-Ready**: Structured JSON data for future AI integration
- **Extensible**: Other plugins can add custom events

## Architecture at a Glance

```
WordPress Events → Event Trackers → Database (BerlinDB)
                                         ↓
                              Report Manager (weekly freeze)
                                         ↓
                          Dashboard Widget + Email Digest
```

## Database Tables

- `wp_sybgo_events` - Individual events with JSON data
- `wp_sybgo_reports` - Weekly report containers
- `wp_sybgo_email_log` - Email delivery tracking

## Key Behaviors

### Event Tracking
When you publish or edit a post, an event is automatically created with:
- Event type (`post_published`, `post_edited`, etc.)
- Object data (post ID, title, URL)
- Metadata (categories, word count, edit %)
- Timestamp

### Weekly Cycle
- **Monday-Sunday**: Events collected (report_id = NULL)
- **Sunday 23:55**: Report frozen, trends calculated
- **Monday 00:05**: Email sent to configured recipients
- **Monday 00:06**: New week starts

### Throttling
- Max 1 edit event per post per hour
- Minimum 5% content change required
- Prevents database bloat from frequent saves

### Edit Magnitude
Calculates percentage of content changed using `similar_text()`:
- 0-5%: Typo fixes
- 5-25%: Minor updates
- 25-50%: Moderate revisions
- 50-100%: Major rewrites

## File Structure

```
sybgo/
├── docs/               # You are here!
├── database/           # BerlinDB schemas and repositories
├── events/             # Event tracking system
│   └── trackers/       # Post, User, Update, Comment trackers
├── reports/            # Report generation and freezing
├── admin/              # Dashboard widget, settings, admin pages
├── email/              # Email manager and templates
├── api/                # Public API for extensibility
└── assets/             # CSS and JavaScript
```

## For Plugin Developers

Want to integrate your plugin with Sybgo?

**Track custom events:**
```php
$tracker = \Rocket\Sybgo\Factory::get_instance()->get_event_tracker();
$tracker->track_custom_event('your_event_type', $event_data, 'your-plugin');
```

**Add email sections:**
```php
add_action('sybgo_email_custom_section', function($report_id) {
    echo '<div>Your custom content</div>';
});
```

See [IMPLEMENTATION.md](./IMPLEMENTATION.md) for full integration guide.

## Configuration

Settings available in **Settings → Sybgo**:
- Email recipients (one per line)
- Minimum edit magnitude threshold
- Enable/disable specific event types

## Cron Jobs

- `sybgo_freeze_weekly_report` - Sunday 23:55
- `sybgo_send_report_emails` - Monday 00:05
- `sybgo_cleanup_old_events` - Daily 3am (delete >1 year)
- `sybgo_retry_failed_emails` - Daily 9am

## Testing

```bash
# Manual trigger freeze
wp cron event run sybgo_freeze_weekly_report

# Manual trigger email
wp cron event run sybgo_send_report_emails

# Check database
wp db query "SELECT * FROM wp_sybgo_events ORDER BY event_timestamp DESC LIMIT 5"
```

## Performance

- **Caching**: WordPress object cache for frequent queries
- **Indexes**: On event_type, report_id, timestamp
- **Cleanup**: Auto-delete events >1 year old
- **Throttling**: Prevents event spam

## Security

- ✅ Nonces on all forms
- ✅ Output escaping
- ✅ Input sanitization
- ✅ Prepared SQL statements (BerlinDB ORM)
- ✅ Capability checks (`manage_options`)

## Future Enhancements

- [ ] **BerlinDB Migration**: Refactor from raw SQL to BerlinDB ORM
- [ ] **AI Integration**: OpenAI/Claude API for intelligent summaries
- [ ] **Export Reports**: PDF/CSV download
- [ ] **Slack Integration**: Post digests to Slack
- [ ] **Multi-Site Support**: Network-wide digests

## Technical Standards

Follows GroupOne technical standards:
- https://gopen.groupone.dev/technical_standards/php/wordpress/

## License

GPL-2.0-or-later

## Support

- Documentation: `/docs/`
- Issues: GitHub Issues
- Code: Follow WordPress Coding Standards
