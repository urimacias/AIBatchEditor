<template>
	<section
		v-if="pages.length > 0"
		class="ext-aibatcheditor-section"
	>
		<header class="ext-aibatcheditor-section__header">
			<span class="ext-aibatcheditor-section__step">3</span>
			<div class="ext-aibatcheditor-section__titles">
				<h2 class="ext-aibatcheditor-section__title">
					{{ $i18n( 'aibatcheditor-ui-step-results-title' ).text() }}
				</h2>
				<p class="ext-aibatcheditor-section__desc">
					{{ $i18n( 'aibatcheditor-ui-step-results-desc' ).text() }}
				</p>
			</div>
		</header>

		<div class="ext-aibatcheditor-section__body ext-aibatcheditor-batch-results">
			<div class="ext-aibatcheditor-batch-results__header">
				<div class="ext-aibatcheditor-batch-results__stats">
					<span class="ext-aibatcheditor-batch-results__stat">
						{{ $i18n( 'aibatcheditor-ui-stats-total', stats.total ).text() }}
					</span>
					<span
						v-if="stats.changed > 0"
						class="ext-aibatcheditor-batch-results__stat ext-aibatcheditor-batch-results__stat--success"
					>
						{{ $i18n( 'aibatcheditor-ui-stats-changed', stats.changed ).text() }}
					</span>
					<span
						v-if="stats.errors > 0"
						class="ext-aibatcheditor-batch-results__stat ext-aibatcheditor-batch-results__stat--error"
					>
						{{ $i18n( 'aibatcheditor-ui-stats-errors', stats.errors ).text() }}
					</span>
					<span
						v-if="stats.pending > 0"
						class="ext-aibatcheditor-batch-results__stat ext-aibatcheditor-batch-results__stat--notice"
					>
						{{ $i18n( 'aibatcheditor-ui-stats-pending', stats.pending ).text() }}
					</span>
				</div>
			</div>

			<cdx-progress-bar
				v-if="running || saving"
				class="ext-aibatcheditor-batch-results__progress"
				:label="progressLabel"
				:percentage="progressPercent"
			></cdx-progress-bar>

			<cdx-message
				v-if="saveError"
				type="error"
				:inline="true"
				class="ext-aibatcheditor-batch-results__save-error"
			>
				{{ saveError }}
			</cdx-message>

			<div
				v-if="hasApprovablePages || hasRetryableErrors"
				class="ext-aibatcheditor-batch-results__toolbar"
			>
				<cdx-checkbox
					v-if="hasApprovablePages"
					:model-value="allApproved"
					:disabled="saving"
					@update:model-value="$emit( 'approve-all', $event )"
				>
					{{ $i18n( 'aibatcheditor-ui-approve-all' ).text() }}
				</cdx-checkbox>
				<div class="ext-aibatcheditor-batch-results__toolbar-actions">
					<cdx-button
						v-if="hasRetryableErrors"
						weight="normal"
						:disabled="running || saving"
						@click="$emit( 'retry-errors' )"
					>
						{{ $i18n( 'aibatcheditor-ui-retry-errors' ).text() }}
					</cdx-button>
					<cdx-button
						v-if="hasApprovablePages"
						action="progressive"
						weight="primary"
						:disabled="saveDisabled"
						@click="$emit( 'save-approved' )"
					>
						{{ $i18n( 'aibatcheditor-ui-save-approved' ).text() }}
					</cdx-button>
				</div>
			</div>

			<div class="ext-aibatcheditor-batch-results__table-wrap">
				<table class="ext-aibatcheditor-batch-results__table">
					<thead>
						<tr>
							<th v-if="hasApprovablePages">
								{{ $i18n( 'aibatcheditor-ui-col-approve' ).text() }}
							</th>
							<th>{{ $i18n( 'aibatcheditor-ui-col-title' ).text() }}</th>
							<th>{{ $i18n( 'aibatcheditor-ui-col-status' ).text() }}</th>
							<th>{{ $i18n( 'aibatcheditor-ui-col-detail' ).text() }}</th>
							<th v-if="showActions">
								{{ $i18n( 'aibatcheditor-ui-col-actions' ).text() }}
							</th>
						</tr>
					</thead>
					<tbody>
						<template v-for="page in pages" :key="page.title">
							<tr>
								<td v-if="hasApprovablePages">
									<cdx-checkbox
										v-if="isApprovable( page )"
										:model-value="page.approved"
										:disabled="saving"
										@update:model-value="$emit( 'toggle-approve', page.title, $event )"
									>
										{{ $i18n( 'aibatcheditor-ui-approve-label' ).text() }}
									</cdx-checkbox>
								</td>
								<td>
									<a
										:href="pageUrl( page.title )"
										class="ext-aibatcheditor-batch-results__title-link"
									>{{ page.title }}</a>
								</td>
								<td>
									<cdx-info-chip :status="statusChip( page.status )">
										{{ statusLabel( page.status ) }}
									</cdx-info-chip>
								</td>
								<td>
									<span
										v-if="page.detail"
										class="ext-aibatcheditor-batch-results__detail"
									>{{ page.detail }}</span>
								</td>
								<td v-if="showActions">
									<cdx-button
										v-if="canRedraft( page )"
										weight="quiet"
										:disabled="running || saving"
										@click="$emit( 'redraft-page', page.title )"
									>
										{{ $i18n( 'aibatcheditor-ui-redraft' ).text() }}
									</cdx-button>
								</td>
							</tr>
							<tr
								v-if="showPageInstructions( page )"
								class="ext-aibatcheditor-batch-results__instructions-row"
							>
								<td :colspan="rowColspan">
									<cdx-field>
										<template #label>
											{{ $i18n( 'aibatcheditor-ui-page-instructions-label' ).text() }}
										</template>
										<cdx-text-input
											:model-value="page.pageInstructions || ''"
											:disabled="running || saving"
											:placeholder="$i18n( 'aibatcheditor-ui-page-instructions-placeholder' ).text()"
											@update:model-value="$emit( 'update-page-instructions', page.title, $event )"
										></cdx-text-input>
									</cdx-field>
								</td>
							</tr>
							<tr
								v-if="page.status === 'changed' && page.original && page.proposed"
								class="ext-aibatcheditor-batch-results__diff-row"
							>
								<td :colspan="rowColspan">
									<diff-viewer
										:title="page.title"
										:original="page.original"
										:proposed="page.proposed"
									></diff-viewer>
								</td>
							</tr>
						</template>
					</tbody>
				</table>
			</div>
		</div>
	</section>
