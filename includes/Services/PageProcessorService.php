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
		$results = $this->processPages(
			$performer,
			$userId,
			[ $titleText ],
			$operation,
			$profile,
			$instructions,
			[],
			$templateContext,
			$includePromptPreview
		);
		return $results[0];
	}

	/**
	 * Process one or more pages. When multiple titles are passed, LLM calls run in parallel.
	 *
	 * @param list<string> $titleTexts
	 * @param array<string, string> $pageInstructions Per-title instruction overrides
	 * @return list<array<string, mixed>>
	 */
	public function processPages(
		Authority $performer,
		int $userId,
		array $titleTexts,
		string $operation,
		string $profile,
		string $defaultInstructions = '',
		array $pageInstructions = [],
		string $templateContext = '',
		bool $includePromptPreview = false
	): array {
		$prepared = [];
		$promptJobs = [];

		foreach ( array_values( $titleTexts ) as $index => $titleText ) {
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
				$prepared[$index] = [ 'done' => $entry ];
				continue;
			}

			$maxSize = $this->getMaxPageSize();
			if ( $this->isPageTooLarge( (int)( $info['size'] ?? 0 ) ) ) {
				$entry['status'] = 'error';
				$entry['error'] = 'aibatcheditor-error-page-too-large';
				$entry['errorParams'] = [ $info['size'], $maxSize ];
				$prepared[$index] = [ 'done' => $entry ];
				continue;
			}

			$original = $info['wikitext'] ?? '';
			$entry['original'] = $original;

			$instructions = trim( $pageInstructions[$titleText] ?? '' );
			if ( $instructions === '' ) {
				$instructions = $defaultInstructions;
			}

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

			$prepared[$index] = [
				'entry' => $entry,
				'original' => $original,
				'infoTitle' => $info['title'],
				'revid' => (int)$info['revid'],
			];
			$promptJobs[$index] = $prompts;
		}

		$needLlm = count( $promptJobs );
		if ( $needLlm > 0 && !$this->rateLimiter->canConsume( $userId, $needLlm ) ) {
			$status = $this->rateLimiter->getStatus( $userId );
			foreach ( $promptJobs as $index => $_prompts ) {
				$entry = $prepared[$index]['entry'];
				$entry['status'] = 'error';
				$entry['error'] = 'aibatcheditor-error-rate-limit';
				$entry['errorParams'] = [ $status['limit'], $status['used'] ];
				$prepared[$index] = [ 'done' => $entry ];
			}
			$promptJobs = [];
		}

		$llmResults = [];
		$llmDurationMs = null;
		if ( $promptJobs !== [] ) {
			$llmStart = microtime( true );
			if ( count( $promptJobs ) === 1 ) {
				$onlyKey = array_key_first( $promptJobs );
				try {
					$llmResults[$onlyKey] = $this->aiService->complete( $promptJobs[$onlyKey] );
				} catch ( LLMServiceException $e ) {
					$llmResults[$onlyKey] = $e;
				} catch ( RuntimeException $e ) {
					$llmResults[$onlyKey] = $e;
				}
			} else {
				$llmResults = $this->aiService->completeMany( $promptJobs );
			}
			$llmDurationMs = (int)round( ( microtime( true ) - $llmStart ) * 1000 );
		}

		$out = [];
		foreach ( $prepared as $index => $item ) {
			if ( isset( $item['done'] ) ) {
				$out[] = $item['done'];
				continue;
			}

			$entry = $item['entry'];
			$original = $item['original'];
			$result = $llmResults[$index] ?? new RuntimeException( 'missing llm result' );

			if ( $result instanceof LLMServiceException ) {
				$entry['status'] = 'error';
				$entry['error'] = $result->getMessageKey();
				$entry['errorParams'] = $result->getParams();
				$entry['llmLogDetail'] = $result->getLogDetail();
				$errorParams = $result->getParams();
				$this->batchLogService->logProcess( $performer, array_filter( [
					'title' => $item['infoTitle'],
					'operation' => $operation,
					'llmError' => $result->getMessageKey(),
					'httpCode' => $errorParams[0] ?? null,
					'detail' => $result->getLogDetail(),
					'llmDurationMs' => $llmDurationMs,
					'model' => $this->config->get( 'AIBatchEditorModel' ),
					'promptVersion' => PromptFactory::PROMPT_VERSION,
				], static fn ( $value ) => $value !== null ) );
				$out[] = $entry;
				continue;
			}

			if ( $result instanceof RuntimeException || !is_string( $result ) ) {
				$entry['status'] = 'error';
				$entry['error'] = 'aibatcheditor-error-llm-request-failed';
				$out[] = $entry;
				continue;
			}

			$this->rateLimiter->consume( $userId, 1 );
			$proposed = $result;
			$maxSize = $this->getMaxPageSize();

			if ( $this->isPageTooLarge( strlen( $proposed ) ) ) {
				$entry['status'] = 'error';
				$entry['error'] = 'aibatcheditor-error-page-too-large';
				$entry['errorParams'] = [ strlen( $proposed ), $maxSize ];
				$out[] = $entry;
				continue;
			}

			if ( $proposed === $original ) {
				$entry['status'] = 'omitted';
				$this->logLlmTiming(
					$performer,
					$item['infoTitle'],
					$operation,
					$original,
					$llmDurationMs,
					'omitted'
				);
				$out[] = $entry;
				continue;
			}

			$entry['status'] = 'changed';
			$this->logLlmTiming(
				$performer,
				$item['infoTitle'],
				$operation,
				$original,
				$llmDurationMs,
				'changed'
			);
			$entry['proposed'] = $proposed;
			$entry['draftToken'] = $this->draftTokenService->issue(
				$item['infoTitle'],
				$item['revid'],
				$proposed,
				$userId
			);

			$warnings = $this->proposalAnalyzer->analyze( $original, $proposed );
			if ( $warnings !== [] ) {
				$entry['warnings'] = $warnings;
			}

			$out[] = $entry;
		}

		return $out;
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