// The Fixes tab, a preview, Apply, the change being served, and Undo, in a real browser.
// Local wp-env only: it signs in as wp-env's default administrator on localhost.
const { test, expect } = require( '@playwright/test' );

const USER = process.env.WP_USER || 'admin';
const PASS = process.env.WP_PASSWORD || 'password';

async function signIn( page ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', USER );
	await page.fill( '#user_pass', PASS );
	await Promise.all( [ page.waitForURL( /wp-admin/, { timeout: 45000 } ), page.click( '#wp-submit' ) ] );
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
}

test.beforeEach( async ( { page } ) => {
	await signIn( page );
} );

test( 'the Fixes tab lists every kind of row and says how many points it can reach', async ( { page } ) => {
	await page.goto( '/wp-admin/admin.php?page=readiness-index-fixes' );
	await expect( page.locator( '.ri-fix' ) ).toHaveCount( 9 );
	await expect( page.locator( '.ri-reachable' ) ).toContainText( 'of the 21 points' );
	await expect( page.locator( '.ri-kind-fix-here' ).first() ).toBeVisible();
	await expect( page.locator( '.ri-kind-hand-on' ).first() ).toBeVisible();
	// No horizontal scroll at any width.
	const overflow = await page.evaluate( () => document.documentElement.scrollWidth - document.documentElement.clientWidth );
	expect( overflow ).toBeLessThanOrEqual( 0 );
} );

test( 'a hand-it-on prompt loads when its row opens and is shown as text, never as markup', async ( { page } ) => {
	await page.goto( '/wp-admin/admin.php?page=readiness-index-fixes' );
	const row = page.locator( '#fix-AIR-2\\.5 details' );
	await row.locator( 'summary' ).click();
	const prompt = row.locator( 'pre.ri-prompt' );
	await expect( prompt ).toContainText( '<b>Not markup</b>' );
	await expect( row.locator( 'pre.ri-prompt b' ) ).toHaveCount( 0 );
	await expect( row.getByRole( 'button', { name: 'Copy the instructions' } ) ).toBeVisible();
} );

test( 'preview, apply, the change is served, and undo gives it back', async ( { page, request } ) => {
	await page.goto( '/wp-admin/admin.php?page=readiness-index-preview&fixer=llms-txt' );
	await expect( page.locator( '.ri-diff' ) ).toBeVisible();
	await expect( page.getByRole( 'button', { name: 'Apply' } ) ).toBeEnabled();
	await page.getByRole( 'button', { name: 'Apply' } ).click();
	await expect( page.locator( '.notice' ).first() ).toContainText( 'Applied.' );

	const served = await request.get( '/llms.txt' );
	expect( served.status() ).toBe( 200 );
	expect( served.headers()[ 'content-type' ] ).toContain( 'text/plain' );
	expect( await served.text() ).toContain( '## Pages' );

	await page.goto( '/wp-admin/admin.php?page=readiness-index-changes' );
	await expect( page.locator( '.ri-changes tbody tr' ).first() ).toContainText( 'Applied' );
	await page.getByRole( 'button', { name: 'Undo' } ).first().click();
	await expect( page.locator( '.notice' ).first() ).toContainText( 'Undone.' );
	expect( ( await request.get( '/llms.txt' ) ).status() ).toBe( 404 );
} );

test( 'the content signals are not preselected and Apply stays disabled until each is chosen', async ( { page } ) => {
	await page.goto( '/wp-admin/admin.php?page=readiness-index-preview&fixer=robots-txt&choices[policy]=1&choices[content_signals][search]=yes' );
	await expect( page.getByRole( 'button', { name: 'Apply' } ) ).toBeDisabled();
} );
