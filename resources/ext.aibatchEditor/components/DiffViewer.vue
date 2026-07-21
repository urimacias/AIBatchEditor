<template>
	<div class="ext-aibatcheditor-diff-viewer">
		<div class="ext-aibatcheditor-diff-viewer__header">
			<span class="ext-aibatcheditor-diff-viewer__label">
				{{ $i18n( 'aibatcheditor-ui-diff-heading', title ).text() }}
			</span>
			<div class="ext-aibatcheditor-diff-viewer__actions">
				<cdx-button
					weight="quiet"
					:disabled="articlePreviewLoading"
					@click="openArticlePreview"
				>
					{{ articlePreviewLoading ?
						$i18n( 'aibatcheditor-ui-preview-article-loading' ).text() :
						$i18n( 'aibatcheditor-ui-preview-article' ).text() }}
				</cdx-button>
				<cdx-button
					v-if="expanded && !loading"
					weight="quiet"
					@click="collapse"
				>
					{{ $i18n( 'aibatcheditor-ui-hide-diff' ).text() }}
				</cdx-button>
				<cdx-button
					v-else-if="!loading && !expanded"
					weight="quiet"
					@click="loadDiff"
				>
					{{ $i18n( 'aibatcheditor-ui-preview-diff' ).text() }}
				</cdx-button>
			</div>
		</div>

		<div class="ext-aibatcheditor-diff-viewer__body">
			<div
				v-if="loading"
				class="ext-aibatcheditor-diff-viewer__loading"
			>
				<cdx-progress-indicator></cdx-progress-indicator>
			</div>

			<cdx-message
				v-if="error"
				type="error"
				:inline="true"
			>
				{{ error }}
			</cdx-message>

			<div
				v-if="expanded && diffHtml"
				class="ext-aibatcheditor-diff-viewer__content"
				v-html="diffHtml"
			></div>
		</div>
	</div>
</template>

<script>
const { defineComponent, ref } = require( 'vue' );
const { CdxButton, CdxMessage, CdxProgressIndicator } = require( '../codex.js' );
const api = require( '../api.js' );

