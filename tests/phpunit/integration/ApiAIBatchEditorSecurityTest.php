<?php

namespace MediaWiki\Extension\AIBatchEditor\Tests;

use MediaWiki\Extension\AIBatchEditor\Exceptions\LLMServiceException;
use MediaWiki\Extension\AIBatchEditor\Services\AIService;
use MediaWiki\Extension\AIBatchEditor\Services\PageContentService;

/**
 * @group Database
 * @coversNothing
 */
class ApiAIBatchEditorSecurityTest extends \ApiTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->setService( 'AIBatchEditor.AIService', $this->createMockAIService() );
	}

	private function createMockAIService(): AIService {
		return new class() extends AIService {
			public function __construct() {
			}

			public function complete( array $prompts ): string {
				if ( preg_match( '/Revise the following wikitext:\n\n(.*)$/s', $prompts['user'], $m ) ) {
					return trim( $m[1] ) . "\n\nAI revised.";
				}
				return $prompts['user'] . "\n\nAI revised.";
			}
		};
	}

	private function mockNonWikitextPageInfo( string $titleText ): void {
		$services = $this->getServiceContainer();
		$mock = $this->getMockBuilder( PageContentService::class )
			->setConstructorArgs( [
				$services->getWikiPageFactory(),
				$services->getPermissionManager(),
				$services->getTitleFactory(),
				$services->getNamespaceInfo(),
				$services->getConnectionProvider(),
			] )
			->onlyMethods( [ 'getPageInfo' ] )
			->getMock();

		$mock->method( 'getPageInfo' )->willReturn( [
			'title' => $titleText,
			'exists' => true,
			'editable' => false,
			'revid' => 42,
			'size' => 12,
			'error' => 'not-wikitext',
		] );

		$this->setService( 'AIBatchEditor.PageContentService', $mock );
	}

	public function testProcessRejectsOversizedInstructions(): void {
		$this->overrideConfigValue( 'AIBatchEditorMaxInstructionsLength', 8 );
		$this->expectApiErrorCode( 'instructions-too-long' );
		$performer = $this->getTestSysop()->getAuthority();
		$this->doApiRequestWithToken( [
			'action' => 'aibatcheditorprocess',
			'titles' => 'Página principal',
			'operation' => 'spellcheck',
			'profile' => 'balanced',
			'instructions' => 'way too long instructions',
		], null, $performer );
	}

	public function testDiffRejectsOversizedInput(): void {
		$this->overrideConfigValue( 'AIBatchEditorMaxPageSize', 10 );
		$this->expectApiErrorCode( 'diff-too-large' );
		$performer = $this->getTestSysop()->getAuthority();
		$this->doApiRequestWithToken( [
			'action' => 'aibatcheditordiff',
			'original' => str_repeat( 'a', 25 ),
			'proposed' => 'short',
		], null, $performer );
	}

	public function testSaveRejectsOversizedProposedWikitext(): void {
		$this->overrideConfigValue( 'AIBatchEditorMaxPageSize', 20 );
		$page = $this->getExistingTestPage( 'AIBatchEditorSaveSizeLimit' );
		$revRecord = $page->getRevisionRecord();
		$performer = $this->getTestSysop()->getAuthority();

		$this->expectApiErrorCode( 'proposed-too-large' );
		$this->doApiRequestWithToken( [
			'action' => 'aibatcheditorsave',
			'summary' => 'Too large',
			'edits' => json_encode( [
				[
					'title' => $page->getTitle()->getPrefixedText(),
					'revid' => $revRecord->getId(),
					'proposed' => str_repeat( 'x', 200 ),
				],
			] ),
		], null, $performer );
	}

	public function testProcessRejectsNonWikitextPage(): void {
		$titleText = 'NonWikitextProcessPage';
		$this->mockNonWikitextPageInfo( $titleText );
		$performer = $this->getTestSysop()->getAuthority();

		[ $data ] = $this->doApiRequestWithToken( [
			'action' => 'aibatcheditorprocess',
			'titles' => $titleText,
			'operation' => 'spellcheck',
			'profile' => 'balanced',
		], null, $performer );

		$result = $data['aibatcheditorprocess'];
		$this->assertSame( 'error', $result['pages'][0]['status'] );
		$this->assertSame( 'aibatcheditor-page-error-not-wikitext', $result['pages'][0]['error'] );
	}

	public function testProcessSanitizesLlmHttpErrors(): void {
		$this->setService( 'AIBatchEditor.AIService', new class() extends AIService {
			public function __construct() {
			}

			public function complete( array $prompts ): string {
				throw new LLMServiceException(
					'aibatcheditor-error-llm-http',
					[ '502', 'secret upstream error detail' ],
					'raw body'
				);
			}
		} );

		$page = $this->getExistingTestPage( 'AIBatchEditorLlmHttpError' );
		$performer = $this->getTestSysop()->getAuthority();

		[ $data ] = $this->doApiRequestWithToken( [
			'action' => 'aibatcheditorprocess',
			'titles' => $page->getTitle()->getPrefixedText(),
			'operation' => 'spellcheck',
			'profile' => 'balanced',
		], null, $performer );

		$pageResult = $data['aibatcheditorprocess']['pages'][0];
		$this->assertSame( 'error', $pageResult['status'] );
		$this->assertStringNotContainsString( 'secret upstream', $pageResult['errorInfo'] );
		$this->assertStringContainsString( '502', $pageResult['errorInfo'] );
	}

	public function testListMarksNonWikitextPageAsSkipped(): void {
		$titleText = 'NonWikitextListPage';
		$this->mockNonWikitextPageInfo( $titleText );
		$performer = $this->getTestSysop()->getAuthority();

		[ $data ] = $this->doApiRequestWithToken( [
			'action' => 'aibatcheditorlist',
			'titles' => $titleText,
		], null, $performer );

		$listPage = $data['pages'][0];
		$this->assertSame( 'not-wikitext', $listPage['error'] );
		$this->assertSame( 0, $listPage['editable'] );
	}

}