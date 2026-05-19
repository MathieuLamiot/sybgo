/**
 * MCP Connect Helper — Modal Close Behaviors (AC6)
 *
 * Verifies the three modal dismiss paths:
 *   1. × (close) button
 *   2. Escape key
 *   3. Backdrop click (clicking the dark overlay outside the modal content)
 */

import { test, expect, Page } from '@playwright/test';
import { loginAsAdmin } from '../../fixtures/auth';

const NAME_INPUT = '#new_application_password_name';
const ADD_BUTTON  = '#do_new_application_password';
const NOTICE_SEL  = '.new-application-password-notice';

async function goToProfile( page: Page ) {
	await page.goto( '/wp-admin/profile.php', { waitUntil: 'networkidle' } );
	await page.waitForSelector( '#application-passwords-section', { timeout: 15000 } );
	await page.locator( '#application-passwords-section' ).scrollIntoViewIfNeeded();
}

async function createPassword( page: Page, label = 'Close-Test Password' ) {
	await page.locator( NAME_INPUT ).fill( label );
	const [ response ] = await Promise.all( [
		page.waitForResponse(
			r => r.url().includes( 'application-passwords' ) && r.request().method() === 'POST',
			{ timeout: 15000 }
		),
		page.locator( ADD_BUTTON ).click(),
	] );
	expect( response.status() ).toBe( 201 );
	await page.waitForSelector( NOTICE_SEL, { timeout: 10000 } );
	await page.waitForTimeout( 600 );
}

async function openModal( page: Page ) {
	await page.waitForSelector( '.mcp-helper-connect-btn', { timeout: 8000 } );
	await page.locator( '.mcp-helper-connect-btn' ).click();
	await page.waitForSelector( '#mcp-helper-modal', { state: 'visible', timeout: 5000 } );
	await page.waitForTimeout( 300 );
}

// ── Tests ─────────────────────────────────────────────────────────────────────

test( 'AC6 — Modal closes via × (close) button', async ( { page } ) => {
	await loginAsAdmin( page );
	await goToProfile( page );
	await createPassword( page );
	await openModal( page );

	// Verify modal is open
	await expect( page.locator( '#mcp-helper-modal' ) ).toBeVisible();

	// Click the × button
	await page.locator( '.mcp-helper-modal-close' ).click();
	await page.waitForTimeout( 300 ); // let fadeOut animation complete

	// Modal should now be hidden
	await expect( page.locator( '#mcp-helper-modal' ) ).toBeHidden();
} );

test( 'AC6 — Modal closes via Escape key', async ( { page } ) => {
	await loginAsAdmin( page );
	await goToProfile( page );
	await createPassword( page );
	await openModal( page );

	// Verify modal is open
	await expect( page.locator( '#mcp-helper-modal' ) ).toBeVisible();

	// Press Escape
	await page.keyboard.press( 'Escape' );
	await page.waitForTimeout( 300 );

	// Modal should now be hidden
	await expect( page.locator( '#mcp-helper-modal' ) ).toBeHidden();
} );

test( 'AC6 — Modal closes via backdrop click', async ( { page } ) => {
	await loginAsAdmin( page );
	await goToProfile( page );
	await createPassword( page );
	await openModal( page );

	// Verify modal is open
	await expect( page.locator( '#mcp-helper-modal' ) ).toBeVisible();

	// Click the backdrop (the #mcp-helper-modal element itself, not the inner content)
	// The backdrop is the full-screen overlay; clicking a corner ensures we hit it.
	await page.locator( '#mcp-helper-modal' ).click( { position: { x: 10, y: 10 } } );
	await page.waitForTimeout( 300 );

	// Modal should now be hidden
	await expect( page.locator( '#mcp-helper-modal' ) ).toBeHidden();
} );
