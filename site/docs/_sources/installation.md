# Installation

There are three ways to install Sybgo. Pick the one that matches how you usually manage plugins.

## Requirements

<!-- source: wp-plugin/docs/development.md, lib/docs/ai-transport.md -->

- WordPress 5.0 or later
- PHP 7.4 or later
- MySQL 5.7+ or MariaDB 10.2+
- WordPress 7 or later (optional — only required for [AI summaries](./ai-summaries.md))

The plugin works with multisite. <!-- TODO verify: multisite behaviour — does each subsite get its own digest, or is there a network-level digest? -->

## Option 1: Install from WordPress.org

<!-- TODO verify: Sybgo is not yet on the WordPress.org plugin directory. Confirm slug and update once approved. -->

1. In your WordPress admin, go to **Plugins → Add New**.
2. Search for **Sybgo**.
3. Click **Install Now**, then **Activate**.

## Option 2: Upload a release zip from GitHub

1. Download the latest release zip from the [Sybgo releases page](https://github.com/MathieuLamiot/sybgo/releases). <!-- TODO verify: confirm public release URL once first release is cut -->
2. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**.
3. Choose the zip file and click **Install Now**.
4. Click **Activate Plugin**.

## Option 3: Manual install

If you manage your site over SFTP:

1. Unzip the Sybgo download on your computer.
2. Upload the `sybgo` folder into `wp-content/plugins/` on your server.
3. In your WordPress admin, go to **Plugins**, find **Sybgo**, and click **Activate**.

## Where to find Sybgo after activation

Once activated, Sybgo adds three things to your admin:

- **Site Activity Digest** widget on the main **Dashboard** screen <!-- source: wp-plugin/admin/class-dashboard-widget.php register_widget() -->
- **Sybgo Reports** top-level menu in the admin sidebar <!-- source: wp-plugin/admin/class-reports-page.php add_reports_page() -->
- **Settings → Sybgo** entry under the standard Settings menu <!-- source: wp-plugin/admin/class-settings-page.php add_settings_page() -->

Sybgo starts tracking activity immediately. Your first weekly digest will be sent on the following Monday morning.

## Next steps

- [Configure email recipients and tracking options](./settings.md)
- [Understand what arrives in your weekly digest](./your-weekly-digest.md)
