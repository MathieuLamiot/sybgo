import { Locator, Page, expect } from '@playwright/test';

/**
 * Page object for the Sybgo Settings page at
 * `/wp-admin/options-general.php?page=sybgo-settings`.
 *
 * Source: wp-plugin/admin/class-settings-page.php
 */
export class SettingsPage {
	readonly page: Page;
	readonly heading: Locator;
	readonly emailRecipients: Locator;
	readonly fromName: Locator;
	readonly fromEmail: Locator;
	readonly editMagnitudeThreshold: Locator;
	readonly retentionDays: Locator;
	readonly saveButton: Locator;
	readonly footprintTable: Locator;

	constructor( page: Page ) {
		this.page = page;
		this.heading = page.getByRole( 'heading', { name: 'Sybgo Settings', exact: true } );
		this.emailRecipients = page.getByLabel( 'Email Recipients' );
		this.fromName = page.getByLabel( 'From Name' );
		this.fromEmail = page.getByLabel( 'From Email' );
		this.editMagnitudeThreshold = page.getByLabel( /Edit Magnitude Threshold/ );
		this.retentionDays = page.getByLabel( /Data Retention Period/ );
		this.saveButton = page.getByRole( 'button', { name: 'Save Settings' } );
		this.footprintTable = page.locator( 'h2:has-text("Database Footprint") + table, h3:has-text("Database Footprint") + table' );
	}

	async goto(): Promise<void> {
		await this.page.goto( '/wp-admin/options-general.php?page=sybgo-settings' );
		await expect( this.heading ).toBeVisible();
	}

	async save(): Promise<void> {
		await this.saveButton.click();
		await expect( this.page.getByText( /Settings saved|saved successfully/i ) ).toBeVisible();
	}
}
