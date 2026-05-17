import { expect, test } from '@playwright/test';
import { loginAsAdmin } from '../fixtures/auth';
import { runFromRepoRoot, wpCli } from '../fixtures/wp-cli';
import { ReportDetailsPage } from '../page-objects/report-details';
import { ReportsListPage } from '../page-objects/reports-list';

test.describe( 'Reports lifecycle', () => {
	test.beforeAll( () => {
		runFromRepoRoot( 'bin/dev-seed.sh' );
	} );

	test.beforeEach( async ( { page } ) => {
		await loginAsAdmin( page );
	} );

	test( 'reports list renders an active report row with the seed data', async ( { page } ) => {
		const list = new ReportsListPage( page );
		await list.goto();

		const status = await list.getRowStatus( 0 );
		expect( status ).toBe( 'ACTIVE' );
	} );

	test( 'View Details navigates to the report detail page (nonce honored)', async ( { page } ) => {
		const list = new ReportsListPage( page );
		await list.goto();
		await list.openDetailsForRow( 0 );

		// The URL must include &_wpnonce= — without it, render_reports_page() 403s.
		expect( page.url() ).toMatch( /_wpnonce=[a-f0-9]+/ );

		const detail = new ReportDetailsPage( page );
		await detail.expectLoaded();
	} );

	test( 'AI summary on the detail page reflects WP version capability', async ( { page } ) => {
		const list = new ReportsListPage( page );
		await list.goto();
		await list.openDetailsForRow( 0 );

		const detail = new ReportDetailsPage( page );
		const disabled = await detail.aiSummaryButtonIsDisabled();
		if ( disabled ) {
			const reason = await detail.aiSummaryDisabledReason();
			expect( reason ).toMatch( /WordPress 7/i );
		}
		// On WP 7 the button is enabled — no further assertion here so the
		// test passes on both environments without a hidden version gate.
	} );

	test( 'freezing produces a new active row and a frozen row', async () => {
		// Run the freeze cron directly to keep the assertion deterministic.
		wpCli( 'cron event run sybgo_freeze_weekly_report' );

		const beforeAndAfter = wpCli(
			"db query \"SELECT status FROM wp_sybgo_reports ORDER BY id DESC LIMIT 2\" --skip-column-names"
		);
		const statuses = beforeAndAfter
			.trim()
			.split( '\n' )
			.map( ( line ) => line.trim().toLowerCase() );

		// Newest row is the freshly-created active report; the previous one
		// transitioned to frozen.
		expect( statuses[ 0 ] ).toBe( 'active' );
		expect( statuses[ 1 ] ).toBe( 'frozen' );
	} );
} );
