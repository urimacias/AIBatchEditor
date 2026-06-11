( function () {
	'use strict';

	const Vue = require( 'vue' );
	const App = require( './App.vue' );

	const mountNode = document.getElementById( 'ext-aibatcheditor-root' );
	if ( !mountNode ) {
		return;
	}

	const config = mw.config.get( 'wgAIBatchEditor', {
		maxBatch: 50,
		defaultProfile: 'balanced',
		concurrency: 3,
		enabledOperations: {
			wikilinks: true,
			spellcheck: true,
			formatting: true,
			style: true,
			custom: true,
			templates: true
		},
		templateSourceWiki: 'https://es.wikipedia.org',
		promptPreview: true
	} );

	mountNode.textContent = '';
	Vue.createMwApp( App, { config } ).mount( mountNode );
}() );