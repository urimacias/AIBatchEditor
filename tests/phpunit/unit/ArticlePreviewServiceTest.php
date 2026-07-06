<?php

namespace MediaWiki\Extension\AIBatchEditor\Tests;

use MediaWiki\Extension\AIBatchEditor\Services\ArticlePreviewService;
use MediaWiki\Extension\AIBatchEditor\Services\PageContentService;
use MediaWiki\Parser\ParserFactory;
use MediaWikiUnitTestCase;
use Wikimedia\ObjectCache\HashBagOStuff;
use Wikimedia\UUID\GlobalIdGenerator;

/**
 * @covers \MediaWiki\Extension\AIBatchEditor\Services\ArticlePreviewService
 */
class ArticlePreviewServiceTest extends MediaWikiUnitTestCase {

	private function newService(
		?PageContentService $pageContentService = null,
		?GlobalIdGenerator $idGenerator = null
	): ArticlePreviewService {
		$idGenerator ??= $this->createMock( GlobalIdGenerator::class );
		$idGenerator->method( 'newUUIDv4' )->willReturn( '00000000-0000-4000-8000-000000000001' );

		$pageContentService ??= $this->createMock( PageContentService::class );

		return new ArticlePreviewService(
			new HashBagOStuff(),
			$idGenerator,
			$this->createMock( ParserFactory::class ),
			$pageContentService
		);
	}

	public function testStoreAndFetchReturnsMatchingEntry(): void {
		$service = $this->newService();
		$token = $service->store( 'Preview_Page', '== Proposed ==', 42 );

		$this->assertSame( '00000000-0000-4000-8000-000000000001', $token );
		$this->assertSame(
			[
				'title' => 'Preview_Page',
				'proposed' => '== Proposed ==',
			],
			$service->fetch( $token, 42 )
		);
	}

	public function testFetchRejectsWrongUser(): void {
		$service = $this->newService();
		$token = $service->store( 'Preview_Page', 'text', 42 );

		$this->assertNull( $service->fetch( $token, 99 ) );
	}

	public function testFetchReturnsNullForUnknownToken(): void {
		$service = $this->newService();

		$this->assertNull( $service->fetch( 'missing-token', 42 ) );
		$this->assertNull( $service->fetch( '', 42 ) );
	}

}