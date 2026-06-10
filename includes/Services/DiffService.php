<?php

namespace MediaWiki\Extension\AIBatchEditor\Services;

use DifferenceEngine;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Context\IContextSource;
use MediaWiki\Language\Language;

/**
 * Renders wikitext diffs using MediaWiki's DifferenceEngine.
 */
class DiffService {

	/**
	 * @param string $original
	 * @param string $proposed
	 * @param IContextSource $context
	 * @param string|null $title Optional page title for diff headers
	 * @return string HTML diff body (table), or empty string if no diff
	 */
	public function renderWikitextDiff(
		string $original,
		string $proposed,
		IContextSource $context,
		?string $title = null
	): string {
		$oldContent = new WikitextContent( $original );
		$newContent = new WikitextContent( $proposed );

		$diffEngine = new DifferenceEngine( $context );
		$diffEngine->setContent( $oldContent, $newContent );

		$lang = $context->getLanguage();
		if ( $lang instanceof Language ) {
			$diffEngine->setTextLanguage( $lang );
		}

		$oldHeader = $title !== null && $title !== ''
			? $context->msg( 'aibatcheditor-diff-old-version', $title )->text()
			: false;
		$newHeader = $title !== null && $title !== ''
			? $context->msg( 'aibatcheditor-diff-new-version', $title )->text()
			: false;

		$html = $diffEngine->getDiff( $oldHeader, $newHeader );
		return is_string( $html ) ? $html : '';
	}

}