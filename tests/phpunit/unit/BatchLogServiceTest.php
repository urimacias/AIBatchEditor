<?php

namespace MediaWiki\Extension\AIBatchEditor\Tests;

use MediaWiki\Extension\AIBatchEditor\Services\BatchLogService;
use MediaWiki\Permissions\UltimateAuthority;
use MediaWiki\User\UserIdentityValue;
use MediaWikiUnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \MediaWiki\Extension\AIBatchEditor\Services\BatchLogService
 */
class BatchLogServiceTest extends MediaWikiUnitTestCase {

	public function testLogProcessWritesStructuredContext(): void {
		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->once() )
			->method( 'info' )
			->with(
				$this->stringStartsWith( 'AIBatchEditor {action}' ),
				$this->callback( static function ( array $context ): bool {
					return $context['action'] === 'process'
						&& $context['operation'] === 'spellcheck'
						&& $context['pageCount'] === 2;
				} )
			);

		$service = new BatchLogService( $logger );
		$performer = new UltimateAuthority( new UserIdentityValue( 7, 'TestAdmin' ) );
		$service->logProcess( $performer, [
			'operation' => 'spellcheck',
			'pageCount' => 2,
		] );
	}

	public function testLogSaveWritesEditAudit(): void {
		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->once() )
			->method( 'info' )
			->with(
				$this->stringStartsWith( 'AIBatchEditor {action}' ),
				$this->callback( static function ( array $context ): bool {
					return $context['action'] === 'save'
						&& $context['operation'] === 'spellcheck'
						&& $context['profile'] === 'balanced'
						&& isset( $context['edits'][0]['title'] )
						&& isset( $context['edits'][0]['revid'] )
						&& isset( $context['edits'][0]['proposedSha256'] )
						&& $context['edits'][0]['status'] === 'saved';
				} )
			);

		$service = new BatchLogService( $logger );
		$performer = new UltimateAuthority( new UserIdentityValue( 7, 'TestAdmin' ) );
		$service->logSave( $performer, [
			'operation' => 'spellcheck',
			'profile' => 'balanced',
			'editCount' => 1,
			'saved' => 1,
			'errors' => 0,
			'edits' => [
				[
					'title' => 'Test_Page',
					'revid' => 42,
					'proposedSha256' => hash( 'sha256', 'proposed wikitext' ),
					'status' => 'saved',
					'newrevid' => 43,
				],
			],
		] );
	}

}