import { expect, test } from '@playwright/test';
import { loginAsAdmin } from '../fixtures/auth';
import { runFromRepoRoot } from '../fixtures/wp-cli';
import { DashboardWidgetPage } from '../page-objects/dashboard-widget';

test.describe( 'Dashboard widget', () => {
	test.beforeAll( () => {
		runFromRepoRoot( 'bin/dev-seed.sh' );
	} );

	test.beforeEach( async ( { page } ) => {
		await loginAsAdmin( page );
	} );

	test( 'appears on the WP dashboard with the expected title', async ( { page } ) => {
		const widget = new DashboardWidgetPage( page );
		await widget.goto();

		await expect( widget.title ).toBeVisible();
		await expect( widget.root ).toContainText( "This Week's Activity" );
	} );

	test( 'shows a non-zero event counter after seeding', async ( { page } ) => {
		const widget = new DashboardWidgetPage( page );
		await widget.goto();

		const count = await widget.getEventCount();
		expect( count ).toBeGreaterThan( 0 );
	} );

	test( 'AI summary button is disabled on WP < 7 with a clear tooltip', async ( { page } ) => {
		const widget = new DashboardWidgetPage( page );
		await widget.goto();

		// Plugin renders the button even on WP < 7, but disables it with a tooltip.
		await expect( widget.aiSummaryButton ).toBeDisabled();
		const title = await widget.aiSummaryButton.getAttribute( 'title' );
		expect( title ).toMatch( /WordPress 7/i );
	} );

	test( 'PHP errors section lists tracked errors', async ( { page } ) => {
		const widget = new DashboardWidgetPage( page );
		await widget.goto();

		await expect( widget.phpErrorsSection ).toBeVisible();
		// Seed triggers at least one demo notice + one demo warning.
		await expect( widget.root ).toContainText( /distinct signatures/i );
	} );
} );
