<?php

namespace MediaWiki\Extension\AIBatchEditor\Api;

use MediaWiki\Api\ApiMain;
use MediaWiki\Extension\AIBatchEditor\Services\BatchLogService;
use MediaWiki\Extension\AIBatchEditor\Services\BatchRunService;
use MediaWiki\Extension\AIBatchEditor\Services\PromptFactory;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Cancel a running server-side AI batch run.
 *
 * @ingroup API
 */
class ApiAIBatchEditorBatchCancel extends ApiAIBatchEditorBase {

	private BatchRunService $batchRunService;
	private BatchLogService $batchLogService;

	public function __construct(
		ApiMain $mainModule,
		string $moduleName,
		BatchRunService $batchRunService,
		BatchLogService $batchLogService
	) {
		parent::__construct( $mainModule, $moduleName, '' );
		$this->batchRunService = $batchRunService;
		$this->batchLogService = $batchLogService;
	}

	public function execute(): void {
		$this->checkAIBatchEditorPermission();
		$params = $this->extractRequestParams();
		$batchId = trim( $params['batchid'] ?? '' );
		if ( $batchId === '' ) {
			$this->dieWithError( 'aibatcheditor-error-batch-missing-id', 'missing-batch-id' );
		}

		$state = $this->batchRunService->cancelBatch( $batchId, $this->getAuthority() );
		if ( $state === null ) {
			$this->dieWithError( 'aibatcheditor-error-batch-not-found', 'batch-not-found' );
		}

		if ( ( $state['status'] ?? '' ) === 'cancelled' && empty( $state['cancelLogged'] ) ) {
			$pages = $state['pages'] ?? [];
			$this->batchLogService->logProcess( $this->getAuthority(), [
				'batchId' => $batchId,
				'operation' => $state['operation'] ?? '',
				'profile' => $state['profile'] ?? '',
				'pageCount' => (int)( $state['total'] ?? 0 ),
				'changed' => count( array_filter( $pages, static fn ( $p ) => ( $p['status'] ?? '' ) === 'changed' ) ),
				'errors' => count( array_filter( $pages, static fn ( $p ) => ( $p['status'] ?? '' ) === 'error' ) ),
				'mode' => 'server-batch-cancelled',
				'promptVersion' => PromptFactory::PROMPT_VERSION,
			] );
			$state['cancelLogged'] = true;
			$this->batchRunService->markCancelLogged( $batchId, $this->getUser()->getId() );
		}

		$this->getResult()->addValue( null, 'aibatcheditorbatchcancel', $this->formatBatchState( $state ) );
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
			'action=aibatcheditorbatchcancel&batchid=00000000-0000-4000-8000-000000000000'
				=> 'apihelp-aibatcheditorbatchcancel-example-1',
		];
	}

}