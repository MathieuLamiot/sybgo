import { Locator, Page, expect } from '@playwright/test';

/**
 * Page object for the "Site Activity Digest" dashboard widget.
 *
 * Source: wp-plugin/admin/class-dashboard-widget.php
 * Widget id: `sybgo_activity_widget`
 */
export class DashboardWidgetPage {
	readonly page: Page;
	readonly root: Locator;
	readonly title: Locator;
	readonly aiSummaryButton: Locator;
	readonly eventCounter: Locator;
	readonly filterTabs: Locator;
	readonly phpErrorsSection: Locator;

	constructor( page: Page ) {
		this.page = page;
		this.root = page.locator( '#sybgo_activity_widget' );
		this.title = this.root.getByRole( 'heading', { name: 'Site Activity Digest' } );
		this.aiSummaryButton = this.root.locator( '.sybgo-widget-ai-btn' );
		this.eventCounter = this.root.locator( '.sybgo-event-counter, [data-event-counter]' ).first();
		this.filterTabs = this.root.getByRole( 'tab' );
		this.phpErrorsSection = this.root.getByText( /PHP Errors/i );
	}

	async goto(): Promise<void> {
		await this.page.goto( '/wp-admin/' );
		await expect( this.root ).toBeVisible();
	}

	async getEventCount(): Promise<number> {
		const text = await this.root.locator( 'text=/^\\d+$/' ).first().innerText();
		return Number.parseInt( text, 10 );
	}

	async clickFilterTab( name: 'All' | 'Posts' | 'Users' | 'Updates' | 'Comments' ): Promise<void> {
		await this.root.getByRole( 'button', { name, exact: true } ).click();
	}
}
