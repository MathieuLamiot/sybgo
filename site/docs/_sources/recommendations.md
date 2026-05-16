# Recommendations

> **Coming in v1.** The Recommendation System is not yet shipped. This page describes the intended behaviour so you know what to expect. Nothing on this page is live in the plugin today. <!-- TODO verify: confirm with product whether any subset of the recommendation engine has shipped -->

Sybgo will look at the activity it captures and turn some of it into actionable suggestions: things you can do to improve your site, surfaced where you are already looking (the digest, the dashboard widget, the report detail page).

## How it will work

A small evaluator will run **once a day** via WordPress cron. It will look at recent events and check a handful of rules. When a rule matches, it produces a recommendation — a short title, a one-line reason, and a link to act on it.

## Examples of what it might recommend

- **"You captured 24 PHP warnings this week. Consider installing Sentry to get a full stack trace."** — triggered when the PHP error category has activity. <!-- TODO verify: actual integrations and triggers planned for v1 -->
- **"You uploaded 18 images this week. Imagify can compress them automatically."** — triggered when large media uploads are detected.
- **"You have 3 plugins with available updates."** — triggered from update events.

Recommendations are advisory. Sybgo does not install anything for you. Each one will include a clear "what this will do" description and a link to the relevant plugin's WordPress.org page.

## Dismissing or snoozing

You will be able to:

- **Dismiss** a recommendation permanently (it will not come back).
- **Snooze** it (it will return on the next evaluation if still relevant).

<!-- TODO verify: exact dismiss/snooze semantics and where the controls live in the UI -->

## Frequency

The recommendation evaluator will run once a day. It will not run on every page load and will not slow down your admin.

## Privacy

Recommendations are evaluated locally on your site. No data is sent to any external service to produce them.

## Next steps

- [See what events feed into recommendations](./what-sybgo-tracks.md)
- [Configure what gets tracked](./settings.md)
