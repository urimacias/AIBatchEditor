<?php

namespace MediaWiki\Extension\AIBatchEditor\Api;

use MediaWiki\Api\ApiMain;
use MediaWiki\Api\ApiResult;
use MediaWiki\Extension\AIBatchEditor\Services\BatchLogService;
use MediaWiki\Extension\AIBatchEditor\Services\BatchRunService;
use MediaWiki\Extension\AIBatchEditor\Services\PageContentService;
use MediaWiki\Extension\AIBatchEditor\Services\PromptFactory;
use MediaWiki\Extension\AIBatchEditor\Services\TemplateSourceService;
use RuntimeException;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Start a server-side AI batch run.
 *
 * @ingroup API
 */
class ApiAIBatchEditorBatchStart extends ApiAIBatchEditorBase {

	private PageContentService $pageContentService;
	private BatchRunService $batchRunService;
	private BatchLogService $batchLogService;
	private TemplateSourceService $templateSourceService;

	public function __construct(
		ApiMain $mainModule,
		string $moduleName,
		PageContentService $pageContentService,
		BatchRunService $batchRunService,
		BatchLogService $batchLogService,
		TemplateSourceService $templateSourceService
	) {
		parent::__construct( $mainModule, $moduleName, '' );
		$this->pageContentService = $pageContentService;
		$this->batchRunService = $batchRunService;
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
		if ( $titles === null || trim( $titles ) === '' ) {
			$this->dieWithError( 'aibatcheditor-error-no-input', 'no-input' );
		}

		$maxBatch = (int)$this->getBatchConfig()->get( 'AIBatchEditorMaxBatch' );
		$titleTexts = $this->pageContentService->resolveTitleTexts(
			$titles,
			null,
			null,
			$maxBatch,
			$this->getAuthority()
		);
		$this->enforceBatchLimit( $titleTexts );

		$pageInstructions = $this->parsePageInstructions( $params['pageinstructions'] ?? '' );

		$state = $this->batchRunService->startBatch(
			$this->getAuthority(),
			$titleTexts,
			$operation,
			$profile,
			$instructions,
			$templateContext,
			$pageInstructions
		);

		$this->batchLogService->logProcess( $this->getAuthority(), [
			'operation' => $operation,
			'profile' => $profile,
			'batchId' => $state['batchId'],
			'pageCount' => $state['total'],
			'mode' => 'server-batch',
			'promptVersion' => PromptFactory::PROMPT_VERSION,
		] );

		$this->getResult()->addValue( null, 'aibatcheditorbatchstart', $this->formatBatchState( $state ) );
	}

	/**
	 * @return array<string, string>
	 */
	private function parsePageInstructions( string $json ): array {
		if ( trim( $json ) === '' ) {
			return [];
		}
		$data = json_decode( $json, true );
		if ( !is_array( $data ) ) {
			$this->dieWithError( 'aibatcheditor-error-batch-invalid-page-instructions', 'invalid-page-instructions' );
		}
		$map = [];
		foreach ( $data as $title => $value ) {
			if ( is_string( $title ) && is_string( $value ) ) {
				$map[$title] = $value;
			}
		}
		return $map;
	}

	/**
	 * @param array<string, mixed> $state
	 * @return array<string, mixed>
	 */
	private function formatBatchState( array $state ): array {
		$pages = $state['pages'] ?? [];
		ApiResult::setIndexedTagName( $pages, 'page' );
		return [
			'batchId' => $state['batchId'],
			'status' => $state['status'],
			'total' => $state['total'],
			'completed' => $state['completed'],
			'operation' => $state['operation'],
			'profile' => $state['profile'],
			'pages' => $pages,
		];
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'titles' => [
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'string',
			],
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
			'pageinstructions' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=aibatcheditorbatchstart&titles=Main_Page&operation=spellcheck&profile=balanced'
				=> 'apihelp-aibatcheditorbatchstart-example-1',
		];
	}

}