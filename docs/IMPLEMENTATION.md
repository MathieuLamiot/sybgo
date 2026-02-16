# Sybgo - Implementation Guide

Quick guide for developers working on the Sybgo plugin codebase.

## Development Setup

```bash
# Clone repository
cd wp-content/plugins
git clone <repo-url> sybgo
cd sybgo

# Install dependencies
composer install

# Run code standards check
composer phpcs

# Fix code standards
composer phpcs:fix

# Run tests
composer run-tests
```

## Key Functional Behaviors

### 1. Event Tracking Flow

**Behavior**: When a post is published or edited, an event is automatically tracked.

**Flow**:
```
User publishes post
    ↓
WordPress fires 'transition_post_status' hook
    ↓
Post_Tracker::track_post_status_change() triggered
    ↓
Check throttling (has this post been tracked in last hour?)
    ↓
Calculate edit magnitude (if edit) using similar_text()
    ↓
Build event_data JSON with all metadata
    ↓
Event_Repository::create() saves to database
    ↓
Event stored with report_id = NULL (unassigned to report)
```

**Code Location**: [events/trackers/class-post-tracker.php](../events/trackers/class-post-tracker.php)

### 2. Edit Magnitude Calculation

**Behavior**: When a published post is edited, calculate % of content changed.

**Algorithm**:
```php
// Strip HTML from old and new content
$old_clean = wp_strip_all_tags($old_content);
$new_clean = wp_strip_all_tags($new_content);

// Calculate similarity
similar_text($old_clean, $new_clean, $percentage);

// Convert to change percentage
$edit_magnitude = 100 - (int)$percentage;
```

**Throttling**:
- Maximum 1 edit event per post per hour
- Minimum edit magnitude: 5% (configurable via `sybgo_min_edit_magnitude` option)
- New publishes always tracked (magnitude = 100)

**Code Location**: `Post_Tracker::calculate_edit_magnitude()`

### 3. Weekly Report Freeze

**Behavior**: Every Sunday at 23:55, the current week's events are "frozen" into a report.

**Flow**:
```
Sunday 23:55 - WP Cron fires 'sybgo_freeze_weekly_report'
    ↓
Report_Manager::freeze_current_report()
    ↓
1. Get active report (status='active')
    ↓
2. Get all unassigned events (report_id IS NULL)
    ↓
3. Generate summary_data:
   - Count events by type
   - Calculate trends (compare to previous week)
   - Generate highlights array
   - Identify top authors
    ↓
4. Assign all events to this report_id
    ↓
5. Update report:
   - status = 'frozen'
   - period_end = now
   - frozen_at = now
    ↓
6. Create new active report for next week
```

**Code Location**: [reports/class-report-manager.php](../reports/class-report-manager.php)

### 4. Trend Calculation

**Behavior**: Compare current week to previous week and show ↑↓ indicators.

**Algorithm**:
```php
$current_count = 12; // This week's posts
$previous_count = 10; // Last week's posts

$change = (($current_count - $previous_count) / $previous_count) * 100;
// Result: 20% increase

$direction = $change > 0 ? 'up' : ($change < 0 ? 'down' : 'same');
```

**Output**:
```json
{
  "posts_published": {
    "current": 12,
    "previous": 10,
    "change_percent": 20.0,
    "direction": "up"
  }
}
```

**Code Location**: `Report_Generator::get_trend_comparison()`

### 5. Email Delivery

**Behavior**: Every Monday at 00:05, send HTML email with last week's frozen report.

**Flow**:
```
Monday 00:05 - WP Cron fires 'sybgo_send_report_emails'
    ↓
Email_Manager::send_report_email()
    ↓
1. Get last frozen report
    ↓
2. Get recipients from settings (sybgo_email_recipients option)
    ↓
3. Generate HTML email from template
    ↓
4. For each recipient:
   - Send via wp_mail()
   - Log success/failure to email_log table
   - If failed, add to retry queue
    ↓
5. Update report status = 'emailed'
```

**Retry Logic**:
- Daily cron at 9am retries failed emails
- Max 3 retry attempts
- Exponential backoff between retries

**Code Location**: [email/class-email-manager.php](../email/class-email-manager.php)

### 6. Dashboard Widget

**Behavior**: Shows activity digest in WP Admin dashboard sidebar.

