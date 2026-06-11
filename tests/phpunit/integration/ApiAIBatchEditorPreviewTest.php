<?php

namespace MediaWiki\Extension\AIBatchEditor\Tests;

/**
 * @group Database
 * @covers \MediaWiki\Extension\AIBatchEditor\Api\ApiAIBatchEditorPreview
 */
class ApiAIBatchEditorPreviewTest extends \ApiTestCase {

	public function testPreviewRequiresPermission(): void {
		$this->expectApiErrorCode( 'permissiondenied' );
		$performer = $this->getTestUser()->getAuthority();
		$this->doApiRequest( [
			'action' => 'aibatcheditorpreview',
			'titles' => 'Página principal',
			'operation' => 'spellcheck',
		], null, false, $performer );
	}

	public function testPreviewReturnsPrompts(): void {
		$page = $this->getExistingTestPage( 'AIBatchEditorPreviewTest' );
		$this->editPage( $page->getTitle(), '== Preview test ==' );
		$performer = $this->getTestSysop()->getAuthority();

		[ $data ] = $this->doApiRequestWithToken( [
			'action' => 'aibatcheditorpreview',
			'titles' => $page->getTitle()->getPrefixedText(),
			'operation' => 'custom',
			'profile' => 'balanced',
			'instructions' => 'Add a wikilink to Main Page',
		], null, $performer );

		$preview = $data['aibatcheditorpreview'];
		$this->assertSame( $page->getTitle()->getPrefixedText(), $preview['title'] );
		$this->assertStringContainsString( 'MANDATORY EDITOR INSTRUCTIONS', $preview['system'] );
		$this->assertStringContainsString( 'Add a wikilink to Main Page', $preview['system'] );
		$this->assertStringContainsString( '== Preview test ==', $preview['user'] );
	}

	public function testPreviewRequiresTitle(): void {
		$this->expectApiErrorCode( 'preview-needs-title' );
		$performer = $this->getTestSysop()->getAuthority();
		$this->doApiRequestWithToken( [
			'action' => 'aibatcheditorpreview',
			'titles' => '   ',
			'operation' => 'spellcheck',
		], null, $performer );
	}

}