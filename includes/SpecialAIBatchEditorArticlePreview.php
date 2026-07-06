<?php

namespace MediaWiki\Extension\AIBatchEditor;

use ErrorPageError;
use MediaWiki\Extension\AIBatchEditor\Services\ArticlePreviewService;
use MediaWiki\Html\Html;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Title\Title;
use SpecialPage;

/**
 * Special:AIBatchEditorArticlePreview
 *
 * Renders a staged proposed revision in the normal wiki skin.
 *
 * @ingroup SpecialPage
 */
class SpecialAIBatchEditorArticlePreview extends SpecialPage {

	private ArticlePreviewService $articlePreviewService;

	public function __construct( ArticlePreviewService $articlePreviewService ) {
		parent::__construct( 'AIBatchEditorArticlePreview', 'aibatchedit' );
		$this->articlePreviewService = $articlePreviewService;
	}

	protected function getGroupName() {
		return 'wiki';
	}

	/**
	 * @param string|null $subPage
	 * @throws ErrorPageError
	 */
	public function execute( $subPage ) {
		$this->setHeaders();
		$this->outputHeader();

		$token = trim( (string)$this->getRequest()->getVal( 'token', '' ) );
		if ( $token === '' ) {
			throw new ErrorPageError(
				'aibatcheditor-error-article-preview-token-invalid',
				'aibatcheditor-error-article-preview-token-invalid'
			);
		}

		$entry = $this->articlePreviewService->fetch( $token, $this->getUser()->getId() );
		if ( $entry === null ) {
			throw new ErrorPageError(
				'aibatcheditor-error-article-preview-token-expired',
				'aibatcheditor-error-article-preview-token-expired'
			);
		}

		$title = Title::newFromText( $entry['title'] );
		if ( !$title ) {
			throw new ErrorPageError(
				'aibatcheditor-error-article-preview-token-invalid',
				'aibatcheditor-error-article-preview-token-invalid'
			);
		}

		$out = $this->getOutput();
		$out->setPageTitle( $title->getPrefixedText() );

		$out->addHTML(
			Html::rawElement(
				'div',
				[ 'class' => 'mw-message-box warning ext-aibatcheditor-article-preview-banner' ],
				$this->msg( 'aibatcheditor-article-preview-banner' )->parse()
			)
		);

		try {
			$parserOutput = $this->articlePreviewService->render(
				$entry['title'],
				$entry['proposed'],
				$this->getContext()
			);
		} catch ( \InvalidArgumentException $e ) {
			$key = $e->getMessage();
			if ( str_starts_with( $key, 'aibatcheditor-' ) ) {
				throw new ErrorPageError( $key, $key );
			}
			throw new ErrorPageError(
				'aibatcheditor-error-article-preview-token-invalid',
				'aibatcheditor-error-article-preview-token-invalid'
			);
		}

		$out->addParserOutputContent(
			$parserOutput,
			ParserOptions::newFromContext( $this->getContext() )
		);
	}

}