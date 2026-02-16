# Getting Started with Sybgo

This guide will help new developers understand the Sybgo plugin architecture and get up to speed quickly.

## Your First Day: Understanding the Plugin

### What Does Sybgo Do?

Sybgo ("Since You've Been Gone") automatically tracks meaningful WordPress activity and sends weekly email digests to administrators. Think of it as a "what happened while you were away" report.

**Core Features:**
- Tracks posts/pages (publish + smart edit detection)
- Tracks users, comments, WordPress/plugin/theme updates
- Generates weekly reports with trend analysis (week-over-week comparison)
- Sends beautiful HTML email digests
- Extensible API for other plugins

### Plugin Initialization Flow

Understanding how Sybgo starts up is key to working with the codebase.

#### 1. Entry Point: `sybgo.php`

The main plugin file does three things:
```php
// 1. Define constants
define('SYBGO_VERSION', '1.0.0');
define('SYBGO_PLUGIN_DIR', plugin_dir_path(__FILE__));

// 2. Load autoloader
require_once __DIR__ . '/vendor/autoload.php';

// 3. Initialize plugin
add_action('plugins_loaded', function() {
    Rocket\Sybgo\Sybgo::get_instance();
});
```

#### 2. Main Plugin Class: `class-sybgo.php`

The `Sybgo` class is a singleton orchestrator:

```php
public static function get_instance(): Sybgo {
    if (null === self::$instance) {
        self::$instance = new self();
    }
    return self::$instance;
}

private function __construct() {
    $this->factory = new Factory();

    // Initialize components in order
    $this->init_database();
    $this->init_event_tracking();
    $this->init_reports();
    $this->init_admin();
    $this->init_email();
    $this->init_cron();
    $this->init_extensibility_api();
}
```

**Initialization Order Matters:**
1. **Database** - Tables must exist first
2. **Event Tracking** - Hooks into WordPress actions
3. **Reports** - Depends on event data
4. **Admin UI** - Depends on reports
5. **Email** - Depends on reports
6. **Cron** - Schedules weekly tasks
7. **Extensibility API** - Makes plugin available to others

#### 3. Factory Pattern: `class-factory.php`

The Factory creates and manages all component instances:

```php
// Example: Creating the dashboard widget
$widget = $this->factory->create_dashboard_widget();

// Behind the scenes, Factory handles dependencies:
public function create_dashboard_widget(): object {
    if (null === self::$dashboard_widget_instance) {
        $event_repo = $this->create_event_repository();
        $report_repo = $this->create_report_repository();
        $report_generator = new Report_Generator($event_repo, $report_repo);

        self::$dashboard_widget_instance = new Dashboard_Widget(
            $event_repo,
            $report_repo,
            $report_generator
        );
    }
    return self::$dashboard_widget_instance;
}
```

**Why Use Factory?**
- Singleton management (one instance per component)
- Dependency injection (components get what they need)
- Easier testing (mock the factory in tests)

### Key Architecture Concepts

#### Generic Event Storage

All events use the same table structure:

```sql
CREATE TABLE wp_sybgo_events (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    event_type varchar(100) NOT NULL,        -- 'post_published', 'user_registered'
    event_data LONGTEXT NOT NULL,            -- JSON with ALL event-specific data
    report_id bigint(20) DEFAULT NULL,       -- NULL until weekly freeze
    created_at datetime NOT NULL,
    PRIMARY KEY (id)
);
```

**Why JSON?**
- Scalable: Add new event types without migrations
- AI-ready: LLMs can parse structured data
- Flexible: Each event type has different fields

**Example Event Data:**
```json
{
    "action": "edited",
    "object": {
        "type": "post",
        "id": 123,
        "title": "My Blog Post",
        "url": "https://example.com/post"
    },
    "context": {
        "user_id": 1,
        "user_name": "admin"
    },
    "metadata": {
        "edit_magnitude": 45,
        "word_count": 1500
    }
}
```

#### Event Registry for AI Integration

