<?php

namespace MediaWiki\Extension\AIBatchEditor\Services;

use MediaWiki\Permissions\Authority;
use Psr\Log\LoggerInterface;

/**
 * Structured audit logging for AIBatchEditor API actions.
 */
class BatchLogService {

	private LoggerInterface $logger;

	public function __construct( LoggerInterface $logger ) {
		$this->logger = $logger;
	}

	public function logList( Authority $performer, array $context ): void {
		$this->log( 'list', $performer, $context );
	}

	public function logProcess( Authority $performer, array $context ): void {
		$this->log( 'process', $performer, $context );
	}

	public function logSave( Authority $performer, array $context ): void {
		$this->log( 'save', $performer, $context );
	}

	public function logDraftTokenVerifyFailure( Authority $performer, array $context ): void {
		$this->log( 'draftTokenVerifyFailure', $performer, $context );
	}

	private function log( string $action, Authority $performer, array $context ): void {
		$user = $performer->getUser();
		$payload = array_merge(
			[
				'action' => $action,
				'user' => $user->getName(),
				'userId' => $user->getId(),
			],
			$context
		);
		$message = 'AIBatchEditor {action}';
		$logContext = $this->buildLogContextSuffix( $payload );
		if ( $logContext !== '' ) {
			$message .= ' ' . $logContext;
		}
		$this->logger->info( $message, $payload );
	}

	/**
	 * MediaWiki debug log files often omit structured context; append a compact JSON suffix.
	 *
	 * @param array<string, mixed> $payload
	 */
	private function buildLogContextSuffix( array $payload ): string {
		$keys = [
			'title',
			'operation',
			'llmError',
			'llmDurationMs',
			'httpCode',
			'resultStatus',
			'originalBytes',
			'model',
			'promptVersion',
			'batchId',
			'pageCount',
			'changed',
			'errors',
			'mode',
		];
		$compact = [];
		foreach ( $keys as $key ) {
			if ( !array_key_exists( $key, $payload ) || $payload[$key] === null || $payload[$key] === '' ) {
				continue;
			}
			$compact[$key] = $payload[$key];
		}
		if ( isset( $payload['detail'] ) && is_string( $payload['detail'] ) && $payload['detail'] !== '' ) {
			$compact['detail'] = substr( $payload['detail'], 0, 200 );
		}
		if ( $compact === [] ) {
			return '';
		}
		$encoded = json_encode( $compact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return is_string( $encoded ) ? $encoded : '';
	}

}