**Display**:
- **Top Section**: Last week's frozen report (highlights, trends)
- **Filter Buttons**: All | Posts | Users | Updates | Comments
- **Middle Section**: This week's event count + recent events
- **Trend Indicators**: ↑ 20% or ↓ 15% next to each stat
- **Action Buttons**:
  - "Preview This Week's Digest" (AJAX modal)
  - "Ask AI to Summarize" (placeholder for AI integration)

**AJAX Endpoints**:
- `sybgo_filter_events` - Filter events by type
- `sybgo_preview_digest` - Generate email preview

**Code Location**: [admin/class-dashboard-widget.php](../admin/class-dashboard-widget.php)

### 7. Manual Freeze

**Behavior**: Admin can manually freeze and send report before Sunday.

**Flow**:
```
Admin clicks "Freeze & Send Now" button
    ↓
Confirmation dialog: "Are you sure?"
    ↓
AJAX call to 'sybgo_manual_freeze'
    ↓
Report_Manager::freeze_current_report()
    ↓
Email_Manager::send_report_email()
    ↓
Create new active report immediately
    ↓
Redirect with success message
```

**Use Case**: Site owner going on vacation, wants final report before leaving.

**Code Location**: Reports admin page, manual freeze handler

## BerlinDB Implementation

### Schema Classes

Each table has a dedicated BerlinDB schema class:

**Events Schema** (`database/schemas/class-events-schema.php`):
```php
class Events_Schema extends \BerlinDB\Database\Schema {
    public $columns = [
        'id' => [
            'name' => 'id',
            'type' => 'bigint',
            'unsigned' => true,
            'auto_increment' => true,
            'primary' => true,
        ],
        'event_type' => [
            'name' => 'event_type',
            'type' => 'varchar',
            'length' => '50',
        ],
        // ... other columns
    ];
}
```

### Query Interface

**Insert**:
```php
$event_repo->create([
    'event_type' => 'post_published',
    'event_data' => ['action' => 'published', ...],
    'object_id' => 123,
]);
```

**Query**:
```php
// Get events by report
$events = $event_repo->get_by_report($report_id);

// Get recent unassigned events
$events = $event_repo->get_recent(5);

// Count by type
$counts = $event_repo->count_by_type();
```

**Update**:
```php
// Assign events to report
$event_repo->assign_to_report($report_id, $start_date, $end_date);
```

### Caching

BerlinDB has built-in caching. Manual cache management:

```php
// Clear cache after writes
wp_cache_delete('sybgo_recent_events', 'sybgo_cache');

// Set cache for reads
wp_cache_set('key', $data, 'sybgo_cache', 300); // 5 minutes
```

## Adding New Event Types

**Step 1**: Create event data structure
```php
$event_data = [
    'action' => 'your_action',
    'object' => ['type' => 'your_type', 'id' => 123],
    'metadata' => ['custom_field' => 'value'],
];
```

**Step 2**: Register event type with description
```php
Event_Registry::register_event_type('your_event_type', function($event_data) {
    return "Event Type: Your Event\nDescription: ...\nData Structure: ...";
});
```

**Step 3**: Track the event
```php
$event_repo->create([
    'event_type' => 'your_event_type',
    'event_data' => $event_data,
    'source_plugin' => 'your-plugin-slug',
]);
```

## Extending for Third-Party Plugins

### Example: WooCommerce Integration

**Create separate plugin**: `sybgo-woocommerce`

```php
// In sybgo-woocommerce plugin
add_action('woocommerce_new_order', 'sybgo_woo_track_order', 10, 1);

function sybgo_woo_track_order($order_id) {
    // Check if Sybgo is active
    if (!class_exists('Rocket\Sybgo\Factory')) {
        return;
    }

    $order = wc_get_order($order_id);
    $factory = \Rocket\Sybgo\Factory::get_instance();
    $tracker = $factory->get_event_tracker();

    // Track custom event
    $tracker->track_custom_event('woocommerce_order', [
        'action' => 'created',
        'object' => [
            'type' => 'order',
            'id' => $order_id,
            'status' => $order->get_status(),
        ],
        'metadata' => [
            'total' => $order->get_total(),
            'items_count' => $order->get_item_count(),
            'payment_method' => $order->get_payment_method(),
        ],
    ], 'woocommerce');

    // Register event type description
    \Rocket\Sybgo\Events\Event_Registry::register_event_type(
        'woocommerce_order',
        function($event_data) {
            return "Event Type: WooCommerce Order\n" .
                   "Description: A new order was placed.\n" .
                   "Metadata: total, items_count, payment_method";
        }
    );
}

// Add custom email section
add_action('sybgo_email_custom_section', 'sybgo_woo_email_section', 10, 1);

function sybgo_woo_email_section($report_id) {
    // Query WooCommerce events for this report
    // Display custom HTML
    echo '<div class="woo-section">';
    echo '<h2>E-Commerce Activity</h2>';
    // ... WooCommerce-specific stats
    echo '</div>';
}
```

