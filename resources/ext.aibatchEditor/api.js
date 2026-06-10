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
 * @param {Object} params
 * @return {jQuery.Promise}
 */
function listPages( params ) {
	return getApi().post( Object.assign( {
		action: 'aibatcheditorlist',
		format: 'json'
	}, params ) );
}

/**
 * @param {Object} params
 * @return {jQuery.Promise}
 */
function processPage( params ) {
	return getApi().post( Object.assign( {
		action: 'aibatcheditorprocess',
		format: 'json'
	}, params ) );
}

/**
 * @param {Object} params
 * @return {jQuery.Promise}
 */
function fetchDiff( params ) {
	return getApi().post( Object.assign( {
		action: 'aibatcheditordiff',
		format: 'json'
	}, params ) );
}

/**
 * @param {Object} params
 * @return {jQuery.Promise}
 */
function saveEdits( params ) {
	return getApi().post( Object.assign( {
		action: 'aibatcheditorsave',
		format: 'json'
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
	fetchDiff,
	saveEdits,
	formatError
};