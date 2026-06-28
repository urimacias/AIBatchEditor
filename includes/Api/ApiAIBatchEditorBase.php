<?php

namespace MediaWiki\Extension\AIBatchEditor\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiResult;
use RuntimeException;
use MediaWiki\Config\Config;
use MediaWiki\Extension\AIBatchEditor\Exceptions\LLMServiceException;
use MediaWiki\Extension\AIBatchEditor\Services\PromptFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Shared helpers for AIBatchEditor API modules.
 */
abstract class ApiAIBatchEditorBase extends ApiBase {

	private const DEFAULT_MAX_INSTRUCTIONS = 8192;
	private const EDITS_PAYLOAD_OVERHEAD = 4096;
	private const FALLBACK_MAX_DIFF_TEXT = 1048576;
	private const FALLBACK_MAX_EDITS_PAYLOAD = 5242880;

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

	protected function validateInstructionsForOperation(
		string $operation,
		string $instructions,
		string $templates = ''
	): void {
		if ( $operation === 'custom' && trim( $instructions ) === '' ) {
			$this->dieWithError( 'aibatcheditor-error-custom-needs-instructions', 'custom-needs-instructions' );
		}
		if ( $operation === 'templates' && trim( $templates ) === '' ) {
			$this->dieWithError( 'aibatcheditor-error-templates-needs-names', 'templates-needs-names' );
		}
		$this->assertInstructionsLength( $instructions );
	}

	protected function assertValidCategory( string $category ): void {
		$title = Title::makeTitle( NS_CATEGORY, $category );
		if ( !$title || !$title->exists() ) {
			$this->dieWithError( [ 'aibatcheditor-error-category-not-found', $category ], 'category-not-found' );
		}
	}

	protected function dieWithRuntimeMessageKey( RuntimeException $e, string $code ): void {
		$key = $e->getMessage();
		if ( str_starts_with( $key, 'aibatcheditor-error-' ) ) {
			$this->dieWithError( $key, $code );
			return;
		}
		$this->dieWithError( 'aibatcheditor-error-template-fetch-failed', $code );
	}

	/**
	 * Extend execution time for incremental batch polling (MW 1.45+ removed wfResetTimeLimitsForLongRunningOperation).
	 */
	protected function resetTimeLimitsForLongRunningOperation(): void {
		if ( function_exists( 'wfResetTimeLimitsForLongRunningOperation' ) ) {
			wfResetTimeLimitsForLongRunningOperation();
			return;
		}
		if ( function_exists( 'wfTransactionalTimeLimit' ) ) {
			wfTransactionalTimeLimit();
			return;
		}
		@set_time_limit( 0 );
	}

	protected function getAdvanceTimeLimitSeconds(): int {
		$config = $this->getBatchConfig();
		$requestTimeout = max( 10, (int)$config->get( 'AIBatchEditorRequestTimeout' ) );
		$concurrency = max( 1, (int)$config->get( 'AIBatchEditorConcurrency' ) );
		return ( $requestTimeout * $concurrency ) + 30;
	}

	protected function resetTimeLimitsForBatchAdvance(): void {
		$this->resetTimeLimitsForLongRunningOperation();
		$seconds = $this->getAdvanceTimeLimitSeconds();
		if ( function_exists( 'wfTransactionalTimeLimit' ) ) {
			wfTransactionalTimeLimit( $seconds );
			return;
		}
		@set_time_limit( $seconds );
	}

	/**
	 * @param array<string, mixed> $state
	 * @param bool $includePageBodies Include original/proposed wikitext and draft tokens
	 * @return array<string, mixed>
	 */
	protected function formatBatchState( array $state, bool $includePageBodies = true ): array {
		$pages = array_map(
			fn ( array $page ) => $this->formatPageResult( $page, $includePageBodies ),
			$state['pages'] ?? []
		);
		ApiResult::setIndexedTagName( $pages, 'page' );
		return [
			'batchId' => $state['batchId'],
			'status' => $state['status'],
			'total' => $state['total'],
			'completed' => $state['completed'],
			'operation' => $state['operation'] ?? '',
			'profile' => $state['profile'] ?? '',
			'pages' => $pages,
		];
	}

	protected function assertValidTemplate( string $template ): void {
		$services = MediaWikiServices::getInstance();
		$pageContentService = $services->get( 'AIBatchEditor.PageContentService' );
		$title = $pageContentService->parseTemplateTitle( $template );
		if ( !$title || !$title->exists() ) {
			$this->dieWithError( [ 'aibatcheditor-error-template-page-not-found', $template ], 'template-page-not-found' );
		}
	}