</template>

<script>
const { defineComponent, computed } = require( 'vue' );
const {
	CdxProgressBar,
	CdxInfoChip,
	CdxCheckbox,
	CdxButton,
	CdxField,
	CdxTextInput,
	CdxMessage
} = require( '../codex.js' );
const DiffViewer = require( './DiffViewer.vue' );

module.exports = exports = defineComponent( {
	name: 'BatchResults',
	components: {
		CdxProgressBar,
		CdxInfoChip,
		CdxCheckbox,
		CdxButton,
		CdxField,
		CdxTextInput,
		CdxMessage,
		DiffViewer
	},
	props: {
		pages: {
			type: Array,
			default: () => []
		},
		running: {
			type: Boolean,
			default: false
		},
		saving: {
			type: Boolean,
			default: false
		},
		progressPercent: {
			type: Number,
			default: 0
		},
		saveError: {
			type: String,
			default: ''
		}
	},
	emits: [
		'toggle-approve',
		'approve-all',
		'save-approved',
		'update-page-instructions',
		'redraft-page',
		'retry-errors'
	],
	setup( props ) {
		const statusLabels = {
			pending: mw.msg( 'aibatcheditor-ui-status-pending' ),
			processing: mw.msg( 'aibatcheditor-ui-status-processing' ),
			changed: mw.msg( 'aibatcheditor-ui-status-changed' ),
			omitted: mw.msg( 'aibatcheditor-ui-status-omitted' ),
			error: mw.msg( 'aibatcheditor-ui-status-error' ),
			skipped: mw.msg( 'aibatcheditor-ui-status-skipped' ),
			saving: mw.msg( 'aibatcheditor-ui-status-saving' ),
			saved: mw.msg( 'aibatcheditor-ui-status-saved' ),
			'save-error': mw.msg( 'aibatcheditor-ui-status-save-error' )
		};

		const stats = computed( () => {
			const counts = {
				total: props.pages.length,
				changed: 0,
				errors: 0,
				pending: 0
			};

			props.pages.forEach( ( page ) => {
				if ( page.status === 'changed' || page.status === 'saved' ) {
					counts.changed++;
				} else if ( page.status === 'error' || page.status === 'save-error' ) {
					counts.errors++;
				} else if ( page.status === 'pending' || page.status === 'processing' ) {
					counts.pending++;
				}
			} );

			return counts;
		} );

		const hasApprovablePages = computed( () => props.pages.some( ( page ) => (
			page.status === 'changed' ||
			page.status === 'saving' ||
			page.status === 'saved' ||
			page.status === 'save-error'
		) ) );

		const hasRetryableErrors = computed( () => (
			!props.running &&
			!props.saving &&
			props.pages.some( ( page ) => page.status === 'error' )
		) );

		const approvablePages = computed( () => props.pages.filter( ( page ) => isApprovable( page ) ) );

		const showActions = computed( () => props.pages.some( ( page ) => canRedraft( page ) || page.status === 'pending' ) );

		const rowColspan = computed( () => {
			let cols = 3;
			if ( hasApprovablePages.value ) {
				cols++;
			}
			if ( showActions.value ) {
				cols++;
			}
			return cols;
		} );

		const allApproved = computed( () => (
			approvablePages.value.length > 0 &&
			approvablePages.value.every( ( page ) => page.approved )
		) );

		const saveDisabled = computed( () => (
			props.saving ||
			props.running ||
			!approvablePages.value.some( ( page ) => page.approved )
		) );

		const progressLabel = computed( () => (
			props.saving ?
				mw.msg( 'aibatcheditor-ui-save-started' ) :
				mw.msg( 'aibatcheditor-ui-progress-label' )
		) );

		const pageUrl = ( title ) => mw.util.getUrl( title );

		const isApprovable = ( page ) => page.status === 'changed';

		const canRedraft = ( page ) => (
			!props.running &&
			!props.saving &&
			[ 'changed', 'omitted', 'error' ].indexOf( page.status ) !== -1
		);

		const showPageInstructions = ( page ) => (
			page.status === 'pending' || canRedraft( page )
		);

		const statusLabel = ( status ) => statusLabels[ status ] || status;

		const statusChip = ( status ) => {
			switch ( status ) {
				case 'changed':
				case 'saved':
					return 'success';
				case 'omitted':
					return 'notice';
				case 'error':
				case 'save-error':
					return 'error';
				case 'processing':
				case 'saving':
					return 'notice';
				default:
					return 'notice';
			}
		};

		return {
			stats,
			hasApprovablePages,
			hasRetryableErrors,
			allApproved,
			saveDisabled,
			progressLabel,
			showActions,
			rowColspan,
			pageUrl,
			isApprovable,
			canRedraft,
			showPageInstructions,
			statusLabel,
			statusChip
		};
	}
} );
</script>