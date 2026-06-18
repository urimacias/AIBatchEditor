// @ts-check
const { request } = require( '@playwright/test' );
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

	const api = await request.newContext( { baseURL } );
	const tokenResponse = await api.get(
		'/api.php?action=query&meta=tokens&type=login&format=json'
	);
	const tokenData = await tokenResponse.json();
	const loginToken = tokenData.query.tokens.logintoken;

	const loginResponse = await api.post( '/api.php', {
		form: {
			action: 'login',
			lgname: user,
			lgpassword: password,
			lgtoken: loginToken,
			format: 'json'
		}
	} );
	const loginData = await loginResponse.json();

	if ( loginData.login?.result !== 'Success' ) {
		await api.dispose();
		throw new Error(
			'E2E login failed: ' + ( loginData.login?.reason || JSON.stringify( loginData ) )
		);
	}

	await api.storageState( { path: storagePath } );
	await api.dispose();
};