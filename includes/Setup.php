<?php

namespace MediaWiki\Extension\AIBatchEditor;

/**
 * Early extension bootstrap (extension.json callback).
 */
class Setup {

	public static function onRegistration(): void {
		global $IP;

		EnvFile::load( $IP . '/.env' );

		if ( empty( $GLOBALS['wgAIBatchEditorApiKey'] ) ) {
			$key = getenv( 'XAI_API_KEY' );
			if ( is_string( $key ) && $key !== '' ) {
				$GLOBALS['wgAIBatchEditorApiKey'] = $key;
			}
		}

		if ( empty( $GLOBALS['wgAIBatchEditorStubMode'] ) ) {
			$stub = getenv( 'AIBATCHEDITOR_E2E_STUB' );
			if ( $stub === '1' ) {
				$GLOBALS['wgAIBatchEditorStubMode'] = true;
			}
		}
	}

}