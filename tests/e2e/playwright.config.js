// @ts-check
const { defineConfig } = require( '@playwright/test' );

const baseURL = process.env.MW_E2E_BASE_URL || 'http://localhost:8080';

module.exports = defineConfig( {
	testDir: './specs',
	timeout: 120000,
	expect: { timeout: 30000 },
	fullyParallel: false,
	retries: process.env.CI ? 1 : 0,
	use: {
		baseURL,
		trace: 'on-first-retry',
		storageState: 'storageState.json'
	},
	globalSetup: require.resolve( './global-setup.js' )
} );