	protected function shouldIncludePromptPreview(): bool {
		return (bool)$this->getBatchConfig()->get( 'AIBatchEditorPromptPreview' );
	}

	protected function getMaxPageSize(): int {
		return max( 0, (int)$this->getBatchConfig()->get( 'AIBatchEditorMaxPageSize' ) );
	}

	protected function isPageTooLarge( int $size ): bool {
		$maxSize = $this->getMaxPageSize();
		return $maxSize > 0 && $size > $maxSize;
	}

	protected function getMaxInstructionsLength(): int {
		$configured = (int)$this->getBatchConfig()->get( 'AIBatchEditorMaxInstructionsLength' );
		return $configured > 0 ? $configured : self::DEFAULT_MAX_INSTRUCTIONS;
	}

	protected function getMaxDiffTextLength(): int {
		$maxPage = $this->getMaxPageSize();
		return $maxPage > 0 ? $maxPage * 2 : self::FALLBACK_MAX_DIFF_TEXT;
	}

	protected function getMaxEditsPayloadLength(): int {
		$maxPage = $this->getMaxPageSize();
		$maxBatch = max( 1, (int)$this->getBatchConfig()->get( 'AIBatchEditorMaxBatch' ) );
		if ( $maxPage > 0 ) {
			return ( $maxPage * $maxBatch ) + self::EDITS_PAYLOAD_OVERHEAD;
		}
		return self::FALLBACK_MAX_EDITS_PAYLOAD;
	}

	protected function assertInstructionsLength( string $instructions ): void {
		$max = $this->getMaxInstructionsLength();
		if ( strlen( $instructions ) > $max ) {
			$this->dieWithError(
				[ 'aibatcheditor-error-instructions-too-long', strlen( $instructions ), $max ],
				'instructions-too-long'
			);
		}
	}

	protected function assertDiffTextLength( string $original, string $proposed ): void {
		$max = $this->getMaxDiffTextLength();
		if ( strlen( $original ) > $max || strlen( $proposed ) > $max ) {
			$this->dieWithError(
				[ 'aibatcheditor-error-diff-too-large', $max ],
				'diff-too-large'
			);
		}
	}

	protected function assertEditsPayloadLength( string $editsJson ): void {
		$max = $this->getMaxEditsPayloadLength();
		if ( strlen( $editsJson ) > $max ) {
			$this->dieWithError(
				[ 'aibatcheditor-error-edits-payload-too-large', strlen( $editsJson ), $max ],
				'edits-payload-too-large'
			);
		}
	}

	protected function assertProposedWikitextLength( string $proposed ): void {
		$max = $this->getMaxPageSize();
		if ( $max > 0 && strlen( $proposed ) > $max ) {
			$this->dieWithError(
				[ 'aibatcheditor-error-save-proposed-too-large', strlen( $proposed ), $max ],
				'proposed-too-large'
			);
		}
	}

	/**
	 * @param array<string, mixed> $page
	 * @param bool $includeBodies Include large wikitext fields and draft tokens
	 * @return array<string, mixed>
	 */
	protected function formatPageResult( array $page, bool $includeBodies = true ): array {
		if ( isset( $page['error'] ) && !isset( $page['errorInfo'] ) ) {
			$params = $page['errorParams'] ?? [];
			if ( $page['error'] === 'aibatcheditor-error-llm-http' ) {
				$httpCode = $params[0] ?? '?';
				$page['errorInfo'] = $this->msg( 'aibatcheditor-error-llm-http-generic', $httpCode )->text();
			} else {
				$page['errorInfo'] = $this->msg( $page['error'], ...$params )->text();
			}
			unset( $page['errorParams'], $page['llmLogDetail'] );
		}
		if ( !$includeBodies ) {
			unset(
				$page['original'],
				$page['proposed'],
				$page['draftToken'],
				$page['warnings'],
				$page['promptSystem'],
				$page['promptUser']
			);
		}
		return $page;
	}

	protected function formatLlmErrorForClient( LLMServiceException $e ): string {
		if ( $e->getMessageKey() === 'aibatcheditor-error-llm-http' ) {
			$params = $e->getParams();
			$httpCode = $params[0] ?? '?';
			return $this->msg( 'aibatcheditor-error-llm-http-generic', $httpCode )->text();
		}
		return $this->msg( $e->getMessageKey(), ...$e->getParams() )->text();
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
			'template' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'prefix' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
		];
	}

}