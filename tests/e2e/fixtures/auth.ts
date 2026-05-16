import { Page, expect } from '@playwright/test';

/**
 * Default wp-env admin credentials. Override via env vars when running
 * against a different environment.
 */
export const ADMIN_USER = process.env.SYBGO_ADMIN_USER ?? 'admin';
export const ADMIN_PASS = process.env.SYBGO_ADMIN_PASS ?? 'password';

/**
 * Log in as the WordPress administrator. Idempotent: if a session cookie is
 * already set, navigation hits /wp-admin/ directly and skips the form.
 */
export async function loginAsAdmin( page: Page ): Promise<void> {
	await page.goto( '/wp-login.php' );

	// Already logged in? Cookie redirects to wp-admin.
	if ( page.url().includes( '/wp-admin/' ) ) {
		return;
	}

	await page.getByLabel( 'Username or Email Address' ).fill( ADMIN_USER );
	await page.getByLabel( 'Password', { exact: true } ).fill( ADMIN_PASS );
	await page.getByRole( 'button', { name: 'Log In' } ).click();

	await expect( page ).toHaveURL( /\/wp-admin\/?/ );
}
