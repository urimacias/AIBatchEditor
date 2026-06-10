<template>
	<section class="ext-aibatcheditor-section">
		<header class="ext-aibatcheditor-section__header">
			<span class="ext-aibatcheditor-section__step">1</span>
			<div class="ext-aibatcheditor-section__titles">
				<h2 class="ext-aibatcheditor-section__title">
					{{ $i18n( 'aibatcheditor-ui-step-pages-title' ).text() }}
				</h2>
				<p class="ext-aibatcheditor-section__desc">
					{{ $i18n( 'aibatcheditor-ui-step-pages-desc' ).text() }}
				</p>
			</div>
		</header>

		<div class="ext-aibatcheditor-section__body">
			<div class="ext-aibatcheditor-page-picker">
				<cdx-field>
					<template #label>
						{{ $i18n( 'aibatcheditor-ui-input-mode' ).text() }}
					</template>
					<cdx-select
						v-model:selected="inputMode"
						:menu-items="inputModeItems"
						:disabled="disabled"
					></cdx-select>
				</cdx-field>

				<cdx-field v-if="inputMode === 'titles'">
					<template #label>
						{{ $i18n( 'aibatcheditor-ui-titles-label' ).text() }}<span
							class="ext-aibatcheditor-required"
							:title="$i18n( 'aibatcheditor-ui-required-field' ).text()"
						>*</span>
					</template>
					<cdx-text-area
						v-model="titles"
						:placeholder="$i18n( 'aibatcheditor-ui-titles-placeholder' ).text()"
						:rows="6"
						:disabled="disabled"
					></cdx-text-area>
					<template #help-text>
						{{ $i18n( 'aibatcheditor-ui-titles-help' ).text() }}
					</template>
				</cdx-field>

				<template v-else>
					<cdx-field>
						<template #label>
							{{ $i18n( 'aibatcheditor-ui-category-label' ).text() }}<span
								class="ext-aibatcheditor-required"
								:title="$i18n( 'aibatcheditor-ui-required-field' ).text()"
							>*</span>
						</template>
						<cdx-text-input
							v-model="category"
							:placeholder="$i18n( 'aibatcheditor-ui-category-placeholder' ).text()"
							:disabled="disabled"
						></cdx-text-input>
					</cdx-field>
					<cdx-field>
						<template #label>
							{{ $i18n( 'aibatcheditor-ui-prefix-label' ).text() }}
						</template>
						<cdx-text-input
							v-model="prefix"
							:placeholder="$i18n( 'aibatcheditor-ui-prefix-placeholder' ).text()"
							:disabled="disabled"
						></cdx-text-input>
						<template #help-text>
							{{ $i18n( 'aibatcheditor-ui-prefix-help' ).text() }}
						</template>
					</cdx-field>
				</template>

				<div class="ext-aibatcheditor-page-picker__actions">
					<cdx-button
						action="progressive"
						weight="primary"
						:disabled="disabled"
						@click="$emit( 'validate' )"
					>
						{{ $i18n( 'aibatcheditor-ui-validate' ).text() }}
					</cdx-button>
				</div>
			</div>
		</div>
	</section>
</template>

<script>
const { defineComponent, ref, watch, computed } = require( 'vue' );
const {
	CdxField,
	CdxSelect,
	CdxTextArea,
	CdxTextInput,
	CdxButton
} = require( '../codex.js' );

module.exports = exports = defineComponent( {
	name: 'PagePicker',
	components: {
		CdxField,
		CdxSelect,
		CdxTextArea,
		CdxTextInput,
		CdxButton
	},
	props: {
		disabled: {
			type: Boolean,
			default: false
		}
	},
	emits: [ 'validate', 'update:selection' ],
	setup( props, { emit } ) {
		const inputMode = ref( 'titles' );
		const titles = ref( '' );
		const category = ref( '' );
		const prefix = ref( '' );

		const inputModeItems = computed( () => [
			{
				label: mw.msg( 'aibatcheditor-ui-mode-titles' ),
				value: 'titles'
			},
			{
				label: mw.msg( 'aibatcheditor-ui-mode-category' ),
				value: 'category'
			}
		] );

		const emitSelection = () => {
			emit( 'update:selection', {
				inputMode: inputMode.value,
				titles: titles.value,
				category: category.value,
				prefix: prefix.value
			} );
		};

		watch( [ inputMode, titles, category, prefix ], emitSelection, { immediate: true } );

		return {
			inputMode,
			titles,
			category,
			prefix,
			inputModeItems
		};
	}
} );
</script>