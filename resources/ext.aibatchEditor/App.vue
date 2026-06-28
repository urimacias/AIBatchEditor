<template>
	<div class="ext-aibatcheditor-app">
		<p class="ext-aibatcheditor-required-legend">
			{{ $i18n( 'aibatcheditor-ui-required-legend' ).text() }}
		</p>

		<div
			v-if="globalError || globalNotice"
			ref="alertsRef"
			class="ext-aibatcheditor-app__alerts"
		>
			<cdx-message
				v-if="llmNotConfigured"
				type="warning"
				:inline="false"
				class="ext-aibatcheditor-app__message"
			>
				{{ $i18n( 'aibatcheditor-error-llm-not-configured' ).text() }}
			</cdx-message>

			<cdx-message
				v-if="globalError"
				type="error"
				:inline="false"
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
		</div>

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
			:summary-error="summaryFieldError"
			:instructions-error="instructionsFieldError"
			:templates-error="templatesFieldError"
			:default-template-source="config.templateSourceWiki || 'https://es.wikipedia.org'"
			:prompt-preview-enabled="!!config.promptPreview"
			:preview-page-title="previewPageTitle"
			:validated-page-count="validatedPages.length"
			:rate-limit="rateLimit"
			@run="onRun"
			@update:options="onOptionsUpdate"
		></operation-selector>

		<batch-results
			:pages="resultPages"
			:running="running"
			:saving="saving"
			:progress-percent="progressPercent"
			:save-error="saveError"
			:prompt-preview-enabled="!!config.promptPreview"
			@toggle-approve="onToggleApprove"
			@approve-all="onApproveAll"
			@save-approved="onSaveApproved"
			@update-page-instructions="onUpdatePageInstructions"
			@redraft-page="onRedraftPage"
			@retry-errors="onRetryErrors"
			@cancel-batch="onCancelBatch"
			@diff-viewed="onDiffViewed"
			:cancelling="cancelling"
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
const errors = require( './errors.js' );

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
			template: '',
			prefix: ''
		} );
		const options = ref( {
			operation: '',
			profile: props.config.defaultProfile || 'balanced',
			instructions: '',
			summary: '',
			templates: '',
			templatesource: ''
		} );

		const validatedPages = ref( [] );
		const resultPages = ref( [] );
		const validating = ref( false );
		const running = ref( false );
		const cancelling = ref( false );
		const saving = ref( false );
		const progressPercent = ref( 0 );
		const alertsRef = ref( null );
		const globalError = ref( '' );
		const globalNotice = ref( '' );
		const saveError = ref( '' );
		const summaryFieldError = ref( false );
		const instructionsFieldError = ref( false );
		const templatesFieldError = ref( false );
		const rateLimit = ref( props.config.rateLimit || {
			limit: 0,
			used: 0,
			remaining: 0
		} );

		const previewPageTitle = computed( () => (
			validatedPages.value[ 0 ] ? validatedPages.value[ 0 ].title : ''
		) );

		const runDisabled = computed( () => (
			running.value ||
			validating.value ||
			validatedPages.value.length === 0 ||
			!options.value.operation ||
			!props.config.llmConfigured ||
			(
				rateLimit.value.limit > 0 &&
				validatedPages.value.length > rateLimit.value.remaining
			)
		) );

		const llmNotConfigured = computed( () => !props.config.llmConfigured );

		const onSelectionUpdate = ( value ) => {
			selection.value = value;
		};

		const emphasizeAlerts = () => {
			const target = alertsRef.value || '.ext-aibatcheditor-app__alerts';
			errors.scrollToAndEmphasize( target, 'ext-aibatcheditor-app__alerts--emphasized' );
		};

		const emphasizeSaveError = () => {
			errors.scrollToAndEmphasize(
				'.ext-aibatcheditor-batch-results__save-error',
				'ext-aibatcheditor-batch-results__save-error--emphasized'
			);
		};

		const showGlobalError = ( message, fieldErrors ) => {
			globalNotice.value = '';
			globalError.value = message;
			summaryFieldError.value = !!( fieldErrors && fieldErrors.summary );
			instructionsFieldError.value = !!( fieldErrors && fieldErrors.instructions );
			templatesFieldError.value = !!( fieldErrors && fieldErrors.templates );
			emphasizeAlerts();
		};

		const clearFieldErrors = () => {
			summaryFieldError.value = false;
			instructionsFieldError.value = false;
			templatesFieldError.value = false;
		};

		const onOptionsUpdate = ( value ) => {
			options.value = value;
			if ( value.summary && value.summary.trim() ) {
				summaryFieldError.value = false;
				if ( saveError.value ) {
					saveError.value = '';
				}
			}
			if ( value.instructions && value.instructions.trim() ) {
				instructionsFieldError.value = false;
			}
			if ( value.templates && value.templates.trim() ) {
				templatesFieldError.value = false;
			}
		};

		const listParams = () => {
			const params = {};
			if ( selection.value.inputMode === 'category' ) {
				params.category = selection.value.category.trim();
				if ( selection.value.prefix.trim() ) {
					params.prefix = selection.value.prefix.trim();
				}
			} else if ( selection.value.inputMode === 'template' ) {
				params.template = selection.value.template.trim();
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
				showGlobalError(
					mw.msg( 'aibatcheditor-error-custom-needs-instructions' ),
					{ instructions: true }
				);
				errors.scrollToAndEmphasize( '.ext-aibatcheditor-operation-selector__instructions-field' );
				return false;
			}
			if ( options.value.operation === 'templates' && !options.value.templates.trim() ) {
				showGlobalError(
					mw.msg( 'aibatcheditor-error-templates-needs-names' ),
					{ templates: true }
				);
				errors.scrollToAndEmphasize( '.ext-aibatcheditor-operation-selector__templates-field' );
				return false;
			}
			return true;
		};

		const onValidate = () => {
			globalError.value = '';
			globalNotice.value = '';
			clearFieldErrors();
			validating.value = true;
			resultPages.value = [];

			api.listPages( listParams() )
				.then( ( data ) => {
					const pages = api.normalizeList( data.pages );
					if ( data.rateLimit ) {
						rateLimit.value = {
							limit: data.rateLimit.limit || 0,
							used: data.rateLimit.used || 0,
							remaining: data.rateLimit.remaining || 0
						};
					}
					const maxPageSize = data.maxPageSize || props.config.maxPageSize || 0;
					validatedPages.value = pages.filter( ( page ) => page.exists && page.editable && !page.error );

					const skipped = pages.length - validatedPages.value.length;
					const tooLargeCount = pages.filter( ( page ) => page.error === 'too-large' ).length;
					if ( validatedPages.value.length === 0 ) {
						showGlobalError( mw.msg( 'aibatcheditor-ui-no-valid-pages' ) );
					} else if ( data.categoryTruncated || data.templateTruncated ) {
						const loaded = data.categoryTruncated ? data.categoryLoaded : data.templateLoaded;
						const total = data.categoryTruncated ? data.categoryTotal : data.templateTotal;
						globalNotice.value = mw.msg(
							'aibatcheditor-ui-category-truncated',
							loaded,
							total,
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
					showGlobalError( api.formatApiError( code, data ) );
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

		const getChangedRow = ( title ) => resultPages.value.find( ( row ) => (
			row.title === title && row.status === 'changed'
		) );

		const hasWarnings = ( row ) => Array.isArray( row.warnings ) && row.warnings.length > 0;

		const confirmApprove = ( rows ) => {
			const unviewed = rows.filter( ( row ) => !row.diffViewed );
			if ( unviewed.length > 0 ) {
				const messageKey = rows.length > 1 ?
					'aibatcheditor-ui-approve-all-without-diff-confirm' :
					'aibatcheditor-ui-approve-without-diff-confirm';
				if ( !window.confirm( mw.msg( messageKey, unviewed.length ) ) ) {
					return false;
				}
			}

			const warned = rows.filter( ( row ) => hasWarnings( row ) );
			if ( warned.length > 0 ) {
				const messageKey = rows.length > 1 ?
					'aibatcheditor-ui-approve-all-with-warnings-confirm' :
					'aibatcheditor-ui-approve-with-warnings-confirm';
				if ( !window.confirm( mw.msg( messageKey, warned.length ) ) ) {
					return false;
				}
			}

			return true;
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
				const existing = resultPages.value.find( ( row ) => row.title === pageResult.title );
				const incomingToken = pageResult.draftToken || '';
				patch.draftToken = incomingToken || ( existing ? ( existing.draftToken || '' ) : '' );
				patch.warnings = pageResult.warnings || [];
				patch.promptSystem = pageResult.promptSystem || '';
				patch.promptUser = pageResult.promptUser || '';
				patch.approved = false;
				patch.diffViewed = false;
			} else {
				patch.approved = false;
				patch.proposed = null;
				patch.draftToken = '';
				patch.warnings = [];
				patch.diffViewed = false;
			}
			updateResultRow( pageResult.title, patch );
		};

		let batchPollTimer = null;
		let batchCancelled = false;
		const currentBatchId = ref( '' );

		const stopBatchPoll = () => {
			if ( batchPollTimer ) {
				clearTimeout( batchPollTimer );
				batchPollTimer = null;
			}
		};

		const markUnprocessedAsCancelled = () => {
			const detail = mw.msg( 'aibatcheditor-ui-status-cancelled-detail' );
			resultPages.value = resultPages.value.map( ( row ) => (
				row.status === 'pending' || row.status === 'processing' ?
					Object.assign( {}, row, {
						status: 'cancelled',
						detail,
						approved: false,
						proposed: null,
						draftToken: ''
					} ) :
					row
			) );
		};

		const buildPageInstructionsPayload = ( titles ) => {
			const map = {};
			const batchInstructions = options.value.instructions.trim();
			titles.forEach( ( title ) => {
				const instructions = getPageInstructions( title );
				if ( !instructions ) {
					return;
				}
				if ( options.value.operation === 'custom' || instructions !== batchInstructions ) {
					map[ title ] = instructions;
				}
			} );
			return Object.keys( map ).length ? JSON.stringify( map ) : '';
		};

		const runServerBatch = ( titles, completeMessageKey ) => {
			const doneMessage = completeMessageKey || 'aibatcheditor-ui-run-complete';
			let lastApplied = 0;

			titles.forEach( ( title ) => {
				const instructions = getPageInstructions( title );
				if ( options.value.operation === 'custom' && !instructions ) {
					updateResultRow( title, {
						status: 'error',
						detail: mw.msg( 'aibatcheditor-error-custom-needs-instructions' )
					} );
				} else {
					updateResultRow( title, {
						status: 'processing',
						detail: mw.msg( 'aibatcheditor-ui-status-processing' )
					} );
				}
			} );

			const runnableTitles = titles.filter( ( title ) => {
				if ( options.value.operation === 'custom' && !getPageInstructions( title ) ) {
					return false;
				}
				return true;
			} );

			if ( runnableTitles.length === 0 ) {
				running.value = false;
				progressPercent.value = 100;
				return;
			}

			const params = {
				titles: runnableTitles.join( '|' ),
				operation: options.value.operation,
				profile: options.value.profile
			};
			if ( options.value.instructions.trim() ) {
				params.instructions = options.value.instructions.trim();
			}
			if ( options.value.operation === 'templates' ) {
				params.templates = options.value.templates.trim();
				if ( options.value.templatesource.trim() ) {
					params.templatesource = options.value.templatesource.trim();
				}
			}
			const pageInstructions = buildPageInstructionsPayload( runnableTitles );
			if ( pageInstructions ) {
				params.pageinstructions = pageInstructions;
			}

			const pollIntervalMs = props.config.pollIntervalMs || 800;

			const applyBatchStatus = ( status ) => {
				const pages = api.normalizeList( status.pages );
				pages.slice( lastApplied ).forEach( ( pageResult ) => {
					applyPageResult( pageResult );
				} );
				lastApplied = pages.length;
				if ( status.total > 0 ) {
					progressPercent.value = Math.round(
						( status.completed / status.total ) * 100
					);
				}
			};

			const finishBatch = ( status ) => {
				running.value = false;
				currentBatchId.value = '';
				stopBatchPoll();
				if ( status.status === 'complete' ) {
					globalNotice.value = mw.msg( doneMessage );
				} else if ( status.status === 'cancelled' ) {
					markUnprocessedAsCancelled();
					globalNotice.value = mw.msg( 'aibatcheditor-ui-cancel-batch-complete' );
				}
			};

			const failBatch = ( message ) => {
				runnableTitles.forEach( ( title ) => {
					const row = resultPages.value.find( ( item ) => item.title === title );
					if ( row && row.status === 'processing' ) {
						updateResultRow( title, { status: 'error', detail: message } );
					}
				} );
				running.value = false;
				currentBatchId.value = '';
				showGlobalError( message );
				stopBatchPoll();
			};

			const pollBatch = ( batchId ) => {
				api.getBatchStatus( { batchid: batchId } )
					.then( ( data ) => {
						if ( batchCancelled || !running.value ) {
							return;
						}
						const status = data.aibatcheditorbatchstatus || {};
						applyBatchStatus( status );
						if ( status.status === 'complete' || status.status === 'cancelled' ) {
							finishBatch( status );
							return;
						}
						batchPollTimer = setTimeout( () => {
							pollBatch( batchId );
						}, pollIntervalMs );
					} )
					.catch( () => {
						if ( batchCancelled || !running.value ) {
							return;
						}
						batchPollTimer = setTimeout( () => {
							pollBatch( batchId );
						}, pollIntervalMs );
					} );
			};

			const driveBatchAdvance = ( batchId ) => {
				if ( batchCancelled || !running.value ) {
					return;
				}
				api.advanceBatch( { batchid: batchId } )
					.then( ( data ) => {
						if ( batchCancelled || !running.value ) {
							return;
						}
						const status = data.aibatcheditorbatchadvance || {};
						applyBatchStatus( status );
						if ( status.status === 'complete' || status.status === 'cancelled' ) {
							finishBatch( status );
							return;
						}
						driveBatchAdvance( batchId );
					} )
					.catch( ( code, data ) => {
						if ( batchCancelled ) {
							return;
						}
						failBatch( api.formatApiError( code, data ) );
					} );
			};

			stopBatchPoll();
			batchCancelled = false;
			currentBatchId.value = '';
			api.startBatch( params )
				.then( ( data ) => {
					const batchId = ( data.aibatcheditorbatchstart || {} ).batchId;
					if ( !batchId ) {
						throw new Error( 'missing batch id' );
					}
					currentBatchId.value = batchId;
					pollBatch( batchId );
					driveBatchAdvance( batchId );
				} )
				.catch( ( code, data ) => {
					const message = api.formatApiError( code, data );
					runnableTitles.forEach( ( title ) => {
						updateResultRow( title, { status: 'error', detail: message } );
					} );
					running.value = false;
					showGlobalError( message );
				} );
		};

		const onRun = () => {
			if ( runDisabled.value || !validateInstructions() ) {
				return;
			}

			globalError.value = '';
			clearFieldErrors();
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

			runServerBatch( titles );
		};

		const onRedraftPage = ( title ) => {
			if ( running.value || saving.value || !validateInstructions() ) {
				return;
			}
			running.value = true;
			progressPercent.value = 0;
			runServerBatch( [ title ] );
		};

		const onCancelBatch = () => {
			if ( !running.value || !currentBatchId.value || cancelling.value ) {
				return;
			}
			if ( !window.confirm( mw.msg( 'aibatcheditor-ui-cancel-batch-confirm' ) ) ) {
				return;
			}

			batchCancelled = true;
			stopBatchPoll();
			cancelling.value = true;
			const batchId = currentBatchId.value;

			api.cancelBatch( { batchid: batchId } )
				.then( ( data ) => {
					const status = data.aibatcheditorbatchcancel || {};
					const pages = api.normalizeList( status.pages );
					pages.forEach( ( pageResult ) => {
						applyPageResult( pageResult );
					} );
					if ( status.total > 0 ) {
						progressPercent.value = Math.round(
							( status.completed / status.total ) * 100
						);
					}
					markUnprocessedAsCancelled();
					globalNotice.value = mw.msg( 'aibatcheditor-ui-cancel-batch-complete' );
				} )
				.catch( ( code, data ) => {
					showGlobalError( api.formatApiError( code, data ) );
				} )
				.always( () => {
					cancelling.value = false;
					running.value = false;
					currentBatchId.value = '';
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

			runServerBatch( titles, 'aibatcheditor-ui-retry-complete' );
		};

		const onDiffViewed = ( title ) => {
			updateResultRow( title, { diffViewed: true } );
		};

		const onToggleApprove = ( title, approved ) => {
			if ( approved ) {
				const row = getChangedRow( title );
				if ( row && !confirmApprove( [ row ] ) ) {
					return;
				}
			}
			updateResultRow( title, { approved } );
			if ( approved ) {
				saveError.value = '';
			}
		};

		const onApproveAll = ( approved ) => {
			if ( approved ) {
				const changed = resultPages.value.filter( ( row ) => row.status === 'changed' );
				if ( changed.length > 0 && !window.confirm(
					mw.msg( 'aibatcheditor-ui-approve-all-confirm', changed.length )
				) ) {
					return;
				}
				if ( !confirmApprove( changed ) ) {
					return;
				}
			}
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
				const message = mw.msg( 'aibatcheditor-ui-save-summary-required' );
				saveError.value = message;
				showGlobalError( message, { summary: true } );
				emphasizeSaveError();
				errors.scrollToAndEmphasize( '.ext-aibatcheditor-operation-selector__summary-field' );
				return;
			}

			const approved = resultPages.value.filter( ( row ) => (
				row.status === 'changed' &&
				row.approved &&
				row.proposed &&
				row.revid &&
				row.draftToken
			) );

			if ( approved.length === 0 ) {
				const message = mw.msg( 'aibatcheditor-ui-save-none-selected' );
				saveError.value = message;
				showGlobalError( message );
				emphasizeSaveError();
				return;
			}

			const unviewedApproved = approved.filter( ( row ) => !row.diffViewed );
			if ( unviewedApproved.length > 0 && !window.confirm(
				mw.msg( 'aibatcheditor-ui-save-without-diff-confirm', unviewedApproved.length )
			) ) {
				return;
			}

			globalError.value = '';
			saveError.value = '';
			clearFieldErrors();
			globalNotice.value = mw.msg( 'aibatcheditor-ui-save-refreshing-tokens' );
			saving.value = true;
			progressPercent.value = 0;

			approved.forEach( ( row ) => {
				updateResultRow( row.title, {
					status: 'saving',
					detail: mw.msg( 'aibatcheditor-ui-status-saving' )
				} );
			} );

			const refreshEdits = approved.map( ( row ) => ( {
				title: row.title,
				revid: row.revid,
				proposed: row.proposed
			} ) );

			const runSave = ( rowsToSave ) => {
				const edits = rowsToSave.map( ( row ) => ( {
					title: row.title,
					revid: row.revid,
					proposed: row.proposed,
					draftToken: row.draftToken
				} ) );

				return api.saveEdits( {
					summary,
					operation: options.value.operation,
					profile: options.value.profile,
					edits: JSON.stringify( edits )
				} );
			};

			api.refreshDraftTokens( {
				edits: JSON.stringify( refreshEdits )
			} )
				.then( ( data ) => {
					const refresh = data.aibatcheditorrefreshdrafttokens || {};
					const refreshPages = api.normalizeList( refresh.pages );
					const rowsToSave = [];
					let refreshError = '';

					refreshPages.forEach( ( pageResult ) => {
						const status = pageResult.status || 'error';
						if ( status === 'ok' && pageResult.draftToken ) {
							updateResultRow( pageResult.title, {
								draftToken: pageResult.draftToken
							} );
							const row = approved.find( ( item ) => item.title === pageResult.title );
							if ( row ) {
								rowsToSave.push( Object.assign( {}, row, {
									draftToken: pageResult.draftToken
								} ) );
							}
							return;
						}

						let detail = '';
						if ( status === 'conflict' ) {
							detail = api.formatError( 'aibatcheditor-error-save-conflict', pageResult );
						} else if ( pageResult.error ) {
							detail = api.formatError( pageResult.error, pageResult );
						} else {
							detail = mw.msg( 'aibatcheditor-ui-save-refresh-failed' );
						}
						refreshError = refreshError || detail;
						updateResultRow( pageResult.title, {
							status: 'save-error',
							detail,
							approved: false
						} );
					} );

					if ( rowsToSave.length === 0 ) {
						saveError.value = refreshError || mw.msg( 'aibatcheditor-ui-save-refresh-failed' );
						showGlobalError( saveError.value );
						emphasizeSaveError();
						return null;
					}

					if ( refreshError ) {
						globalNotice.value = mw.msg(
							'aibatcheditor-ui-save-refresh-partial',
							rowsToSave.length,
							approved.length
						);
					}

					return runSave( rowsToSave );
				} )
				.then( ( data ) => {
					if ( !data ) {
						return;
					}
					const result = data.aibatcheditorsave || {};
					api.normalizeList( result.pages ).forEach( ( pageResult ) => {
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
					const message = api.formatApiError( code, data );
					approved.forEach( ( row ) => {
						updateResultRow( row.title, {
							status: 'save-error',
							detail: message
						} );
					} );
					saveError.value = message;
					showGlobalError( message );
					emphasizeSaveError();
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
			cancelling,
			saving,
			progressPercent,
			alertsRef,
			globalError,
			globalNotice,
			saveError,
			summaryFieldError,
			instructionsFieldError,
			templatesFieldError,
			previewPageTitle,
			rateLimit,
			runDisabled,
			llmNotConfigured,
			onSelectionUpdate,
			onOptionsUpdate,
			onValidate,
			onRun,
			onToggleApprove,
			onApproveAll,
			onSaveApproved,
			onUpdatePageInstructions,
			onRedraftPage,
			onRetryErrors,
			onCancelBatch,
			onDiffViewed
		};
	}
} );
</script>