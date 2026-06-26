<?php

namespace MediaWiki\Extension\AIBatchEditor\Api;

use MediaWiki\Api\ApiMain;
use MediaWiki\Extension\AIBatchEditor\Services\PageContentService;
use MediaWiki\Extension\AIBatchEditor\Services\PromptFactory;
use MediaWiki\Extension\AIBatchEditor\Services\TemplateSourceService;
use RuntimeException;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * API module to preview LLM prompts without calling the AI.
 *
 * @ingroup API
 */
class ApiAIBatchEditorPreview extends ApiAIBatchEditorBase {

	private PageContentService $pageContentService;
	private PromptFactory $promptFactory;
	private TemplateSourceService $templateSourceService;

	public function __construct(
		ApiMain $mainModule,
		string $moduleName,
		PageContentService $pageContentService,
		PromptFactory $promptFactory,
		TemplateSourceService $templateSourceService
	) {
		parent::__construct( $mainModule, $moduleName, '' );
		$this->pageContentService = $pageContentService;
		$this->promptFactory = $promptFactory;
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

		$titleText = trim( $params['titles'] ?? '' );
		if ( $titleText === '' ) {
			$this->dieWithError( 'aibatcheditor-error-preview-needs-title', 'preview-needs-title' );
		}

		$templateContext = '';
		if ( $operation === 'templates' ) {
			try {
				$reference = $this->templateSourceService->buildReferenceContext( $templates, $templateSource );
				$templateContext = $reference['context'];
			} catch ( RuntimeException $e ) {
				$this->dieWithRuntimeMessageKey( $e, 'template-fetch' );
			}
		}

		$info = $this->pageContentService->getPageInfo( $titleText, $this->getAuthority(), true );
		if ( isset( $info['error'] ) ) {
			$this->dieWithError( 'aibatcheditor-page-error-' . $info['error'], 'page-error' );
		}

		$wikitext = $info['wikitext'] ?? '';
		$pageInstructions = trim( $params['pageinstructions'] ?? '' );
		$effectiveInstructions = $pageInstructions !== '' ? $pageInstructions : $instructions;

		$prompts = $this->promptFactory->buildPrompts(
			$operation,
			$profile,
			$wikitext,
			$effectiveInstructions,
			$templateContext
		);

		$this->getResult()->addValue( null, 'aibatcheditorpreview', [
			'title' => $info['title'],
			'system' => $prompts['system'],
			'user' => $prompts['user'],
		] );
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
			'pageinstructions' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'templates' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'templatesource' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=aibatcheditorpreview&titles=Main_Page&operation=custom&profile=balanced&instructions=Fix+links'
				=> 'apihelp-aibatcheditorpreview-example-1',
		];
	}

}