<?php

namespace MediaWiki\Extension\AIBatchEditor\Api;

use MediaWiki\Api\ApiMain;
use MediaWiki\Api\ApiResult;
use MediaWiki\Extension\AIBatchEditor\Services\DraftTokenService;
use MediaWiki\Extension\AIBatchEditor\Services\EditService;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * API module to refresh draft tokens before saving approved edits.
 *
 * @ingroup API
 */
class ApiAIBatchEditorRefreshDraftTokens extends ApiAIBatchEditorBase {

	private EditService $editService;
	private DraftTokenService $draftTokenService;

	public function __construct(
		ApiMain $mainModule,
		string $moduleName,
		EditService $editService,
		DraftTokenService $draftTokenService
	) {
		parent::__construct( $mainModule, $moduleName, '' );
		$this->editService = $editService;
		$this->draftTokenService = $draftTokenService;
	}

	public function execute(): void {
		$this->checkAIBatchEditorPermission();
		$params = $this->extractRequestParams();

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

		$userId = $this->getUser()->getId();
		$pages = [];
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

			$validation = $this->editService->validateForDraftRefresh(
				$this->getAuthority(),
				$title,
				(int)$revid
			);

			$entry = [
				'title' => $validation['title'],
				'status' => $validation['status'],
			];
			if ( isset( $validation['error'] ) ) {
				$entry['error'] = $validation['error'];
			}
			if ( $validation['status'] === 'ok' ) {
				$entry['draftToken'] = $this->draftTokenService->issue(
					$validation['title'],
					(int)$validation['revid'],
					$proposed,
					$userId
				);
			}
			$pages[] = $entry;
		}

		ApiResult::setIndexedTagName( $pages, 'page' );
		$this->getResult()->addValue( null, 'aibatcheditorrefreshdrafttokens', [
			'pages' => $pages,
		] );
	}

	/** @inheritDoc */
	public function isWriteMode() {
		return true;
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'edits' => [
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => 'string',
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=aibatcheditorrefreshdrafttokens&edits=%5B%7B%22title%22%3A%22Main_Page%22%7D%5D'
				=> 'apihelp-aibatcheditorrefreshdrafttokens-example-1',
		];
	}

}