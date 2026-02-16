# Report Lifecycle

This guide explains how Sybgo generates weekly reports, calculates trends, and delivers email digests.

## Weekly Cycle Overview

```
Monday-Sunday: Collect Events
       ↓
Sunday 23:55: Freeze Report
       ↓
Calculate Trends & Summary
       ↓
Monday 00:05: Send Email
       ↓
Monday 00:06: New Week Starts
```

## Report States

Every report progresses through three states:

### 1. Active Report
- **When:** Monday 00:06 - Sunday 23:55
- **Status:** `active`
- **Events:** New events have `report_id = NULL` (unassigned)
- **Behavior:** Collecting events throughout the week

### 2. Frozen Report
- **When:** Sunday 23:55
- **Status:** `frozen`
- **Events:** All unassigned events get assigned to this report
- **Summary:** Trends and statistics calculated
- **Next:** Ready for email delivery

### 3. Emailed Report
- **When:** Monday 00:05
- **Status:** `emailed`
- **Delivery:** Email sent to all configured recipients
- **Archive:** Stored for 1 year for reference

## How Report Freezing Works

Every Sunday at 23:55, the freeze process runs automatically:

### Step 1: Query Unassigned Events
```sql
SELECT * FROM wp_sybgo_events
WHERE report_id IS NULL
ORDER BY created_at ASC;
```

### Step 2: Calculate Statistics
```php
$totals = [
    'posts_published' => 12,
    'posts_edited' => 45,
    'users_registered' => 3,
    'comments_posted' => 28,
    // ... other event types
];
```

### Step 3: Calculate Trends
Compare current week to previous week:

```php
$trends = [
    'posts_published' => [
        'current' => 12,
        'previous' => 10,
        'change_percent' => 20,
        'direction' => 'up'
    ],
    'users_registered' => [
        'current' => 3,
        'previous' => 5,
        'change_percent' => -40,
        'direction' => 'down'
    ]
];
```

### Step 4: Generate Highlights
Automatically create human-readable highlights:
- "12 new posts published ↑ 20%"
- "WordPress updated to 6.5"
- "3 new users registered ↓ 40%"

### Step 5: Assign Events to Report
```sql
UPDATE wp_sybgo_events
SET report_id = 123
WHERE report_id IS NULL;
```

### Step 6: Save Report
```json
{
    "id": 123,
    "status": "frozen",
    "period_start": "2026-02-10 00:00:00",
    "period_end": "2026-02-16 23:59:59",
    "summary_data": {
        "total_events": 88,
        "totals": { ... },
        "trends": { ... },
        "highlights": [ ... ],
        "top_authors": [ ... ]
    }
}
```

## Email Delivery

Monday at 00:05, the email delivery process runs:

### Step 1: Get Last Frozen Report
```sql
SELECT * FROM wp_sybgo_reports
WHERE status = 'frozen'
ORDER BY period_end DESC
LIMIT 1;
```

### Step 2: Get Recipients
From Settings → Sybgo → Email Recipients:
```
admin@example.com
editor@example.com
```

### Step 3: Generate HTML Email
Using the email template:
- Header with date range
- Highlights section (bulleted list)
- Statistics cards (total posts, users, comments)
- Link to view full report in admin

### Step 4: Send via wp_mail()
```php
wp_mail(
    'admin@example.com',
    'Your Weekly Digest - Feb 10-16',
    $html_content,
    ['Content-Type: text/html; charset=UTF-8']
);
```

### Step 5: Log Delivery
```sql
INSERT INTO wp_sybgo_email_log (
    report_id,
    recipient,
    status,
    sent_at
) VALUES (123, 'admin@example.com', 'sent', NOW());
```

### Step 6: Update Report Status
```sql
UPDATE wp_sybgo_reports
SET status = 'emailed', emailed_at = NOW()
WHERE id = 123;
```

## Trend Calculation Details

Trends show week-over-week changes with percentage and direction.

### Formula
```
change_percent = ((current - previous) / previous) * 100
```

### Direction Indicators
- **↑ up**: Current week > previous week
- **↓ down**: Current week < previous week
- **→ same**: Current week = previous week

### Examples

**Scenario 1: Growth**
- Previous week: 10 posts
- Current week: 12 posts
- Change: +20%
- Display: "12 new posts published ↑ 20%"

**Scenario 2: Decline**
- Previous week: 5 users
- Current week: 3 users
- Change: -40%
- Display: "3 new users registered ↓ 40%"

**Scenario 3: No Previous Data**
- Previous week: No report exists
- Current week: 15 comments
- Change: N/A
- Display: "15 new comments"

## Manual Operations

### Manual Freeze & Send
You can manually trigger a freeze at any time:

**Admin UI:** Tools → Reports → "Freeze & Send Now" button

This will:
1. End the current week early
2. Freeze report with current events
3. Send email immediately
4. Create new active report

