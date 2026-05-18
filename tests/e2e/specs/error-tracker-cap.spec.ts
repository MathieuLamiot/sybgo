import { expect, test } from '@playwright/test';
import { loginAsAdmin } from '../fixtures/auth';
import { runFromRepoRoot, wpCli } from '../fixtures/wp-cli';
import { DashboardWidgetPage } from '../page-objects/dashboard-widget';

/**
 * E2E / integration tests for Error_Tracker daily-limit enforcement —
 * issue #78 / EPIC #74.
 *
 * Errors are triggered via `wp eval` (same PHP process as WordPress, so the
 * Error_Tracker's set_error_handler() is active).  Each test starts with a
 * clean slate for the current-period aggregated_events rows.
 *
 * Signature derivation: md5( file ':' line ':' message_snippet ).
 * wp-cli eval writes each snippet to a fresh temp file, so errors on different
 * lines — or with different messages — always produce different signatures.
 * Errors on the SAME line with the SAME message within one eval call share a
 * signature (used in the "known-signature increment" test).
 *
 * Priority map (higher = more important):
 *   E_USER_ERROR=7, E_USER_WARNING=5, E_USER_NOTICE=1
 *
 * Fatal errors (E_USER_ERROR) are intentionally avoided because they terminate
 * the PHP process; E_USER_WARNING and E_USER_NOTICE are sufficient to test all
 * eviction paths.
 */

// Aggregated events use report_id = 0 as the sentinel for "current period"
// (distinct from singular events which use report_id IS NULL).
const COUNT_QUERY = "db query \"SELECT COUNT(*) FROM wp_sybgo_aggregated_events WHERE event_type='php_error' AND report_id = 0\" --skip-column-names";
const CLEAR_QUERY = "db query \"DELETE FROM wp_sybgo_aggregated_events WHERE event_type='php_error' AND report_id = 0\"";

function distinctCount(): number {
	return Number( wpCli( COUNT_QUERY ).trim() );
}

