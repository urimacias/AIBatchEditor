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
							{{ $i18n( 'aibatcheditor-ui-operation-label' ).text() }}
						</template>
						<cdx-select
							v-model:selected="selectedOperation"
							:menu-items="operationItems"
							:default-label="$i18n( 'aibatcheditor-ui-operation-placeholder' ).text()"
						></cdx-select>
					</cdx-field>

					<cdx-field>
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

				<cdx-field>
					<template #label>
						{{ $i18n( 'aibatcheditor-ui-instructions-label' ).text() }}
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

				<cdx-field>
					<template #label>
						{{ $i18n( 'aibatcheditor-ui-summary-label' ).text() }}
					</template>
					<cdx-text-input
						v-model="editSummary"
						:placeholder="$i18n( 'aibatcheditor-ui-summary-placeholder' ).text()"
					></cdx-text-input>
					<template #help-text>
						{{ $i18n( 'aibatcheditor-ui-summary-help' ).text() }}
					</template>
				</cdx-field>

				<div class="ext-aibatcheditor-operation-selector__actions">
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
const { CdxField, CdxSelect, CdxTextArea, CdxTextInput, CdxButton } = require( '../codex.js' );

module.exports = exports = defineComponent( {
	name: 'OperationSelector',
	components: {
		CdxField,
		CdxSelect,
		CdxTextArea,
		CdxTextInput,
		CdxButton
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
		}
	},
	emits: [ 'run', 'update:options' ],
	setup( props, { emit } ) {
		const operationLabels = {
			wikilinks: mw.msg( 'aibatcheditor-ui-operation-wikilinks' ),
			spellcheck: mw.msg( 'aibatcheditor-ui-operation-spellcheck' ),
			formatting: mw.msg( 'aibatcheditor-ui-operation-formatting' ),
			style: mw.msg( 'aibatcheditor-ui-operation-style' ),
			custom: mw.msg( 'aibatcheditor-ui-operation-custom' )
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

		const profileHelpText = computed( () => {
			const operation = selectedOperation.value;
			const profile = selectedProfile.value;
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

		const emitOptions = () => {
			emit( 'update:options', {
				operation: selectedOperation.value,
				profile: selectedProfile.value,
				instructions: instructions.value,
				summary: editSummary.value
			} );
		};

		watch( [ selectedOperation, selectedProfile, instructions, editSummary ], emitOptions, { immediate: true } );

		return {
			operationItems,
			profileItems,
			profileHelpText,
			selectedOperation,
			selectedProfile,
			instructions,
			editSummary
		};
	}
} );
</script>