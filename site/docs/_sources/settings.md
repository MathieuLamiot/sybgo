# Settings

All Sybgo options live on a single page: **Settings → Sybgo** in your WordPress admin. <!-- source: wp-plugin/admin/class-settings-page.php add_settings_page() -->

The page is organised into five sections, each described below.

## Email Configuration

<!-- source: wp-plugin/admin/class-settings-page.php "sybgo_email_section" -->

> Configure who receives the weekly activity digest emails.

- **Email Recipients** — one email address per line. Invalid addresses are skipped on save. Leave blank to use the WordPress admin email.
- **From Name** — the name shown in the "From" field of the digest email. Defaults to your site's title.
- **From Email** — the address shown in the "From" field. Defaults to the WordPress admin email.

See [Email delivery](./email-delivery.md) for details on when emails are sent.

## Event Tracking

<!-- source: wp-plugin/admin/class-settings-page.php "sybgo_tracking_section" -->

> Choose which events to track and how sensitive tracking should be.

- **Enabled Event Types** — a list of checkboxes, one per event type registered on your site. Uncheck any you don't want to track. The default selection enables: posts published/edited/deleted, user registered, user role changed, core updated, plugin updated, theme updated, comment posted, comment approved. <!-- source: wp-plugin/admin/class-settings-page.php get_default_event_types() -->
- **Edit Magnitude Threshold** — a number between 0 and 100 (default **5**). An edit must change at least this percentage of a post's content to be tracked. Set to 0 to track every keystroke; set to 25 to only track substantial rewrites.

See [What Sybgo tracks](./what-sybgo-tracks.md) for the full list of events.

## Report Settings

<!-- source: wp-plugin/admin/class-settings-page.php "sybgo_report_section" -->

> Configure report generation and delivery behavior.

- **Send Empty Reports** — a checkbox labelled **"Send weekly digest even if no events occurred"**. Off by default. Turn on if you'd rather receive an "All quiet this week" email as proof of life. <!-- source: wp-plugin/admin/class-settings-page.php render_send_empty_reports_field() -->

## AI Summary Settings

<!-- source: wp-plugin/admin/class-settings-page.php "sybgo_ai_section" -->

The behaviour of this section depends on your WordPress version:

- **WordPress 7 or later, with AI configured**: the description says *"AI-powered summaries use the WordPress 7 native AI provider. Configure your AI connector in Settings → Connectors."*
- **WordPress earlier than 7**: a warning notice replaces the section, asking you to upgrade to enable AI features.

There is no Sybgo-specific configuration here — the connector lives under **Settings → Connectors** in WordPress 7. See [AI summaries](./ai-summaries.md).

## Database Management

<!-- source: wp-plugin/admin/class-settings-page.php "sybgo_database_section" -->

> Control how long event data is retained in the database and monitor storage usage.

- **Data Retention Period** — number of days to keep events before automatic cleanup. Default **90** days. <!-- source: wp-plugin/admin/class-settings-page.php DEFAULT_RETENTION_DAYS -->
- **Database Footprint** panel — a table showing each Sybgo database table, its current row count, and its estimated size in MB. A grand total is shown at the bottom. <!-- source: wp-plugin/admin/class-settings-page.php render_database_stats_panel() -->
- **Run Cleanup Now** button — immediately deletes events and aggregated data older than the configured retention period, without waiting for the daily cleanup cron.

Cleanup also runs automatically every day at 03:00 (site time zone). <!-- source: lib/docs/report-lifecycle.md cron table sybgo_cleanup_old_events -->

## Quick Help panel

<!-- source: wp-plugin/admin/class-settings-page.php render_settings_page() "Quick Help" -->

At the bottom of the page, a **Quick Help** panel summarises the most-used options. It's the same information as above, condensed for at-a-glance reference.

## Next steps

- [Verify your install with the dashboard widget](./your-weekly-digest.md)
- [Read about data storage and privacy](./privacy-and-data.md)
