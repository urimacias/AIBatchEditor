<template>
	<section class="ext-aibatcheditor-section">
		<header class="ext-aibatcheditor-section__header">
			<span class="ext-aibatcheditor-section__step">2</span>
			<div class="ext-aibatcheditor-section__titles">
				<h2 class="ext-aibatcheditor-section__title">
					{{ $i18n( 'aibatcheditor-ui-step-operation-title' ).text() }}
				</h2>
				<p class="ext-aibatcheditor-section__desc">
					{{ $i18n( 'aibatcheditor-ui-step-operation-desc' ).text() }}
				</p>
			</div>
		</header>

		<div class="ext-aibatcheditor-section__body">
			<div class="ext-aibatcheditor-operation-selector">
				<div class="ext-aibatcheditor-operation-selector__grid">
					<cdx-field>
						<template #label>
							{{ $i18n( 'aibatcheditor-ui-operation-label' ).text() }}<span
								class="ext-aibatcheditor-required"
								:title="$i18n( 'aibatcheditor-ui-required-field' ).text()"
							>*</span>
						</template>
						<cdx-select
							v-model:selected="selectedOperation"
							:menu-items="operationItems"
							:default-label="$i18n( 'aibatcheditor-ui-operation-placeholder' ).text()"
						></cdx-select>
					</cdx-field>

					<cdx-field v-if="!isCustomOperation">
						<template #label>
							{{ $i18n( 'aibatcheditor-ui-profile-label' ).text() }}
						</template>
						<cdx-select
							v-model:selected="selectedProfile"
							:menu-items="profileItems"
						></cdx-select>
						<template #help-text>
							{{ profileHelpText }}
						</template>
					</cdx-field>
				</div>

				<cdx-message
					v-if="isCustomOperation"
					type="notice"
					:inline="true"
					class="ext-aibatcheditor-operation-selector__profile-notice"
				>
					{{ $i18n( 'aibatcheditor-ui-profile-custom-fixed' ).text() }}
				</cdx-message>

				<template v-if="isTemplatesOperation">
					<cdx-field
						class="ext-aibatcheditor-operation-selector__templates-field"
						:status="templatesError ? 'error' : 'default'"
					>
						<template #label>
							{{ $i18n( 'aibatcheditor-ui-templates-label' ).text() }}<span
								class="ext-aibatcheditor-required"
								:title="$i18n( 'aibatcheditor-ui-required-field' ).text()"
							>*</span>
						</template>
						<cdx-text-input
							v-model="templateNames"
							:placeholder="$i18n( 'aibatcheditor-ui-templates-placeholder' ).text()"
						></cdx-text-input>
						<template #help-text>
							{{ $i18n( 'aibatcheditor-ui-templates-help' ).text() }}
						</template>
					</cdx-field>

					<cdx-field>
						<template #label>
							{{ $i18n( 'aibatcheditor-ui-templatesource-label' ).text() }}
						</template>
						<cdx-text-input
							v-model="templateSource"
							:placeholder="defaultTemplateSource"
						></cdx-text-input>
						<template #help-text>
							{{ $i18n( 'aibatcheditor-ui-templatesource-help' ).text() }}
						</template>
					</cdx-field>
				</template>

				<cdx-field
					class="ext-aibatcheditor-operation-selector__instructions-field"
					:status="instructionsError ? 'error' : 'default'"
				>
					<template #label>
						{{ $i18n( 'aibatcheditor-ui-instructions-label' ).text() }}<span
							v-if="isCustomOperation"
							class="ext-aibatcheditor-required"
							:title="$i18n( 'aibatcheditor-ui-required-field' ).text()"
						>*</span>
					</template>
					<cdx-text-area
						v-model="instructions"
						:placeholder="$i18n( 'aibatcheditor-ui-instructions-placeholder' ).text()"
						:rows="3"
					></cdx-text-area>
					<template #help-text>
						{{ $i18n( 'aibatcheditor-ui-instructions-help' ).text() }}
					</template>
				</cdx-field>

				<cdx-field
					class="ext-aibatcheditor-operation-selector__summary-field"
					:status="summaryError ? 'error' : 'default'"
				>
					<template #label>
						{{ $i18n( 'aibatcheditor-ui-summary-label' ).text() }}<span
							class="ext-aibatcheditor-required"
							:title="$i18n( 'aibatcheditor-ui-required-field' ).text()"
						>*</span>
					</template>
					<cdx-text-input
						v-model="editSummary"
						:placeholder="$i18n( 'aibatcheditor-ui-summary-placeholder' ).text()"
					></cdx-text-input>
					<template #help-text>
						{{ $i18n( 'aibatcheditor-ui-summary-help' ).text() }}
					</template>
				</cdx-field>

				<prompt-preview
					v-if="promptPreviewEnabled && previewData"
					:title="previewData.title"
					:system-prompt="previewData.system"
					:user-prompt="previewData.user"
				></prompt-preview>

				<cdx-message
					v-if="previewError"
					type="error"
					:inline="true"
				>
					{{ previewError }}
				</cdx-message>

				<cdx-message
					v-if="draftNoticeText"
					:type="draftNoticeType"
					:inline="false"
					class="ext-aibatcheditor-operation-selector__draft-notice"
				>
					{{ draftNoticeText }}
				</cdx-message>

				<div class="ext-aibatcheditor-operation-selector__actions">
					<cdx-button
						v-if="promptPreviewEnabled"
						weight="normal"
						:disabled="previewDisabled"
						@click="loadPreview"
					>
						{{ previewLoading ?
							$i18n( 'aibatcheditor-ui-preview-prompt-loading' ).text() :
							$i18n( 'aibatcheditor-ui-preview-prompt' ).text() }}
					</cdx-button>
					<cdx-button
						action="progressive"
						weight="primary"
						:disabled="runDisabled"
						@click="$emit( 'run' )"
					>
						{{ $i18n( 'aibatcheditor-ui-run' ).text() }}
					</cdx-button>
				</div>
			</div>
		</div>
	</section>
