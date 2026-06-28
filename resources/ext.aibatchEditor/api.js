/**
 * API helpers for AIBatchEditor.
 *
 * @module ext.aibatchEditor.api
 */
'use strict';

/** @type {mw.Api|null} */
let apiInstance = null;

/**
 * @return {mw.Api}
 */
function getApi() {
	if ( !apiInstance ) {
		apiInstance = new mw.Api();
	}
	return apiInstance;
}

/**
 * @return {Object}
 */
function getClientConfig() {
	return mw.config.get( 'wgAIBatchEditor', {} );
}

/**
 * @return {number}
 */
function getAdvanceTimeoutMs() {
	const cfg = getClientConfig();
	const requestTimeout = Math.max( 10, cfg.requestTimeout || 120 );
	const concurrency = Math.max( 1, cfg.concurrency || 1 );
	return ( requestTimeout * concurrency + 45 ) * 1000;
}

/** @type {number} */
const POLL_TIMEOUT_MS = 30000;

/**
 * @param {string} action
 * @param {Object} params
 * @param {number} [timeoutMs]
 * @return {jQuery.Promise}
 */
function postAction( action, params, timeoutMs ) {
	const ajaxOptions = timeoutMs ? { timeout: timeoutMs } : undefined;
	return getApi().post(
		Object.assign( {}, baseParams(), { action }, params ),
		ajaxOptions
	);
}

/**
 * Default parameters for all extension API calls.
 *
 * @return {Object}
 */
function baseParams() {
	return {
		format: 'json',
		formatversion: 2,
		errorformat: 'plaintext',
		errorlang: mw.config.get( 'wgUserLanguage' )
	};
}

/**
 * Normalize list-shaped API results (formatversion 1 objects or fv2 arrays).
 *
 * @param {*} value
 * @return {Array}
 */
function normalizeList( value ) {
	if ( Array.isArray( value ) ) {
		return value;
	}
	if ( value && typeof value === 'object' ) {
		return Object.keys( value )
			.filter( ( key ) => String( parseInt( key, 10 ) ) === key )
			.sort( ( a, b ) => Number( a ) - Number( b ) )
			.map( ( key ) => value[ key ] );
	}
	return [];
}

/**
 * @param {Object} params
 * @return {jQuery.Promise}
 */
function listPages( params ) {
	return postAction( 'aibatcheditorlist', params );
}

/**
 * @param {Object} params
 * @return {jQuery.Promise}
 */
function startBatch( params ) {
	return postAction( 'aibatcheditorbatchstart', params, POLL_TIMEOUT_MS );
}

/**
 * Read batch progress from object cache (fast; does not call the LLM).
 *
 * @param {Object} params
 * @return {jQuery.Promise}
 */
function getBatchStatus( params ) {
	return postAction( 'aibatcheditorbatchstatus', params, POLL_TIMEOUT_MS );
}

/**
 * Process the next chunk of a batch (may call the LLM; long-running).
 *
 * @param {Object} params
 * @return {jQuery.Promise}
 */
function advanceBatch( params ) {
	return postAction( 'aibatcheditorbatchadvance', params, getAdvanceTimeoutMs() );
}

/**
 * @param {Object} params
 * @return {jQuery.Promise}
 */
function cancelBatch( params ) {
	return postAction( 'aibatcheditorbatchcancel', params, POLL_TIMEOUT_MS );
}

/**
 * @param {Object} params
 * @return {jQuery.Promise}
 */
function fetchDiff( params ) {
	return getApi().post( Object.assign( {}, baseParams(), {
		action: 'aibatcheditordiff'
	}, params ) );
}

/**
 * Refresh draft tokens for approved edits before saving.
 *
 * The UI calls this automatically before {@link saveEdits}. Each edit object must
 * include title, revid, and proposed wikitext (no draftToken). Returns fresh
 * draftToken values bound to the current user and base revision.
 *
 * @param {Object} params
 * @param {string} params.edits JSON array of { title, revid, proposed } objects
 * @return {jQuery.Promise}
 */
function refreshDraftTokens( params ) {
	return getApi().postWithToken( 'csrf', Object.assign( {}, baseParams(), {
		action: 'aibatcheditorrefreshdrafttokens'
	}, params ) );
}

/**
 * @param {Object} params
 * @return {jQuery.Promise}
 */
function saveEdits( params ) {
	return getApi().postWithToken( 'csrf', Object.assign( {}, baseParams(), {
		action: 'aibatcheditorsave'
	}, params ) );
}

