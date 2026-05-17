import { expect, test } from '@playwright/test';
import { loginAsAdmin } from '../fixtures/auth';
import { SettingsPage } from '../page-objects/settings';

test.describe( 'Settings page', () => {
	test.beforeEach( async ( { page } ) => {
		await loginAsAdmin( page );
	} );

	test( 'renders all expected sections', async ( { page } ) => {
		const settings = new SettingsPage( page );
		await settings.goto();

		await expect( settings.emailRecipients ).toBeVisible();
		await expect( settings.fromName ).toBeVisible();
		await expect( settings.fromEmail ).toBeVisible();
		await expect( settings.retentionDays ).toBeVisible();
		await expect( settings.saveButton ).toBeVisible();
	} );

	test( 'database footprint table lists the sybgo tables', async ( { page } ) => {
		const settings = new SettingsPage( page );
		await settings.goto();

		await expect( settings.footprintTable ).toContainText( 'sybgo_events' );
		await expect( settings.footprintTable ).toContainText( 'sybgo_reports' );
		await expect( settings.footprintTable ).toContainText( 'sybgo_aggregated_events' );
	} );

	test( 'changing the retention period and saving persists the value', async ( { page } ) => {
		const settings = new SettingsPage( page );
		await settings.goto();

		await settings.retentionDays.fill( '45' );
		await settings.save();

		await page.reload();
		await expect( settings.retentionDays ).toHaveValue( '45' );

		// Restore default to keep test isolation.
		await settings.retentionDays.fill( '90' );
		await settings.save();
	} );
} );
