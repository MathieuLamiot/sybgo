import { expect, test } from '@playwright/test';
import { loginAsAdmin } from '../fixtures/auth';
import { runFromRepoRoot, wpCli } from '../fixtures/wp-cli';
import { DashboardWidgetPage } from '../page-objects/dashboard-widget';
import { ReportDetailsPage } from '../page-objects/report-details';

/**
 * E2E tests for dashboard widget navigation and filter functionality —
 * issue #77 / EPIC #74.
 *
 * Tests cover:
 *  - Conditional display of "View This Week's Details" / "View Last Week's Details" links.
 *  - Navigation to report detail pages with valid nonce (wp_nonce_url added in fix for #77).
 *  - Filter tab AJAX updates event counts and list content.
 *  - No JS console errors during widget interaction.
 */
test.describe( 'Dashboard widget navigation', () => {
	// ------------------------------------------------------------------
	// Navigation links — require active + frozen reports
	// ------------------------------------------------------------------
	test.describe( 'navigation links', () => {
		test.beforeAll( () => {
			runFromRepoRoot( 'bin/dev-seed.sh' );
			// Freeze once so both a frozen and a new active report exist.
			wpCli( 'cron event run sybgo_freeze_weekly_report' );
		} );

		test.beforeEach( async ( { page } ) => {
			await loginAsAdmin( page );
		} );

		test( '"View This Week\'s Details" link is visible when an active report exists', async ( { page } ) => {
			const widget = new DashboardWidgetPage( page );
			await widget.goto();

			await expect( widget.viewThisWeekLink ).toBeVisible();
		} );

		test( '"View Last Week\'s Details" link is visible when a frozen report exists', async ( { page } ) => {
			const widget = new DashboardWidgetPage( page );
			await widget.goto();

			await expect( widget.viewLastWeekLink ).toBeVisible();
		} );

		test( '"View This Week\'s Details" link href includes _wpnonce for the sybgo_view_report action', async ( { page } ) => {
			const widget = new DashboardWidgetPage( page );
			await widget.goto();

			const href = await widget.viewThisWeekLink.getAttribute( 'href' );
			expect( href ).toMatch( /_wpnonce=[a-f0-9]+/ );
			expect( href ).toMatch( /view=details/ );
			expect( href ).toMatch( /report_id=\d+/ );
		} );

		test( '"View Last Week\'s Details" link href includes _wpnonce for the sybgo_view_report action', async ( { page } ) => {
			const widget = new DashboardWidgetPage( page );
			await widget.goto();

			const href = await widget.viewLastWeekLink.getAttribute( 'href' );
			expect( href ).toMatch( /_wpnonce=[a-f0-9]+/ );
			expect( href ).toMatch( /view=details/ );
			expect( href ).toMatch( /report_id=\d+/ );
		} );

		test( 'clicking "View This Week\'s Details" navigates to active report detail page', async ( { page } ) => {
			const widget = new DashboardWidgetPage( page );
			await widget.goto();

			await widget.viewThisWeekLink.click();
			await page.waitForURL( /view=details/ );

			const detail = new ReportDetailsPage( page );
			await detail.expectLoaded();
		} );

		test( 'clicking "View Last Week\'s Details" navigates to frozen report detail page', async ( { page } ) => {
			const widget = new DashboardWidgetPage( page );
			await widget.goto();

			await widget.viewLastWeekLink.click();
			await page.waitForURL( /view=details/ );

			const detail = new ReportDetailsPage( page );
			await detail.expectLoaded();
		} );
	} );

	// ------------------------------------------------------------------
	// Links absent when no reports exist
	// ------------------------------------------------------------------
	test.describe( 'when no reports exist', () => {
		test.beforeAll( () => {
			// Delete all reports so neither navigation link renders.
			wpCli( 'db query "DELETE FROM wp_sybgo_reports"' );
		} );

		test.afterAll( () => {
			// Re-activate the plugin so its activation hook recreates an active
			// report, restoring predictable state for subsequent describe blocks.
			wpCli( 'plugin deactivate sybgo' );
			wpCli( 'plugin activate sybgo' );
			// Re-seed so events exist for the filter-tab tests below.
			runFromRepoRoot( 'bin/dev-seed.sh' );
		} );

		test.beforeEach( async ( { page } ) => {
			await loginAsAdmin( page );
		} );

		test( 'navigation links are absent when there are no reports', async ( { page } ) => {
			const widget = new DashboardWidgetPage( page );
			await widget.goto();

			await expect( widget.viewThisWeekLink ).not.toBeVisible();
			await expect( widget.viewLastWeekLink ).not.toBeVisible();
		} );
	} );

	// ------------------------------------------------------------------
	// Filter tabs — require seeded events data
	// ------------------------------------------------------------------
	test.describe( 'filter tabs', () => {
		test.beforeAll( () => {
			runFromRepoRoot( 'bin/dev-seed.sh' );
		} );

		test.beforeEach( async ( { page } ) => {
			await loginAsAdmin( page );
		} );

		test( '"All" filter is active by default', async ( { page } ) => {
			const widget = new DashboardWidgetPage( page );
			await widget.goto();

			const active = await widget.getActiveFilterName();
			expect( active.trim().toLowerCase() ).toBe( 'all' );
		} );

		test( 'clicking "Posts" filter triggers AJAX and returns a successful response', async ( { page } ) => {
			const widget = new DashboardWidgetPage( page );
			await widget.goto();

			// clickFilterTabAndWait returns the server-reported count.
			const count = await widget.clickFilterTabAndWait( 'Posts' );
			expect( typeof count ).toBe( 'number' );
			expect( count ).toBeGreaterThanOrEqual( 0 );
		} );

		test( 'Posts filter count is ≤ All filter count', async ( { page } ) => {
			const widget = new DashboardWidgetPage( page );
			await widget.goto();

			const allCount = await widget.getEventCount();
			const postsCount = await widget.clickFilterTabAndWait( 'Posts' );

			expect( postsCount ).toBeLessThanOrEqual( allCount );
		} );

		test( 'switching filters does not trigger a page navigation', async ( { page } ) => {
			const widget = new DashboardWidgetPage( page );
			await widget.goto();

			const initialUrl = page.url();

			// Switch to Posts then back to All — URL must never change.
			await widget.clickFilterTabAndWait( 'Posts' );
			expect( page.url() ).toBe( initialUrl );

			await widget.clickFilterTabAndWait( 'All' );
			expect( page.url() ).toBe( initialUrl );
		} );

		test( 'clicking a filter tab makes that tab the active one', async ( { page } ) => {
			const widget = new DashboardWidgetPage( page );
			await widget.goto();

			await widget.clickFilterTabAndWait( 'Posts' );
			const activeAfterPosts = await widget.getActiveFilterName();
			expect( activeAfterPosts.trim().toLowerCase() ).toBe( 'posts' );

			await widget.clickFilterTabAndWait( 'All' );
			const activeAfterAll = await widget.getActiveFilterName();
			expect( activeAfterAll.trim().toLowerCase() ).toBe( 'all' );
		} );

		test( 'no JS console errors during filter interactions', async ( { page } ) => {
			const errors: string[] = [];
			page.on( 'console', ( msg ) => {
				if ( msg.type() === 'error' ) {
					errors.push( msg.text() );
				}
			} );

			const widget = new DashboardWidgetPage( page );
			await widget.goto();

			await widget.clickFilterTabAndWait( 'Posts' );
			await widget.clickFilterTabAndWait( 'Comments' );
			await widget.clickFilterTabAndWait( 'All' );

			expect( errors ).toHaveLength( 0 );
		} );
	} );
} );
