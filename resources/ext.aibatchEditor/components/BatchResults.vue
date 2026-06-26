<template>
	<section
		v-if="pages.length > 0"
		class="ext-aibatcheditor-section ext-aibatcheditor-section--wide"
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

			<div
				v-if="running || saving"
				class="ext-aibatcheditor-batch-results__progress-row"
			>
				<cdx-progress-bar
					class="ext-aibatcheditor-batch-results__progress"
					:label="progressLabel"
					:percentage="progressPercent"
				></cdx-progress-bar>
				<cdx-button
					v-if="running"
					class="ext-aibatcheditor-batch-results__cancel"
					action="destructive"
					weight="normal"
					:disabled="cancelling || saving"
					@click="$emit( 'cancel-batch' )"
				>
					{{ $i18n( 'aibatcheditor-ui-cancel-batch' ).text() }}
				</cdx-button>
			</div>

			<cdx-message
				v-if="saveError"
				type="error"
				:inline="false"
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

			<div class="ext-aibatcheditor-batch-results__pages">
				<article
					v-for="page in pages"
					:key="page.title"
					class="ext-aibatcheditor-batch-results__page"
					:class="{
						'ext-aibatcheditor-batch-results__page--changed': page.status === 'changed'
					}"
				>
					<div class="ext-aibatcheditor-batch-results__page-meta">
						<div
							v-if="hasApprovablePages && isApprovable( page )"
							class="ext-aibatcheditor-batch-results__page-approve"
						>
							<cdx-checkbox
								:model-value="page.approved"
								:disabled="saving"
								@update:model-value="$emit( 'toggle-approve', page.title, $event )"
							>
								{{ $i18n( 'aibatcheditor-ui-approve-label' ).text() }}
							</cdx-checkbox>
						</div>

						<div class="ext-aibatcheditor-batch-results__page-main">
							<a
								:href="pageUrl( page.title )"
								class="ext-aibatcheditor-batch-results__title-link"
							>{{ page.title }}</a>
							<cdx-info-chip :status="statusChip( page.status )">
								{{ statusLabel( page.status ) }}
							</cdx-info-chip>
							<span
								v-if="page.detail"
								class="ext-aibatcheditor-batch-results__detail"
							>{{ page.detail }}</span>
						</div>

						<div
							v-if="canRedraft( page )"
							class="ext-aibatcheditor-batch-results__page-actions"
						>
							<cdx-button
								weight="quiet"
								:disabled="running || saving"
								@click="$emit( 'redraft-page', page.title )"
							>
								{{ $i18n( 'aibatcheditor-ui-redraft' ).text() }}
							</cdx-button>
						</div>
					</div>

					<div
						v-if="page.warnings && page.warnings.length > 0"
						class="ext-aibatcheditor-batch-results__page-panel ext-aibatcheditor-batch-results__page-warnings"
					>
						<cdx-message
							v-for="warning in page.warnings"
							:key="warning"
							type="warning"
							:inline="true"
						>
							{{ warningLabel( warning ) }}
						</cdx-message>
					</div>

					<div
						v-if="showPageInstructions( page )"
						class="ext-aibatcheditor-batch-results__page-panel ext-aibatcheditor-batch-results__page-instructions"
					>
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
					</div>

					<div
						v-if="promptPreviewEnabled && page.promptSystem && page.promptUser"
						class="ext-aibatcheditor-batch-results__page-panel"
					>
						<prompt-preview
							:title="page.title"
							:system-prompt="page.promptSystem"
							:user-prompt="page.promptUser"
							:show-notice="false"
						></prompt-preview>
					</div>

					<div
						v-if="page.status === 'changed' && page.original && page.proposed"
						class="ext-aibatcheditor-batch-results__page-panel ext-aibatcheditor-batch-results__page-diff"
					>
						<diff-viewer
							:title="page.title"
							:original="page.original"
							:proposed="page.proposed"
							:auto-load="false"
							@diff-viewed="$emit( 'diff-viewed', $event )"
						></diff-viewer>
					</div>

					<div
						v-if="page.status === 'saved' && page.newrevid"
						class="ext-aibatcheditor-batch-results__page-panel ext-aibatcheditor-batch-results__post-save"
					>
						<p class="ext-aibatcheditor-batch-results__post-save-heading">
							{{ $i18n( 'aibatcheditor-ui-save-post-heading' ).text() }}
						</p>
						<ul class="ext-aibatcheditor-batch-results__post-save-links">
							<li>
								<a :href="revisionUrl( page.title, page.newrevid )">
									{{ $i18n( 'aibatcheditor-ui-save-post-revision-link', page.newrevid ).text() }}
								</a>
							</li>
							<li>
								<a :href="historyUrl( page.title )">
									{{ $i18n( 'aibatcheditor-ui-save-post-history-link' ).text() }}
								</a>
							</li>
						</ul>
					</div>
				</article>
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
const PromptPreview = require( './PromptPreview.vue' );

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
		DiffViewer,
		PromptPreview
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
		cancelling: {
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
		},
		promptPreviewEnabled: {
			type: Boolean,
			default: false
		}
	},
	emits: [
		'toggle-approve',
		'approve-all',
		'save-approved',
		'update-page-instructions',
		'redraft-page',
		'retry-errors',
		'cancel-batch',
		'diff-viewed'
	],
	setup( props ) {
		const statusLabels = {
			pending: mw.msg( 'aibatcheditor-ui-status-pending' ),
			processing: mw.msg( 'aibatcheditor-ui-status-processing' ),
			changed: mw.msg( 'aibatcheditor-ui-status-changed' ),
			omitted: mw.msg( 'aibatcheditor-ui-status-omitted' ),
			error: mw.msg( 'aibatcheditor-ui-status-error' ),
			skipped: mw.msg( 'aibatcheditor-ui-status-skipped' ),
			cancelled: mw.msg( 'aibatcheditor-ui-status-cancelled' ),
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
				} else if (
					page.status === 'pending' ||
					page.status === 'processing' ||
					page.status === 'cancelled'
				) {
					if ( page.status !== 'cancelled' ) {
						counts.pending++;
					}
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

		const revisionUrl = ( title, revid ) => mw.util.getUrl( title, { oldid: revid } );

		const historyUrl = ( title ) => mw.util.getUrl( title, { action: 'history' } );

		const warningLabel = ( code ) => {
			const key = 'aibatcheditor-ui-warning-' + code;
			return mw.message( key ).exists() ? mw.msg( key ) : code;
		};

		const isApprovable = ( page ) => page.status === 'changed';

		const canRedraft = ( page ) => (
			!props.running &&
			!props.saving &&
			[ 'changed', 'omitted', 'error', 'cancelled' ].indexOf( page.status ) !== -1
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
			pageUrl,
			revisionUrl,
			historyUrl,
			warningLabel,
			isApprovable,
			canRedraft,
			showPageInstructions,
			statusLabel,
			statusChip
		};
	}
} );
</script>