import { expect, test } from '@playwright/test';
import { loginAsAdmin } from '../fixtures/auth';
import { runFromRepoRoot, wpCli } from '../fixtures/wp-cli';
import { DashboardWidgetPage } from '../page-objects/dashboard-widget';
import { ReportDetailsPage } from '../page-objects/report-details';
import { ReportsListPage } from '../page-objects/reports-list';

/**
 * Smoke tests — issue #46 / EPIC #74.
 *
 * Each test validates a complete happy-path workflow end-to-end.  They are
 * intentionally broad: a failure here signals that a critical user flow is
 * broken and warrants deeper investigation via the individual feature specs.
 */
test.describe( 'Smoke: critical happy-path workflows', () => {
	test.beforeAll( () => {
		runFromRepoRoot( 'bin/dev-seed.sh' );
	} );

	test.beforeEach( async ( { page } ) => {
		await loginAsAdmin( page );
	} );

	// ------------------------------------------------------------------
	// 1. Admin login and dashboard access
	// ------------------------------------------------------------------
	test( 'admin can log in and see the dashboard with the Sybgo widget', async ( { page } ) => {
		const widget = new DashboardWidgetPage( page );
		await widget.goto();

		await expect( widget.title ).toBeVisible();
		await expect( widget.root ).toContainText( "This Week's Activity" );
	} );

	// ------------------------------------------------------------------
	// 2. Active report visible on dashboard widget
	// ------------------------------------------------------------------
	test( 'active report details link is visible on the dashboard widget', async ( { page } ) => {
		const widget = new DashboardWidgetPage( page );
		await widget.goto();

		await expect( widget.viewThisWeekLink ).toBeVisible();
	} );

	// ------------------------------------------------------------------
	// 3. Report lifecycle: seed → freeze → frozen in list
	// ------------------------------------------------------------------
	test( 'freeze workflow produces an active + frozen report visible in the list', async ( { page } ) => {
		wpCli( 'cron event run sybgo_freeze_weekly_report' );

		const list = new ReportsListPage( page );
		await list.goto();

		const statuses = await list.getAllRowStatuses();
		expect( statuses ).toContain( 'ACTIVE' );
		expect( statuses ).toContain( 'FROZEN' );
	} );

	// ------------------------------------------------------------------
	// 4. Email delivery: freeze (0-event report) → email cron → SENT badge
	//
	// wp-env has no live SMTP server.  We exercise the "quiet period" code path:
	// when a frozen report has total_events = 0 and send_empty_reports = false,
	// Email_Manager::send_report_email() marks the report as emailed without
	// calling wp_mail().  Full delivery-to-mailbox tests require Mailhog (#75).
	// ------------------------------------------------------------------
	test( 'email delivery — quiet period path marks 0-event frozen report as SENT', async ( { page } ) => {
		// Clear current-period events so the next frozen report has 0 total_events.
		wpCli( "db query \"DELETE FROM wp_sybgo_events WHERE report_id IS NULL\"" );
		wpCli( "db query \"DELETE FROM wp_sybgo_aggregated_events WHERE report_id = 0\"" );
		wpCli( 'cron event run sybgo_freeze_weekly_report' );
		wpCli( 'cron event run sybgo_send_report_emails' );

		const list = new ReportsListPage( page );
		await list.goto();

		const statuses = await list.getAllRowStatuses();
		expect( statuses ).toContain( 'SENT' );
	} );

	// ------------------------------------------------------------------
	// 5. Dashboard widget filters
	// ------------------------------------------------------------------
	test( 'dashboard widget filter tabs respond to clicks without errors', async ( { page } ) => {
		const errors: string[] = [];
		page.on( 'console', ( msg ) => {
			if ( msg.type() === 'error' ) errors.push( msg.text() );
		} );

		const widget = new DashboardWidgetPage( page );
		await widget.goto();

		const postsCount = await widget.clickFilterTabAndWait( 'Posts' );
		expect( typeof postsCount ).toBe( 'number' );
		expect( postsCount ).toBeGreaterThanOrEqual( 0 );

		await widget.clickFilterTabAndWait( 'All' );
		expect( errors ).toHaveLength( 0 );
	} );

	// ------------------------------------------------------------------
	// 6. Error tracking captured and visible on dashboard
	// ------------------------------------------------------------------
	test( 'PHP errors tracked via wp eval appear in the dashboard widget', async ( { page } ) => {
		// Clear current-period errors to get a predictable baseline.
		wpCli(
			"db query \"DELETE FROM wp_sybgo_aggregated_events WHERE event_type='php_error' AND report_id IS NULL\""
		);

		wpCli( "eval 'trigger_error(\"Sybgo smoke test error\", E_USER_NOTICE);'" );

		const widget = new DashboardWidgetPage( page );
		await widget.goto();

		const errorCount = await widget.getDistinctErrorCount();
		expect( errorCount ).toBeGreaterThanOrEqual( 1 );
		await expect( widget.phpErrorsSection ).toBeVisible();
	} );

	// ------------------------------------------------------------------
	// 7. Report detail page accessible from the list
	// ------------------------------------------------------------------
	test( 'report detail page is accessible via the View Details link and loads correctly', async ( { page } ) => {
		const list = new ReportsListPage( page );
		await list.goto();

		await list.openDetailsForRow( 0 );

		const detail = new ReportDetailsPage( page );
		await detail.expectLoaded();
		expect( page.url() ).toMatch( /_wpnonce=[a-f0-9]+/ );
	} );
} );
