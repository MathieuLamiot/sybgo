# AJAX Actions

Sybgo registers the following `wp_ajax_*` actions for authenticated admin users.

## sybgo_generate_ai_summary

Generates an AI summary for a specific frozen report and persists it to the database.

Registered in `Reports_Page::init()` and handled by `Reports_Page::ajax_generate_ai_summary()`.

| Parameter | Source | Description |
|-----------|--------|-------------|
| `nonce` | `$_POST` | Nonce verified against `sybgo_admin_nonce` via `check_ajax_referer` |
| `report_id` | `$_POST` | ID of the frozen report to summarize |

**Capability required:** `manage_options`

**Success response:**
```json
{ "success": true, "data": { "summary": "A busy week with 12 new posts..." } }
```

**Error responses** (standard WP JSON error envelope):
- Missing/invalid nonce → HTTP 403 via `check_ajax_referer`
- No `manage_options` capability → `Unauthorized`
- AI summarizer unavailable (WP < 7) → `AI summaries require WordPress 7 or later.`
- Invalid or zero `report_id` → `Invalid report ID.`
- Report not found → `Report not found.`
- AI generation returned null → `The AI summary could not be generated...`

The summary is persisted via `Report_Repository::set_ai_summary()`, which merges the AI text into the report's `summary_data` JSON without overwriting other fields (totals, trends, highlights). The nonce `sybgo_admin_nonce` is localised to the admin JS object `sybgoAdmin.nonce` via `wp_localize_script`.

## sybgo_widget_ai_summary

Generates an ephemeral AI summary of the current week's events for the dashboard widget. The summary is **not persisted**.

Registered in `Dashboard_Widget::init()` and handled by `Dashboard_Widget::ajax_widget_ai_summary()`.

| Parameter | Source | Description |
|-----------|--------|-------------|
| `nonce` | `$_POST` | Nonce verified against `sybgo_widget_nonce` via `check_ajax_referer` |

**Capability required:** `manage_options`

**Success response:**
```json
{ "success": true, "data": { "summary": "A busy week with 12 new posts..." } }
```

**Error responses** follow the same pattern as `sybgo_generate_ai_summary`.

The handler fetches unassigned events (`report_id IS NULL`), calls `Report_Generator::generate_live_summary()` to compute totals and trends for the current week, then passes those to `AI_Summarizer::generate_summary()`.

## sybgo_preview_digest

Returns the rendered HTML for the dashboard widget's digest preview modal.

Registered in `Dashboard_Widget::init()` and handled by `Dashboard_Widget::ajax_preview_digest()`.

| Parameter | Source | Description |
|-----------|--------|-------------|
| `nonce` | `$_POST` | Nonce verified against `sybgo_widget_nonce` |

**Capability required:** `manage_options`

Returns an HTML fragment rendered by `Dashboard_Widget::render_preview_content()`. No AI summary is included in the preview.

## sybgo_filter_events

Returns filtered event rows for the dashboard widget's event list.

Registered in `Dashboard_Widget::init()` and handled by `Dashboard_Widget::ajax_filter_events()`.

| Parameter | Source | Description |
|-----------|--------|-------------|
| `nonce` | `$_POST` | Nonce verified against `sybgo_widget_nonce` |
| `filter` | `$_POST` | Event type to filter (`all`, `post`, `user`, `update`, `comment`) |
