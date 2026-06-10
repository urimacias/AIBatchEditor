<?php

namespace MediaWiki\Extension\AIBatchEditor\Tests;

use MediaWiki\Extension\AIBatchEditor\Services\AIService;

/**
 * @group Database
 * @covers \MediaWiki\Extension\AIBatchEditor\Api\ApiAIBatchEditorProcess
 */
class ApiAIBatchEditorProcessTest extends \ApiTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->setService( 'AIBatchEditor.AIService', $this->createMockAIService() );
	}

	private function createMockAIService(): AIService {
		return new class() extends AIService {
			public function __construct() {
			}

			public function complete( array $prompts ): string {
				if ( str_contains( $prompts['user'], 'unchanged marker' ) ) {
					if ( preg_match( '/Revise the following wikitext:\n\n(.*)$/s', $prompts['user'], $m ) ) {
						return trim( $m[1] );
					}
				}
				if ( preg_match( '/Revise the following wikitext:\n\n(.*)$/s', $prompts['user'], $m ) ) {
					return trim( $m[1] ) . "\n\nAI revised.";
				}
				return $prompts['user'] . "\n\nAI revised.";
			}
		};
	}

	public function testProcessRequiresPermission(): void {
		$this->expectApiErrorCode( 'permissiondenied' );
		$performer = $this->getTestUser()->getAuthority();
		$this->doApiRequest( [
			'action' => 'aibatcheditorprocess',
			'titles' => 'Página principal',
			'operation' => 'spellcheck',
		], null, false, $performer );
	}

	public function testProcessReturnsChangedStatus(): void {
		$page = $this->getExistingTestPage( 'AIBatchEditorProcessChanged' );
		$performer = $this->getTestSysop()->getAuthority();

		[ $data ] = $this->doApiRequestWithToken( [
			'action' => 'aibatcheditorprocess',
			'titles' => $page->getTitle()->getPrefixedText(),
			'operation' => 'spellcheck',
			'profile' => 'balanced',
		], null, $performer );

		$result = $data['aibatcheditorprocess'];
		$this->assertSame( 'spellcheck', $result['operation'] );
		$this->assertCount( 1, $result['pages'] );
		$this->assertSame( 'changed', $result['pages'][0]['status'] );
		$this->assertStringContainsString( 'AI revised.', $result['pages'][0]['proposed'] );
	}

	public function testProcessReturnsOmittedWhenUnchanged(): void {
		$this->editPage(
			'AIBatchEditorProcessOmitted',
			"unchanged marker\n\nNo edits expected."
		);
		$page = $this->getExistingTestPage( 'AIBatchEditorProcessOmitted' );
		$performer = $this->getTestSysop()->getAuthority();

		[ $data ] = $this->doApiRequestWithToken( [
			'action' => 'aibatcheditorprocess',
			'titles' => $page->getTitle()->getPrefixedText(),
			'operation' => 'formatting',
			'profile' => 'conservative',
		], null, $performer );

		$result = $data['aibatcheditorprocess'];
		$this->assertSame( 'omitted', $result['pages'][0]['status'] );
		$this->assertArrayNotHasKey( 'proposed', $result['pages'][0] );
	}

	public function testProcessRejectsOversizedPage(): void {
		$this->overrideConfigValue( 'AIBatchEditorMaxPageSize', 20 );
		$this->editPage( 'AIBatchEditorTooLarge', str_repeat( 'x', 200 ) );
		$page = $this->getExistingTestPage( 'AIBatchEditorTooLarge' );
		$performer = $this->getTestSysop()->getAuthority();

		[ $data ] = $this->doApiRequestWithToken( [
			'action' => 'aibatcheditorprocess',
			'titles' => $page->getTitle()->getPrefixedText(),
			'operation' => 'spellcheck',
			'profile' => 'balanced',
		], null, $performer );

		$result = $data['aibatcheditorprocess'];
		$this->assertSame( 'error', $result['pages'][0]['status'] );
		$this->assertSame( 'aibatcheditor-error-page-too-large', $result['pages'][0]['error'] );
	}

	public function testCustomOperationRequiresInstructions(): void {
		$this->expectApiErrorCode( 'custom-needs-instructions' );
		$performer = $this->getTestSysop()->getAuthority();
		$this->doApiRequestWithToken( [
			'action' => 'aibatcheditorprocess',
			'titles' => 'Página principal',
			'operation' => 'custom',
			'profile' => 'balanced',
			'instructions' => '',
		], null, $performer );
	}

}