// @ts-check
const { chromium } = require( '@playwright/test' );
const fs = require( 'fs' );
const path = require( 'path' );

module.exports = async () => {
	const baseURL = process.env.MW_E2E_BASE_URL || 'http://localhost:8080';
	const user = process.env.MW_E2E_USER || '';
	const password = process.env.MW_E2E_PASSWORD || '';
	const storagePath = path.join( __dirname, 'storageState.json' );

	if ( !user || !password ) {
		throw new Error(
			'Set MW_E2E_USER and MW_E2E_PASSWORD (sysop account) before running browser tests.'
		);
	}

	const browser = await chromium.launch();
	const context = await browser.newContext();
	const page = await context.newPage();

	await page.goto( `${ baseURL }/index.php?title=Special:UserLogin` );
	await page.locator( '#wpName1' ).fill( user );
	await page.locator( '#wpPassword1' ).fill( password );
	await page.locator( '#wpLoginAttempt' ).click();
	await page.waitForURL( /Special:UserLogin|title=/ );

	if ( page.url().includes( 'Special:UserLogin' ) ) {
		await browser.close();
		throw new Error( 'E2E login failed. Check MW_E2E_USER and MW_E2E_PASSWORD.' );
	}

	await context.storageState( { path: storagePath } );
	await browser.close();
};