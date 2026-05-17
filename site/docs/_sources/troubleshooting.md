# Troubleshooting

This page covers the most common problems and the concrete steps to fix them.

## The weekly email did not arrive

**Step 1: Check the Sybgo Reports page.**

Go to **Sybgo Reports** in your admin sidebar. Look at the most recent report's status badge:

- **SENT (green)** — Sybgo successfully handed the email to your server. The problem is downstream: spam filter, recipient inbox, or mail server.
- **FROZEN (yellow)** — the report was created but the email step did not complete. Click **Resend Email**.
- **ACTIVE (blue)** — the week's report has not been frozen yet. Either it's still mid-week, or Sunday's freeze cron did not run.

**Step 2: Check your spam folder.**

Default WordPress emails are sometimes filtered as spam. Install a transactional email plugin like **WP Mail SMTP**, **Post SMTP**, or **FluentSMTP** and route mail through a real provider.

**Step 3: Confirm your recipients are correct.**

Go to **Settings → Sybgo → Email Recipients**. One email per line. Invalid addresses are silently skipped. <!-- source: wp-plugin/admin/class-settings-page.php sanitize_settings() -->

**Step 4: Check that WordPress cron is running.**

On low-traffic sites, the scheduler may not fire because nobody visits the site at 00:05 on Monday morning. Set up a system cron — see WordPress's [official guide](https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/).

If you have WP-CLI, you can trigger the send manually:

```
wp cron event run sybgo_send_report_emails
```

<!-- source: lib/docs/report-lifecycle.md "Reports Not Freezing Automatically" -->

## Reports are not freezing on Sunday night

Same root cause as missing emails: WordPress cron requires traffic. Set up a system cron, or use the **Freeze & Send Now** button on the Sybgo Reports page to freeze manually.

WP-CLI manual trigger:

```
wp cron event run sybgo_freeze_weekly_report
```

## Trends are missing from my digest

Trend arrows compare this week to last week. If you only have one frozen report so far, there is no previous week to compare to and trends will not show. They will appear from week 2 onwards. <!-- source: lib/docs/report-lifecycle.md "Trends Not Showing" -->

## An edit I made did not show up

Two things filter out edits:

- **The hour-per-post throttle.** If you already saved the same post within the last hour, the new save will be skipped. <!-- source: lib/docs/event-tracking.md "Smart Throttling" -->
- **The edit magnitude threshold.** Edits that change less than 5% of the content (by default) are skipped. Lower the threshold under **Settings → Sybgo → Edit Magnitude Threshold** if you want smaller changes to be captured.

## The "Get AI Summary" button is disabled

This happens when AI summaries are not available on your site. The most common reasons:

- You are on WordPress earlier than 7. AI summaries require WordPress 7 or later. <!-- source: lib/docs/ai-transport.md "WordPress 7 Requirement" -->
- You are on WordPress 7+ but no AI provider is configured. Go to **Settings → Connectors** in your admin to set one up.

If you click the button and get an error like *"The AI summary could not be generated. Please check your WordPress AI connector configuration"*, the connector is configured but failed — verify the API key and quota with your provider. <!-- source: wp-plugin/admin/class-dashboard-widget.php ajax_widget_ai_summary() -->

## The dashboard widget is empty

If the widget shows "No events tracked yet this week", Sybgo is working but the week has been quiet. Try publishing a post, updating a plugin, or waiting for normal site activity. <!-- source: wp-plugin/admin/class-dashboard-widget.php render_events_list() -->

If the widget itself is missing from the dashboard, check the **Screen Options** dropdown at the top right of the Dashboard screen — it may have been hidden.

## The database is using more space than I expect

Open **Settings → Sybgo → Database Footprint** for a per-table breakdown. <!-- source: wp-plugin/admin/class-settings-page.php render_database_stats_panel() -->

If the **`wp_sybgo_aggregated_events`** table is unusually large, your site is generating a lot of PHP warnings. Fix the underlying PHP errors (or temporarily disable error tracking by unchecking it under **Enabled Event Types**).

To free space immediately, click **Run Cleanup Now** on the Settings page. To reduce retention long-term, lower the **Data Retention Period** value.

## Resending an email did nothing

The **Resend Email** button on the Sybgo Reports page sends the digest to whichever addresses are configured **at the time you click** (not at the time the report was originally generated). Check **Settings → Sybgo → Email Recipients** is correct, then try again. <!-- source: wp-plugin/admin/class-reports-page.php handle_resend_email() -->

## Still stuck?

If none of the above resolves your issue, please open a ticket on the [Sybgo issue tracker](https://github.com/MathieuLamiot/sybgo/issues) with:

- Your WordPress version, PHP version, and Sybgo version.
- The status badge of the most recent report.
- Any error message shown in the admin or in your `wp-content/debug.log`.
