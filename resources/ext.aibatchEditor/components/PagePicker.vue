<template>
	<div class="ext-aibatcheditor-page-picker">
		<cdx-field>
			<template #label>
				{{ $i18n( 'aibatcheditor-ui-input-mode' ).text() }}
			</template>
			<cdx-select
				v-model:selected="inputMode"
				:menu-items="inputModeItems"
			></cdx-select>
		</cdx-field>

		<cdx-field v-if="inputMode === 'titles'">
			<template #label>
				{{ $i18n( 'aibatcheditor-ui-titles-label' ).text() }}
			</template>
			<cdx-text-area
				v-model="titles"
				:placeholder="$i18n( 'aibatcheditor-ui-titles-placeholder' ).text()"
				:rows="6"
			></cdx-text-area>
			<template #help-text>
				{{ $i18n( 'aibatcheditor-ui-titles-help' ).text() }}
			</template>
		</cdx-field>

		<template v-else>
			<cdx-field>
				<template #label>
					{{ $i18n( 'aibatcheditor-ui-category-label' ).text() }}
				</template>
				<cdx-text-input
					v-model="category"
					:placeholder="$i18n( 'aibatcheditor-ui-category-placeholder' ).text()"
				></cdx-text-input>
			</cdx-field>
			<cdx-field>
				<template #label>
					{{ $i18n( 'aibatcheditor-ui-prefix-label' ).text() }}
				</template>
				<cdx-text-input
					v-model="prefix"
					:placeholder="$i18n( 'aibatcheditor-ui-prefix-placeholder' ).text()"
				></cdx-text-input>
				<template #help-text>
					{{ $i18n( 'aibatcheditor-ui-prefix-help' ).text() }}
				</template>
			</cdx-field>
		</template>

		<div class="ext-aibatcheditor-page-picker__actions">
			<cdx-button
				action="progressive"
				:disabled="disabled"
				@click="$emit( 'validate' )"
			>
				{{ $i18n( 'aibatcheditor-ui-validate' ).text() }}
			</cdx-button>
		</div>
	</div>
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