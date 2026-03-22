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

### PHP Errors
- **`php_error`** - A PHP warning, notice, or deprecation was captured by the site (aggregated; see below)

## How Event Tracking Works

When you perform an action in WordPress (publish a post, approve a comment, etc.), Sybgo's tracker classes listen for the corresponding WordPress hook and create an event record. Each tracker registers its event types via the `sybgo_event_types` filter and hooks into WordPress actions to capture events.

### Tracker Class Hierarchy

Trackers extend one of two abstract base classes in `lib/events/abstracts/`, depending on the storage strategy:

- **`Abstract_Singular_Event`** — for events logged individually. Provides `record()` (persists the event, applies `sybgo_event_data` and `sybgo_should_track_event` filters, fires `sybgo_event_recorded`) and `is_throttled()` (checks whether a recent event for the same object is within a given window). The constructor automatically wires the `sybgo_event_types` filter. All 4 built-in trackers extend this class.

- **`Abstract_Aggregated_Event`** — for events accumulated daily rather than logged individually. Provides `increment(string $event_type, float $value = 1.0, array $dimensions = [], array $meta = [])`, which upserts a row in `wp_sybgo_aggregated_events` for today's date. Pass `$value = 1.0` for simple counts, or a decimal (e.g. `249.95`) to accumulate sums such as revenue. Pass `$dimensions` to break the metric down per object, role, product, etc. No filter registration is done automatically; subclasses hook into WordPress actions directly.

### Event Categories

Every event type belongs to a category: `'singular'` (default) or `'aggregated'`. The category is declared via the `category` key when registering the event type on the `sybgo_event_types` filter. `Event_Registry::get_event_category( $event_type )` returns the category string.

Singular events are written to `wp_sybgo_events` (one row per occurrence). Aggregated events are written to `wp_sybgo_aggregated_events` (one row per `(event_type, dimensions, date)` combination, with an accumulating `value`).

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

## PHP Error Tracking

`Error_Tracker` (`lib/events/trackers/class-error-tracker.php`) registers a custom PHP error handler via `set_error_handler()` at plugin init. It captures non-fatal PHP errors — warnings, notices, user errors, deprecations — as aggregated events stored in `wp_sybgo_aggregated_events` with `event_type = 'php_error'`.

### What is captured

Non-fatal PHP errors are captured via `set_error_handler()`: `E_WARNING`, `E_NOTICE`, `E_USER_ERROR`, `E_USER_WARNING`, `E_USER_NOTICE`, `E_DEPRECATED`, `E_USER_DEPRECATED`. Fatal levels (`E_ERROR`, `E_PARSE`, `E_CORE_ERROR`, `E_COMPILE_ERROR`) bypass `set_error_handler()` and are captured instead via a `register_shutdown_function()` callback that inspects `error_get_last()`. The daily cap (see below) is not enforced for fatals, since a fatal terminates the request immediately and cannot produce a loop.

Errors suppressed with the `@` operator are detected by comparing `error_reporting()` at handler invocation time against the mask captured at handler registration time. Any difference indicates `@`-suppression and the error is silently skipped. This approach works reliably across all PHP versions.

### Error signatures and dimensions

Each error occurrence is identified by a **signature**: an md5 hash of `file:line:message_excerpt` (first 100 characters of the message). The signature and the error level are stored as `dimensions`, so each distinct error location gets its own row in `wp_sybgo_aggregated_events`. Repeated occurrences of the same error just increment the `value` counter on that row.

The `meta` column stores a snapshot: `file`, `line`, and the first 100 characters of the message, for display purposes.

### Per-period cap

To prevent database bloat from error storms, at most **5 distinct error signatures** are stored per report period. On each error, `Error_Tracker` queries `Aggregated_Event_Repository::count_distinct_dimensions_for_report('php_error', null)` — where `null` selects rows with `report_id = 0` (the sentinel for the current, not-yet-frozen period). Once 5 distinct signatures have been recorded in the current period, new signatures are dropped. Already-known signatures continue to accumulate. The cap resets automatically after a freeze because the freeze operation sets `report_id` to the real report ID on all sentinel rows, making the `report_id = 0` set empty again.

### Handler chaining

