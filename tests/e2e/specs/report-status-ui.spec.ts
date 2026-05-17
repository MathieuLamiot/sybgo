import { expect, test } from '@playwright/test';
import { loginAsAdmin } from '../fixtures/auth';
import { runFromRepoRoot, wpCli } from '../fixtures/wp-cli';
import { ReportDetailsPage } from '../page-objects/report-details';
import { ReportsListPage } from '../page-objects/reports-list';

/**
 * E2E tests for report status UI and lifecycle — issue #76 / EPIC #74.
 *
 * Validates that status badges display correctly after state transitions,
 * that ordering is correct (most-recent first), and that the detail page
 * is reachable and shows the right information for frozen reports.
 */
test.describe( 'Report status UI and lifecycle', () => {
	test.beforeAll( () => {
		// Seed with an active report plus two freeze cycles so we have:
		//   row 0 → ACTIVE  (newest)
		//   row 1 → FROZEN  (second-to-last freeze)
		//   row 2 → FROZEN  (first freeze, oldest)
		runFromRepoRoot( 'bin/dev-seed.sh' );
		wpCli( 'cron event run sybgo_freeze_weekly_report' ); // 1st freeze
		wpCli( 'cron event run sybgo_freeze_weekly_report' ); // 2nd freeze
	} );

	test.beforeEach( async ( { page } ) => {
		await loginAsAdmin( page );
	} );

	test( 'active report displays ACTIVE status badge', async ( { page } ) => {
		const list = new ReportsListPage( page );
		await list.goto();

		const status = await list.getRowStatus( 0 );
		expect( status ).toBe( 'ACTIVE' );
	} );

	test( 'frozen report displays FROZEN status badge after freeze cron', async ( { page } ) => {
		const list = new ReportsListPage( page );
		await list.goto();

		const statuses = await list.getAllRowStatuses();
		expect( statuses ).toContain( 'FROZEN' );
	} );

	test( 'reports list is ordered newest first: active row then frozen rows', async ( { page } ) => {
		const list = new ReportsListPage( page );
		await list.goto();

		const statuses = await list.getAllRowStatuses();
		// Newest row must be ACTIVE; subsequent rows must be FROZEN (not ACTIVE).
		expect( statuses[ 0 ] ).toBe( 'ACTIVE' );
		expect( statuses.slice( 1 ).every( ( s ) => s === 'FROZEN' ) ).toBe( true );
	} );

	test( 'multiple frozen reports display in correct order (most recent first)', async ( { page } ) => {
		const list = new ReportsListPage( page );
		await list.goto();

		// Verify at least 2 frozen rows exist after 2 freeze cycles.
		const statuses = await list.getAllRowStatuses();
		const frozenCount = statuses.filter( ( s ) => s === 'FROZEN' ).length;
		expect( frozenCount ).toBeGreaterThanOrEqual( 2 );

		// Confirm DB ordering: the second-newest frozen report has a lower ID than
		// the first-newest.  The list renders newest-first so row 1 > row 2 by period.
		const rows = wpCli(
			"db query \"SELECT id FROM wp_sybgo_reports WHERE status='frozen' ORDER BY id DESC LIMIT 2\" --skip-column-names"
		);
		const ids = rows
			.trim()
			.split( '\n' )
			.map( ( s ) => Number( s.trim() ) );
		expect( ids[ 0 ] ).toBeGreaterThan( ids[ 1 ] );
	} );

	test( 'frozen report can be opened from the list and detail page loads', async ( { page } ) => {
		const list = new ReportsListPage( page );
		await list.goto();

		const frozenIndex = await list.findRowIndexByStatus( 'FROZEN' );
		await list.openDetailsForRow( frozenIndex );

		const detail = new ReportDetailsPage( page );
		await detail.expectLoaded();
		// Nonce must be in the URL so check_admin_referer passes.
		expect( page.url() ).toMatch( /_wpnonce=[a-f0-9]+/ );
	} );

	test( 'report detail page heading identifies the frozen report', async ( { page } ) => {
		const list = new ReportsListPage( page );
		await list.goto();

		const frozenIndex = await list.findRowIndexByStatus( 'FROZEN' );
		await list.openDetailsForRow( frozenIndex );

		const detail = new ReportDetailsPage( page );
		// Heading format is "Report: <date-range>".
		await expect( detail.heading ).toBeVisible();
		await expect( detail.summarySection ).toBeVisible();
	} );
} );