test.describe( 'Error tracker daily-limit enforcement', () => {
	test.beforeAll( () => {
		runFromRepoRoot( 'bin/dev-seed.sh' );
	} );

	test.beforeEach( async ( { page } ) => {
		await loginAsAdmin( page );
	} );

	test.beforeEach( () => {
		// Reset current-period error rows AFTER login so that any PHP notices
		// captured during the admin page load do not pollute the test's count.
		// Tests that assert low exact counts (< cap) are sensitive to stray rows.
		wpCli( CLEAR_QUERY );
	} );

	// ------------------------------------------------------------------
	// Cap boundary: 6th distinct error at cap=5 is rejected
	// ------------------------------------------------------------------
	test( 'at cap (5 signatures), 6th distinct error is rejected', () => {
		// All six trigger_error calls are in one eval → one temp file.
		// Different messages on different lines → 6 distinct signatures.
		wpCli(
			'eval ' +
			`'trigger_error("cap_boundary_1", E_USER_NOTICE);` +
			` trigger_error("cap_boundary_2", E_USER_NOTICE);` +
			` trigger_error("cap_boundary_3", E_USER_NOTICE);` +
			` trigger_error("cap_boundary_4", E_USER_NOTICE);` +
			` trigger_error("cap_boundary_5", E_USER_NOTICE);` +
			` trigger_error("cap_boundary_6", E_USER_NOTICE);'`
		);

		expect( distinctCount() ).toBe( 5 );
	} );

	// ------------------------------------------------------------------
	// Priority-based eviction: warning (priority 5) evicts notice (priority 1)
	// ------------------------------------------------------------------
	test( 'high-priority error evicts a low-priority error when cap is reached', () => {
		// Fill cap with 5 E_USER_NOTICE entries (priority=1).
		// Sixth is E_USER_WARNING (priority=5) — must evict one notice.
		wpCli(
			'eval ' +
			`'trigger_error("evict_notice_1", E_USER_NOTICE);` +
			` trigger_error("evict_notice_2", E_USER_NOTICE);` +
			` trigger_error("evict_notice_3", E_USER_NOTICE);` +
			` trigger_error("evict_notice_4", E_USER_NOTICE);` +
			` trigger_error("evict_notice_5", E_USER_NOTICE);` +
			` trigger_error("evict_warning_high", E_USER_WARNING);'`
		);

		// Count stays at 5 (eviction happened, cap maintained).
		expect( distinctCount() ).toBe( 5 );

		// The warning must be stored — verify by checking the level in dimensions JSON.
		const warningCount = Number(
			wpCli(
				`db query "SELECT COUNT(*) FROM wp_sybgo_aggregated_events WHERE event_type='php_error' AND report_id = 0 AND JSON_UNQUOTE(JSON_EXTRACT(dimensions, '$.level')) = 'user_warning'" --skip-column-names`
			).trim()
		);
		expect( warningCount ).toBe( 1 );
	} );

	// ------------------------------------------------------------------
	// No eviction when incoming priority ≤ stored lowest priority
	// ------------------------------------------------------------------
	test( 'equal-or-lower priority error is rejected when cap is reached', () => {
		// Fill cap with 5 E_USER_WARNING entries (priority=5).
		// Sixth is E_USER_NOTICE (priority=1) — must be rejected (not evicted).
		wpCli(
			'eval ' +
			`'trigger_error("no_evict_warning_1", E_USER_WARNING);` +
			` trigger_error("no_evict_warning_2", E_USER_WARNING);` +
			` trigger_error("no_evict_warning_3", E_USER_WARNING);` +
			` trigger_error("no_evict_warning_4", E_USER_WARNING);` +
			` trigger_error("no_evict_warning_5", E_USER_WARNING);` +
			` trigger_error("no_evict_notice_low", E_USER_NOTICE);'`
		);

		// Still exactly 5 rows — the notice was rejected.
		expect( distinctCount() ).toBe( 5 );

		// No notice must have been stored.
		const noticeCount = Number(
			wpCli(
				`db query "SELECT COUNT(*) FROM wp_sybgo_aggregated_events WHERE event_type='php_error' AND report_id = 0 AND JSON_UNQUOTE(JSON_EXTRACT(dimensions, '$.level')) = 'user_notice'" --skip-column-names`
			).trim()
		);
		expect( noticeCount ).toBe( 0 );
	} );

	// ------------------------------------------------------------------
	// Known-signature increment: same error twice → 1 row, value=2
	// ------------------------------------------------------------------
	test( 'known signature increments the counter without adding a new row', () => {
		// Two trigger_error calls on THE SAME line with the SAME message
		// within one eval → identical file+line+message → same signature.
		wpCli(
			'eval ' +
			`'for ($i=0;$i<3;$i++){trigger_error("known_sig_increment_test",E_USER_NOTICE);}'`
		);

		// Still 1 distinct signature.
		expect( distinctCount() ).toBe( 1 );

		// The single row has value = 3 (incremented 3 times).
		const value = Number(
			wpCli(
				`db query "SELECT value FROM wp_sybgo_aggregated_events WHERE event_type='php_error' AND report_id = 0 LIMIT 1" --skip-column-names`
			).trim()
		);
		expect( value ).toBe( 3 );
	} );

	// ------------------------------------------------------------------
	// Custom cap via filter
	// ------------------------------------------------------------------
	test( 'custom cap set via sybgo_error_tracker_daily_cap filter is enforced', () => {
		// Register a filter that lowers the cap to 2, then trigger 3 unique errors.
		// All in one eval so the filter stays active for all three trigger_error calls.
		wpCli(
			'eval ' +
			`'add_filter("sybgo_error_tracker_daily_cap",function(){return 2;});` +
			` trigger_error("custom_cap_test_1",E_USER_NOTICE);` +
			` trigger_error("custom_cap_test_2",E_USER_NOTICE);` +
			` trigger_error("custom_cap_test_3",E_USER_NOTICE);'`
		);

		// Cap=2 → only 2 rows stored, 3rd rejected.
		expect( distinctCount() ).toBe( 2 );
	} );

	// ------------------------------------------------------------------
	// Oldest entry evicted first on priority tie
	// ------------------------------------------------------------------
	test( 'when multiple entries share the lowest priority, the oldest is evicted first', () => {
		// Insert 5 E_USER_NOTICE entries sequentially (oldest → newest order by id).
		// Then add one E_USER_WARNING which must evict the OLDEST notice.
		//
		// We can't directly observe insertion order within a single eval, so we
		// use two separate eval calls: first fills 5 notices, second adds warning.
		// Since each eval uses a different temp file, the signatures already differ.
		wpCli(
			'eval ' +
			`'trigger_error("oldest_evict_notice_1",E_USER_NOTICE);` +
			` trigger_error("oldest_evict_notice_2",E_USER_NOTICE);` +
			` trigger_error("oldest_evict_notice_3",E_USER_NOTICE);` +
			` trigger_error("oldest_evict_notice_4",E_USER_NOTICE);` +
			` trigger_error("oldest_evict_notice_5",E_USER_NOTICE);'`
		);
		expect( distinctCount() ).toBe( 5 );

		// Capture the lowest ID before eviction (that is the oldest row).
		const oldestId = Number(
			wpCli(
				`db query "SELECT id FROM wp_sybgo_aggregated_events WHERE event_type='php_error' AND report_id = 0 ORDER BY id ASC LIMIT 1" --skip-column-names`
			).trim()
		);

		// Add a higher-priority warning from a second eval (distinct signature).
		wpCli( `eval 'trigger_error("oldest_evict_warning",E_USER_WARNING);'` );

		// Count must remain at 5.
		expect( distinctCount() ).toBe( 5 );

		// The previously-oldest row must be gone.
		const survivedOldest = Number(
			wpCli(
				`db query "SELECT COUNT(*) FROM wp_sybgo_aggregated_events WHERE id=${oldestId}" --skip-column-names`
			).trim()
		);
		expect( survivedOldest ).toBe( 0 );
	} );

	// ------------------------------------------------------------------
	// UI: dashboard widget reflects the distinct error count
	// ------------------------------------------------------------------
	test( 'dashboard widget error section reflects the distinct signature count', async ( { page } ) => {
		const widget = new DashboardWidgetPage( page );

		// Navigate to the dashboard first so that any PHP notices generated
		// during the admin page load are captured before we clear the slate.
		await widget.goto();

		// Clear again after navigation: widget.goto() loads the WP admin which
		// runs plugin init and may emit PHP notices via the error handler.
		wpCli( CLEAR_QUERY );

		// Trigger exactly 3 distinct E_USER_NOTICE errors.
		wpCli(
			'eval ' +
			`'trigger_error("widget_count_test_1",E_USER_NOTICE);` +
			` trigger_error("widget_count_test_2",E_USER_NOTICE);` +
			` trigger_error("widget_count_test_3",E_USER_NOTICE);'`
		);

		// Reload so the widget renders with the fresh DB state.
		await page.reload();

		const displayedCount = await widget.getDistinctErrorCount();
		expect( displayedCount ).toBe( 3 );
	} );
} );
