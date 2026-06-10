<?php

namespace MediaWiki\Extension\AIBatchEditor\Tests;

use MediaWiki\Extension\AIBatchEditor\Services\DiffService;
use MediaWiki\Request\FauxRequest;
use RequestContext;

/**
 * @group Database
 * @covers \MediaWiki\Extension\AIBatchEditor\Services\DiffService
 */
class DiffServiceTest extends \MediaWikiIntegrationTestCase {

	public function testRenderWikitextDiffShowsChanges(): void {
		$context = new RequestContext();
		$context->setRequest( new FauxRequest() );
		$context->setLanguage( 'en' );

		$service = new DiffService();
		$html = $service->renderWikitextDiff(
			'Hello world',
			'Hello brave new world',
			$context,
			'Test Page'
		);

		$this->assertStringContainsString( 'diff', $html );
		$this->assertStringContainsString( 'brave', $html );
	}

	public function testUnchangedWikitextProducesEmptyDiff(): void {
		$context = new RequestContext();
		$context->setRequest( new FauxRequest() );
		$context->setLanguage( 'en' );

		$service = new DiffService();
		$html = $service->renderWikitextDiff(
			'Same text',
			'Same text',
			$context
		);

		$this->assertStringContainsString( 'mw-diff-empty', $html );
	}

}