<?php

namespace MediaWiki\Extension\AIBatchEditor\Tests;

/**
 * @group Database
 * @covers \MediaWiki\Extension\AIBatchEditor\Api\ApiAIBatchEditorArticlePreview
 */
class ApiAIBatchEditorArticlePreviewTest extends \ApiTestCase {

	public function testArticlePreviewRequiresPermission(): void {
		$this->expectApiErrorCode( 'permissiondenied' );
		$performer = $this->getTestUser()->getAuthority();
		$this->doApiRequest( [
			'action' => 'aibatcheditorarticlepreview',
			'title' => 'Main_Page',
			'proposed' => 'Preview text',
		], null, false, $performer );
	}

	public function testArticlePreviewReturnsTokenAndUrl(): void {
		$page = $this->getExistingTestPage( 'AIBatchEditorArticlePreviewApiTest' );
		$this->editPage( $page->getTitle(), 'Original content' );

		$performer = $this->getTestSysop()->getAuthority();
		[ $data ] = $this->doApiRequestWithToken( [
			'action' => 'aibatcheditorarticlepreview',
			'title' => $page->getTitle()->getPrefixedText(),
			'proposed' => "== Preview heading ==\nPreview body.",
		], null, $performer );

		$preview = $data['aibatcheditorarticlepreview'];
		$this->assertNotEmpty( $preview['token'] );
		$this->assertStringContainsString( 'Special:AIBatchEditorArticlePreview', $preview['url'] );
		$this->assertStringContainsString( 'token=' . $preview['token'], $preview['url'] );
	}

	public function testArticlePreviewRequiresTitle(): void {
		$this->expectApiErrorCode( 'preview-needs-title' );
		$performer = $this->getTestSysop()->getAuthority();
		$this->doApiRequestWithToken( [
			'action' => 'aibatcheditorarticlepreview',
			'title' => '   ',
			'proposed' => 'Preview text',
		], null, $performer );
	}

}