# Event Tracking

This guide explains what events Sybgo tracks, how tracking works, and how to configure it.

## What Events Are Tracked

Sybgo tracks 16 different event types across 4 categories:

### Posts & Pages
- **`post_published`** - New post or page published
- **`post_edited`** - Existing post/page updated (with edit magnitude)
- **`post_deleted`** - Post/page moved to trash

### Users
- **`user_registered`** - New user account created
- **`user_role_changed`** - User role modified (subscriber → editor, etc.)
- **`user_deleted`** - User account deleted

### WordPress Updates
- **`core_updated`** - WordPress core version updated
- **`plugin_updated`** - Plugin updated to new version
- **`plugin_installed`** - New plugin installed
- **`plugin_activated`** - Plugin activated
- **`plugin_deactivated`** - Plugin deactivated
- **`theme_updated`** - Theme updated to new version
- **`theme_installed`** - New theme installed
- **`theme_switched`** - Active theme changed

### Comments
- **`comment_posted`** - New comment submitted
- **`comment_approved`** - Comment approved/unapproved/marked spam

## How Event Tracking Works

When you perform an action in WordPress (publish a post, approve a comment, etc.), Sybgo's tracker classes listen for the corresponding WordPress hook and create an event record. Each tracker registers its event types via the `sybgo_event_types` filter and hooks into WordPress actions to capture events.

### Event Data Structure

Each event stores the following information:

```json
{
    "action": "published",
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

This structured format makes it easy for:
- Generating human-readable email summaries
- Filtering events by type or object
- AI-powered summaries (via Anthropic Claude API)

## Smart Throttling

To prevent database bloat from frequent auto-saves, Sybgo throttles edit events:

**Rules:**
- Maximum 1 edit event per post per hour
- Minimum 5% content change required (configurable in settings)

**Example:**
```
10:00 AM - Publish post → Event recorded
10:15 AM - Edit post (fix typo) → Event recorded
10:30 AM - Edit again → Skipped (within 1 hour)
11:20 AM - Edit again → Event recorded (>1 hour passed)
```

## Edit Magnitude Tracking

When you edit a post, Sybgo calculates what percentage of the content changed:

**How it works:**
- Compares old content vs new content using `similar_text()`
- Calculates percentage difference
- Stores in `metadata.edit_magnitude` field

**Interpretation:**
- 0-5%: Typo fixes, minor corrections
- 5-25%: Small updates, added paragraph
- 25-50%: Moderate revisions
- 50-100%: Major rewrite

**Example in weekly digest:**
> "Updated: How to Install WordPress (45% changed)"

## Configuring Event Tracking

### Enable/Disable Event Types

**Admin UI:** Settings → Sybgo

Uncheck any event types you don't want to track. This is useful for:
- Reducing noise (e.g., disable comment events on low-traffic sites)
- Focusing on specific activities (e.g., only track posts and users)

### Adjust Edit Threshold

**Admin UI:** Settings → Sybgo

Set the minimum percentage change to track edit events:
- Default: 5%
- Range: 0-100%

**Use cases:**
- Set to 0% to track all edits (including tiny typos)
- Set to 25% to only track significant rewrites

## Viewing Tracked Events

### Dashboard Widget

**Location:** WP Admin Dashboard → "Site Activity Digest" widget (sidebar)

**Features:**
- Last Week's Summary with highlights from the previous frozen report
- This week's event count
- Filter events by type (All, Posts, Users, Updates, Comments)
- Preview this week's digest (with AI summary if configured)

### Reports Page

**Location:** WP Admin → Sybgo Reports (top-level menu)

Shows all reports (active, frozen, emailed) with period dates, event counts, status, and detailed view with summary cards, highlights, and full event list.

### Database Inspection

```sql
-- View recent events
SELECT event_type, JSON_EXTRACT(event_data, '$.object.title') as title, event_timestamp
FROM wp_sybgo_events
ORDER BY event_timestamp DESC
LIMIT 10;

-- Count events by type
SELECT event_type, COUNT(*) as total
FROM wp_sybgo_events
WHERE report_id IS NULL  -- Current week only
GROUP BY event_type;

-- View edit magnitude for recent edits
SELECT
    JSON_EXTRACT(event_data, '$.object.title') as title,
    JSON_EXTRACT(event_data, '$.metadata.edit_magnitude') as edit_pct,
    event_timestamp
FROM wp_sybgo_events
WHERE event_type = 'post_edited'
ORDER BY event_timestamp DESC
LIMIT 10;
```

## Troubleshooting

### Events Not Being Tracked

**Check 1: Is the event type enabled?**
- Go to Settings → Sybgo
- Ensure the checkbox is enabled for that event type

**Check 2: Is throttling preventing the event?**
- Edit events are limited to 1 per hour per post
- Check if you edited the same post recently
- Wait 1 hour and try again

**Check 3: Is the edit magnitude too small?**
- Minor typo fixes may be below the threshold
- Check the edit magnitude threshold in Settings → Sybgo
- Lower the threshold or make larger edits

**Check 4: Verify WordPress hooks are firing**
```php
// Add to functions.php for debugging
add_action('transition_post_status', function($new_status, $old_status, $post) {
    error_log("Post {$post->ID} changed from {$old_status} to {$new_status}");
}, 10, 3);
```

### Viewing Raw Event Data

```bash
# Using WP-CLI
wp db query "SELECT * FROM wp_sybgo_events WHERE id = 123"

# Pretty print JSON
wp db query "SELECT JSON_PRETTY(event_data) FROM wp_sybgo_events WHERE id = 123"
```

## Event Examples

### Post Published Event
```json
{
    "action": "published",
    "object": {
        "type": "post",
        "id": 42,
        "title": "Getting Started with WordPress",
        "url": "https://example.com/getting-started"
    },
    "context": {
        "user_id": 1,
        "user_name": "admin"
    },
    "metadata": {
        "categories": ["Tutorials"],
        "tags": ["wordpress", "beginner"],
        "word_count": 1200
    }
}
```

### User Registered Event
```json
{
    "action": "registered",
    "object": {
        "type": "user",
        "id": 5,
        "username": "john_doe",
        "email": "john@example.com"
    },
    "metadata": {
        "role": "subscriber",
        "previous_role": null
    }
}
```

### WordPress Core Updated Event
```json
{
    "action": "updated",
    "object": {
        "type": "core",
        "name": "WordPress Core",
        "slug": "wordpress"
    },
    "metadata": {
        "old_version": "6.4.2",
        "new_version": "6.5.0"
    }
}
```

## Performance Considerations

**Database Impact:**
- Each event is ~1KB of data
- Active sites: ~100-500 events/week
- 1-year retention = ~5,000-25,000 events (~5-25MB)

**Automatic Cleanup:**
- Events older than 1 year are automatically deleted
- Runs daily at 3:00 AM via cron
- Manually trigger: `wp cron event run sybgo_cleanup_old_events`

**Caching:**
- Event queries are cached for 1 hour
- Cache automatically invalidated when new events created
- Improves dashboard widget performance

## Related Documentation

- [Report Lifecycle](report-lifecycle.md) - How events become weekly reports
- [Extension API](extension-api.md) - Track custom events from other plugins
- [Development Guide](development.md) - Creating new event trackers and project structure
