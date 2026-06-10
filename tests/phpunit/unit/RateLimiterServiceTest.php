<?php

namespace MediaWiki\Extension\AIBatchEditor\Tests;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\AIBatchEditor\Services\RateLimiterService;
use MediaWikiUnitTestCase;
use Wikimedia\ObjectCache\HashBagOStuff;

/**
 * @covers \MediaWiki\Extension\AIBatchEditor\Services\RateLimiterService
 */
class RateLimiterServiceTest extends MediaWikiUnitTestCase {

	public function testConsumeRespectsHourlyLimit(): void {
		$service = new RateLimiterService(
			new ServiceOptions( RateLimiterService::CONSTRUCTOR_OPTIONS, [
				'AIBatchEditorRateLimitPerHour' => 3,
			] ),
			new HashBagOStuff()
		);

		$this->assertTrue( $service->canConsume( 42, 1 ) );
		$service->consume( 42, 1 );
		$service->consume( 42, 1 );
		$this->assertTrue( $service->canConsume( 42, 1 ) );
		$service->consume( 42, 1 );
		$this->assertFalse( $service->canConsume( 42, 1 ) );

		$status = $service->getStatus( 42 );
		$this->assertSame( 3, $status['limit'] );
		$this->assertSame( 3, $status['used'] );
		$this->assertSame( 0, $status['remaining'] );
	}

}