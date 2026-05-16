import { Locator, Page, expect } from '@playwright/test';

/**
 * Page object for the Sybgo Reports list at
 * `/wp-admin/admin.php?page=sybgo-reports`.
 *
 * Source: wp-plugin/admin/class-reports-page.php (render_reports_page list view)
 *
 * Status badges: ACTIVE, FROZEN, SENT.
 * Each row exposes a "View Details" link; active rows expose
 * "Freeze & Send Now"; frozen rows expose "Resend Email".
 */
export class ReportsListPage {
	readonly page: Page;
	readonly heading: Locator;
	readonly table: Locator;

	constructor( page: Page ) {
		this.page = page;
		this.heading = page.getByRole( 'heading', { name: 'Sybgo Reports', exact: true } );
		this.table = page.locator( '.wrap table' ).first();
	}

	async goto(): Promise<void> {
		await this.page.goto( '/wp-admin/admin.php?page=sybgo-reports' );
		await expect( this.heading ).toBeVisible();
	}

	async row( index: number ): Promise<Locator> {
		const rows = this.table.locator( 'tbody tr' );
		await expect( rows.nth( index ) ).toBeVisible();
		return rows.nth( index );
	}

	async getRowStatus( index: number ): Promise<'ACTIVE' | 'FROZEN' | 'SENT'> {
		const row = await this.row( index );
		const badge = await row.locator( '.sybgo-status-badge, .badge, .status' ).first().innerText();
		const normalized = badge.trim().toUpperCase();
		if ( normalized !== 'ACTIVE' && normalized !== 'FROZEN' && normalized !== 'SENT' ) {
			throw new Error( `Unexpected report status badge: ${ badge }` );
		}
		return normalized;
	}

	async openDetailsForRow( index: number ): Promise<void> {
		const row = await this.row( index );
		await row.getByRole( 'link', { name: 'View Details' } ).click();
		await expect( this.page ).toHaveURL( /view=details/ );
	}

	async freezeActiveReport( index: number ): Promise<void> {
		const row = await this.row( index );
		await row.getByRole( 'link', { name: /Freeze & Send Now/ } ).click();

		// Confirmation modal: "Yes, Freeze & Send".
		await this.page.getByRole( 'button', { name: /Yes, Freeze & Send/ } ).click();
	}
}
