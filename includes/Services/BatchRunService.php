<?php

namespace MediaWiki\Extension\AIBatchEditor\Services;

use MediaWiki\Config\Config;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Permissions\Authority;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\UUID\GlobalIdGenerator;

/**
 * Server-side batch runner with incremental progress stored in object cache.
 */
class BatchRunService {

	public const CONSTRUCTOR_OPTIONS = [
		'AIBatchEditorConcurrency',
	];

	private const CACHE_PREFIX = 'aibatcheditor-batch';
	private const TTL_SECONDS = 3600;

	private ServiceOptions $options;
	private BagOStuff $cache;
	private GlobalIdGenerator $idGenerator;
	private PageProcessorService $pageProcessor;
	private Config $config;

	public function __construct(
		ServiceOptions $options,
		BagOStuff $cache,
		GlobalIdGenerator $idGenerator,
		PageProcessorService $pageProcessor,
		Config $config
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->options = $options;
		$this->cache = $cache;
		$this->idGenerator = $idGenerator;
		$this->pageProcessor = $pageProcessor;
		$this->config = $config;
	}

	/**
	 * @param list<string> $titleTexts
	 * @param array<string, string> $pageInstructions
	 * @return array<string, mixed>
	 */
	public function startBatch(
		Authority $performer,
		array $titleTexts,
		string $operation,
		string $profile,
		string $instructions,
		string $templateContext,
		array $pageInstructions = []
	): array {
		$batchId = $this->idGenerator->newUUIDv4();
		$userId = $performer->getUser()->getId();
		$state = [
			'batchId' => $batchId,
			'userId' => $userId,
			'operation' => $operation,
			'profile' => $profile,
			'instructions' => $instructions,
			'templateContext' => $templateContext,
			'pageInstructions' => $pageInstructions,
			'pending' => array_values( $titleTexts ),
			'pages' => [],
			'status' => 'running',
			'total' => count( $titleTexts ),
			'completed' => 0,
		];
		$this->saveState( $batchId, $state );
		return $state;
	}

	public function markAuditLogged( string $batchId, int $userId ): void {
		$state = $this->getBatch( $batchId, $userId );
		if ( $state === null ) {
			return;
		}
		$state['auditLogged'] = true;
		$this->saveState( $batchId, $state );
	}

	public function markCancelLogged( string $batchId, int $userId ): void {
		$state = $this->getBatch( $batchId, $userId );
		if ( $state === null ) {
			return;
		}
		$state['cancelLogged'] = true;
		$this->saveState( $batchId, $state );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function getBatch( string $batchId, int $userId ): ?array {
		$state = $this->loadState( $batchId );
		if ( $state === null || (int)( $state['userId'] ?? 0 ) !== $userId ) {
			return null;
		}
		return $state;
	}

	/**
	 * Process the next chunk of pending pages and return updated batch state.
	 *
	 * @return array<string, mixed>|null
	 */
	public function cancelBatch( string $batchId, Authority $performer ): ?array {
		$userId = $performer->getUser()->getId();
		$state = $this->getBatch( $batchId, $userId );
		if ( $state === null ) {
			return null;
		}
		$status = (string)( $state['status'] ?? '' );
		if ( $status === 'complete' || $status === 'cancelled' ) {
			return $state;
		}
		$state['status'] = 'cancelled';
		$state['pending'] = [];
		$this->saveState( $batchId, $state );
		return $state;
	}

	public function advanceBatch( string $batchId, Authority $performer ): ?array {
		$userId = $performer->getUser()->getId();
		$state = $this->getBatch( $batchId, $userId );
		if ( $state === null ) {
			return null;
		}
		if ( ( $state['status'] ?? '' ) === 'cancelled' ) {
			return $state;
		}
		if ( ( $state['status'] ?? '' ) === 'complete' || ( $state['pending'] ?? [] ) === [] ) {
			$state['status'] = 'complete';
			$this->saveState( $batchId, $state );
			return $state;
		}

		$includePromptPreview = (bool)$this->config->get( 'AIBatchEditorPromptPreview' );
		$steps = max( 1, (int)$this->options->get( 'AIBatchEditorConcurrency' ) );
		$processed = 0;

		while ( $processed < $steps && ( $state['pending'] ?? [] ) !== [] ) {
			$titleText = array_shift( $state['pending'] );
			$pageInstructions = $state['pageInstructions'] ?? [];
			$perPageInstructions = trim( $pageInstructions[$titleText] ?? '' );
			$instructions = $perPageInstructions !== '' ?
				$perPageInstructions :
				(string)( $state['instructions'] ?? '' );

			$result = $this->pageProcessor->processPage(
				$performer,
				$userId,
				$titleText,
				(string)$state['operation'],
				(string)$state['profile'],
				$instructions,
				(string)( $state['templateContext'] ?? '' ),
				$includePromptPreview
			);

			$state['pages'][] = $result;
			$state['completed'] = (int)( $state['completed'] ?? 0 ) + 1;
			$processed++;
		}

		if ( ( $state['pending'] ?? [] ) === [] ) {
			$state['status'] = 'complete';
		} else {
			$state['status'] = 'running';
		}

		$this->saveState( $batchId, $state );
		return $state;
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function saveState( string $batchId, array $state ): void {
		$this->cache->set(
			$this->cache->makeKey( self::CACHE_PREFIX, $batchId ),
			$state,
			self::TTL_SECONDS
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function loadState( string $batchId ): ?array {
		$value = $this->cache->get( $this->cache->makeKey( self::CACHE_PREFIX, $batchId ) );
		return is_array( $value ) ? $value : null;
	}

}