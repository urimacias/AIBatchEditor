<?php

namespace MediaWiki\Extension\AIBatchEditor\Services;

use MediaWiki\Config\Config;
use MediaWiki\Extension\AIBatchEditor\Exceptions\LLMServiceException;
use MediaWiki\Permissions\Authority;
use RuntimeException;

/**
 * Runs a single-page AI draft and returns a structured result row.
 */
class PageProcessorService {

	private PageContentService $pageContentService;
	private AIService $aiService;
	private PromptFactory $promptFactory;
	private RateLimiterService $rateLimiter;
	private BatchLogService $batchLogService;
	private DraftTokenService $draftTokenService;
	private ProposalAnalyzer $proposalAnalyzer;
	private Config $config;

	public function __construct(
		PageContentService $pageContentService,
		AIService $aiService,
		PromptFactory $promptFactory,
		RateLimiterService $rateLimiter,
		BatchLogService $batchLogService,
		DraftTokenService $draftTokenService,
		ProposalAnalyzer $proposalAnalyzer,
		Config $config
	) {
		$this->pageContentService = $pageContentService;
		$this->aiService = $aiService;
		$this->promptFactory = $promptFactory;
		$this->rateLimiter = $rateLimiter;
		$this->batchLogService = $batchLogService;
		$this->draftTokenService = $draftTokenService;
		$this->proposalAnalyzer = $proposalAnalyzer;
		$this->config = $config;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function processPage(
		Authority $performer,
		int $userId,
		string $titleText,
		string $operation,
		string $profile,
		string $instructions = '',
		string $templateContext = '',
		bool $includePromptPreview = false
	): array {
		$info = $this->pageContentService->getPageInfo(
			$titleText,
			$performer,
			true
		);

		$entry = [
			'title' => $info['title'],
			'revid' => $info['revid'],
		];

		if ( isset( $info['error'] ) ) {
			$entry['status'] = 'error';
			$entry['error'] = 'aibatcheditor-page-error-' . $info['error'];
			return $entry;
		}

		$maxSize = $this->getMaxPageSize();
		if ( $this->isPageTooLarge( (int)( $info['size'] ?? 0 ) ) ) {
			$entry['status'] = 'error';
			$entry['error'] = 'aibatcheditor-error-page-too-large';
			$entry['errorParams'] = [ $info['size'], $maxSize ];
			return $entry;
		}

		$original = $info['wikitext'] ?? '';
		$entry['original'] = $original;

		if ( !$this->rateLimiter->canConsume( $userId, 1 ) ) {
			$status = $this->rateLimiter->getStatus( $userId );
			$entry['status'] = 'error';
			$entry['error'] = 'aibatcheditor-error-rate-limit';
			$entry['errorParams'] = [ $status['limit'], $status['used'] ];
			return $entry;
		}

		$llmDurationMs = null;
		try {
			$prompts = $this->promptFactory->buildPrompts(
				$operation,
				$profile,
				$original,
				$instructions,
				$templateContext
			);
			if ( $includePromptPreview ) {
				$entry['promptSystem'] = $prompts['system'];
				$entry['promptUser'] = $prompts['user'];
			}
			$llmStart = microtime( true );
			$proposed = $this->aiService->complete( $prompts );
			$llmDurationMs = (int)round( ( microtime( true ) - $llmStart ) * 1000 );
			$this->rateLimiter->consume( $userId, 1 );
		} catch ( LLMServiceException $e ) {
			$entry['status'] = 'error';
			$entry['error'] = $e->getMessageKey();
			$entry['errorParams'] = $e->getParams();
			$entry['llmLogDetail'] = $e->getLogDetail();
			$errorParams = $e->getParams();
			$this->batchLogService->logProcess( $performer, array_filter( [
				'title' => $info['title'],
				'operation' => $operation,
				'llmError' => $e->getMessageKey(),
				'httpCode' => $errorParams[0] ?? null,
				'detail' => $e->getLogDetail(),
				'llmDurationMs' => $llmDurationMs,
				'model' => $this->config->get( 'AIBatchEditorModel' ),
				'promptVersion' => PromptFactory::PROMPT_VERSION,
			], static fn ( $value ) => $value !== null ) );
			return $entry;
		} catch ( RuntimeException $e ) {
			$entry['status'] = 'error';
			$entry['error'] = 'aibatcheditor-error-llm-request-failed';
			return $entry;
		}

		if ( $this->isPageTooLarge( strlen( $proposed ) ) ) {
			$entry['status'] = 'error';
			$entry['error'] = 'aibatcheditor-error-page-too-large';
			$entry['errorParams'] = [ strlen( $proposed ), $maxSize ];
			return $entry;
		}

		if ( $proposed === $original ) {
			$entry['status'] = 'omitted';
			$this->logLlmTiming( $performer, $info['title'], $operation, $original, $llmDurationMs, 'omitted' );
			return $entry;
		}

		$entry['status'] = 'changed';
		$this->logLlmTiming( $performer, $info['title'], $operation, $original, $llmDurationMs, 'changed' );
		$entry['proposed'] = $proposed;
		$entry['draftToken'] = $this->draftTokenService->issue(
			$info['title'],
			(int)$info['revid'],
			$proposed,
			$userId
		);

		$warnings = $this->proposalAnalyzer->analyze( $original, $proposed );
		if ( $warnings !== [] ) {
			$entry['warnings'] = $warnings;
		}

		return $entry;
	}

	private function logLlmTiming(
		Authority $performer,
		string $title,
		string $operation,
		string $original,
		?int $llmDurationMs,
		string $resultStatus
	): void {
		if ( $llmDurationMs === null ) {
			return;
		}
		$this->batchLogService->logProcess( $performer, [
			'title' => $title,
			'operation' => $operation,
			'llmDurationMs' => $llmDurationMs,
			'originalBytes' => strlen( $original ),
			'model' => $this->config->get( 'AIBatchEditorModel' ),
			'resultStatus' => $resultStatus,
			'promptVersion' => PromptFactory::PROMPT_VERSION,
		] );
	}

	private function getMaxPageSize(): int {
		return max( 0, (int)$this->config->get( 'AIBatchEditorMaxPageSize' ) );
	}

	private function isPageTooLarge( int $size ): bool {
		$maxSize = $this->getMaxPageSize();
		return $maxSize > 0 && $size > $maxSize;
	}

}