</template>

<script>
const { defineComponent, ref, computed, watch } = require( 'vue' );
const { CdxField, CdxSelect, CdxTextArea, CdxTextInput, CdxButton, CdxMessage } = require( '../codex.js' );
const PromptPreview = require( './PromptPreview.vue' );
const api = require( '../api.js' );

module.exports = exports = defineComponent( {
	name: 'OperationSelector',
	components: {
		CdxField,
		CdxSelect,
		CdxTextArea,
		CdxTextInput,
		CdxButton,
		CdxMessage,
		PromptPreview
	},
	props: {
		enabledOperations: {
			type: Object,
			required: true
		},
		defaultProfile: {
			type: String,
			default: 'balanced'
		},
		operationProfiles: {
			type: Object,
			default: () => ( {} )
		},
		runDisabled: {
			type: Boolean,
			default: true
		},
		summaryError: {
			type: Boolean,
			default: false
		},
		instructionsError: {
			type: Boolean,
			default: false
		},
		templatesError: {
			type: Boolean,
			default: false
		},
		defaultTemplateSource: {
			type: String,
			default: 'https://es.wikipedia.org'
		},
		promptPreviewEnabled: {
			type: Boolean,
			default: false
		},
		previewPageTitle: {
			type: String,
			default: ''
		},
		validatedPageCount: {
			type: Number,
			default: 0
		},
		rateLimit: {
			type: Object,
			default: () => ( {
				limit: 0,
				used: 0,
				remaining: 0
			} )
		}
	},
	emits: [ 'run', 'update:options' ],
	setup( props, { emit } ) {
		const operationLabels = {
			wikilinks: mw.msg( 'aibatcheditor-ui-operation-wikilinks' ),
			spellcheck: mw.msg( 'aibatcheditor-ui-operation-spellcheck' ),
			formatting: mw.msg( 'aibatcheditor-ui-operation-formatting' ),
			style: mw.msg( 'aibatcheditor-ui-operation-style' ),
			custom: mw.msg( 'aibatcheditor-ui-operation-custom' ),
			templates: mw.msg( 'aibatcheditor-ui-operation-templates' )
		};

		const profileLabels = {
			conservative: mw.msg( 'aibatcheditor-ui-profile-conservative' ),
			balanced: mw.msg( 'aibatcheditor-ui-profile-balanced' ),
			aggressive: mw.msg( 'aibatcheditor-ui-profile-aggressive' )
		};

		const operationItems = computed( () => Object.keys( props.enabledOperations )
			.filter( ( key ) => props.enabledOperations[ key ] )
			.map( ( key ) => ( {
				label: operationLabels[ key ] || key,
				value: key
			} ) ) );

		const profileItems = computed( () => Object.keys( profileLabels ).map( ( key ) => ( {
			label: profileLabels[ key ],
			value: key
		} ) ) );

		const isCustomOperation = computed( () => selectedOperation.value === 'custom' );
		const isTemplatesOperation = computed( () => selectedOperation.value === 'templates' );

		const effectiveProfile = computed( () => (
			selectedOperation.value === 'custom' ? 'balanced' : selectedProfile.value
		) );

		const profileHelpText = computed( () => {
			const operation = selectedOperation.value;
			const profile = effectiveProfile.value;
			const instruction = props.operationProfiles[ operation ] &&
				props.operationProfiles[ operation ][ profile ];
			if ( instruction ) {
				return instruction;
			}
			return mw.msg( 'aibatcheditor-ui-profile-help' );
		} );

		const selectedOperation = ref( operationItems.value[ 0 ] ? operationItems.value[ 0 ].value : '' );
		const selectedProfile = ref( props.defaultProfile );
		const instructions = ref( '' );
		const editSummary = ref( '' );
		const templateNames = ref( '' );
		const templateSource = ref( '' );
		const previewData = ref( null );
		const previewError = ref( '' );
		const previewLoading = ref( false );

		const previewDisabled = computed( () => (
			props.runDisabled ||
			previewLoading.value ||
			!props.previewPageTitle
		) );

		const draftNoticeType = computed( () => {
			if ( props.validatedPageCount <= 0 || !props.rateLimit.limit ) {
				return 'notice';
			}
			return props.validatedPageCount > props.rateLimit.remaining ? 'warning' : 'notice';
		} );

		const draftNoticeText = computed( () => {
			if ( props.validatedPageCount <= 0 || !props.rateLimit.limit ) {
				return '';
			}
			if ( props.validatedPageCount > props.rateLimit.remaining ) {
				return mw.msg(
					'aibatcheditor-ui-draft-notice-insufficient',
					props.validatedPageCount,
					props.rateLimit.remaining,
					props.rateLimit.limit
				);
			}
			return mw.msg(
				'aibatcheditor-ui-draft-notice',
				props.validatedPageCount,
				props.rateLimit.remaining,
				props.rateLimit.limit
			);
		} );

		const buildPreviewParams = () => {
			const params = {
				titles: props.previewPageTitle,
				operation: selectedOperation.value,
				profile: effectiveProfile.value
			};
			if ( instructions.value.trim() ) {
				params.instructions = instructions.value.trim();
			}
			if ( selectedOperation.value === 'templates' ) {
				params.templates = templateNames.value.trim();
				if ( templateSource.value.trim() ) {
					params.templatesource = templateSource.value.trim();
				}
			}
			return params;
		};

		const loadPreview = () => {
			if ( previewDisabled.value ) {
				return;
			}
			if ( selectedOperation.value === 'templates' && !templateNames.value.trim() ) {
				previewError.value = mw.msg( 'aibatcheditor-error-templates-needs-names' );
				return;
			}
			if ( selectedOperation.value === 'custom' && !instructions.value.trim() ) {
				previewError.value = mw.msg( 'aibatcheditor-error-custom-needs-instructions' );
				return;
			}

			previewLoading.value = true;
			previewError.value = '';
			previewData.value = null;

			api.previewPrompt( buildPreviewParams() )
				.then( ( data ) => {
					const result = data.aibatcheditorpreview || {};
					previewData.value = {
						title: result.title || props.previewPageTitle,
						system: result.system || '',
						user: result.user || ''
					};
				} )
				.catch( ( code, data ) => {
					previewError.value = api.formatApiError( code, data );
				} )
				.always( () => {
					previewLoading.value = false;
				} );
		};

		const emitOptions = () => {
			emit( 'update:options', {
				operation: selectedOperation.value,
				profile: effectiveProfile.value,
				instructions: instructions.value,
				summary: editSummary.value,
				templates: templateNames.value,
				templatesource: templateSource.value
			} );
		};

		watch(
			[ selectedOperation, selectedProfile, instructions, editSummary, templateNames, templateSource ],
			() => {
				emitOptions();
				previewData.value = null;
				previewError.value = '';
			},
			{ immediate: true }
		);

		return {
			previewData,
			previewError,
			previewLoading,
			previewDisabled,
			draftNoticeText,
			draftNoticeType,
			loadPreview,
			isCustomOperation,
			isTemplatesOperation,
			operationItems,
			profileItems,
			profileHelpText,
			effectiveProfile,
			selectedOperation,
			selectedProfile,
			instructions,
			editSummary,
			templateNames,
			templateSource
		};
	}
} );
</script>