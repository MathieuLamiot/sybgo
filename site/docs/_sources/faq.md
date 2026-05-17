# FAQ

## 1. Will Sybgo slow down my site?

It shouldn't. Sybgo only writes a small row to its own database table when a tracked event happens (a post is published, a plugin is updated). It does not run on every page view. Edit events are throttled to one per post per hour, and PHP error logging is capped at five distinct errors per week to avoid loops. <!-- source: lib/docs/event-tracking.md "Smart Throttling" and "Per-period cap" -->

## 2. Does Sybgo send my data to your server?

No. All event data stays in your WordPress database. The plugin itself does not phone home. The only outbound traffic is:

- The weekly digest email, sent through your normal WordPress mail setup.
- AI summary requests, **only if you have explicitly enabled them** on WordPress 7 with a configured AI connector. See [AI summaries](./ai-summaries.md).

## 3. How much disk space does Sybgo use?

It depends on how active your site is. A typical active site stores roughly 100 to 500 events per week, at about 1 KB per event. With the default 90-day retention, that's around 5 to 25 MB total. <!-- source: lib/docs/event-tracking.md "Performance Considerations" -->

You can check the exact footprint at any time under **Settings → Sybgo → Database Footprint**. <!-- source: wp-plugin/admin/class-settings-page.php render_database_stats_panel() -->

## 4. What happens to my data if I uninstall the plugin?

Deleting Sybgo from the Plugins screen wipes **everything**: all custom tables are dropped, all scheduled tasks are cancelled, and all Sybgo options are deleted. <!-- source: wp-plugin/docs/development.md "Plugin Uninstall" -->

Just **deactivating** (without deleting) keeps the data in place so you can re-activate later without losing anything. <!-- source: wp-plugin/docs/development.md "Plugin Uninstall" — "deactivation hook only clears cron events" -->

## 5. Can I export my data?

There is no built-in export button. You can query the `wp_sybgo_events` and `wp_sybgo_reports` tables directly with a tool like phpMyAdmin or WP-CLI to extract data. <!-- TODO verify: confirm no UI export exists and whether one is planned -->

## 6. Does Sybgo work on multisite?

Sybgo activates per site. <!-- TODO verify: confirm multisite behaviour — network activation, separate digests per subsite, or aggregation -->

## 7. Can I track events from my own plugin?

Yes. Sybgo exposes a small extension API (`sybgo_track_event()`) that any plugin can call to record custom events. WooCommerce orders, Contact Form 7 submissions, custom bookings — any plugin can plug in and have its events appear alongside the built-in ones. See the technical [Extension API guide](https://github.com/MathieuLamiot/sybgo/blob/main/lib/docs/extension-api.md) for details.

## 8. When are the emails sent?

Every Monday at 00:05 in your site's time zone, for the week that just ended (Monday through Sunday). The report is frozen on Sunday at 23:55. You can also trigger an immediate send any time from **Sybgo Reports → Freeze & Send Now**. <!-- source: lib/docs/report-lifecycle.md "Cron Schedule" -->

## 9. I have a low-traffic site and the emails don't arrive on time. Why?

WordPress's scheduling system depends on someone visiting your site. On very quiet sites, nobody triggers cron at the scheduled minute. The fix is to set up a real system cron job — see [Troubleshooting](./troubleshooting.md).

## 10. Is the AI summary on by default?

No. The AI summary is opt-in. Even on WordPress 7, no AI calls are made until you click **"Get AI Summary"** or **"Generate AI Summary"**. On WordPress versions earlier than 7, the AI feature is disabled entirely. See [AI summaries](./ai-summaries.md).

## Next steps

- [Open the troubleshooting guide](./troubleshooting.md)
- [Read about privacy and data](./privacy-and-data.md)