Each event type registers a "describe" callback:

```php
// In Post Tracker initialization
Event_Registry::register_event_type('post_published', function($event_data) {
    return "Event Type: Post Published\n" .
           "Description: A new post was published.\n" .
           "Data Structure:\n" .
           "  - object.id: Post ID\n" .
           "  - object.title: Post title\n" .
           "  - metadata.word_count: Word count\n";
});
```

This allows AI to understand event context without hardcoded knowledge.

#### Weekly Report Lifecycle

```
┌─────────────────┐
│  Active Report  │ ← New events added here (report_id = NULL)
└────────┬────────┘
         │
         │ Sunday 23:55: Freeze
         ▼
┌─────────────────┐
│ Frozen Report   │ ← Events assigned, summary generated
└────────┬────────┘
         │
         │ Monday 00:05: Email
         ▼
┌─────────────────┐
│ Emailed Report  │ ← Email log created
└─────────────────┘
```

**Key Methods:**
- `Report_Manager::freeze_current_report()` - Assigns events, generates summary
- `Report_Generator::generate_summary()` - Aggregates statistics, calculates trends
- `Email_Manager::send_report_email()` - Sends HTML digest to recipients

### Directory Structure

```
sybgo/
├── sybgo.php                     # Entry point (register with WordPress)
├── class-sybgo.php               # Main orchestrator (singleton)
├── class-factory.php             # Dependency injection container
│
├── database/                     # Data layer
│   ├── class-databasemanager.php   # Schema, migrations, cleanup
│   ├── class-event-repository.php  # Event CRUD operations
│   └── class-report-repository.php # Report CRUD operations
│
├── events/                       # Event tracking
│   ├── class-event-tracker.php     # Coordinator + throttling
│   ├── class-event-registry.php    # AI description registry
│   └── trackers/
│       ├── class-post-tracker.php      # Posts/pages + edit magnitude
│       ├── class-user-tracker.php      # Users + role changes
│       ├── class-comment-tracker.php   # Comments + moderation
│       └── class-update-tracker.php    # Core/plugin/theme updates
│
├── reports/                      # Report generation
│   ├── class-report-manager.php    # Lifecycle (freeze, email)
│   └── class-report-generator.php  # Aggregation + trends
│
├── admin/                        # WordPress admin UI
│   ├── class-dashboard-widget.php  # WP dashboard widget
│   ├── class-settings-page.php     # Email + preferences
│   └── class-reports-page.php      # Report list/detail views
│
├── email/                        # Email system
│   ├── class-email-manager.php     # Send + retry logic
│   └── class-email-template.php    # HTML template generation
│
├── api/                          # Extensibility
│   ├── class-extensibility-api.php # Public hooks/filters
│   └── functions.php               # Global helper functions
│
├── docs/                         # Documentation
└── Tests/                        # Unit + integration tests
```

## Common Development Tasks

### Adding a New Event Type

Let's say you want to track media uploads.

**Step 1: Create Tracker Class**

Create `events/trackers/class-media-tracker.php`:

```php
namespace Rocket\Sybgo\Events\Trackers;

use Rocket\Sybgo\Events\Event_Registry;

class Media_Tracker {
    private object $event_tracker;

    public function __construct(object $event_tracker) {
        $this->event_tracker = $event_tracker;
        $this->register_hooks();
        $this->register_event_types();
    }

    private function register_hooks(): void {
        add_action('add_attachment', [$this, 'on_media_upload']);
    }

    private function register_event_types(): void {
        Event_Registry::register_event_type('media_uploaded', function($event_data) {
            return "Event Type: Media Uploaded\n" .
                   "Description: A new file was uploaded to Media Library.\n" .
                   "Data Structure:\n" .
                   "  - object.id: Attachment ID\n" .
                   "  - object.filename: Original filename\n" .
                   "  - metadata.file_size: Size in bytes\n" .
                   "  - metadata.mime_type: File MIME type\n";
        });
    }

    public function on_media_upload(int $attachment_id): void {
        $attachment = get_post($attachment_id);
        $metadata = wp_get_attachment_metadata($attachment_id);

        $event_data = [
            'action' => 'uploaded',
            'object' => [
                'type' => 'media',
                'id' => $attachment_id,
                'filename' => basename(get_attached_file($attachment_id)),
                'url' => wp_get_attachment_url($attachment_id)
            ],
            'context' => [
                'user_id' => get_current_user_id(),
                'user_name' => wp_get_current_user()->user_login
            ],
            'metadata' => [
                'file_size' => filesize(get_attached_file($attachment_id)),
                'mime_type' => $attachment->post_mime_type,
                'dimensions' => isset($metadata['width']) ? "{$metadata['width']}x{$metadata['height']}" : null
            ]
        ];

        $this->event_tracker->track_event('media_uploaded', $event_data);
    }
}
```

