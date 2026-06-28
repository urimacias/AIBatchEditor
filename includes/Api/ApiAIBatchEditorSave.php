<?php

namespace MediaWiki\Extension\AIBatchEditor\Api;

use MediaWiki\Api\ApiMain;
use MediaWiki\Api\ApiResult;
use MediaWiki\Extension\AIBatchEditor\Services\BatchLogService;
use MediaWiki\Extension\AIBatchEditor\Services\DraftTokenService;
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
	private DraftTokenService $draftTokenService;

	public function __construct(
		ApiMain $mainModule,
		string $moduleName,
		EditService $editService,
		BatchLogService $batchLogService,
		DraftTokenService $draftTokenService
	) {
		parent::__construct( $mainModule, $moduleName, '' );
		$this->editService = $editService;
		$this->batchLogService = $batchLogService;
		$this->draftTokenService = $draftTokenService;
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
			$draftToken = trim( $edit['draftToken'] ?? '' );

			if ( $title === '' || !is_numeric( $revid ) || !is_string( $proposed ) || $draftToken === '' ) {
				$this->dieWithError( 'aibatcheditor-error-save-invalid-json', 'invalid-json' );
			}

			$this->assertProposedWikitextLength( $proposed );

			$tokenFailure = $this->draftTokenService->verifyWithReason(
				$draftToken,
				$title,
				(int)$revid,
				$proposed,
				$this->getUser()->getId()
			);
			if ( $tokenFailure !== null ) {
				$refreshCheck = $this->editService->validateForDraftRefresh(
					$this->getAuthority(),
					$title,
					(int)$revid
				);
				$logContext = [
					'title' => $title,
					'revid' => (int)$revid,
					'proposedLength' => strlen( $proposed ),
					'reason' => $tokenFailure,
				];
				if ( $refreshCheck['status'] === 'ok' ) {
					$logContext['recovered'] = true;
					$this->batchLogService->logDraftTokenVerifyFailure(
						$this->getAuthority(),
						$logContext
					);
				} else {
					$this->batchLogService->logDraftTokenVerifyFailure(
						$this->getAuthority(),
						$logContext
					);
					$this->dieWithDraftTokenError( $tokenFailure );
				}
			}

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

	private function dieWithDraftTokenError( string $reason ): void {
		$map = [
			DraftTokenService::REASON_BAD_SIGNATURE => [
				'aibatcheditor-error-save-draft-token-bad-signature',
				'draft-token-bad-signature',
			],
			DraftTokenService::REASON_CONTENT_MISMATCH => [
				'aibatcheditor-error-save-draft-token-content-mismatch',
				'draft-token-content-mismatch',
			],
			DraftTokenService::REASON_EXPIRED => [
				'aibatcheditor-error-save-draft-token-expired',
				'draft-token-expired',
			],
			DraftTokenService::REASON_REVID_MISMATCH => [
				'aibatcheditor-error-save-draft-token-revid-mismatch',
				'draft-token-revid-mismatch',
			],
			DraftTokenService::REASON_TITLE_MISMATCH => [
				'aibatcheditor-error-save-draft-token-title-mismatch',
				'draft-token-title-mismatch',
			],
			DraftTokenService::REASON_USER_MISMATCH => [
				'aibatcheditor-error-save-draft-token-user-mismatch',
				'draft-token-user-mismatch',
			],
		];

		[ $message, $code ] = $map[$reason] ?? [
			'aibatcheditor-error-save-invalid-draft-token',
			'invalid-draft-token',
		];
		$this->dieWithError( $message, $code );
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