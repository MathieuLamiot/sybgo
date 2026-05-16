# AI summaries

Sybgo can ask an AI provider to write a short prose summary of the week's activity ("This week, three new posts were published, traffic from registered users dipped slightly, and a plugin update was applied without incident"). This is an **opt-in** feature that requires WordPress 7 or later.

## What it is

The AI summary is a paragraph or two of human-readable text that complements the statistics cards and highlights. It appears:

- In the dashboard widget, after you click **"Get AI Summary"**. <!-- source: wp-plugin/admin/class-dashboard-widget.php ajax_widget_ai_summary() -->
- On the report detail page (Sybgo Reports → View Details), in the **AI Summary** section. <!-- source: wp-plugin/admin/class-reports-page.php render_report_details() -->

AI summaries are never generated automatically at report freeze time. You always click a button to produce one.

## Requirements

<!-- source: lib/docs/ai-transport.md "WordPress 7 Requirement" -->

- **WordPress 7 or later.** On earlier WordPress versions, the AI buttons are disabled with a tooltip explaining the requirement.
- **An AI connector configured under Settings → Connectors.** WordPress 7 introduced a native AI client (`wp_ai_client_prompt()`); Sybgo uses whatever provider you have configured there.

If WordPress 7 is not available, or no provider is configured, no AI calls are ever made and no data ever leaves your site for AI purposes.

## What data is sent, and to whom

When you click **"Get AI Summary"**:

1. Sybgo gathers the week's events (titles, types, counts, trends) from your local database.
2. It builds a prompt describing the activity.
3. It sends that prompt to **the AI provider you configured in WordPress 7's Connectors settings**. Sybgo does not pick the provider — you do.
4. The provider's response is shown in the admin and stored alongside the report so you don't have to regenerate it. <!-- source: lib/docs/report-lifecycle.md "AI Summary (On-Demand)" -->

What is sent depends on what events happened that week. It can include post titles, post URLs, user display names, and plugin/theme names. <!-- TODO verify: confirm exact fields included in the AI prompt by reading AI_Summarizer -->

If you have not configured an AI connector in WordPress 7, no AI calls are made. The button will return an error message asking you to check your connector configuration. <!-- source: wp-plugin/admin/class-dashboard-widget.php ajax_widget_ai_summary() -->

## How to enable

1. Make sure you are running **WordPress 7 or later**.
2. Configure an AI provider under **Settings → Connectors** in your WordPress admin. <!-- source: wp-plugin/admin/class-settings-page.php render_ai_section_description() -->
3. Open **Settings → Sybgo**. The **AI Summary Settings** section will show that the AI provider is detected.
4. To generate a summary on demand:
   - From the dashboard widget: click **"Get AI Summary"**.
   - From a report: open **Sybgo Reports → View Details** on the report you want, then click **"Generate AI Summary"**. Once a summary exists, the button label changes to **"Regenerate AI Summary"**.

## Costs

Sybgo does not charge for AI summaries — but the AI provider you connect probably does. Each generation is one prompt to your configured provider. The size of the prompt scales with how active your site was that week.

<!-- TODO verify: typical token count per weekly summary -->

To keep costs predictable, generate summaries on demand rather than at every page load. Once generated for a report, the result is cached on that report.

## Privacy

- AI calls are only made when you click an "AI Summary" button.
- The data sent goes only to the provider configured in WordPress 7's Connectors panel.
- Sybgo does not call any AI service itself, and does not send data to Anthropic, OpenAI, or any other vendor unless you have explicitly wired one up via WordPress 7.
- See [Privacy and data](./privacy-and-data.md) for the full picture.

## Next steps

- [Read what else Sybgo stores and where](./privacy-and-data.md)
- [See settings related to AI](./settings.md)