module.exports = exports = defineComponent( {
	name: 'DiffViewer',
	components: {
		CdxButton,
		CdxMessage,
		CdxProgressIndicator
	},
	emits: [ 'diff-viewed' ],
	props: {
		title: {
			type: String,
			required: true
		},
		original: {
			type: String,
			required: true
		},
		proposed: {
			type: String,
			required: true
		},
		autoLoad: {
			type: Boolean,
			default: false
		}
	},
	setup( props, { emit } ) {
		const expanded = ref( false );
		const loading = ref( false );
		const articlePreviewLoading = ref( false );
		const diffHtml = ref( '' );
		const error = ref( '' );

		const loadDiff = () => {
			loading.value = true;
			error.value = '';

			api.fetchDiff( {
				title: props.title,
				original: props.original,
				proposed: props.proposed
			} )
				.then( ( data ) => {
					const result = data.aibatcheditordiff || {};
					diffHtml.value = result.html || '';
					expanded.value = true;
					emit( 'diff-viewed', props.title );
					if ( result.unchanged ) {
						error.value = mw.msg( 'aibatcheditor-ui-diff-unchanged' );
					}
				} )
				.catch( ( code, data ) => {
					error.value = api.formatApiError( code, data );
				} )
				.always( () => {
					loading.value = false;
				} );
		};

		/**
		 * Tiny static HTML for the interim preview tab (no external assets).
		 *
		 * @param {string} pageTitle
		 * @return {string}
		 */
		const buildPreviewLoadingHtml = ( pageTitle ) => {
			const escapeHtml = ( text ) => String( text )
				.replace( /&/g, '&amp;' )
				.replace( /</g, '&lt;' )
				.replace( />/g, '&gt;' )
				.replace( /"/g, '&quot;' );
			const heading = escapeHtml( mw.msg( 'aibatcheditor-ui-preview-article-loading' ) );
			const title = escapeHtml( pageTitle || '' );
			const label = escapeHtml( mw.msg( 'aibatcheditor-ui-preview-article' ) );
			const lang = escapeHtml( mw.config.get( 'wgUserLanguage' ) || 'en' );
			// Force an explicit light surface — about:blank often paints pure black in
			// Safari / dark-mode before any document is written.
			return `<!DOCTYPE html>
<html lang="${ lang }">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title>${ heading }</title>
<style>
html{color-scheme:light dark}
*{box-sizing:border-box;margin:0;padding:0}
html,body{min-height:100%;height:100%}
body{
	font:15px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
	color:#202122;
	background:#f0f2f5;
	display:flex;
	align-items:center;
	justify-content:center;
	padding:24px;
}
.card{
	width:min(420px,100%);
	background:#fff;
	border:1px solid #c8ccd1;
	border-radius:10px;
	padding:28px 24px 22px;
	text-align:center;
	box-shadow:0 2px 8px rgba(0,0,0,.06);
}
.spinner{
	width:36px;height:36px;margin:0 auto 16px;
	border:3px solid #eaecf0;
	border-top-color:#36c;
	border-radius:50%;
	animation:spin .7s linear infinite;
}
@keyframes spin{to{transform:rotate(360deg)}}
h1{font-size:1.05rem;font-weight:600;margin-bottom:6px;color:#202122}
.sub{color:#54595d;font-size:.9rem;word-break:break-word}
.badge{
	display:inline-block;margin-top:14px;padding:3px 8px;
	font-size:.75rem;color:#54595d;background:#f1f2f3;
	border-radius:999px;
}
@media (prefers-color-scheme:dark){
	body{background:#101418;color:#f8f9fa}
	.card{background:#202122;border-color:#54595d;box-shadow:none}
	h1{color:#f8f9fa}
	.sub,.badge{color:#c8ccd1}
	.badge{background:#2c2e30}
	.spinner{border-color:#54595d;border-top-color:#6d8af2}
}
@media (prefers-reduced-motion:reduce){.spinner{animation:none;border-top-color:#a2a9b1}}
</style>
</head>
<body>
<div class="card" role="status" aria-live="polite" aria-busy="true">
	<div class="spinner" aria-hidden="true"></div>
	<h1>${ heading }</h1>
	${ title ? `<p class="sub">${ title }</p>` : '' }
	<span class="badge">${ label }</span>
</div>
</body>
</html>`;
		};

		/**
		 * Open a same-origin-safe interim tab with real HTML (not about:blank).
		 * about:blank often stays black in Safari/dark mode when document.write races.
		 *
		 * @param {string} pageTitle
		 * @return {{ win: Window|null, revoke: function():void }}
		 */
		const openPreviewLoadingTab = ( pageTitle ) => {
			let objectUrl = '';
			try {
				const blob = new Blob(
					[ buildPreviewLoadingHtml( pageTitle ) ],
					{ type: 'text/html;charset=utf-8' }
				);
				objectUrl = URL.createObjectURL( blob );
			} catch ( e ) {
				objectUrl = '';
			}

			const win = window.open( objectUrl || 'about:blank', '_blank' );
			if ( !win ) {
				if ( objectUrl ) {
					URL.revokeObjectURL( objectUrl );
				}
				return {
					win: null,
					revoke: () => {}
				};
			}

			// Fallback if Blob URL unavailable (very old browsers).
			if ( !objectUrl ) {
				try {
					const doc = win.document;
					doc.open();
					doc.write( buildPreviewLoadingHtml( pageTitle ) );
					doc.close();
				} catch ( e ) {
					// Ignore.
				}
			}

			return {
				win,
				revoke: () => {
					if ( objectUrl ) {
						// Delay revoke until after navigation has started.
						window.setTimeout( () => {
							try {
								URL.revokeObjectURL( objectUrl );
							} catch ( e ) {
								// Ignore.
							}
						}, 60000 );
					}
				}
			};
		};

		const openArticlePreview = () => {
			error.value = '';
			// Open during the click gesture so browsers allow the tab.
			const opened = openPreviewLoadingTab( props.title );
			const previewWindow = opened.win;
			if ( !previewWindow ) {
				error.value = mw.msg( 'aibatcheditor-error-article-preview-popup-blocked' );
				return;
			}

			articlePreviewLoading.value = true;
			api.fetchArticlePreview( {
				title: props.title,
				proposed: props.proposed
			} )
				.then( ( data ) => {
					const result = data.aibatcheditorarticlepreview || {};
					const url = result.url || '';
					if ( !url ) {
						try {
							previewWindow.close();
						} catch ( e ) {
							// Ignore.
						}
						opened.revoke();
						error.value = mw.msg( 'aibatcheditor-error-api-empty' );
						return;
					}
					try {
						previewWindow.location.href = url;
					} catch ( e ) {
						// If the tab was closed or navigation blocked, open via top-level assign.
						window.location.assign( url );
					}
					opened.revoke();
					emit( 'diff-viewed', props.title );
				} )
				.catch( ( code, errData ) => {
					try {
						previewWindow.close();
					} catch ( e ) {
						// Ignore if the tab was already closed.
					}
					opened.revoke();
					error.value = api.formatApiError( code, errData );
				} )
				.always( () => {
					articlePreviewLoading.value = false;
				} );
		};

		const collapse = () => {
			expanded.value = false;
		};

		if ( props.autoLoad ) {
			loadDiff();
		}

		return {
			expanded,
			loading,
			articlePreviewLoading,
			diffHtml,
			error,
			loadDiff,
			openArticlePreview,
			collapse
		};
	}
} );
</script>