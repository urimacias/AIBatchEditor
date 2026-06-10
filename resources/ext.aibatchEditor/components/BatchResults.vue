<template>
	<div v-if="pages.length > 0" class="ext-aibatcheditor-batch-results">
		<h3>{{ $i18n( 'aibatcheditor-ui-results-heading' ).text() }}</h3>

		<cdx-progress-bar
			v-if="running || saving"
			:label="progressLabel"
			:percentage="progressPercent"
		></cdx-progress-bar>

		<div
			v-if="hasApprovablePages || hasRetryableErrors"
			class="ext-aibatcheditor-batch-results__save-actions"
		>
			<cdx-button
				v-if="hasRetryableErrors"
				weight="normal"
				:disabled="running || saving"
				@click="$emit( 'retry-errors' )"
			>
				{{ $i18n( 'aibatcheditor-ui-retry-errors' ).text() }}
			</cdx-button>
			<cdx-checkbox
				:model-value="allApproved"
				:disabled="saving"
				@update:model-value="$emit( 'approve-all', $event )"
			>
				{{ $i18n( 'aibatcheditor-ui-approve-all' ).text() }}
			</cdx-checkbox>
			<cdx-button
				action="progressive"
				weight="primary"
				:disabled="saveDisabled"
				@click="$emit( 'save-approved' )"
			>
				{{ $i18n( 'aibatcheditor-ui-save-approved' ).text() }}
			</cdx-button>
		</div>

		<table class="wikitable ext-aibatcheditor-batch-results__table">
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
						<td>{{ page.title }}</td>
						<td>
							<cdx-info-chip :status="statusChip( page.status )">
								{{ statusLabel( page.status ) }}
							</cdx-info-chip>
						</td>
						<td>{{ page.detail }}</td>
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
</template>

<script>
const { defineComponent, computed } = require( 'vue' );
const { CdxProgressBar, CdxInfoChip, CdxCheckbox, CdxButton, CdxField, CdxTextInput } = require( '../codex.js' );
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

		const hasApprovablePages = computed( () => props.pages.some( ( page ) => isApprovable( page ) ) );

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
			hasApprovablePages,
			hasRetryableErrors,
			allApproved,
			saveDisabled,
			progressLabel,
			showActions,
			rowColspan,
			isApprovable,
			canRedraft,
			showPageInstructions,
			statusLabel,
			statusChip
		};
	}
} );
</script>