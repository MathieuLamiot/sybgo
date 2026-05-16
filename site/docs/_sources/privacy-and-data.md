# Privacy and data

This page explains exactly what Sybgo stores, where, for how long, and what (if anything) ever leaves your site.

## What is stored on your site

<!-- source: wp-plugin/docs/database-management.md and lib/docs/event-tracking.md, report-lifecycle.md -->

Sybgo creates a small number of custom tables in your WordPress database, prefixed with `wp_sybgo_` (or whatever your site's database prefix is). These tables hold:

- **`wp_sybgo_events`** — one row per individual event (post published, comment posted, etc.). Each row stores a JSON snapshot with the action, the affected object (its ID, title, URL), who performed it, and any relevant metadata. <!-- source: lib/docs/event-tracking.md "Event Data Structure" -->
- **`wp_sybgo_aggregated_events`** — one row per "bucket" of repeated events (notably PHP errors), with a running counter rather than one row per occurrence. <!-- source: lib/docs/event-tracking.md "wp_sybgo_aggregated_events schema" -->
- **`wp_sybgo_reports`** — one row per weekly report, including its statistics, trends, highlights, and the optional AI summary text. <!-- source: lib/docs/report-lifecycle.md "Reports Table" -->
- **`wp_sybgo_email_log`** — one row per email send attempt, with the recipient, the status (sent/failed/pending), and any error message. <!-- source: lib/docs/report-lifecycle.md "Email Log Table" -->

A small number of WordPress options are also stored (`sybgo_settings`, and a legacy `sybgo_email_recipients` kept for backwards compatibility). <!-- source: wp-plugin/admin/class-settings-page.php get_option_names() -->

## What information ends up in events

For each event, Sybgo captures only what's needed to describe what happened. For example:

- **Post events** — post ID, title, URL, author ID, author display name, categories, tags, word count, and (for edits) a percentage of how much changed. <!-- source: lib/docs/event-tracking.md "Event Examples" -->
- **User events** — user ID, username, email, role.
- **Update events** — old and new version numbers.
- **PHP errors** — the error message (first 100 characters), the file, the line number, and the error level.

Sybgo does not capture IP addresses, browser user agents, or visitor sessions.

## Retention

By default, event rows older than **90 days** are deleted automatically every day at 03:00 (your site's time zone). <!-- source: wp-plugin/admin/class-settings-page.php DEFAULT_RETENTION_DAYS, lib/docs/report-lifecycle.md cron table -->

You can change the retention period from **Settings → Sybgo → Database Management → Data Retention Period** (minimum 1 day). <!-- source: wp-plugin/admin/class-settings-page.php render_retention_days_field() -->

Reports themselves are typically kept for a year for trend comparisons. <!-- TODO verify: actual report retention policy — code default versus configurable -->

## What leaves your site

In its default configuration, **nothing about your site's activity is sent to any external service**.

The two exceptions are:

1. **The weekly digest email** — sent through standard WordPress `wp_mail()`. The email goes to whoever you configured under **Settings → Sybgo → Email Recipients**. Where it travels after that depends on how your site sends mail (your hosting provider's mail server, or an SMTP plugin you have installed). <!-- source: lib/docs/report-lifecycle.md "Email Delivery" -->
2. **AI summaries (opt-in)** — if you enable AI summaries on WordPress 7, a prompt describing the week's events is sent to the AI provider you have configured under **Settings → Connectors**. AI calls are only made when you click an "AI Summary" button. See [AI summaries](./ai-summaries.md). <!-- source: wp-plugin/admin/class-dashboard-widget.php ajax_widget_ai_summary() -->

Sybgo does not contact Anthropic, OpenAI, or any other vendor unless you have explicitly wired one up via WordPress 7's connector system.

## Uninstall behaviour

<!-- source: wp-plugin/docs/development.md "Plugin Uninstall" -->

When you **deactivate** Sybgo from the Plugins screen, scheduled tasks are cancelled but the database tables and your settings are kept. Reactivating picks up exactly where you left off.

When you **delete** Sybgo (Plugins → Delete), Sybgo runs a full cleanup:

1. **Drops every Sybgo database table** (events, aggregated events, reports, email log).
2. **Clears every scheduled cron event** Sybgo had registered.
3. **Deletes every Sybgo option** from the WordPress options table.

After deletion, no Sybgo data remains on your site. There is no separate "reset" or "factory defaults" button — delete and re-install if you want a clean slate. <!-- source: wp-plugin/admin/class-uninstaller.php — verified via wp-plugin/docs/development.md "Plugin Uninstall" section -->

## GDPR posture

Sybgo records who performed actions on your site (typically administrators and authors, not anonymous visitors). The information stored is the same information already present in the WordPress posts, users, and comments tables — Sybgo aggregates and copies references; it does not enrich them.

If you receive a data-subject access request:

- WordPress's built-in **Tools → Export Personal Data** tool covers core WordPress data. Sybgo does not currently register a custom exporter or eraser. <!-- TODO verify: confirm whether a personal-data exporter/eraser is implemented -->
- You can manually inspect the `wp_sybgo_events` table for rows referencing the user.

For your privacy policy, you may want to disclose:

- That a plugin records site-administration events for an internal weekly digest.
- That the digest is delivered by email.
- That AI summaries (if enabled) send activity data to a third-party AI provider configured by you.

## Next steps

- [See exactly which events are captured](./what-sybgo-tracks.md)
- [Adjust retention and run a manual cleanup](./settings.md)
