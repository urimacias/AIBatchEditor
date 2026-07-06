<?php

namespace MediaWiki\Extension\AIBatchEditor\Tests;

use ErrorPageError;
use MediaWiki\Extension\AIBatchEditor\Services\ArticlePreviewService;
use MediaWiki\Extension\AIBatchEditor\SpecialAIBatchEditorArticlePreview;
use MediaWiki\Request\FauxRequest;
use MediaWiki\SpecialPage\SpecialPage;

/**
 * @group Database
 * @covers \MediaWiki\Extension\AIBatchEditor\SpecialAIBatchEditorArticlePreview
 */
class SpecialAIBatchEditorArticlePreviewTest extends \SpecialPageTestBase {

	protected function newSpecialPage(): SpecialPage {
		/** @var ArticlePreviewService $previewService */
		$previewService = $this->getServiceContainer()->get( 'AIBatchEditor.ArticlePreviewService' );
		return new SpecialAIBatchEditorArticlePreview( $previewService );
	}

	public function testSpecialPageRendersProposedWikitext(): void {
		$page = $this->getExistingTestPage( 'AIBatchEditorArticlePreviewSpecialTest' );
		$this->editPage( $page->getTitle(), 'Original content' );

		$sysop = $this->getTestSysop();
		/** @var ArticlePreviewService $previewService */
		$previewService = $this->getServiceContainer()->get( 'AIBatchEditor.ArticlePreviewService' );
		$token = $previewService->store(
			$page->getTitle()->getPrefixedText(),
			'== Preview heading ==',
			$sysop->getUser()->getId()
		);

		[ $html ] = $this->executeSpecialPage(
			'',
			new FauxRequest( [ 'token' => $token ] ),
			null,
			$sysop->getAuthority()
		);

		$this->assertStringContainsString( 'ext-aibatcheditor-article-preview-banner', $html );
		$this->assertStringContainsString( 'Preview heading', $html );
	}

	public function testSpecialPageRejectsExpiredToken(): void {
		$sysop = $this->getTestSysop();

		$this->expectException( ErrorPageError::class );
		$this->executeSpecialPage(
			'',
			new FauxRequest( [ 'token' => '00000000-0000-4000-8000-000000009999' ] ),
			null,
			$sysop->getAuthority()
		);
	}

}