`Error_Tracker` stores the previously registered error handler and always calls it after its own logic, so existing error handling (WordPress's own handler, third-party plugins) is unaffected.

### Dashboard display

The WordPress admin dashboard widget includes a **PHP Errors** section that shows:

- The number of distinct error signatures recorded during the current report period.
- The total occurrence count for those signatures.
- A top-5 list of errors, each entry showing a level emoji (warning/user_warning → ⚠️, notice/user_notice → ℹ️, deprecated/user_deprecated → 🔔, user_error → ❌), the error message, the filename and line number, and the occurrence count.

The section only renders when at least one error has been recorded in the period. Both the total count and the per-signature breakdown are fetched using the report-scoped repository methods (`get_sum_for_report` and `get_rows_for_report` with `report_id = null`), which scope the query to unassigned rows rather than a calendar date range.

The report details page (Sybgo Reports → View Details) also renders a **PHP Errors** table below the All Events table, with one row per distinct error signature. The table is populated by `Reports_Page::render_php_errors_table()` using `Aggregated_Event_Repository::get_rows_for_report()` — passing `null` for the active report or the numeric `report_id` for frozen/emailed reports.

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
- Two action buttons at the top of the widget: "Preview This Week's Digest" (opens a modal via the `sybgo_preview_digest` AJAX action) and "View Previous Digest" (opens a modal via the `sybgo_preview_last_digest` AJAX action).
- This week's event count with filter buttons (All, Posts, Users, Updates, Comments).
- PHP Errors section showing distinct signature count, total occurrences, and a top-5 error list (see PHP Error Tracking above).

### Reports Page

**Location:** WP Admin → Sybgo Reports (top-level menu)

Shows all reports (active, frozen, emailed) with period dates, event counts, status, and detailed view with summary cards, highlights, and full event list.

### Database Inspection

Singular events are stored in `wp_sybgo_events` (one row per occurrence). Aggregated events are stored in `wp_sybgo_aggregated_events`, with a unique constraint on `(event_type, dimensions_hash, date, report_id)` so upserts accumulate into the correct period slot.

`Aggregated_Event_Repository` exposes read methods beyond `upsert`. The primary query interface is report-scoped — passing `null` targets unassigned rows (current active period, `report_id = 0`); passing an integer targets a specific frozen report:

- `count_distinct_dimensions_for_report(string $event_type, ?int $report_id): int` — counts distinct dimension sets for the current or a past period. Used by `Error_Tracker` to enforce the 5-signature-per-period cap.
- `get_sum_for_report(string $event_type, ?int $report_id): float` — sums all accumulated values for the period. Used by the dashboard widget for total error occurrence counts.
- `get_rows_for_report(string $event_type, ?int $report_id): array` — returns one row per distinct dimension set (grouped by `dimensions_hash`) with `SUM(value) AS total`, ordered by total descending. Each row contains `dimensions`, `total`, and `meta`. Used by the dashboard PHP Errors section and by the report detail view.

Date-range variants (`count_distinct_dimensions_for_date_range`, `get_sum_for_date_range`, `get_rows_for_event_type_and_date_range`) are also available for cases where a calendar range is needed rather than a report boundary.

The `wp_sybgo_aggregated_events` schema (defined in `DatabaseManager::create_tables()`):

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED | Auto-increment PK |
| `event_type` | VARCHAR(100) | Event type identifier |
| `dimensions` | LONGTEXT | JSON blob of breakdown axes, e.g. `{"role":"editor","product_id":42}`. Empty = `'{}'` (global row). |
| `dimensions_hash` | VARCHAR(64) | SHA2-256 of `dimensions`, computed by MySQL automatically. Used in the UNIQUE KEY. |
| `value` | DECIMAL(20,4) | Accumulated value for the day (count or sum). Default 0. |
| `report_id` | BIGINT UNSIGNED NOT NULL | `0` = current unassigned period (sentinel). Set to the actual report ID during freeze via `assign_to_report()`. Including `report_id` in the unique key allows multiple freeze cycles on the same calendar day without collision. |
| `date` | DATE | Date of the aggregation (Y-m-d) |
| `meta` | LONGTEXT | Optional JSON context snapshot (overwritten on conflict, not accumulated) |

**Use-case examples:**

| Use case | `event_type` | `dimensions` | `value` delta |
|---|---|---|---|
| Page visits per page | `page_view` | `{"post_id": 42}` | 1.0 |
| PHP errors per location | `php_error` | `{"level":"warning","signature":"<md5>"}` | 1.0 |
| WooCommerce units per product | `woo_sale_units` | `{"product_id": 99}` | 1.0 |
| WooCommerce revenue per product | `woo_sale_revenue` | `{"product_id": 99}` | 249.95 |
| User registrations per role | `user_registered` | `{"role": "editor"}` | 1.0 |

```sql
-- View recent singular events
SELECT event_type, JSON_EXTRACT(event_data, '$.object.title') as title, event_timestamp
FROM wp_sybgo_events
ORDER BY event_timestamp DESC
LIMIT 10;

-- Count events by type
SELECT event_type, COUNT(*) as total
FROM wp_sybgo_events
WHERE report_id IS NULL  -- Current period (singular events still use NULL)
GROUP BY event_type;

-- Top 10 pages by visits today
SELECT JSON_UNQUOTE(JSON_EXTRACT(dimensions, '$.post_id')) AS post_id,
       SUM(value) AS visits
FROM wp_sybgo_aggregated_events
WHERE event_type = 'page_view' AND date = CURDATE()
GROUP BY post_id ORDER BY visits DESC LIMIT 10;

-- Revenue per product this week
SELECT JSON_UNQUOTE(JSON_EXTRACT(dimensions, '$.product_id')) AS product_id,
       SUM(value) AS revenue
FROM wp_sybgo_aggregated_events
WHERE event_type = 'woo_sale_revenue'
  AND date BETWEEN DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND CURDATE()
GROUP BY product_id ORDER BY revenue DESC;

-- User registrations per role last 30 days
SELECT JSON_UNQUOTE(JSON_EXTRACT(dimensions, '$.role')) AS role,
       date, SUM(value) AS registrations
FROM wp_sybgo_aggregated_events
WHERE event_type = 'user_registered'
  AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
GROUP BY role, date ORDER BY date, role;
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
- Events and aggregated data older than the configured retention period are automatically deleted (default: 90 days)
- Runs daily at 3:00 AM via cron
- The retention period is configurable via Settings → Sybgo → Database Management → Data Retention Period
- Manually trigger: `wp cron event run sybgo_cleanup_old_events`
- A "Run Cleanup Now" button is also available on the settings page for immediate on-demand cleanup

**Caching:**
- Event queries are cached for 1 hour
- Cache automatically invalidated when new events created
- Improves dashboard widget performance

## Related Documentation

- [Report Lifecycle](report-lifecycle.md) - How events become weekly reports
- [Extension API](extension-api.md) - Track custom events from other plugins
- [Development Guide](development.md) - Creating new event trackers and project structure
