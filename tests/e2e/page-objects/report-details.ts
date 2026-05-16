import { Locator, Page, expect } from '@playwright/test';

/**
 * Page object for the report detail view at
 * `/wp-admin/admin.php?page=sybgo-reports&view=details&report_id=N&_wpnonce=X`.
 *
 * IMPORTANT: the URL is protected by `check_admin_referer( 'sybgo_view_report' )`.
 * Navigate via {@link ReportsListPage.openDetailsForRow} rather than building
 * the URL by hand, so the nonce is present.
 *
 * Source: wp-plugin/admin/class-reports-page.php (render_details_view).
 */
export class ReportDetailsPage {
	readonly page: Page;
	readonly heading: Locator;
	readonly summarySection: Locator;
	readonly highlightsSection: Locator;
	readonly aiSummarySection: Locator;
	readonly aiSummaryButton: Locator;
	readonly allEventsTable: Locator;
	readonly phpErrorsTable: Locator;
	readonly backLink: Locator;

	constructor( page: Page ) {
		this.page = page;
		this.heading = page.getByRole( 'heading', { name: /^Report:/ } );
		this.summarySection = page.getByText( 'Summary', { exact: true } );
		this.highlightsSection = page.getByText( 'Highlights', { exact: true } );
		this.aiSummarySection = page.getByRole( 'heading', { name: 'AI Summary' } );
		this.aiSummaryButton = page.locator( '#sybgo-generate-ai-btn' );
		this.allEventsTable = page.locator( 'h3:has-text("All Events") + table, h2:has-text("All Events") + table' );
		this.phpErrorsTable = page.locator( 'h3:has-text("PHP Errors") + table, h2:has-text("PHP Errors") + table' );
		this.backLink = page.getByRole( 'link', { name: /Back to Reports/ } );
	}

	async expectLoaded(): Promise<void> {
		await expect( this.heading ).toBeVisible();
		await expect( this.summarySection ).toBeVisible();
	}

	async aiSummaryButtonIsDisabled(): Promise<boolean> {
		return ! ( await this.aiSummaryButton.isEnabled() );
	}

	async aiSummaryDisabledReason(): Promise<string | null> {
		return await this.aiSummaryButton.getAttribute( 'title' );
	}
}
