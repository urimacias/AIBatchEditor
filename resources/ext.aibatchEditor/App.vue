<template>
	<div class="ext-aibatcheditor-app">
		<cdx-message
			v-if="globalError"
			type="error"
			:inline="true"
			class="ext-aibatcheditor-app__message"
		>
			{{ globalError }}
		</cdx-message>

		<cdx-message
			v-if="globalNotice"
			type="notice"
			:inline="true"
			class="ext-aibatcheditor-app__message"
		>
			{{ globalNotice }}
		</cdx-message>

		<page-picker
			:disabled="running || validating"
			@validate="onValidate"
			@update:selection="onSelectionUpdate"
		></page-picker>

		<operation-selector
			v-if="validatedPages.length > 0"
			:enabled-operations="config.enabledOperations"
			:default-profile="config.defaultProfile"
			:operation-profiles="config.operationProfiles || {}"
			:run-disabled="runDisabled"
			@run="onRun"
			@update:options="onOptionsUpdate"
		></operation-selector>

		<batch-results
			:pages="resultPages"
			:running="running"
			:saving="saving"
			:progress-percent="progressPercent"
			@toggle-approve="onToggleApprove"
			@approve-all="onApproveAll"
			@save-approved="onSaveApproved"
			@update-page-instructions="onUpdatePageInstructions"
			@redraft-page="onRedraftPage"
			@retry-errors="onRetryErrors"
		></batch-results>
	</div>
</template>

<script>
const { defineComponent, ref, computed } = require( 'vue' );
const { CdxMessage } = require( './codex.js' );
const PagePicker = require( './components/PagePicker.vue' );
const OperationSelector = require( './components/OperationSelector.vue' );
const BatchResults = require( './components/BatchResults.vue' );
const api = require( './api.js' );

