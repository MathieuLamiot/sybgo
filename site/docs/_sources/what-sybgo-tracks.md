# What Sybgo tracks

Sybgo watches your site for sixteen different kinds of events, grouped into five categories. Every event is recorded with what happened, who did it, and when. You can enable or disable individual categories under [Settings](./settings.md).

<!-- source: lib/docs/event-tracking.md "What Events Are Tracked" -->

## Posts and pages

These cover anything published or edited in your site's content.

- **Post published** — a new post or page goes live.
- **Post edited** — an existing post or page is updated. Sybgo measures roughly how much of the content changed; tiny typo fixes can be filtered out (see [Settings](./settings.md)).
- **Post deleted** — a post or page is moved to the trash.

## Users

- **User registered** — a new account is created on your site.
- **User role changed** — someone's role is changed (for example, Subscriber promoted to Editor).
- **User deleted** — an account is removed.

## WordPress updates

These help you keep a record of every change to your site's software.

- **Core updated** — WordPress itself is updated to a new version.
- **Plugin installed** — a new plugin is added.
- **Plugin activated** — a plugin is turned on.
- **Plugin deactivated** — a plugin is turned off.
- **Plugin updated** — a plugin is upgraded to a new version.
- **Theme installed** — a new theme is added.
- **Theme updated** — a theme is upgraded.
- **Theme switched** — the active theme changes.

## Comments

- **Comment posted** — a new comment is submitted.
- **Comment approved** — a comment is approved, unapproved, or marked as spam.

## PHP errors

<!-- source: lib/docs/event-tracking.md "PHP Error Tracking" -->

Sybgo also quietly listens for PHP warnings, notices, and deprecation messages from your site (the kind of thing that usually only shows up in your debug log). It groups identical errors so the same warning firing 10,000 times still only takes one row of space, and it caps the list at the five most distinct errors per week to keep your database tidy.

- **PHP error** — a non-fatal PHP warning, notice, deprecation, or user error was captured.

Fatal errors are also captured when they happen at the end of a request. <!-- source: lib/docs/event-tracking.md "What is captured" -->

## Smart filtering for edits

When you edit a post, Sybgo compares the old and new content to estimate how much actually changed. Two filters keep the log meaningful:

- **At most one edit event per post per hour.** Auto-saves and quick fixes don't flood the digest. <!-- source: lib/docs/event-tracking.md "Smart Throttling" -->
- **Edits below a minimum content change are skipped.** The default threshold is 5%, configurable from 0 to 100% on the Settings page.

So a 10-second typo fix won't appear in your digest, but a real rewrite will.

## Custom event types from other plugins

Other plugins can add their own event types to Sybgo (for example, WooCommerce orders or Contact Form 7 submissions). When they do, those events appear in your digest alongside the built-in ones, with their own icon and label. <!-- source: lib/docs/extension-api.md -->

## Next steps

- [Turn individual event types on or off](./settings.md)
- [See where these events appear](./your-weekly-digest.md)
