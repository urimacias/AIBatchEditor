<?php

namespace MediaWiki\Extension\AIBatchEditor\Tests;

/**
 * @group Database
 * @covers \MediaWiki\Extension\AIBatchEditor\Api\ApiAIBatchEditorList
 * @covers \MediaWiki\Extension\AIBatchEditor\Services\PageContentService
 */
class ApiAIBatchEditorListTest extends \ApiTestCase {

	public function testListRequiresPermission(): void {
		$this->expectApiErrorCode( 'permissiondenied' );
		$performer = $this->getTestUser()->getAuthority();
		$this->doApiRequest( [
			'action' => 'aibatcheditorlist',
			'titles' => 'Main Page',
		], null, false, $performer );
	}

	public function testListReturnsPageForSysop(): void {
		$performer = $this->getTestSysop()->getAuthority();
		[ $data ] = $this->doApiRequestWithToken( [
			'action' => 'aibatcheditorlist',
			'titles' => 'Página principal',
		], null, $performer );

		$this->assertArrayHasKey( 'pages', $data );
		$this->assertNotEmpty( $data['pages'] );
		$this->assertArrayHasKey( 'rateLimit', $data );
		$this->assertArrayHasKey( 'limit', $data['rateLimit'] );
		$this->assertArrayHasKey( 'used', $data['rateLimit'] );
		$this->assertArrayHasKey( 'remaining', $data['rateLimit'] );
	}

	public function testListByTemplateReturnsTranscludingPages(): void {
		$this->editPage( 'Template:AIBatchEditorListTpl', 'Template marker' );
		$this->editPage( 'AIBatchEditorListTplUsed', '{{AIBatchEditorListTpl}}' );
		$this->editPage( 'AIBatchEditorListTplUnused', 'No template here.' );

		$performer = $this->getTestSysop()->getAuthority();
		[ $data ] = $this->doApiRequestWithToken( [
			'action' => 'aibatcheditorlist',
			'template' => 'AIBatchEditorListTpl',
		], null, $performer );

		$titles = array_column( $data['pages'], 'title' );
		$this->assertContains( 'AIBatchEditorListTplUsed', $titles );
		$this->assertNotContains( 'AIBatchEditorListTplUnused', $titles );
		$this->assertArrayHasKey( 'templateTotal', $data );
		$this->assertSame( 1, $data['templateTotal'] );
	}

	public function testListByTemplateAcceptsBracedName(): void {
		$this->editPage( 'Template:AIBatchEditorListTplBraced', 'Body' );
		$this->editPage( 'AIBatchEditorListTplBracedUsed', '{{AIBatchEditorListTplBraced}}' );

		$performer = $this->getTestSysop()->getAuthority();
		[ $data ] = $this->doApiRequestWithToken( [
			'action' => 'aibatcheditorlist',
			'template' => '{{AIBatchEditorListTplBraced}}',
		], null, $performer );

		$titles = array_column( $data['pages'], 'title' );
		$this->assertContains( 'AIBatchEditorListTplBracedUsed', $titles );
	}

	public function testListByTemplateRejectsMissingTemplate(): void {
		$this->expectApiErrorCode( 'template-page-not-found' );
		$performer = $this->getTestSysop()->getAuthority();
		$this->doApiRequestWithToken( [
			'action' => 'aibatcheditorlist',
			'template' => 'AIBatchEditorListTplMissing',
		], null, $performer );
	}

	public function testListByTemplateRespectsPrefixFilter(): void {
		$this->editPage( 'Template:AIBatchEditorListTplPrefix', 'Body' );
		$this->editPage( 'AIBatchEditorListTplPrefixMatch', '{{AIBatchEditorListTplPrefix}}' );
		$this->editPage( 'AIBatchEditorListTplPrefixOther', '{{AIBatchEditorListTplPrefix}}' );

		$performer = $this->getTestSysop()->getAuthority();
		[ $data ] = $this->doApiRequestWithToken( [
			'action' => 'aibatcheditorlist',
			'template' => 'AIBatchEditorListTplPrefix',
			'prefix' => 'AIBatchEditorListTplPrefixMatch',
		], null, $performer );

		$titles = array_column( $data['pages'], 'title' );
		$this->assertSame( [ 'AIBatchEditorListTplPrefixMatch' ], $titles );
	}

}