**Use cases:**
- Testing email template
- Sending mid-week updates
- Demonstrating to stakeholders

### Resend Email
If email delivery failed or you need to send to additional recipients:

**Admin UI:** Tools → Reports → [View Report] → "Resend Email" button

### View Past Reports
**Admin UI:** Tools → Reports

Table shows all frozen/emailed reports:
- Date range
- Total events
- Status (frozen, emailed)
- Actions (View, Resend)

## Empty Reports

If no events occurred during the week, Sybgo handles it gracefully:

### Behavior Based on Settings

**Setting Disabled** (default):
- Report freezes as normal
- Email is NOT sent
- Report status → `emailed` (even though not actually sent)
- Prevents inbox clutter

**Setting Enabled**:
- Report freezes as normal
- Email IS sent with "All quiet" message
- Useful for confirming monitoring is working

**Configure:** Settings → Sybgo → "Send email even if no events"

### Empty Report Email Content
```
Subject: Your Weekly Digest - Feb 10-16

All Quiet This Week

No significant activity occurred on your site this week.
This is normal and not a cause for concern.
```

## Troubleshooting

### Reports Not Freezing Automatically

**Check cron schedule:**
```bash
wp cron event list | grep sybgo_freeze
```

Expected output:
```
sybgo_freeze_weekly_report    2026-02-16 23:55:00
```

**Manual trigger:**
```bash
wp cron event run sybgo_freeze_weekly_report
```

**Common causes:**
- WordPress cron disabled (`DISABLE_WP_CRON = true`)
- Low-traffic site (no page loads to trigger cron)
- PHP errors in freeze callback

**Solution:** Set up system cron:
```bash
# In crontab -e
55 23 * * 0 wget -q -O - https://yoursite.com/wp-cron.php?doing_wp_cron
```

### Emails Not Sending

**Check email log:**
```sql
SELECT * FROM wp_sybgo_email_log
WHERE status = 'failed'
ORDER BY created_at DESC;
```

**Check recipients configured:**
```sql
SELECT option_value FROM wp_options
WHERE option_name = 'sybgo_settings';
```

**Test wp_mail():**
```php
wp_mail('test@example.com', 'Test', 'Test message');
```

**Common causes:**
- SMTP not configured (install WP Mail SMTP plugin)
- Invalid email addresses
- Server email limits exceeded
- HTML emails blocked by recipient

### Trends Not Showing

**Cause:** First week has no previous report to compare

**Solution:** Wait until week 2 for trends to appear

**Verify:**
```sql
SELECT COUNT(*) FROM wp_sybgo_reports WHERE status = 'emailed';
```

Must be >= 2 reports for trends to work.

## Database Schema

### Reports Table
```sql
CREATE TABLE wp_sybgo_reports (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    status varchar(20) NOT NULL,           -- 'active', 'frozen', 'emailed'
    period_start datetime NOT NULL,
    period_end datetime NOT NULL,
    summary_data LONGTEXT NOT NULL,        -- JSON with totals, trends, highlights
    created_at datetime NOT NULL,
    emailed_at datetime DEFAULT NULL,
    PRIMARY KEY (id),
    KEY status (status),
    KEY period_end (period_end)
);
```

### Email Log Table
```sql
CREATE TABLE wp_sybgo_email_log (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    report_id bigint(20) NOT NULL,
    recipient varchar(255) NOT NULL,
    status varchar(20) NOT NULL,           -- 'sent', 'failed', 'pending'
    error_message TEXT DEFAULT NULL,
    created_at datetime NOT NULL,
    sent_at datetime DEFAULT NULL,
    PRIMARY KEY (id),
    KEY report_id (report_id),
    KEY status (status)
);
```

## Cron Schedule

All times are in WordPress timezone (Settings → General):

| Cron Hook | Schedule | Purpose |
|-----------|----------|---------|
| `sybgo_freeze_weekly_report` | Sunday 23:55 | Freeze current week's report |
| `sybgo_send_report_emails` | Monday 00:05 | Send digest emails |
| `sybgo_cleanup_old_events` | Daily 03:00 | Delete events >1 year old |
| `sybgo_retry_failed_emails` | Daily 09:00 | Retry failed email deliveries |

## Performance

**Freeze Duration:**
- 100 events: ~1 second
- 1,000 events: ~3 seconds
- 10,000 events: ~15 seconds

**Email Generation:**
- HTML rendering: ~200ms
- wp_mail() call: ~500ms per recipient

**Recommendations:**
- Keep recipient list under 20 people
- For larger lists, use email service (Mailchimp, SendGrid)

## Related Documentation

- [Event Tracking](event-tracking.md) - What events feed into reports
- [Extension API](extension-api.md) - Customize report generation
- [Development Guide](development.md) - Testing report freezing
