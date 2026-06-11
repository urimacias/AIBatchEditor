<?php

namespace MediaWiki\Extension\AIBatchEditor\Api;

use MediaWiki\Api\ApiMain;
use MediaWiki\Extension\AIBatchEditor\Exceptions\LLMServiceException;
use MediaWiki\Extension\AIBatchEditor\Services\AIService;
use MediaWiki\Extension\AIBatchEditor\Services\BatchLogService;
use MediaWiki\Extension\AIBatchEditor\Services\PageContentService;
use MediaWiki\Extension\AIBatchEditor\Services\PromptFactory;
use MediaWiki\Extension\AIBatchEditor\Services\RateLimiterService;
use MediaWiki\Extension\AIBatchEditor\Services\TemplateSourceService;
use RuntimeException;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * API module to run an AI operation on a batch of pages.
 *
 * @ingroup API
 */
class ApiAIBatchEditorProcess extends ApiAIBatchEditorBase {

	private PageContentService $pageContentService;
	private AIService $aiService;
	private PromptFactory $promptFactory;
	private RateLimiterService $rateLimiter;
	private BatchLogService $batchLogService;
	private TemplateSourceService $templateSourceService;

	public function __construct(
		ApiMain $mainModule,
		string $moduleName,
		PageContentService $pageContentService,
		AIService $aiService,
		PromptFactory $promptFactory,
		RateLimiterService $rateLimiter,
		BatchLogService $batchLogService,
		TemplateSourceService $templateSourceService
	) {
		parent::__construct( $mainModule, $moduleName, '' );
		$this->pageContentService = $pageContentService;
		$this->aiService = $aiService;
		$this->promptFactory = $promptFactory;
		$this->rateLimiter = $rateLimiter;
		$this->batchLogService = $batchLogService;
		$this->templateSourceService = $templateSourceService;
	}

	public function execute(): void {
		$this->checkAIBatchEditorPermission();
		$params = $this->extractRequestParams();

		$operation = $params['operation'];
		$profile = $params['profile'];
		$this->validateOperation( $operation );
		$this->validateProfile( $profile );

		$instructions = trim( $params['instructions'] ?? '' );
		$templates = trim( $params['templates'] ?? '' );
		$templateSource = trim( $params['templatesource'] ?? '' );
		$this->validateInstructionsForOperation( $operation, $instructions, $templates );

		$templateContext = '';
		if ( $operation === 'templates' ) {
			try {
				$reference = $this->templateSourceService->buildReferenceContext( $templates, $templateSource );
				$templateContext = $reference['context'];
			} catch ( RuntimeException $e ) {
				$this->dieWithError( $e->getMessage(), 'template-fetch' );
			}
		}

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
			$maxBatch,
			$this->getAuthority()
		);
		$this->enforceBatchLimit( $titleTexts );

		$pages = [];
		foreach ( $titleTexts as $titleText ) {
			$pages[] = $this->processPage(
				$titleText,
				$operation,
				$profile,
				$instructions,
				$templateContext
			);
		}

		$result = [
			'operation' => $operation,
			'profile' => $profile,
			'pages' => $pages,
		];

		if ( isset( $params['summary'] ) && $params['summary'] !== '' ) {
			$result['summary'] = $params['summary'];
		}

		$this->batchLogService->logProcess( $this->getAuthority(), [
			'operation' => $operation,
			'profile' => $profile,
			'pageCount' => count( $pages ),
			'changed' => count( array_filter( $pages, static fn ( $p ) => ( $p['status'] ?? '' ) === 'changed' ) ),
			'errors' => count( array_filter( $pages, static fn ( $p ) => ( $p['status'] ?? '' ) === 'error' ) ),
		] );

		$this->getResult()->addValue( null, 'aibatcheditorprocess', $result );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function processPage(
		string $titleText,
		string $operation,
		string $profile,
		string $instructions = '',
		string $templateContext = ''
	): array {
		$info = $this->pageContentService->getPageInfo(
			$titleText,
			$this->getAuthority(),
			true
		);

		$entry = [
			'title' => $info['title'],
			'revid' => $info['revid'],
		];

		if ( isset( $info['error'] ) ) {
			$entry['status'] = 'error';
			$entry['error'] = 'aibatcheditor-page-error-' . $info['error'];
			return $entry;
		}

		if ( $this->isPageTooLarge( (int)( $info['size'] ?? 0 ) ) ) {
			$maxSize = $this->getMaxPageSize();
			$entry['status'] = 'error';
			$entry['error'] = 'aibatcheditor-error-page-too-large';
			$entry['errorInfo'] = $this->msg(
				'aibatcheditor-error-page-too-large',
				$info['size'],
				$maxSize
			)->text();
			return $entry;
		}

		$original = $info['wikitext'] ?? '';
		$entry['original'] = $original;

		$userId = $this->getUser()->getId();
		if ( !$this->rateLimiter->canConsume( $userId, 1 ) ) {
			$status = $this->rateLimiter->getStatus( $userId );
			$entry['status'] = 'error';
			$entry['error'] = 'aibatcheditor-error-rate-limit';
			$entry['errorInfo'] = $this->msg(
				'aibatcheditor-error-rate-limit',
				$status['limit'],
				$status['used']
			)->text();
			return $entry;
		}

		try {
			$prompts = $this->promptFactory->buildPrompts(
				$operation,
				$profile,
				$original,
				$instructions,
				$templateContext
			);
			if ( $this->shouldIncludePromptPreview() ) {
				$entry['promptSystem'] = $prompts['system'];
				$entry['promptUser'] = $prompts['user'];
			}
			$proposed = $this->aiService->complete( $prompts );
			$this->rateLimiter->consume( $userId, 1 );
		} catch ( LLMServiceException $e ) {
			$entry['status'] = 'error';
			$entry['error'] = $e->getMessageKey();
			$entry['errorInfo'] = $this->formatLlmErrorForClient( $e );
			$this->batchLogService->logProcess( $this->getAuthority(), [
				'title' => $info['title'],
				'operation' => $operation,
				'llmError' => $e->getMessageKey(),
				'detail' => $e->getLogDetail(),
			] );
			return $entry;
		} catch ( RuntimeException $e ) {
			$entry['status'] = 'error';
			$entry['error'] = 'aibatcheditor-error-llm-request-failed';
			$entry['errorInfo'] = $this->msg( 'aibatcheditor-error-llm-request-failed' )->text();
			return $entry;
		}

		if ( $this->isPageTooLarge( strlen( $proposed ) ) ) {
			$maxSize = $this->getMaxPageSize();
			$entry['status'] = 'error';
			$entry['error'] = 'aibatcheditor-error-page-too-large';
			$entry['errorInfo'] = $this->msg(
				'aibatcheditor-error-page-too-large',
				strlen( $proposed ),
				$maxSize
			)->text();
			return $entry;
		}

		if ( $proposed === $original ) {
			$entry['status'] = 'omitted';
			return $entry;
		}

		$entry['status'] = 'changed';
		$entry['proposed'] = $proposed;
		return $entry;
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return $this->getSharedTitleParams() + [
			'operation' => [
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => PromptFactory::OPERATIONS,
			],
			'profile' => [
				ParamValidator::PARAM_TYPE => PromptFactory::PROFILES,
				ParamValidator::PARAM_DEFAULT => 'balanced',
			],
			'instructions' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'templates' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'templatesource' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'summary' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=aibatcheditorprocess&titles=Página_principal&operation=spellcheck&profile=conservative'
				=> 'apihelp-aibatcheditorprocess-example-1',
		];
	}

}