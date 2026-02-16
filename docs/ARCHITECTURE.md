# Sybgo - Architecture Overview

**Since You've Been Gone** - A WordPress activity digest plugin that tracks meaningful events and sends weekly email reports.

## Core Concept

Sybgo automatically monitors your WordPress site and aggregates important changes (posts, users, updates, comments) into weekly digestible reports. Inspired by New Relic's "Since You've Been Gone" email campaign.

## System Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                     WordPress Events                         │
│  (Posts, Users, Comments, Updates, Themes, Plugins)         │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│                   Event Trackers                             │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐   │
│  │   Post   │  │   User   │  │  Update  │  │ Comment  │   │
│  │ Tracker  │  │ Tracker  │  │ Tracker  │  │ Tracker  │   │
│  └──────────┘  └──────────┘  └──────────┘  └──────────┘   │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│              Event Repository (BerlinDB)                     │
│  - Stores events as JSON (AI-ready structure)               │
│  - Throttling (1 event/hour per object)                     │
│  - Edit magnitude calculation (% changed)                   │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│                  Report Manager                              │
│  - Weekly freeze (Sunday 23:55)                             │
│  - Trend calculation (compare to previous week)             │
│  - Summary generation                                        │
└──────────────────────┬──────────────────────────────────────┘
                       │
           ┌───────────┴───────────┐
           ▼                       ▼
┌──────────────────┐    ┌──────────────────┐
│  Dashboard       │    │  Email           │
│  Widget          │    │  Manager         │
│  - Last report   │    │  - HTML template │
│  - Current week  │    │  - wp_mail()     │
│  - Trends ↑↓     │    │  - Retry queue   │
└──────────────────┘    └──────────────────┘
```

## Database Schema (BerlinDB)

### Table: `wp_sybgo_events`
Stores individual events with generic structure.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT | Auto-increment primary key |
| `event_type` | VARCHAR(50) | Type identifier (post_published, user_registered, etc.) |
| `event_subtype` | VARCHAR(50) | Subtype (post, page, user, etc.) |
| `object_id` | BIGINT | ID of affected object (post ID, user ID, etc.) |
| `user_id` | BIGINT | User who triggered event |
| `event_data` | LONGTEXT | **JSON with ALL event-specific data** |
| `event_timestamp` | DATETIME | When event occurred |
| `report_id` | BIGINT | NULL until assigned to frozen report |
| `source_plugin` | VARCHAR(100) | 'core' or plugin slug |

**Design Principle:** Table is fully generic. Event-specific fields (like `edit_magnitude`) go inside `event_data` JSON.

### Table: `wp_sybgo_reports`
Weekly report containers.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT | Report ID |
| `status` | VARCHAR(20) | 'active', 'frozen', 'emailed' |
| `period_start` | DATETIME | Week start |
| `period_end` | DATETIME | Week end (NULL for active) |
| `summary_data` | LONGTEXT | JSON with totals, trends, highlights |
| `frozen_at` | DATETIME | When frozen |
| `emailed_at` | DATETIME | When email sent |

### Table: `wp_sybgo_email_log`
Email delivery tracking.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT | Log entry ID |
| `report_id` | BIGINT | Associated report |
| `recipient_email` | VARCHAR(255) | Email address |
| `status` | VARCHAR(20) | 'sent', 'failed', 'pending' |
| `sent_at` | DATETIME | Send timestamp |
| `error_message` | TEXT | Error details if failed |

## Key Components

### 1. Event Trackers (`events/trackers/`)
Each tracker monitors specific WordPress hooks and creates events.

**Post Tracker** ([class-post-tracker.php](../events/trackers/class-post-tracker.php))
- Hooks: `transition_post_status`, `post_updated`, `before_delete_post`
- Features: Edit magnitude calculation, 1-hour throttling per post
- Events: `post_published`, `post_edited`, `post_deleted`

**User Tracker** ([class-user-tracker.php](../events/trackers/class-user-tracker.php))
- Hooks: `user_register`, `set_user_role`, `delete_user`
- Events: `user_registered`, `user_role_changed`, `user_deleted`

**Update Tracker** ([class-update-tracker.php](../events/trackers/class-update-tracker.php))
- Hooks: `_core_updated_successfully`, `upgrader_process_complete`
- Events: `core_updated`, `plugin_updated`, `theme_updated`

**Comment Tracker** ([class-comment-tracker.php](../events/trackers/class-comment-tracker.php))
- Hooks: `comment_post`, `wp_set_comment_status`
- Events: `comment_posted`, `comment_approved`, `comment_spam`, `comment_trashed`

### 2. Event Registry ([class-event-registry.php](../events/class-event-registry.php))
Registers event types with AI-friendly descriptions for each event type. When integrating with AI APIs, these descriptions provide context about the data structure.

```php
Event_Registry::describe_event('post_edited', $event_data);
// Returns: "Event Type: Post Edited\nDescription: An existing published post..."
```

### 3. Edit Magnitude Calculation
Uses PHP's `similar_text()` to compare old vs new content:

```php
similar_text($old_content, $new_content, $percentage);
$edit_magnitude = 100 - (int)$percentage;
```

**Ranges:**
- 0-5%: Minimal (typos, formatting)
- 5-25%: Minor updates
- 25-50%: Moderate revisions
- 50-75%: Major rewrite
- 75-100%: Complete rewrite

### 4. Throttling Mechanism
Prevents event spam by limiting 1 event per object per hour:

```php
$last_event = $event_repo->get_last_event_for_object('post_edited', $post_id);
$time_since = current_time('timestamp') - strtotime($last_event['event_timestamp']);
if ($time_since < 3600) return; // Skip
```

### 5. Weekly Cycle

```
Monday 00:00 ──────────────────> Sunday 23:59
     │                                │
     │  Active Report                 │
     │  - event.report_id = NULL      │
     │  - Collecting events           │
     │                                │
     └────────────────────────────────┘
                                      │
                          Sunday 23:55: FREEZE
                                      │
                                      ▼
                          1. Generate summary_data
                          2. Assign events to report_id
                          3. Calculate trends (vs previous week)
                          4. Status = 'frozen'
                                      │
                          Monday 00:05: EMAIL
                                      │
                                      ▼
                          1. Send HTML email
                          2. Status = 'emailed'
                          3. Create new active report
