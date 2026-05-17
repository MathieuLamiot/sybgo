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
			// Wipe the reports table so neither link renders.
			wpCli( 'db query "TRUNCATE TABLE wp_sybgo_reports"' );
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

		test( 'switching between filters updates the events list without page reload', async ( { page } ) => {
			const widget = new DashboardWidgetPage( page );
			await widget.goto();

			// Capture initial HTML of events list.
			const initialHtml = await widget.eventsList.innerHTML();

			// Switch to Posts filter.
			await widget.clickFilterTabAndWait( 'Posts' );
			const postsHtml = await widget.eventsList.innerHTML();

			// Switch back to All.
			await widget.clickFilterTabAndWait( 'All' );
			const allHtml = await widget.eventsList.innerHTML();

			// All and Posts should differ (seed has non-post events too).
			// All and initial All should be equivalent.
			expect( allHtml ).toBe( initialHtml );
			// Posts may equal All only if every event is a post event, but
			// since seed also creates comments, they must differ.
			expect( postsHtml ).not.toBe( initialHtml );
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