## Testing

### Manual Testing Checklist

1. **Event Tracking**:
   - [ ] Publish post → Check database for `post_published` event
   - [ ] Edit post → Check `edit_magnitude` in event_data
   - [ ] Edit again within 1 hour → Should be throttled (no new event)
   - [ ] Register user → Check `user_registered` event
   - [ ] Update plugin → Check `plugin_updated` event

2. **Report Freeze**:
   - [ ] Manually trigger: `wp cron event run sybgo_freeze_weekly_report`
   - [ ] Check report status changed to 'frozen'
   - [ ] Verify all events assigned to report_id
   - [ ] Check summary_data has trends

3. **Dashboard Widget**:
   - [ ] Visit wp-admin → Widget shows in sidebar
   - [ ] Click filter buttons → Events update
   - [ ] Click "Preview Digest" → Modal opens

4. **Email**:
   - [ ] Trigger: `wp cron event run sybgo_send_report_emails`
   - [ ] Check email received
   - [ ] Verify HTML renders correctly

### Unit Tests

Located in `Tests/Unit/`:

```bash
composer test-unit
```

### Integration Tests

Located in `Tests/Integration/`:

```bash
composer test-integration
```

## Debugging

### Enable WordPress Debug Mode

In `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

### Check Event Storage

```sql
SELECT * FROM wp_sybgo_events ORDER BY event_timestamp DESC LIMIT 10;
```

### View Event Data JSON

```php
$event = $event_repo->get_recent(1)[0];
$data = json_decode($event['event_data'], true);
print_r($data);
```

### Check Cron Schedules

```bash
wp cron event list
wp cron event run sybgo_freeze_weekly_report
```

### Clear Cache

```php
wp_cache_flush();
```

## Common Issues

**Issue**: Events not being tracked
- **Check**: Are hooks registered? (`Event_Tracker::init()` called?)
- **Check**: Is event type in allowed list? (Settings page)
- **Check**: Is throttling blocking it?

**Issue**: Email not sending
- **Check**: Recipients configured in Settings?
- **Check**: `wp_mail()` working? (Install WP Mail SMTP plugin to debug)
- **Check**: Email log table for error messages

**Issue**: Dashboard widget not showing
- **Check**: User has `manage_options` capability?
- **Check**: Widget registered in `wp_dashboard_setup` hook?

**Issue**: High database size
- **Check**: Cleanup cron running? (`sybgo_cleanup_old_events`)
- **Check**: Throttling working properly?
- **Reduce**: Edit magnitude threshold (increase from 5% to 10%)

## Performance Optimization

1. **Database Indexes**: Already optimized, but if slow:
   ```sql
   SHOW INDEX FROM wp_sybgo_events;
   ```

2. **Query Optimization**: Use `EXPLAIN` for slow queries

3. **Caching**: Increase cache duration for read-heavy operations

4. **Reduce Events**: Increase throttle period from 3600s to 7200s (2 hours)

5. **Archive Old Reports**: Export and delete reports older than 2 years

## Security Checklist

- [ ] All form submissions use nonces
- [ ] All output is escaped
- [ ] All input is sanitized
- [ ] All SQL uses prepared statements or BerlinDB ORM
- [ ] Capability checks on admin functions
- [ ] No sensitive data in event_data (passwords, tokens, etc.)
- [ ] Email recipients validated as email addresses
- [ ] AJAX endpoints check nonces and capabilities

## Deployment

1. Run code standards: `composer phpcs`
2. Run tests: `composer run-tests`
3. Update version in plugin header
4. Tag release in git
5. Build ZIP without dev dependencies:
   ```bash
   composer install --no-dev
   zip -r sybgo.zip sybgo -x "*/Tests/*" "*/.*"
   ```
6. Upload to WordPress.org or distribute

## Support

For issues or questions:
- GitHub Issues: <repo-url>/issues
- Documentation: /docs/
- Code standards: https://gopen.groupone.dev/technical_standards/php/wordpress/
