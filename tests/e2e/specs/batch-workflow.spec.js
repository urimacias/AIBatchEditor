// @ts-check
const { test, expect } = require( '@playwright/test' );

const pageTitle = `AIBatchEditor_E2E_${ Date.now() }`;

test.describe( 'AIBatchEditor server batch workflow', () => {
	test.beforeAll( async ( { request } ) => {
		const baseURL = process.env.MW_E2E_BASE_URL || 'http://localhost:8080';
		const tokenResponse = await request.get(
			`${ baseURL }/api.php?action=query&meta=tokens&type=csrf&format=json`
		);
		const tokenData = await tokenResponse.json();
		const csrfToken = tokenData.query.tokens.csrftoken;

		const createResponse = await request.post( `${ baseURL }/api.php`, {
			form: {
				action: 'edit',
				title: pageTitle,
				text: 'Artículo de prueba para E2E del editor por lotes.',
				summary: 'E2E setup',
				token: csrfToken,
				format: 'json'
			}
		} );
		const createData = await createResponse.json();
		expect( createData.edit?.result ).toBe( 'Success' );
	} );

	test( 'validate, server-batch draft, approve, and save', async ( { page } ) => {
		page.on( 'dialog', ( dialog ) => dialog.accept() );

		await page.goto( '/index.php?title=Special:AIBatchEditor' );
		await expect( page.locator( '.ext-aibatcheditor-app' ) ).toBeVisible();

		await page.getByRole( 'textbox', { name: 'Títulos*' } ).fill( pageTitle );
		await page.getByRole( 'button', { name: 'Validar páginas' } ).click();
		await expect( page.locator( '.ext-aibatcheditor-operation-selector' ) ).toBeVisible();

		await page.locator( '.ext-aibatcheditor-operation-selector' )
			.getByRole( 'combobox' )
			.first()
			.click();
		await page.getByRole( 'option', { name: 'Corrección ortográfica' } ).click();

		await page.getByRole( 'textbox', { name: 'Resumen de edición*' } )
			.fill( 'Prueba E2E AIBatchEditor' );

		await page.getByRole( 'button', { name: 'Redactar' } ).click();
		const displayTitle = pageTitle.replace( /_/g, ' ' );
		await expect(
			page.locator( '.ext-aibatcheditor-batch-results__page', { hasText: displayTitle } )
		).toContainText( 'Cambiada', { timeout: 60000 } );

		await page.getByRole( 'button', { name: 'Previsualizar diff' } ).click();
		await expect( page.locator( '.ext-aibatcheditor-diff-viewer__content' ) ).toBeVisible();

		await page.getByRole( 'checkbox', { name: 'Aprobar este cambio' } ).check();

		const apiActions = [];
		page.on( 'request', ( request ) => {
			const url = request.url();
			if ( !url.includes( '/api.php' ) ) {
				return;
			}
			const postData = request.postData() || '';
			const match = postData.match( /(?:^|&)action=([^&]+)/ );
			if ( match ) {
				apiActions.push( match[ 1 ] );
			}
		} );

		await page.getByRole( 'button', { name: 'Guardar cambios aprobados' } ).click();

		await expect.poll( () => {
			const refreshIndex = apiActions.indexOf( 'aibatcheditorrefreshdrafttokens' );
			const saveIndex = apiActions.indexOf( 'aibatcheditorsave' );
			return refreshIndex !== -1 && saveIndex !== -1 && refreshIndex < saveIndex;
		} ).toBe( true );

		await expect(
			page.locator( '.ext-aibatcheditor-batch-results__post-save' )
		).toBeVisible( { timeout: 30000 } );
		await expect( page.getByRole( 'link', { name: /Ver revisión/ } ) ).toBeVisible();
	} );
} );