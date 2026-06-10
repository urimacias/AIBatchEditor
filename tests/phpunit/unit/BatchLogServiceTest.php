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
				'AIBatchEditor {action}',
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

}