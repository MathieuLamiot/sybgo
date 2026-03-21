# Database Management

Sybgo provides visibility into its own database footprint and a configurable data retention policy, accessible from the plugin settings page.

## Settings Page: Database Management Section

The **Database Management** section appears at the bottom of Settings → Sybgo. It offers two things: a retention period setting (part of the `sybgo_settings` option) and a live database footprint panel rendered below the form.

### Data Retention Period

The `retention_days` field (option key inside `sybgo_settings`) controls how many days of event data are kept. The default is 90 days. Any positive integer is accepted; the value is clamped to a minimum of 1.

Both `wp_sybgo_events` (filtered by `event_timestamp`) and `wp_sybgo_aggregated_events` (filtered by `date`) are pruned using the same retention window. The deletion runs automatically via the `sybgo_cleanup_old_events` WP-Cron hook (daily at 03:00). The configured value is read at runtime by `Settings_Page::get_retention_days()`, which other components call to stay in sync.

### Database Footprint Panel

Below the settings form, a read-only panel labelled **Database Footprint** shows per-table row counts and estimated sizes for the four plugin tables. Size figures come from `information_schema.TABLES` and are displayed in MB. If the host restricts access to `information_schema` (common on managed WordPress hosting), the size column shows **N/A** for affected tables; row counts are always available.

The panel is rendered by `Settings_Page::render_database_stats_panel()`, which delegates data retrieval to `DB_Stats` (see below).

### Manual Cleanup

A **Run Cleanup Now** button inside the footprint panel submits a form to `admin-post.php` (action `sybgo_run_cleanup`). The handler (`Sybgo::handle_manual_cleanup()`) verifies the nonce (`sybgo_run_cleanup`) and the `manage_options` capability, runs `DatabaseManager::cleanup_old_events()` with the configured retention period, then redirects back to the settings page. The number of deleted rows is appended as a `cleanup-done` query parameter and displayed as an admin notice.

## DB_Stats Class

`DB_Stats` (`lib/database/class-db-stats.php`) encapsulates all read-only database footprint queries. It depends on `DatabaseManager` for table name resolution and is instantiated via `Factory::create_db_stats()` (singleton).

Key methods:

- `get_table_stats(): array` — returns an array keyed by table identifier (`events`, `reports`, `email_log`, `aggregated_events`), each entry containing `table_name`, `row_count` (int), and `size_mb` (float or null).
- `get_total_size_mb(): float` — sums `size_mb` across all tables, treating null as 0, rounded to 2 decimal places.

## DatabaseManager: Configurable Cleanup

`DatabaseManager::cleanup_old_events( int $days = 90 )` accepts a retention period in days. It builds two cutoff values — a datetime for `wp_sybgo_events` and a date for `wp_sybgo_aggregated_events` — and issues DELETE queries against both tables. It then clears the `sybgo_events` and `sybgo_aggregated_events` object-cache groups and returns the total number of rows deleted across both tables.

The `$days` parameter defaults to 90 so existing call sites that omit it continue to work.

## Admin-Post Handler

`Sybgo::handle_manual_cleanup()` is registered on `admin_post_sybgo_run_cleanup` inside `Sybgo::init_admin()`. It is only reachable by logged-in users with `manage_options`. After cleanup, it redirects to `options-general.php?page=sybgo-settings&cleanup-done=<count>`.
