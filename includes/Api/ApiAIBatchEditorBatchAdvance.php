<?php

namespace MediaWiki\Extension\AIBatchEditor\Api;

use MediaWiki\Api\ApiMain;
use MediaWiki\Extension\AIBatchEditor\Services\BatchLogService;
use MediaWiki\Extension\AIBatchEditor\Services\BatchRunService;
use MediaWiki\Extension\AIBatchEditor\Services\PromptFactory;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Process the next chunk of a server-side AI batch run.
 *
 * Long-running: each call may invoke one or more LLM requests. The UI should
 * poll aibatcheditorbatchstatus separately for fast progress updates.
 *
 * @ingroup API
 */
class ApiAIBatchEditorBatchAdvance extends ApiAIBatchEditorBase {

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
		$this->resetTimeLimitsForBatchAdvance();
		$params = $this->extractRequestParams();
		$batchId = trim( $params['batchid'] ?? '' );
		if ( $batchId === '' ) {
			$this->dieWithError( 'aibatcheditor-error-batch-missing-id', 'missing-batch-id' );
		}

		$state = $this->batchRunService->advanceBatch( $batchId, $this->getAuthority() );
		if ( $state === null ) {
			$this->dieWithError( 'aibatcheditor-error-batch-not-found', 'batch-not-found' );
		}

		if ( ( $state['status'] ?? '' ) === 'complete' && empty( $state['auditLogged'] ) ) {
			$pages = $state['pages'] ?? [];
			$this->batchLogService->logProcess( $this->getAuthority(), [
				'batchId' => $batchId,
				'operation' => $state['operation'] ?? '',
				'profile' => $state['profile'] ?? '',
				'pageCount' => count( $pages ),
				'changed' => count( array_filter( $pages, static fn ( $p ) => ( $p['status'] ?? '' ) === 'changed' ) ),
				'errors' => count( array_filter( $pages, static fn ( $p ) => ( $p['status'] ?? '' ) === 'error' ) ),
				'mode' => 'server-batch-complete',
				'promptVersion' => PromptFactory::PROMPT_VERSION,
			] );
			$this->batchRunService->markAuditLogged( $batchId, $this->getUser()->getId() );
		}

		$this->getResult()->addValue( null, 'aibatcheditorbatchadvance', $this->formatBatchState( $state ) );
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
			'action=aibatcheditorbatchadvance&batchid=00000000-0000-4000-8000-000000000000'
				=> 'apihelp-aibatcheditorbatchadvance-example-1',
		];
	}

}