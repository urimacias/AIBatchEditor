<?php

namespace MediaWiki\Extension\AIBatchEditor\Api;

use MediaWiki\Api\ApiMain;
use MediaWiki\Api\ApiResult;
use MediaWiki\Extension\AIBatchEditor\Services\BatchLogService;
use MediaWiki\Extension\AIBatchEditor\Services\PageContentService;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * API module to validate and list pages for batch editing.
 *
 * @ingroup API
 */
class ApiAIBatchEditorList extends ApiAIBatchEditorBase {

	private PageContentService $pageContentService;
	private BatchLogService $batchLogService;

	public function __construct(
		ApiMain $mainModule,
		string $moduleName,
		PageContentService $pageContentService,
		BatchLogService $batchLogService
	) {
		parent::__construct( $mainModule, $moduleName, '' );
		$this->pageContentService = $pageContentService;
		$this->batchLogService = $batchLogService;
	}

	public function execute(): void {
		$this->checkAIBatchEditorPermission();
		$params = $this->extractRequestParams();

		$titles = $params['titles'] ?? null;
		$category = $params['category'] ?? null;
		$prefix = $params['prefix'] ?? null;

		if ( ( $titles === null || $titles === '' ) && ( $category === null || $category === '' ) ) {
			$this->dieWithError( 'aibatcheditor-error-no-input', 'no-input' );
		}

		$maxBatch = (int)$this->getBatchConfig()->get( 'AIBatchEditorMaxBatch' );
		if ( $category !== null && $category !== '' ) {
			$this->assertValidCategory( $category );
		}
		$titleTexts = $this->pageContentService->resolveTitleTexts(
			$titles,
			$category,
			$prefix,
			$maxBatch
		);
		$this->enforceBatchLimit( $titleTexts );

		$maxPageSize = $this->getMaxPageSize();
		$pages = [];
		foreach ( $titleTexts as $titleText ) {
			$info = $this->pageContentService->getPageInfo( $titleText, $this->getAuthority() );
			$entry = [
				'title' => $info['title'],
				'exists' => $info['exists'] ? 1 : 0,
				'editable' => $info['editable'] ? 1 : 0,
				'revid' => $info['revid'],
				'size' => $info['size'],
			];
			if ( isset( $info['error'] ) ) {
				$entry['error'] = $info['error'];
			} elseif ( $this->isPageTooLarge( (int)$info['size'] ) ) {
				$entry['error'] = 'too-large';
			}
			$pages[] = $entry;
		}

		ApiResult::setIndexedTagName( $pages, 'page' );
		$result = [ 'pages' => $pages ];

		if ( $category !== null && $category !== '' ) {
			$categoryTotal = $this->pageContentService->countEligibleCategoryMembers( $category, $prefix );
			$result['categoryTotal'] = $categoryTotal;
			$result['categoryLoaded'] = count( $titleTexts );
			$result['categoryTruncated'] = $categoryTotal > count( $titleTexts ) ? 1 : 0;
			$result['maxBatch'] = $maxBatch;
		}

		$this->batchLogService->logList( $this->getAuthority(), [
			'category' => $category,
			'loaded' => count( $titleTexts ),
			'categoryTotal' => $result['categoryTotal'] ?? null,
		] );

		if ( $maxPageSize > 0 ) {
			$result['maxPageSize'] = $maxPageSize;
		}

		$this->getResult()->addValue( null, 'pages', $pages );
		foreach ( $result as $key => $value ) {
			if ( $key !== 'pages' ) {
				$this->getResult()->addValue( null, $key, $value );
			}
		}
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return $this->getSharedTitleParams();
	}

	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=aibatcheditorlist&titles=Página_principal|Main_Page'
				=> 'apihelp-aibatcheditorlist-example-1',
			'action=aibatcheditorlist&category=Example&prefix=Test'
				=> 'apihelp-aibatcheditorlist-example-2',
		];
	}

}