```

## Event Data JSON Structure

All event-specific data stored in `event_data` column as JSON:

### Post/Page Event
```json
{
  "action": "edited",
  "object": {
    "type": "post",
    "id": 123,
    "title": "My Blog Post",
    "url": "https://example.com/my-blog-post"
  },
  "context": {
    "user_id": 1,
    "user_name": "admin"
  },
  "metadata": {
    "categories": ["Technology"],
    "tags": ["wordpress"],
    "word_count": 1500,
    "edit_magnitude": 45
  }
}
```

### Report Summary Data
```json
{
  "totals": {
    "posts_published": 12,
    "posts_edited": 45,
    "users_registered": 3
  },
  "trends": {
    "posts_published": {
      "current": 12,
      "previous": 10,
      "change_percent": 20,
      "direction": "up"
    }
  },
  "highlights": [
    "12 new posts published ↑ 20%",
    "WordPress updated to 6.5"
  ],
  "top_authors": [
    {"name": "John", "count": 8}
  ]
}
```

## AI Integration

The plugin is designed to be "AI-ready":

1. **Structured JSON**: All data stored in predictable, semantic JSON format
2. **Event Descriptions**: Each event type has a description method explaining its structure
3. **Context Generation**: `Event_Registry::get_ai_context_for_events()` creates AI-friendly context

**Future Use Case:**
```php
$context = Event_Registry::get_ai_context_for_events($events);
$prompt = "Analyze this week's activity and provide insights:\n\n" . $context;
// Send to OpenAI/Claude API
```

## Extensibility for Other Plugins

### Track Custom Events
```php
// In WooCommerce plugin
add_action('woocommerce_new_order', function($order_id) {
    if (class_exists('Rocket\Sybgo\Events\Event_Tracker')) {
        $tracker = Factory::get_instance()->get_event_tracker();
        $tracker->track_custom_event('woocommerce_order', [
            'action' => 'created',
            'object' => ['type' => 'order', 'id' => $order_id],
            'metadata' => ['total' => wc_get_order($order_id)->get_total()]
        ], 'woocommerce');
    }
});
```

### Add Custom Email Sections
```php
add_action('sybgo_email_custom_section', function($report_id) {
    echo '<div class="woocommerce-section">';
    echo '<h2>E-Commerce Activity</h2>';
    // Display WooCommerce-specific data
    echo '</div>';
});
```

## File Structure

```
sybgo/
├── class-sybgo.php              # Main plugin entry point
├── class-factory.php            # Dependency injection container
├── database/
│   ├── class-databasemanager.php    # BerlinDB schema setup
│   ├── class-event-repository.php   # Event CRUD operations
│   └── class-report-repository.php  # Report CRUD operations
├── events/
│   ├── class-event-registry.php     # Event type registration
│   ├── class-event-tracker.php      # Core tracking coordinator
│   └── trackers/
│       ├── class-post-tracker.php   # Post/page events
│       ├── class-user-tracker.php   # User events
│       ├── class-update-tracker.php # Update events
│       └── class-comment-tracker.php# Comment events
├── reports/
│   ├── class-report-manager.php     # Report lifecycle
│   └── class-report-generator.php   # Summary generation
├── admin/
│   ├── class-dashboard-widget.php   # WP dashboard widget
│   ├── class-settings-page.php      # Settings UI
│   └── class-admin-page.php         # Reports admin page
├── email/
│   ├── class-email-manager.php      # Email delivery
│   └── class-email-template.php     # HTML templates
├── api/
│   └── class-extensibility-api.php  # Public API
└── assets/
    ├── css/admin.css
    └── js/admin.js