**Step 2: Wire Up in Main Plugin**

Add to `class-sybgo.php`:

```php
private function init_event_tracking(): void {
    // ... existing trackers ...

    // Add media tracker
    require_once SYBGO_PLUGIN_DIR . 'events/trackers/class-media-tracker.php';
    $media_tracker = new \Rocket\Sybgo\Events\Trackers\Media_Tracker($event_tracker);
}
```

**Step 3: Update Report Generator**

Add to `reports/class-report-generator.php`:

```php
private function calculate_totals(array $events): array {
    $totals = [
        'posts_published' => 0,
        // ... existing types ...
        'media_uploaded' => 0,  // Add this
    ];

    foreach ($events as $event) {
        switch ($event['event_type']) {
            // ... existing cases ...
            case 'media_uploaded':
                $totals['media_uploaded']++;
                break;
        }
    }

    return $totals;
}
```

**Step 4: Add to Email Template**

The new event type will automatically appear in highlights if significant.

### Debugging Event Tracking

**Check if events are being recorded:**

```php
// In your tracker's hook callback
error_log('Sybgo: Tracking media_uploaded for attachment ' . $attachment_id);

// Check throttling
if ($this->event_tracker->should_throttle('media', $attachment_id)) {
    error_log('Sybgo: Throttled media upload event');
    return;
}
```

**Query events directly:**

```sql
SELECT * FROM wp_sybgo_events
WHERE event_type = 'media_uploaded'
ORDER BY created_at DESC
LIMIT 10;
```

**Verify event data structure:**

```sql
SELECT event_type, JSON_PRETTY(event_data)
FROM wp_sybgo_events
WHERE id = 123;
```

### Testing Your Changes

**Run unit tests:**
```bash
composer test-unit
```

**Run specific test:**
```bash
vendor/bin/phpunit --filter MediaTrackerTest
```

**Manual testing checklist:**
1. Upload media → Check event in database
2. Trigger weekly freeze → Check report summary includes media count
3. Send test email → Verify media appears in digest

## Next Steps

- Read [ARCHITECTURE.md](ARCHITECTURE.md) for system design details
- Read [API.md](API.md) for extensibility examples
- Read [TESTING.md](TESTING.md) for test writing guide
- Check [IMPLEMENTATION.md](IMPLEMENTATION.md) for phase-by-phase implementation details

## Getting Help

