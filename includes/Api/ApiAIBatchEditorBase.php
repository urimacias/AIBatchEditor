<?php

namespace MediaWiki\Extension\AIBatchEditor\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Config\Config;
use MediaWiki\Extension\AIBatchEditor\Services\PromptFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Shared helpers for AIBatchEditor API modules.
 */
abstract class ApiAIBatchEditorBase extends ApiBase {

	protected function checkAIBatchEditorPermission(): void {
		$this->checkUserRightsAny( 'aibatchedit' );
	}

	protected function getBatchConfig(): Config {
		return MediaWikiServices::getInstance()->getMainConfig();
	}

	/**
	 * @param string[] $titles
	 */
	protected function enforceBatchLimit( array $titles ): void {
		$maxBatch = (int)$this->getBatchConfig()->get( 'AIBatchEditorMaxBatch' );
		if ( count( $titles ) > $maxBatch ) {
			$this->dieWithError(
				[ 'aibatcheditor-error-batch-too-large', count( $titles ), $maxBatch ],
				'batch-too-large'
			);
		}
		if ( count( $titles ) === 0 ) {
			$this->dieWithError( 'aibatcheditor-error-no-titles', 'no-titles' );
		}
	}

	protected function isOperationEnabled( string $operation ): bool {
		$enabled = $this->getBatchConfig()->get( 'AIBatchEditorEnabledOperations' );
		return is_array( $enabled ) && !empty( $enabled[$operation] );
	}

	protected function validateOperation( string $operation ): void {
		if ( !in_array( $operation, PromptFactory::OPERATIONS, true ) ) {
			$this->dieWithError( [ 'aibatcheditor-error-invalid-operation', $operation ], 'invalid-operation' );
		}
		if ( !$this->isOperationEnabled( $operation ) ) {
			$this->dieWithError( [ 'aibatcheditor-error-operation-disabled', $operation ], 'operation-disabled' );
		}
	}

	protected function validateProfile( string $profile ): void {
		if ( !in_array( $profile, PromptFactory::PROFILES, true ) ) {
			$this->dieWithError( [ 'aibatcheditor-error-invalid-profile', $profile ], 'invalid-profile' );
		}
	}

	protected function validateInstructionsForOperation( string $operation, string $instructions ): void {
		if ( $operation === 'custom' && trim( $instructions ) === '' ) {
			$this->dieWithError( 'aibatcheditor-error-custom-needs-instructions', 'custom-needs-instructions' );
		}
	}

	protected function assertValidCategory( string $category ): void {
		$title = Title::makeTitle( NS_CATEGORY, $category );
		if ( !$title || !$title->exists() ) {
			$this->dieWithError( [ 'aibatcheditor-error-category-not-found', $category ], 'category-not-found' );
		}
	}

	protected function getMaxPageSize(): int {
		return max( 0, (int)$this->getBatchConfig()->get( 'AIBatchEditorMaxPageSize' ) );
	}

	protected function isPageTooLarge( int $size ): bool {
		$maxSize = $this->getMaxPageSize();
		return $maxSize > 0 && $size > $maxSize;
	}

	/** @inheritDoc */
	public function mustBePosted() {
		return true;
	}

	/** @inheritDoc */
	public function isWriteMode() {
		return false;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	protected function getSharedTitleParams(): array {
		return [
			'titles' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'category' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'prefix' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
		];
	}

}