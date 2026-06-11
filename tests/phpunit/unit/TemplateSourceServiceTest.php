<?php

namespace MediaWiki\Extension\AIBatchEditor\Tests;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\AIBatchEditor\Services\TemplateSourceService;
use MediaWiki\Http\HttpRequestFactory;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AIBatchEditor\Services\TemplateSourceService
 */
class TemplateSourceServiceTest extends MediaWikiUnitTestCase {

	private function makeService( array $configOverrides = [] ): TemplateSourceService {
		$config = array_merge( [
			'AIBatchEditorTemplateSourceWiki' => 'https://es.wikipedia.org',
			'AIBatchEditorTemplateSourceAllowHosts' => [ 'es.wikipedia.org' ],
			'AIBatchEditorMaxPageSize' => 51200,
		], $configOverrides );

		return new TemplateSourceService(
			new ServiceOptions( TemplateSourceService::CONSTRUCTOR_OPTIONS, $config ),
			$this->createMock( HttpRequestFactory::class )
		);
	}

	public function testParseTemplateNames(): void {
		$service = $this->makeService();
		$this->assertSame(
			[ 'Ficha', 'Citation' ],
			$service->parseTemplateNames( "Ficha\nCitation|Ficha" )
		);
	}

	public function testRejectsDisallowedHost(): void {
		$this->expectException( \RuntimeException::class );
		$service = $this->makeService();
		$service->buildReferenceContext( 'Ficha', 'https://evil.example.org' );
	}

	public function testRequiresTemplateNames(): void {
		$this->expectException( \RuntimeException::class );
		$service = $this->makeService();
		$service->buildReferenceContext( '   ' );
	}

}