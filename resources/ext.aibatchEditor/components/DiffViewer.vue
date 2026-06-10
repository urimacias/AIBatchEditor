<template>
	<div class="ext-aibatcheditor-diff-viewer">
		<div class="ext-aibatcheditor-diff-viewer__header">
			<span class="ext-aibatcheditor-diff-viewer__label">
				{{ $i18n( 'aibatcheditor-ui-diff-heading', title ).text() }}
			</span>
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
			default: true
		}
	},
	setup( props ) {
		const expanded = ref( false );
		const loading = ref( false );
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
					if ( result.unchanged ) {
						error.value = mw.msg( 'aibatcheditor-ui-diff-unchanged' );
					}
				} )
				.catch( ( code, data ) => {
					error.value = data && data.error ? data.error.info : api.formatError( code );
				} )
				.always( () => {
					loading.value = false;
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
			diffHtml,
			error,
			loadDiff,
			collapse
		};
	}
} );
</script>