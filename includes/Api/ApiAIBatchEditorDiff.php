<?php

namespace MediaWiki\Extension\AIBatchEditor\Api;

use MediaWiki\Api\ApiMain;
use MediaWiki\Extension\AIBatchEditor\Services\DiffService;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * API module to render a wikitext diff for batch preview.
 *
 * @ingroup API
 */
class ApiAIBatchEditorDiff extends ApiAIBatchEditorBase {

	private DiffService $diffService;

	public function __construct(
		ApiMain $mainModule,
		string $moduleName,
		DiffService $diffService
	) {
		parent::__construct( $mainModule, $moduleName, '' );
		$this->diffService = $diffService;
	}

	public function execute(): void {
		$this->checkAIBatchEditorPermission();
		$params = $this->extractRequestParams();

		$original = $params['original'];
		$proposed = $params['proposed'];
		$title = $params['title'] ?? '';

		$this->assertDiffTextLength( $original, $proposed );

		if ( $original === $proposed ) {
			$this->getResult()->addValue( null, 'aibatcheditordiff', [
				'html' => '',
				'unchanged' => 1,
			] );
			return;
		}

		$html = $this->diffService->renderWikitextDiff(
			$original,
			$proposed,
			$this->getContext(),
			$title !== '' ? $title : null
		);

		$this->getResult()->addValue( null, 'aibatcheditordiff', [
			'html' => $html,
			'unchanged' => 0,
		] );
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'original' => [
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'string',
			],
			'proposed' => [
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'string',
			],
			'title' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=aibatcheditordiff&title=Main_Page&original=old&proposed=new'
				=> 'apihelp-aibatcheditordiff-example-1',
		];
	}

}