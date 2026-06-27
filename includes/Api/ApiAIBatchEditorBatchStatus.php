<?php

namespace MediaWiki\Extension\AIBatchEditor\Api;

use MediaWiki\Api\ApiMain;
use MediaWiki\Extension\AIBatchEditor\Services\BatchRunService;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Read server-side AI batch progress (fast; does not call the LLM).
 *
 * @ingroup API
 */
class ApiAIBatchEditorBatchStatus extends ApiAIBatchEditorBase {

	private BatchRunService $batchRunService;

	public function __construct(
		ApiMain $mainModule,
		string $moduleName,
		BatchRunService $batchRunService
	) {
		parent::__construct( $mainModule, $moduleName, '' );
		$this->batchRunService = $batchRunService;
	}

	public function execute(): void {
		$this->checkAIBatchEditorPermission();
		$params = $this->extractRequestParams();
		$batchId = trim( $params['batchid'] ?? '' );
		if ( $batchId === '' ) {
			$this->dieWithError( 'aibatcheditor-error-batch-missing-id', 'missing-batch-id' );
		}

		$state = $this->batchRunService->getBatch( $batchId, $this->getUser()->getId() );
		if ( $state === null ) {
			$this->dieWithError( 'aibatcheditor-error-batch-not-found', 'batch-not-found' );
		}

		$this->getResult()->addValue( null, 'aibatcheditorbatchstatus', $this->formatBatchState( $state ) );
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'batchid' => [
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'string',
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=aibatcheditorbatchstatus&batchid=00000000-0000-4000-8000-000000000000'
				=> 'apihelp-aibatcheditorbatchstatus-example-1',
		];
	}

}