import { expect, test } from '@playwright/test';
import { loginAsAdmin } from '../fixtures/auth';
import { runFromRepoRoot, wpCli } from '../fixtures/wp-cli';
import { ReportsListPage } from '../page-objects/reports-list';

/**
 * E2E tests for the email delivery pipeline — issue #75 / EPIC #74.
 *
 * Tests validate DB-observable behaviour of the `sybgo_send_report_emails` cron
 * without requiring an SMTP/Mailhog setup.  Email delivery is triggered via
 * wp-cli and report-status transitions are checked against the database.
 *
 * NOTE: Verifying actual email content (subject, body, recipients) requires a
 * mail-capture server (e.g. Mailhog).  That infrastructure is tracked as a
 * follow-up and is intentionally out of scope here.
 */
test.describe( 'Email delivery pipeline', () => {
	// ------------------------------------------------------------------
	// Scenario A: no frozen report — cron must be a no-op
	// ------------------------------------------------------------------
	test.describe( 'when no frozen report exists', () => {
		test.beforeAll( () => {
			// Fresh seed produces only an active report; no freeze.
			runFromRepoRoot( 'bin/dev-seed.sh' );
		} );

		test( 'email cron is a no-op when there is no frozen report to send', () => {
			const emailed = wpCli(
				"db query \"SELECT COUNT(*) FROM wp_sybgo_reports WHERE status='emailed'\" --skip-column-names"
			).trim();

			wpCli( 'cron event run sybgo_send_report_emails' );

			const emailedAfter = wpCli(
				"db query \"SELECT COUNT(*) FROM wp_sybgo_reports WHERE status='emailed'\" --skip-column-names"
			).trim();

			expect( emailedAfter ).toBe( emailed );
		} );
	} );

	// ------------------------------------------------------------------
	// Scenario B: frozen report exists — cron must transition to emailed
	// ------------------------------------------------------------------
	test.describe( 'when a frozen report exists', () => {
		test.beforeAll( () => {
			runFromRepoRoot( 'bin/dev-seed.sh' );
			wpCli( 'cron event run sybgo_freeze_weekly_report' );
		} );

		test.beforeEach( async ( { page } ) => {
			await loginAsAdmin( page );
		} );

		test( 'running the email cron transitions the frozen report to emailed status', () => {
			wpCli( 'cron event run sybgo_send_report_emails' );

			// The just-emailed report is the second-newest row (newest is the
			// active report created automatically by the freeze cron).
			const result = wpCli(
				"db query \"SELECT status FROM wp_sybgo_reports ORDER BY id DESC LIMIT 2\" --skip-column-names"
			);
			const [ newest, previous ] = result
				.trim()
				.split( '\n' )
				.map( ( s ) => s.trim().toLowerCase() );

			expect( newest ).toBe( 'active' );
			expect( previous ).toBe( 'emailed' );
		} );

		test( 'emailed report displays SENT badge in the reports list', async ( { page } ) => {
			const list = new ReportsListPage( page );
			await list.goto();

			const statuses = await list.getAllRowStatuses();
			expect( statuses ).toContain( 'SENT' );
		} );

		test( 'emailed report has a Resend Email action in the reports list', async ( { page } ) => {
			const list = new ReportsListPage( page );
			await list.goto();

			await expect( list.table ).toContainText( /Resend Email/i );
		} );

		test( 'emailed report no longer appears as FROZEN in the reports list', async ( { page } ) => {
			const list = new ReportsListPage( page );
			await list.goto();

			// After emailing there should be no FROZEN row — it became SENT.
			const statuses = await list.getAllRowStatuses();
			expect( statuses ).not.toContain( 'FROZEN' );
		} );
	} );
} );
