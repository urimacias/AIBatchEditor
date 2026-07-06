<?php

namespace MediaWiki\Extension\AIBatchEditor\Api;

use MediaWiki\Api\ApiMain;
use MediaWiki\Extension\AIBatchEditor\Services\ArticlePreviewService;
use MediaWiki\Extension\AIBatchEditor\Services\PageContentService;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * API module to stage proposed wikitext for a rendered article preview.
 *
 * @ingroup API
 */
class ApiAIBatchEditorArticlePreview extends ApiAIBatchEditorBase {

	private ArticlePreviewService $articlePreviewService;
	private PageContentService $pageContentService;

	public function __construct(
		ApiMain $mainModule,
		string $moduleName,
		ArticlePreviewService $articlePreviewService,
		PageContentService $pageContentService
	) {
		parent::__construct( $mainModule, $moduleName, '' );
		$this->articlePreviewService = $articlePreviewService;
		$this->pageContentService = $pageContentService;
	}

	public function execute(): void {
		$this->checkAIBatchEditorPermission();
		$params = $this->extractRequestParams();

		$title = trim( (string)$params['title'] );
		$proposed = $params['proposed'];

		if ( $title === '' ) {
			$this->dieWithError( 'aibatcheditor-error-preview-needs-title', 'preview-needs-title' );
		}

		$this->assertProposedWikitextLength( $proposed );

		$info = $this->pageContentService->getPageInfo( $title, $this->getAuthority() );
		if ( isset( $info['error'] ) ) {
			$this->dieWithError( 'aibatcheditor-page-error-' . $info['error'], 'page-error' );
		}

		$userId = $this->getUser()->getId();
		$token = $this->articlePreviewService->store(
			$info['title'],
			$proposed,
			$userId
		);
		$url = $this->articlePreviewService->buildPreviewUrl( $token );
		if ( $url === '' ) {
			$this->dieWithError( 'aibatcheditor-error-article-preview-unavailable', 'preview-unavailable' );
		}

		$this->getResult()->addValue( null, 'aibatcheditorarticlepreview', [
			'token' => $token,
			'url' => $url,
		] );
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'title' => [
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'string',
			],
			'proposed' => [
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'string',
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=aibatcheditorarticlepreview&title=Main_Page&proposed=Preview+text'
				=> 'apihelp-aibatcheditorarticlepreview-example-1',
		];
	}

}