module.exports = exports = defineComponent( {
	name: 'AIBatchEditorApp',
	components: {
		CdxMessage,
		PagePicker,
		OperationSelector,
		BatchResults
	},
	props: {
		config: {
			type: Object,
			required: true
		}
	},
	setup( props ) {
		const selection = ref( {
			inputMode: 'titles',
			titles: '',
			category: '',
			prefix: ''
		} );
		const options = ref( {
			operation: '',
			profile: props.config.defaultProfile || 'balanced',
			instructions: '',
			summary: ''
		} );

		const validatedPages = ref( [] );
		const resultPages = ref( [] );
		const validating = ref( false );
		const running = ref( false );
		const saving = ref( false );
		const progressPercent = ref( 0 );
		const globalError = ref( '' );
		const globalNotice = ref( '' );

		const runDisabled = computed( () => (
			running.value ||
			validating.value ||
			validatedPages.value.length === 0 ||
			!options.value.operation
		) );

		const onSelectionUpdate = ( value ) => {
			selection.value = value;
		};

		const onOptionsUpdate = ( value ) => {
			options.value = value;
		};

		const listParams = () => {
			const params = {};
			if ( selection.value.inputMode === 'category' ) {
				params.category = selection.value.category.trim();
				if ( selection.value.prefix.trim() ) {
					params.prefix = selection.value.prefix.trim();
				}
			} else {
				params.titles = selection.value.titles.trim();
			}
			return params;
		};

		const getPageInstructions = ( title ) => {
			const row = resultPages.value.find( ( item ) => item.title === title );
			const pageSpecific = row && row.pageInstructions ? row.pageInstructions.trim() : '';
			return pageSpecific || options.value.instructions.trim();
		};

		const validateInstructions = () => {
			if ( options.value.operation === 'custom' && !options.value.instructions.trim() ) {
				globalError.value = mw.msg( 'aibatcheditor-error-custom-needs-instructions' );
				return false;
			}
			return true;
		};

		const onValidate = () => {
			globalError.value = '';
			globalNotice.value = '';
			validating.value = true;
			resultPages.value = [];

			api.listPages( listParams() )
				.then( ( data ) => {
					const pages = data.pages || [];
					const maxPageSize = data.maxPageSize || props.config.maxPageSize || 0;
					validatedPages.value = pages.filter( ( page ) => page.exists && page.editable && !page.error );

					const skipped = pages.length - validatedPages.value.length;
					const tooLargeCount = pages.filter( ( page ) => page.error === 'too-large' ).length;
					if ( validatedPages.value.length === 0 ) {
						globalError.value = mw.msg( 'aibatcheditor-ui-no-valid-pages' );
					} else if ( data.categoryTruncated ) {
						globalNotice.value = mw.msg(
							'aibatcheditor-ui-category-truncated',
							data.categoryLoaded,
							data.categoryTotal,
							data.maxBatch
						);
					} else if ( tooLargeCount > 0 && maxPageSize > 0 ) {
						globalNotice.value = mw.msg(
							'aibatcheditor-ui-too-large-skipped',
							tooLargeCount,
							maxPageSize
						);
						if ( skipped > tooLargeCount ) {
							globalNotice.value += ' ' + mw.msg(
								'aibatcheditor-ui-validated-notice',
								validatedPages.value.length,
								skipped - tooLargeCount
							);
						}
					} else if ( skipped > 0 ) {
						globalNotice.value = mw.msg( 'aibatcheditor-ui-validated-notice', validatedPages.value.length, skipped );
					} else {
						globalNotice.value = mw.msg( 'aibatcheditor-ui-validated-count', validatedPages.value.length );
					}

					resultPages.value = pages.map( ( page ) => {
						let detail = '';
						if ( page.error === 'too-large' && maxPageSize > 0 ) {
							detail = mw.msg( 'aibatcheditor-page-error-too-large', page.size, maxPageSize );
						} else if ( page.error ) {
							detail = api.formatError( 'aibatcheditor-page-error-' + page.error );
						}
						return {
							title: page.title,
							status: page.exists && page.editable && !page.error ? 'pending' : 'skipped',
							detail,
							pageInstructions: ''
						};
					} );
				} )
				.catch( ( code, data ) => {
					validatedPages.value = [];
					globalError.value = data && data.error ? data.error.info : api.formatError( code );
				} )
				.always( () => {
					validating.value = false;
				} );
		};

		const updateResultRow = ( title, patch ) => {
			const index = resultPages.value.findIndex( ( row ) => row.title === title );
			if ( index !== -1 ) {
				resultPages.value[ index ] = Object.assign( {}, resultPages.value[ index ], patch );
			}
		};

		const applyPageResult = ( pageResult ) => {
			const status = pageResult.status || 'error';
			let detail = '';

			if ( status === 'error' ) {
				detail = api.formatError( pageResult.error, pageResult );
			} else if ( status === 'omitted' ) {
				detail = mw.msg( 'aibatcheditor-ui-status-omitted-detail' );
			} else if ( status === 'changed' ) {
				detail = mw.msg( 'aibatcheditor-ui-status-changed-detail' );
			}

			const patch = { status, detail };
			if ( status === 'changed' ) {
				patch.original = pageResult.original || '';
				patch.proposed = pageResult.proposed || '';
				patch.revid = pageResult.revid || null;
				patch.approved = true;
			} else {
				patch.approved = false;
				patch.proposed = null;
			}
			updateResultRow( pageResult.title, patch );
		};

		const processOnePage = ( title ) => {
			const instructions = getPageInstructions( title );
			if ( options.value.operation === 'custom' && !instructions ) {
				updateResultRow( title, {
					status: 'error',
					detail: mw.msg( 'aibatcheditor-error-custom-needs-instructions' )
				} );
				return $.Deferred().resolve().promise();
			}

			updateResultRow( title, {
				status: 'processing',
				detail: mw.msg( 'aibatcheditor-ui-status-processing' )
			} );

			const processParams = {
				titles: title,
				operation: options.value.operation,
				profile: options.value.profile
			};
			if ( instructions ) {
				processParams.instructions = instructions;
			}

			return api.processPage( processParams )
				.then( ( data ) => {
					const result = data.aibatcheditorprocess || {};
					const pageResult = ( result.pages || [] )[ 0 ] || {};
					pageResult.title = pageResult.title || title;
					applyPageResult( pageResult );
				} )
				.catch( ( code, data ) => {
					updateResultRow( title, {
						status: 'error',
						detail: data && data.error ? data.error.info : api.formatError( code )
					} );
				} );
		};

		const runParallel = ( titles, completeMessageKey ) => {
			const concurrency = Math.max( 1, props.config.concurrency || 3 );
			let completed = 0;
			let nextIndex = 0;
			let active = 0;
			const doneMessage = completeMessageKey || 'aibatcheditor-ui-run-complete';

			const pump = () => {
				while ( active < concurrency && nextIndex < titles.length ) {
					const title = titles[ nextIndex ];
					nextIndex++;
					active++;
					processOnePage( title ).always( () => {
						active--;
						completed++;
						progressPercent.value = Math.round( ( completed / titles.length ) * 100 );
						if ( completed >= titles.length ) {
							running.value = false;
							globalNotice.value = mw.msg( doneMessage );
						} else {
							pump();
						}
					} );
				}
			};

			pump();
		};

		const onRun = () => {
			if ( runDisabled.value || !validateInstructions() ) {
				return;
			}

			globalError.value = '';
			globalNotice.value = mw.msg( 'aibatcheditor-ui-run-started' );
			running.value = true;
			progressPercent.value = 0;

			const titles = validatedPages.value.map( ( page ) => page.title );
			resultPages.value = titles.map( ( title ) => {
				const existing = resultPages.value.find( ( row ) => row.title === title );
				return {
					title,
					status: 'pending',
					detail: '',
					pageInstructions: existing ? ( existing.pageInstructions || '' ) : ''
				};
			} );

			runParallel( titles );
		};

		const onRedraftPage = ( title ) => {
			if ( running.value || saving.value || !validateInstructions() ) {
				return;
			}
			running.value = true;
			progressPercent.value = 0;
			processOnePage( title ).always( () => {
				running.value = false;
				progressPercent.value = 100;
			} );
		};

		const onRetryErrors = () => {
			if ( running.value || saving.value || !validateInstructions() ) {
				return;
			}

			const titles = resultPages.value
				.filter( ( row ) => row.status === 'error' )
				.map( ( row ) => row.title );

			if ( titles.length === 0 ) {
				return;
			}

			globalError.value = '';
			globalNotice.value = mw.msg( 'aibatcheditor-ui-retry-started' );
			running.value = true;
			progressPercent.value = 0;

			titles.forEach( ( title ) => {
				updateResultRow( title, {
					status: 'pending',
					detail: '',
					approved: false,
					proposed: null
				} );
			} );

			runParallel( titles, 'aibatcheditor-ui-retry-complete' );
		};

		const onToggleApprove = ( title, approved ) => {
			updateResultRow( title, { approved } );
		};

		const onApproveAll = ( approved ) => {
			resultPages.value = resultPages.value.map( ( row ) => (
				row.status === 'changed' ? Object.assign( {}, row, { approved } ) : row
			) );
		};

		const onUpdatePageInstructions = ( title, value ) => {
			updateResultRow( title, { pageInstructions: value } );
		};

		const onSaveApproved = () => {
			const summary = options.value.summary.trim();
			if ( !summary ) {
				globalError.value = mw.msg( 'aibatcheditor-ui-save-summary-required' );
				return;
			}

			const approved = resultPages.value.filter( ( row ) => (
				row.status === 'changed' && row.approved && row.proposed && row.revid
			) );

			if ( approved.length === 0 ) {
				globalError.value = mw.msg( 'aibatcheditor-ui-save-none-selected' );
				return;
			}

			globalError.value = '';
			globalNotice.value = mw.msg( 'aibatcheditor-ui-save-started' );
			saving.value = true;
			progressPercent.value = 0;

			approved.forEach( ( row ) => {
				updateResultRow( row.title, {
					status: 'saving',
					detail: mw.msg( 'aibatcheditor-ui-status-saving' )
				} );
			} );

			const edits = approved.map( ( row ) => ( {
				title: row.title,
				revid: row.revid,
				proposed: row.proposed
			} ) );

			api.saveEdits( {
				summary,
				edits: JSON.stringify( edits )
			} )
				.then( ( data ) => {
					const result = data.aibatcheditorsave || {};
					const pages = result.pages || [];
					pages.forEach( ( pageResult ) => {
						const status = pageResult.status || 'error';
						let detail = '';

						if ( status === 'saved' ) {
							detail = mw.msg( 'aibatcheditor-ui-status-saved-detail' );
						} else if ( status === 'omitted' ) {
							detail = mw.msg( 'aibatcheditor-ui-status-omitted-detail' );
						} else if ( status === 'error' ) {
							detail = api.formatError( pageResult.error, pageResult );
						}

						updateResultRow( pageResult.title, {
							status: status === 'error' ? 'save-error' : status,
							detail,
							approved: false,
							newrevid: pageResult.newrevid || null
						} );
					} );
					globalNotice.value = mw.msg( 'aibatcheditor-ui-save-complete' );
					progressPercent.value = 100;
				} )
				.catch( ( code, data ) => {
					const message = data && data.error ? data.error.info : api.formatError( code );
					approved.forEach( ( row ) => {
						updateResultRow( row.title, {
							status: 'save-error',
							detail: message
						} );
					} );
					globalError.value = message;
				} )
				.always( () => {
					saving.value = false;
				} );
		};

		return {
			validatedPages,
			resultPages,
			validating,
			running,
			saving,
			progressPercent,
			globalError,
			globalNotice,
			runDisabled,
			onSelectionUpdate,
			onOptionsUpdate,
			onValidate,
			onRun,
			onToggleApprove,
			onApproveAll,
			onSaveApproved,
			onUpdatePageInstructions,
			onRedraftPage,
			onRetryErrors
		};
	}
} );
</script>