<?php

namespace MediaWiki\Extension\AIBatchEditor\Api;

use MediaWiki\Api\ApiMain;
use MediaWiki\Api\ApiResult;
use MediaWiki\Extension\AIBatchEditor\Services\BatchLogService;
use MediaWiki\Extension\AIBatchEditor\Services\EditService;
use MediaWiki\Extension\AIBatchEditor\Services\PromptFactory;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * API module to save approved AI-proposed edits.
 *
 * @ingroup API
 */
class ApiAIBatchEditorSave extends ApiAIBatchEditorBase {

	private EditService $editService;
	private BatchLogService $batchLogService;

	public function __construct(
		ApiMain $mainModule,
		string $moduleName,
		EditService $editService,
		BatchLogService $batchLogService
	) {
		parent::__construct( $mainModule, $moduleName, '' );
		$this->editService = $editService;
		$this->batchLogService = $batchLogService;
	}

	public function execute(): void {
		$this->checkAIBatchEditorPermission();
		$params = $this->extractRequestParams();

		$summary = trim( $params['summary'] ?? '' );
		if ( $summary === '' ) {
			$this->dieWithError( 'aibatcheditor-error-save-missing-summary', 'missing-summary' );
		}

		$operation = trim( $params['operation'] ?? '' );
		$profile = trim( $params['profile'] ?? '' );
		if ( $operation !== '' ) {
			$this->validateOperation( $operation );
		}
		if ( $profile !== '' ) {
			$this->validateProfile( $profile );
		}

		$editsJson = $params['edits'] ?? '';
		if ( $editsJson === '' ) {
			$this->dieWithError( 'aibatcheditor-error-save-no-edits', 'no-edits' );
		}

		$this->assertEditsPayloadLength( $editsJson );

		$edits = json_decode( $editsJson, true );
		if ( !is_array( $edits ) || $edits === [] ) {
			$this->dieWithError( 'aibatcheditor-error-save-invalid-json', 'invalid-json' );
		}

		$maxBatch = (int)$this->getBatchConfig()->get( 'AIBatchEditorMaxBatch' );
		if ( count( $edits ) > $maxBatch ) {
			$this->dieWithError(
				[ 'aibatcheditor-error-batch-too-large', count( $edits ), $maxBatch ],
				'batch-too-large'
			);
		}

		$pages = [];
		$auditEdits = [];
		foreach ( $edits as $edit ) {
			if ( !is_array( $edit ) ) {
				$this->dieWithError( 'aibatcheditor-error-save-invalid-json', 'invalid-json' );
			}

			$title = trim( $edit['title'] ?? '' );
			$revid = $edit['revid'] ?? null;
			$proposed = $edit['proposed'] ?? null;

			if ( $title === '' || !is_numeric( $revid ) || !is_string( $proposed ) ) {
				$this->dieWithError( 'aibatcheditor-error-save-invalid-json', 'invalid-json' );
			}

			$this->assertProposedWikitextLength( $proposed );

			$result = $this->editService->savePage(
				$this->getAuthority(),
				$title,
				(int)$revid,
				$proposed,
				$summary
			);

			$entry = [
				'title' => $result['title'],
				'status' => $result['status'],
			];
			if ( isset( $result['revid'] ) ) {
				$entry['revid'] = $result['revid'];
			}
			if ( isset( $result['newrevid'] ) ) {
				$entry['newrevid'] = $result['newrevid'];
			}
			if ( isset( $result['error'] ) ) {
				$entry['error'] = $result['error'];
			}
			$pages[] = $entry;

			$auditEdits[] = [
				'title' => $result['title'],
				'revid' => (int)$revid,
				'proposedSha256' => hash( 'sha256', $proposed ),
				'status' => $result['status'],
			];
			if ( isset( $result['newrevid'] ) ) {
				$auditEdits[ count( $auditEdits ) - 1 ]['newrevid'] = $result['newrevid'];
			}
		}

		ApiResult::setIndexedTagName( $pages, 'page' );
		$this->getResult()->addValue( null, 'aibatcheditorsave', [
			'summary' => $summary,
			'pages' => $pages,
		] );

		$logContext = [
			'editCount' => count( $pages ),
			'saved' => count( array_filter( $pages, static fn ( $p ) => ( $p['status'] ?? '' ) === 'saved' ) ),
			'errors' => count( array_filter( $pages, static fn ( $p ) => ( $p['status'] ?? '' ) === 'error' ) ),
			'edits' => $auditEdits,
		];
		if ( $operation !== '' ) {
			$logContext['operation'] = $operation;
		}
		if ( $profile !== '' ) {
			$logContext['profile'] = $profile;
		}
		$this->batchLogService->logSave( $this->getAuthority(), $logContext );
	}

	/** @inheritDoc */
	public function isWriteMode() {
		return true;
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'summary' => [
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'string',
			],
			'edits' => [
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'string',
			],
			'operation' => [
				ParamValidator::PARAM_TYPE => PromptFactory::OPERATIONS,
			],
			'profile' => [
				ParamValidator::PARAM_TYPE => PromptFactory::PROFILES,
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=aibatcheditorsave&summary=AI+spellcheck&edits=%5B%7B%22title%22%3A%22Main_Page%22%7D%5D'
				=> 'apihelp-aibatcheditorsave-example-1',
		];
	}

}