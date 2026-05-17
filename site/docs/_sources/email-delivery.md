# Email delivery

This page covers when emails are sent, who gets them, and what to do if they don't arrive.

## When is the digest sent?

<!-- source: lib/docs/report-lifecycle.md "Weekly Cycle Overview" and "Cron Schedule" -->

Every week, Sybgo runs two automatic steps:

1. **Sunday at 23:55** (your site's time zone): freeze the week's report. <!-- source: lib/docs/report-lifecycle.md cron table sybgo_freeze_weekly_report -->
2. **Monday at 00:05** (your site's time zone): send the digest email. <!-- source: lib/docs/report-lifecycle.md cron table sybgo_send_report_emails -->

So Monday morning you should have a fresh digest in your inbox.

The site's time zone comes from **Settings → General** in WordPress.

## Who receives the email?

By default, the digest is sent to the **administrator email** configured under **Settings → General → Administration Email Address**. <!-- source: wp-plugin/admin/class-settings-page.php render_email_recipients_field() — defaults to get_option('admin_email') -->

You can change this from **Settings → Sybgo → Email Configuration**:

- **Email Recipients** — one email address per line. Leave blank to use the admin email.
- **From Name** — the name shown in the "From" field of the email. Defaults to your site's title.
- **From Email** — the email address shown in the "From" field. Defaults to the admin email.

Invalid email addresses are silently ignored when you save. <!-- source: wp-plugin/admin/class-settings-page.php sanitize_settings() -->

## Changing recipients

1. Go to **Settings → Sybgo**.
2. Under **Email Configuration → Email Recipients**, type one email per line.
3. Click **Save Settings**.

Adding several people? Keep the list reasonable (under 20 addresses is a safe rule of thumb — for larger lists, use a real mailing service). <!-- source: lib/docs/report-lifecycle.md "Performance" -->

## Turning the email off

There is no "disable email" toggle, but you have two options:

- **Empty the recipients list.** With no valid email address, no emails will be sent. <!-- TODO verify: confirm behaviour when admin email is also blank — does Sybgo still attempt to send? -->
- **Deactivate the plugin** from the Plugins screen if you don't want any Sybgo activity at all.

## Empty-week emails

By default, if a week had no tracked activity, **no email is sent** to avoid inbox clutter. <!-- source: lib/docs/report-lifecycle.md "Empty Reports" -->

If you'd rather receive a quiet "All quiet this week" email as confirmation that monitoring is working:

1. Go to **Settings → Sybgo**.
2. Under **Report Settings**, check **"Send weekly digest even if no events occurred"**. <!-- source: wp-plugin/admin/class-settings-page.php render_send_empty_reports_field() -->
3. Save.

## Resending a past digest

If a digest didn't arrive or you want to forward it to a new team member:

1. Go to **Sybgo Reports**.
2. Find the report you want.
3. Click **"Resend Email"** in the Actions column. <!-- source: wp-plugin/admin/class-reports-page.php render_report_row() -->

The email is sent immediately to all addresses currently configured under Settings → Sybgo. <!-- source: wp-plugin/admin/class-reports-page.php handle_resend_email() -->

## Troubleshooting

### "My Monday digest didn't arrive."

**Check the Sybgo Reports page.** If the latest report is marked **SENT**, the email left your server — the problem is in transit (spam folder, mail server, or recipient inbox).

**Check the spam folder.** Default WordPress emails are often filtered as spam because they come from `wordpress@yourdomain.com`. Install a transactional email plugin like **WP Mail SMTP**, **Post SMTP**, or **FluentSMTP** and route mail through a real provider (Sendgrid, Mailgun, Postmark, etc.).

**Check WordPress cron.** Sybgo relies on WordPress's scheduled-tasks system. On very low-traffic sites, cron may not fire because nobody visits the site at the scheduled time. The fix is a real system cron: see WordPress's official guide on [hooking WP-Cron into the system task scheduler](https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/).

**Check that your admin email is valid.** Go to **Settings → General** and confirm that the administrator email is correct and verified.

### "I got an email, but the trends are missing."

Trends compare this week to last week. The first time you use Sybgo, there is no previous week to compare against — trends appear from week 2 onwards. <!-- source: lib/docs/report-lifecycle.md "Trends Not Showing" -->

### "I changed recipients but the next email still went to the old address."

The recipient list is read at send time, so a change saved before Monday morning will take effect on the next send. If the email was already sent, use **Resend Email** from the Sybgo Reports page.

## Next steps

- [See settings around email](./settings.md)
- [Understand cron and scheduling issues](./troubleshooting.md)
