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
	readonly filterTabs: Locator;
	readonly phpErrorsSection: Locator;
	readonly viewThisWeekLink: Locator;
	readonly viewLastWeekLink: Locator;
	readonly eventsList: Locator;
	readonly widgetActions: Locator;

	constructor( page: Page ) {
		this.page = page;
		this.root = page.locator( '#sybgo_activity_widget' );
		this.title = this.root.getByRole( 'heading', { name: 'Site Activity Digest' } );
		this.aiSummaryButton = this.root.locator( '.sybgo-widget-ai-btn' );
		this.filterTabs = this.root.getByRole( 'tab' );
		this.phpErrorsSection = this.root.getByText( /PHP Errors/i );
		this.viewThisWeekLink = this.root.getByRole( 'link', { name: "View This Week's Details" } );
		this.viewLastWeekLink = this.root.getByRole( 'link', { name: "View Last Week's Details" } );
		this.eventsList = this.root.locator( '.sybgo-events-list' );
		this.widgetActions = this.root.locator( '.sybgo-widget-actions' );
	}

	async goto(): Promise<void> {
		await this.page.goto( '/wp-admin/' );
		await expect( this.root ).toBeVisible();
	}

	async getEventCount(): Promise<number> {
		const strong = this.root.locator( '.sybgo-current-week .sybgo-event-stats strong' ).first();
		const text = await strong.innerText();
		return Number.parseInt( text, 10 );
	}

	async getDistinctErrorCount(): Promise<number> {
		const strong = this.root.locator( '.sybgo-php-errors .sybgo-event-stats strong' ).first();
		const text = await strong.innerText();
		return Number.parseInt( text, 10 );
	}

	async clickFilterTab( name: 'All' | 'Posts' | 'Users' | 'Updates' | 'Comments' ): Promise<void> {
		await this.root.getByRole( 'button', { name, exact: true } ).click();
	}

	/**
	 * Click a filter tab and wait for the AJAX response. Returns the updated event count
	 * reported by the server.
	 */
	async clickFilterTabAndWait( name: 'All' | 'Posts' | 'Users' | 'Updates' | 'Comments' ): Promise<number> {
		const responsePromise = this.page.waitForResponse(
			( r ) => r.url().includes( 'admin-ajax.php' ) && r.request().method() === 'POST'
		);
		await this.clickFilterTab( name );
		const response = await responsePromise;
		const json = ( await response.json() ) as { success: boolean; data: { count: number; html: string } };
		return json.data.count;
	}

	async getActiveFilterName(): Promise<string> {
		return this.root.locator( '.sybgo-filter-btn.active' ).innerText();
	}
}
