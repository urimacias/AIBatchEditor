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
		$this->logger->info(
			'AIBatchEditor {action}',
			array_merge(
				[
					'action' => $action,
					'user' => $user->getName(),
					'userId' => $user->getId(),
				],
				$context
			)
		);
	}

}