```

## WordPress Hooks Used

### Monitoring Hooks
- `transition_post_status` - Post status changes
- `post_updated` - Post edits
- `before_delete_post` - Post deletions
- `user_register` - New users
- `set_user_role` - Role changes
- `delete_user` - User deletions
- `_core_updated_successfully` - WP core updates
- `upgrader_process_complete` - Plugin/theme updates
- `comment_post` - New comments
- `wp_set_comment_status` - Comment moderation

### Cron Hooks
- `sybgo_freeze_weekly_report` - Sunday 23:55
- `sybgo_send_report_emails` - Monday 00:05
- `sybgo_cleanup_old_events` - Daily 3am (delete >1 year old)
- `sybgo_retry_failed_emails` - Daily 9am

### Custom Filters (Extensibility)
- `sybgo_event_data` - Modify event data before storage
- `sybgo_should_track_event` - Skip tracking specific events
- `sybgo_report_summary` - Modify report summary
- `sybgo_email_recipients` - Change email recipient list
- `sybgo_email_template` - Customize email HTML

### Custom Actions (Extensibility)
- `sybgo_event_recorded` - After event saved
- `sybgo_before_report_freeze` - Before freezing
- `sybgo_after_report_freeze` - After freezing
- `sybgo_email_custom_section` - Add sections to email

## Performance Considerations

1. **Caching**: WordPress object cache for frequent queries (`wp_cache_get/set`)
2. **Database Indexes**: On `event_type`, `report_id`, `event_timestamp`
3. **Throttling**: Prevents database bloat from frequent edits
4. **Cleanup**: Automatic deletion of events >1 year old
5. **BerlinDB**: Efficient ORM with built-in caching and query optimization

## Security

1. **Nonces**: All forms use `wp_nonce_field()` and `check_admin_referer()`
2. **Escaping**: Output escaped with `esc_html()`, `esc_url()`, `esc_attr()`
3. **Sanitization**: Input sanitized with `sanitize_text_field()`, `sanitize_email()`
4. **Prepared Statements**: All SQL uses `$wpdb->prepare()` or BerlinDB ORM
5. **Capability Checks**: Admin functions require `manage_options` capability

## Data Retention

- **Events**: Deleted after 1 year (configurable)
- **Reports**: Kept indefinitely (frozen reports are historical records)
- **Email Logs**: Kept indefinitely for audit trail

## Next Steps for New Developers

1. **Read this document** to understand the architecture
2. **Check [IMPLEMENTATION.md](./IMPLEMENTATION.md)** for implementation details
3. **Review [API.md](./API.md)** for public API documentation
4. **Run tests**: `composer run-tests`
5. **Code standards**: `composer phpcs`
