/**
 * API helpers for AIBatchEditor.
 *
 * @module ext.aibatchEditor.api
 */
'use strict';

/**
 * @return {mw.Api}
 */
function getApi() {
	return new mw.Api();
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
	return getApi().post( Object.assign( {}, baseParams(), {
		action: 'aibatcheditorlist'
	}, params ) );
}

/**
 * @param {Object} params
 * @return {jQuery.Promise}
 */
function processPage( params ) {
	return getApi().post( Object.assign( {}, baseParams(), {
		action: 'aibatcheditorprocess'
	}, params ) );
}

/**
 * @param {Object} params
 * @return {jQuery.Promise}
 */
function startBatch( params ) {
	return getApi().post( Object.assign( {}, baseParams(), {
		action: 'aibatcheditorbatchstart'
	}, params ) );
}

/**
 * @param {Object} params
 * @return {jQuery.Promise}
 */
function getBatchStatus( params ) {
	return getApi().post( Object.assign( {}, baseParams(), {
		action: 'aibatcheditorbatchstatus'
	}, params ) );
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
 * @param {string} code
 * @param {Object} [pageResult]
 * @return {string}
 */
function formatError( code, pageResult ) {
	if ( !code ) {
		return '';
	}
	if ( pageResult && pageResult.errorInfo ) {
		return pageResult.errorInfo;
	}
	const msg = mw.msg( code );
	return msg !== '' ? msg : code;
}

module.exports = {
	listPages,
	processPage,
	startBatch,
	getBatchStatus,
	fetchDiff,
	saveEdits,
	previewPrompt,
	formatError,
	normalizeList
};