**Common Issues:**
- Events not tracking? Check [Troubleshooting](#troubleshooting-guide) below
- Cron not running? See [Cron Debugging](#debugging-cron-jobs) below
- Email not sending? See [Email Debugging](#debugging-email-delivery) below

## Troubleshooting Guide

### Events Not Being Tracked

**Symptom:** WordPress actions happen but no events appear in `wp_sybgo_events` table.

**Diagnosis:**

1. **Check if plugin is active:**
   ```php
   if (class_exists('Rocket\Sybgo\Sybgo')) {
       error_log('Sybgo is loaded');
   }
   ```

2. **Verify hooks are registered:**
   ```sql
   -- Check if any events exist
   SELECT COUNT(*) FROM wp_sybgo_events;
   ```

3. **Enable WordPress debug mode:**
   ```php
   // In wp-config.php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   ```

4. **Add debug logging to trackers:**
   ```php
   // In class-post-tracker.php on_post_publish()
   error_log('Sybgo: Post published hook fired for post ' . $post_id);
   ```

**Common Causes:**

- **Throttling:** Edit events are limited to 1/hour per post
  - Check: `SELECT * FROM wp_sybgo_events WHERE event_type='post_edited' ORDER BY created_at DESC;`
  - Solution: Wait 1 hour or disable throttling in Event_Tracker::should_throttle()

- **Wrong hook priority:** Sybgo hooks might run before post is fully saved
  - Solution: Increase hook priority in tracker's add_action() call

- **Event type disabled in settings:** Check Settings → Sybgo → Event Tracking
  - Solution: Enable the event type or remove settings check

### Debugging Cron Jobs

**Symptom:** Weekly reports never freeze/email automatically.

**Diagnosis:**

1. **Check if cron events are scheduled:**
   ```bash
   wp cron event list | grep sybgo
   ```

   Expected output:
   ```
   sybgo_freeze_weekly_report    2026-02-16 23:55:00
   sybgo_send_report_emails      2026-02-17 00:05:00
   sybgo_cleanup_old_events      2026-02-17 03:00:00
   sybgo_retry_failed_emails     2026-02-17 09:00:00
   ```

2. **Check if WordPress cron is disabled:**
   ```bash
   wp config get DISABLE_WP_CRON
   ```

   If `true`, cron won't run automatically.

3. **Manually trigger cron event:**
   ```bash
   wp cron event run sybgo_freeze_weekly_report
   ```

**Common Causes:**

- **Low traffic site:** WordPress cron only runs on page loads
  - Solution: Set up system cron:
    ```bash
    # In crontab -e
    */15 * * * * wget -q -O - https://yoursite.com/wp-cron.php?doing_wp_cron
    ```

- **Cron disabled:** `DISABLE_WP_CRON` is true in wp-config.php
  - Solution: Enable WP cron or use system cron (above)

- **PHP errors in callback:** Cron fails silently
  - Solution: Check error log during manual trigger:
    ```bash
    tail -f wp-content/debug.log
    wp cron event run sybgo_freeze_weekly_report
    ```

- **Timezone issues:** Server time doesn't match expected time
  - Check: `wp option get timezone_string`
  - Solution: Set timezone in Settings → General

### Debugging Email Delivery

**Symptom:** Reports freeze but emails never arrive.

**Diagnosis:**

1. **Check email log table:**
   ```sql
   SELECT * FROM wp_sybgo_email_log
   ORDER BY created_at DESC
   LIMIT 10;
   ```

   Look for `status='failed'` and check `error_message` column.

2. **Verify recipients configured:**
   ```sql
   SELECT option_value FROM wp_options
   WHERE option_name = 'sybgo_email_recipients';
   ```

3. **Test wp_mail() function:**
   ```php
   wp_mail('test@example.com', 'Test', 'Test message');
   ```

**Common Causes:**

- **No SMTP configured:** Default PHP mail() often fails
  - Solution: Install SMTP plugin (WP Mail SMTP, Easy WP SMTP)
  - Verify: Send test email from SMTP plugin settings

- **Empty report with "Don't send empty reports" enabled:**
  - Check: Settings → Sybgo → "Send email even if no events"
  - Solution: Disable setting or ensure events exist

- **Invalid email addresses:**
  - Check: Settings → Sybgo → Email Recipients
  - Solution: Fix typos, ensure valid email format

- **HTML email blocked:** Some servers strip HTML
  - Check: Email arrives but looks broken
  - Solution: Enable "text/html" content type in email client

4. **Check spam folder:** Email delivered but filtered
   - Solution: Add sender to contacts/whitelist

5. **Server email limits:** Shared hosting may limit emails/hour
   - Check with hosting provider
   - Solution: Reduce recipient count or use third-party email service

### Database Issues

**Symptom:** Plugin activation fails or queries error.

**Diagnosis:**

1. **Check if tables exist:**
   ```sql
   SHOW TABLES LIKE 'wp_sybgo_%';
   ```

   Expected: `wp_sybgo_events`, `wp_sybgo_reports`, `wp_sybgo_email_log`

2. **Check table structure:**
   ```sql
   DESCRIBE wp_sybgo_events;
   ```

3. **Check for MySQL errors:**
   ```bash
   tail -f /var/log/mysql/error.log
   ```

**Common Causes:**

- **Database permissions:** User lacks CREATE TABLE privilege
  - Solution: Grant privileges or contact hosting support

- **Old migration state:** Tables partially created
  - Solution: Deactivate plugin, drop tables manually, reactivate:
    ```sql
    DROP TABLE IF EXISTS wp_sybgo_events;
    DROP TABLE IF EXISTS wp_sybgo_reports;
    DROP TABLE IF EXISTS wp_sybgo_email_log;
    ```

- **Index size limits:** Indexes exceed MySQL limits
  - Solution: Check `DatabaseManager::create_events_table()` index sizes

### Performance Issues

**Symptom:** Dashboard widget loads slowly or admin pages hang.

**Diagnosis:**

1. **Check event count:**
   ```sql
   SELECT COUNT(*) FROM wp_sybgo_events;
   ```

   If >100k events, queries may be slow.

2. **Check for missing indexes:**
   ```sql
   SHOW INDEXES FROM wp_sybgo_events;
   ```

   Expected indexes: event_type, report_id, created_at

3. **Profile slow queries:**
   ```sql
   SHOW FULL PROCESSLIST;
   ```

**Solutions:**

- **Enable query caching:** Already implemented in Event_Repository
  - Verify: Check wp_cache_get/set calls in repository methods

- **Optimize queries:** Use EXPLAIN to find slow queries
  ```sql
  EXPLAIN SELECT * FROM wp_sybgo_events WHERE report_id IS NULL;
  ```

- **Run cleanup cron:** Delete old events (>1 year)
  ```bash
  wp cron event run sybgo_cleanup_old_events
  ```

- **Paginate dashboard widget:** Limit events shown to last 50
  - Edit `admin/class-dashboard-widget.php` render_events() method

## Key Files Reference

Quick reference for where to find specific functionality:

| What You Need | File Location |
|---------------|---------------|
| Plugin initialization order | [class-sybgo.php:79-87](../class-sybgo.php#L79-L87) |
| Create new tracker | `events/trackers/class-*-tracker.php` |
| Add event type | Tracker's `register_event_types()` method |
| Modify report summary | [reports/class-report-generator.php:75](../reports/class-report-generator.php#L75) |
| Change email template | [email/class-email-template.php:45](../email/class-email-template.php#L45) |
| Add admin settings field | [admin/class-settings-page.php:112](../admin/class-settings-page.php#L112) |
| Add extensibility hook | [api/class-extensibility-api.php](../api/class-extensibility-api.php) |
| Database schema changes | [database/class-databasemanager.php:64](../database/class-databasemanager.php#L64) |
| Cron schedule changes | [class-sybgo.php:231](../class-sybgo.php#L231) |

## Development Workflow

**Typical development cycle:**

1. **Make changes** to relevant files
2. **Run code standards check:**
   ```bash
   composer phpcs
   ```
3. **Fix any issues:**
   ```bash
   composer phpcs:fix
   ```
4. **Run tests:**
   ```bash
   composer run-tests
   ```
5. **Manual testing** in local WordPress install
6. **Commit changes** with descriptive message

**Local development setup:**

1. Use Local by Flywheel, MAMP, or Docker for WordPress
2. Symlink plugin directory to wp-content/plugins/:
   ```bash
   ln -s /path/to/sybgo /path/to/wordpress/wp-content/plugins/sybgo
   ```
3. Activate plugin in WordPress admin
4. Install development dependencies:
   ```bash
   composer install
   ```