/**
 * @param {Object} params
 * @return {jQuery.Promise}
 */
function previewPrompt( params ) {
	return getApi().post( Object.assign( {}, baseParams(), {
		action: 'aibatcheditorpreview'
	}, params ) );
}

/**
 * True when MediaWiki could not resolve an i18n key (⧼key⧽ or <key>).
 *
 * @param {string} text
 * @return {boolean}
 */
function isWrappedMessageKey( text ) {
	return typeof text === 'string' && /^[⧼<][^⧽>]+[⧽>]$/.test( text.trim() );
}

/**
 * Read a human-readable API error from fv1 or fv2 responses.
 *
 * @param {Object} [data]
 * @return {string}
 */
function extractApiErrorText( data ) {
	if ( !data ) {
		return '';
	}
	if ( data.error && data.error.info && !isWrappedMessageKey( data.error.info ) ) {
		return data.error.info;
	}
	if ( Array.isArray( data.errors ) && data.errors.length > 0 ) {
		const err = data.errors[ 0 ];
		if ( err.text && !isWrappedMessageKey( err.text ) ) {
			return err.text;
		}
		if ( err.html && !isWrappedMessageKey( err.html ) ) {
			return err.html;
		}
		if ( err[ '*' ] && !isWrappedMessageKey( err[ '*' ] ) ) {
			return err[ '*' ];
		}
	}
	return '';
}

/** @type {Object<string, string>} */
const API_ERROR_CODE_MESSAGES = {
	http: 'aibatcheditor-error-api-http',
	timeout: 'aibatcheditor-error-api-timeout',
	abort: 'aibatcheditor-error-api-abort',
	'ok-but-empty': 'aibatcheditor-error-api-empty',
	'batch-not-found': 'aibatcheditor-error-batch-not-found',
	'missing-batch-id': 'aibatcheditor-error-batch-missing-id',
	'no-input': 'aibatcheditor-error-no-input',
	'no-titles': 'aibatcheditor-error-no-titles',
	'preview-needs-title': 'aibatcheditor-error-preview-needs-title',
	'template-fetch': 'aibatcheditor-error-template-fetch-failed',
	'template-page-not-found': 'aibatcheditor-error-template-page-not-found',
	'category-not-found': 'aibatcheditor-error-category-not-found',
	'templates-needs-names': 'aibatcheditor-error-templates-needs-names',
	'custom-needs-instructions': 'aibatcheditor-error-custom-needs-instructions'
};

/**
 * @param {string} code
 * @return {string[]}
 */
function messageKeysForCode( code ) {
	const keys = [];
	if ( API_ERROR_CODE_MESSAGES[ code ] ) {
		keys.push( API_ERROR_CODE_MESSAGES[ code ] );
	}
	if ( code.indexOf( 'aibatcheditor-' ) === 0 ) {
		keys.push( code );
		if ( code === 'aibatcheditor-error-llm-http' ) {
			keys.push( 'aibatcheditor-error-llm-http-generic', 'aibatcheditor-error-llm-request-failed' );
		}
	} else {
		keys.push( 'aibatcheditor-error-' + code );
	}
	return keys;
}

/**
 * @param {string} code
 * @return {string}
 */
function localizeErrorCode( code ) {
	const keys = messageKeysForCode( code );
	for ( let i = 0; i < keys.length; i++ ) {
		const key = keys[ i ];
		if ( mw.message( key ).exists() ) {
			return mw.msg( key );
		}
	}
	return code;
}

/**
 * @param {string} code
 * @param {Object} [pageResult]
 * @return {string}
 */
function formatError( code, pageResult ) {
	if ( !code ) {
		return '';
	}
	if ( pageResult && pageResult.errorInfo && !isWrappedMessageKey( pageResult.errorInfo ) ) {
		return pageResult.errorInfo;
	}
	return localizeErrorCode( code );
}

/**
 * @param {string} code
 * @param {Object} [data]
 * @return {string}
 */
function formatApiError( code, data ) {
	const apiText = extractApiErrorText( data );
	if ( apiText ) {
		return apiText;
	}
	return formatError( code );
}

module.exports = {
	listPages,
	startBatch,
	getBatchStatus,
	advanceBatch,
	cancelBatch,
	fetchDiff,
	refreshDraftTokens,
	saveEdits,
	previewPrompt,
	extractApiErrorText,
	formatError,
	formatApiError,
	normalizeList
};