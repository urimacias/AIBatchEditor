<?php

namespace MediaWiki\Extension\AIBatchEditor\Tests;

use MediaWiki\Extension\AIBatchEditor\Services\PromptFactory;

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
		$this->assertStringContainsString( 'TASK — Editor instructions:', $preview['system'] );
		$this->assertStringContainsString( 'Add a wikilink to Main Page', $preview['system'] );
		$this->assertStringContainsString(
			'Prompt version: ' . PromptFactory::PROMPT_VERSION . '.',
			$preview['system']
		);
		$this->assertStringContainsString( '== Preview test ==', $preview['user'] );
	}

	public function testPreviewSpellcheckIncludesProfileAndPreservation(): void {
		$page = $this->getExistingTestPage( 'AIBatchEditorSpellcheckPreview' );
		$this->editPage( $page->getTitle(), 'Hello wrld' );
		$performer = $this->getTestSysop()->getAuthority();

		[ $data ] = $this->doApiRequestWithToken( [
			'action' => 'aibatcheditorpreview',
			'titles' => $page->getTitle()->getPrefixedText(),
			'operation' => 'spellcheck',
			'profile' => 'balanced',
		], null, $performer );

		$preview = $data['aibatcheditorpreview'];
		$this->assertStringContainsString( 'All obvious typos in prose.', $preview['system'] );
		$this->assertStringContainsString( 'SCOPE for this operation (what may change):', $preview['system'] );
		$this->assertStringContainsString( 'Do not change grammar, wording, structure', $preview['system'] );
		$this->assertStringContainsString( 'Hello wrld', $preview['user'] );
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