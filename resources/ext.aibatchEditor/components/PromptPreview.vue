<template>
	<div class="ext-aibatcheditor-prompt-preview">
		<cdx-message
			v-if="showNotice"
			type="notice"
			:inline="true"
			class="ext-aibatcheditor-prompt-preview__notice"
		>
			{{ $i18n( 'aibatcheditor-ui-preview-prompt-notice' ).text() }}
		</cdx-message>

		<p
			v-if="title"
			class="ext-aibatcheditor-prompt-preview__title"
		>
			{{ $i18n( 'aibatcheditor-ui-preview-prompt-for', title ).text() }}
		</p>

		<div class="ext-aibatcheditor-prompt-preview__block">
			<h4 class="ext-aibatcheditor-prompt-preview__label">
				{{ $i18n( 'aibatcheditor-ui-preview-prompt-system' ).text() }}
			</h4>
			<pre class="ext-aibatcheditor-prompt-preview__code">{{ systemPrompt }}</pre>
		</div>

		<div class="ext-aibatcheditor-prompt-preview__block">
			<h4 class="ext-aibatcheditor-prompt-preview__label">
				{{ $i18n( 'aibatcheditor-ui-preview-prompt-user' ).text() }}
			</h4>
			<pre class="ext-aibatcheditor-prompt-preview__code">{{ userPrompt }}</pre>
		</div>
	</div>
</template>

<script>
const { defineComponent } = require( 'vue' );
const { CdxMessage } = require( '../codex.js' );

module.exports = exports = defineComponent( {
	name: 'PromptPreview',
	components: {
		CdxMessage
	},
	props: {
		title: {
			type: String,
			default: ''
		},
		systemPrompt: {
			type: String,
			required: true
		},
		userPrompt: {
			type: String,
			required: true
		},
		showNotice: {
			type: Boolean,
			default: true
		}
	}
} );
</script>