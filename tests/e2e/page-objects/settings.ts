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
		this.heading = page.getByRole( 'heading', { name: 'Settings' } );
		this.emailRecipients = page.locator( 'textarea[name*="email_recipients"]' );
		this.fromName = page.locator( 'input[name*="from_name"]' );
		this.fromEmail = page.locator( 'input[name*="from_email"]' );
		this.editMagnitudeThreshold = page.locator( 'input[name*="edit_magnitude"]' );
		this.retentionDays = page.locator( 'input[name*="retention"]' );
		this.saveButton = page.getByRole( 'button', { name: /Save/ } );
		this.footprintTable = page.locator( 'h2:has-text("Database Footprint") + table, h3:has-text("Database Footprint") + table' );
	}

	async goto(): Promise<void> {
		await this.page.goto( '/wp-admin/options-general.php?page=sybgo-settings' );
		await expect( this.emailRecipients ).toBeVisible();
	}

	async save(): Promise<void> {
		await this.saveButton.click();
		await expect( this.page.getByText( /Settings saved|saved successfully/i ).first() ).toBeVisible();
	}
}
