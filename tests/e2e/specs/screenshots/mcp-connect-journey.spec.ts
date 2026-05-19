/**
 * MCP Connect Helper — User Journey Screenshots
 *
 * Captures every step of the demo flow for the product team.
 * Outputs to tests/e2e/screenshots/mcp-journey/
 */

import { test, expect, Page } from '@playwright/test';
import { loginAsAdmin } from '../../fixtures/auth';
import path from 'path';
import fs from 'fs';

const OUT_DIR = path.resolve( __dirname, '../../screenshots/mcp-journey' );

// Correct WordPress IDs (underscores, not dashes).
const NAME_INPUT = '#new_application_password_name';
const ADD_BUTTON  = '#do_new_application_password';
const NOTICE_SEL  = '.new-application-password-notice';

test.use( { viewport: { width: 1280, height: 800 } } );

async function shot( page: Page, name: string ) {
	fs.mkdirSync( OUT_DIR, { recursive: true } );
	await page.screenshot( { path: path.join( OUT_DIR, name ) } );
}

async function goToProfile( page: Page ) {
	await page.goto( '/wp-admin/profile.php', { waitUntil: 'networkidle' } );
	await page.waitForSelector( '#application-passwords-section', { timeout: 15000 } );
	await page.locator( '#application-passwords-section' ).scrollIntoViewIfNeeded();
}

async function createPassword( page: Page, label = 'Demo AI Connection' ) {
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
	await page.waitForTimeout( 600 ); // let MutationObserver fire
}

// ── Tests ─────────────────────────────────────────────────────────────────────

test( '01 — Profile page: Application Passwords section', async ( { page } ) => {
	await loginAsAdmin( page );
	await goToProfile( page );
	await shot( page, '01-profile-app-passwords-section.png' );
} );

test( '02 — New password row with "Connect to AI" button', async ( { page } ) => {
	await loginAsAdmin( page );
	await goToProfile( page );
	await createPassword( page );
	await page.waitForSelector( '.mcp-helper-connect-btn', { timeout: 8000 } );
	await shot( page, '02-new-password-with-connect-button.png' );
} );

test( '03 — Modal opens — Claude Desktop tab', async ( { page } ) => {
	await loginAsAdmin( page );
	await goToProfile( page );
	await createPassword( page );
	await page.waitForSelector( '.mcp-helper-connect-btn', { timeout: 8000 } );
	await page.locator( '.mcp-helper-connect-btn' ).click();
	await page.waitForSelector( '#mcp-helper-modal', { state: 'visible', timeout: 5000 } );
	await page.waitForTimeout( 300 );
	await shot( page, '03-modal-claude-desktop.png' );
} );

test( '04 — Modal: GitHub Copilot tab', async ( { page } ) => {
	await loginAsAdmin( page );
	await goToProfile( page );
	await createPassword( page );
	await page.waitForSelector( '.mcp-helper-connect-btn', { timeout: 8000 } );
	await page.locator( '.mcp-helper-connect-btn' ).click();
	await page.waitForSelector( '#mcp-helper-modal', { state: 'visible', timeout: 5000 } );
	await page.locator( '.mcp-helper-tab-btn[data-tool="github-copilot"]' ).click();
	await page.waitForTimeout( 300 );
	await shot( page, '04-modal-github-copilot.png' );
} );

test( '05 — Copy button "Copied!" feedback', async ( { page } ) => {
	await loginAsAdmin( page );
	await goToProfile( page );
	await createPassword( page );
	await page.waitForSelector( '.mcp-helper-connect-btn', { timeout: 8000 } );
	await page.locator( '.mcp-helper-connect-btn' ).click();
	await page.waitForSelector( '#mcp-helper-modal', { state: 'visible', timeout: 5000 } );
	await page.waitForTimeout( 300 );
	await page.context().grantPermissions( [ 'clipboard-write' ] );
	await page.locator( '#mcp-helper-copy-btn' ).click();
	await page.waitForTimeout( 300 );
	await shot( page, '05-copy-feedback.png' );
} );

test( '06 — JSON contains correct WP API URL and username', async ( { page } ) => {
	await loginAsAdmin( page );
	await goToProfile( page );
	await createPassword( page );
	await page.waitForSelector( '.mcp-helper-connect-btn', { timeout: 8000 } );
	await page.locator( '.mcp-helper-connect-btn' ).click();
	await page.waitForSelector( '#mcp-helper-modal', { state: 'visible', timeout: 5000 } );
	await page.waitForTimeout( 300 );

	const jsonText = await page.locator( '#mcp-helper-json-output' ).textContent() ?? '{}';
	const config = JSON.parse( jsonText );

	expect( config ).toHaveProperty( 'mcpServers' );
	expect( config.mcpServers[ 'my-plugin' ].env.WP_API_URL ).toContain( '/wp-json/mcp/mcp-adapter-default-server' );
	expect( config.mcpServers[ 'my-plugin' ].env.WP_API_USERNAME ).toBe( 'admin' );
	expect( config.mcpServers[ 'my-plugin' ].env.WP_API_PASSWORD ).not.toBe( '' );

	await shot( page, '06-json-verified.png' );
} );
