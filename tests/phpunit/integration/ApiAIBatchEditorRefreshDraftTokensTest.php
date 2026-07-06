<?php

namespace MediaWiki\Extension\AIBatchEditor\Tests;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\AIBatchEditor\Services\DraftTokenService;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;

/**
 * @group Database
 * @covers \MediaWiki\Extension\AIBatchEditor\Api\ApiAIBatchEditorRefreshDraftTokens
 */
class ApiAIBatchEditorRefreshDraftTokensTest extends \ApiTestCase {

	public function testRefreshRequiresPermission(): void {
		$this->expectApiErrorCode( 'permissiondenied' );
		$performer = $this->getTestUser()->getAuthority();
		$this->doApiRequest( [
			'action' => 'aibatcheditorrefreshdrafttokens',
			'edits' => '[]',
		], null, false, $performer );
	}

	public function testRefreshIssuesTokenAndSaveSucceeds(): void {
		$page = $this->getExistingTestPage( 'AIBatchEditorRefreshTokenTest' );
		$titleText = $page->getTitle()->getPrefixedText();
		$revRecord = $page->getRevisionRecord();
		$proposed = $revRecord->getContent( SlotRecord::MAIN, RevisionRecord::RAW )->getText()
			. "\n\nRefreshed save test.";

		$performer = $this->getTestSysop()->getAuthority();
		[ $refreshData ] = $this->doApiRequestWithToken( [
			'action' => 'aibatcheditorrefreshdrafttokens',
			'edits' => json_encode( [
				[
					'title' => $titleText,
					'revid' => $revRecord->getId(),
					'proposed' => $proposed,
				],
			] ),
		], null, $performer );

		$refresh = $refreshData['aibatcheditorrefreshdrafttokens'];
		$this->assertCount( 1, $refresh['pages'] );
		$this->assertSame( 'ok', $refresh['pages'][0]['status'] );
		$this->assertNotEmpty( $refresh['pages'][0]['draftToken'] );

		[ $saveData ] = $this->doApiRequestWithToken( [
			'action' => 'aibatcheditorsave',
			'summary' => 'Refresh token save test',
			'edits' => json_encode( [
				[
					'title' => $titleText,
					'revid' => $revRecord->getId(),
					'proposed' => $proposed,
					'draftToken' => $refresh['pages'][0]['draftToken'],
				],
			] ),
		], null, $performer );

		$this->assertSame( 'saved', $saveData['aibatcheditorsave']['pages'][0]['status'] );
	}

	public function testSaveRecoversFromStaleTokenSignature(): void {
		$page = $this->getExistingTestPage( 'AIBatchEditorStaleTokenTest' );
		$titleText = $page->getTitle()->getPrefixedText();
		$revRecord = $page->getRevisionRecord();
		$proposed = $revRecord->getContent( SlotRecord::MAIN, RevisionRecord::RAW )->getText()
			. "\n\nStale token recovery.";

		$staleService = new DraftTokenService(
			new ServiceOptions( DraftTokenService::CONSTRUCTOR_OPTIONS, [
				'SecretKey' => 'stale-secret-for-test',
				'AIBatchEditorDraftTokenSecret' => '',
			] )
		);
		$staleToken = $staleService->issue(
			$titleText,
			$revRecord->getId(),
			$proposed,
			$this->getTestSysop()->getUser()->getId()
		);

		$performer = $this->getTestSysop()->getAuthority();
		[ $data ] = $this->doApiRequestWithToken( [
			'action' => 'aibatcheditorsave',
			'summary' => 'Recovered stale token save',
			'edits' => json_encode( [
				[
					'title' => $titleText,
					'revid' => $revRecord->getId(),
					'proposed' => $proposed,
					'draftToken' => $staleToken,
				],
			] ),
		], null, $performer );

		$this->assertSame( 'saved', $data['aibatcheditorsave']['pages'][0]['status'] );
	}

	public function testRefreshAfterStaleTokenAllowsSave(): void {
		$page = $this->getExistingTestPage( 'AIBatchEditorStaleRefreshTest' );
		$titleText = $page->getTitle()->getPrefixedText();
		$revRecord = $page->getRevisionRecord();
		$proposed = $revRecord->getContent( SlotRecord::MAIN, RevisionRecord::RAW )->getText()
			. "\n\nStale refresh recovery.";

		$staleService = new DraftTokenService(
			new ServiceOptions( DraftTokenService::CONSTRUCTOR_OPTIONS, [
				'SecretKey' => 'another-stale-secret',
				'AIBatchEditorDraftTokenSecret' => '',
			] )
		);
		$staleToken = $staleService->issue(
			$titleText,
			$revRecord->getId(),
			$proposed,
			$this->getTestSysop()->getUser()->getId()
		);
		$this->assertNotEmpty( $staleToken );

		$performer = $this->getTestSysop()->getAuthority();
		[ $refreshData ] = $this->doApiRequestWithToken( [
			'action' => 'aibatcheditorrefreshdrafttokens',
			'edits' => json_encode( [
				[
					'title' => $titleText,
					'revid' => $revRecord->getId(),
					'proposed' => $proposed,
				],
			] ),
		], null, $performer );

		$freshToken = $refreshData['aibatcheditorrefreshdrafttokens']['pages'][0]['draftToken'];
		[ $saveData ] = $this->doApiRequestWithToken( [
			'action' => 'aibatcheditorsave',
			'summary' => 'Recovered save',
			'edits' => json_encode( [
				[
					'title' => $titleText,
					'revid' => $revRecord->getId(),
					'proposed' => $proposed,
					'draftToken' => $freshToken,
				],
			] ),
		], null, $performer );

		$this->assertSame( 'saved', $saveData['aibatcheditorsave']['pages'][0]['status'] );
	}

	public function testRefreshReturnsConflictWhenRevisionChanged(): void {
		$page = $this->getExistingTestPage( 'AIBatchEditorRefreshConflictTest' );
		$titleText = $page->getTitle()->getPrefixedText();
		$revRecord = $page->getRevisionRecord();
		$proposed = $revRecord->getContent( SlotRecord::MAIN, RevisionRecord::RAW )->getText()
			. "\n\nConflict test.";

		$performer = $this->getTestSysop()->getAuthority();
		$this->editPage( $titleText, $proposed . "\nExternal edit." );

		[ $refreshData ] = $this->doApiRequestWithToken( [
			'action' => 'aibatcheditorrefreshdrafttokens',
			'edits' => json_encode( [
				[
					'title' => $titleText,
					'revid' => $revRecord->getId(),
					'proposed' => $proposed,
				],
			] ),
		], null, $performer );

		$pageResult = $refreshData['aibatcheditorrefreshdrafttokens']['pages'][0];
		$this->assertSame( 'conflict', $pageResult['status'] );
		$this->assertArrayNotHasKey( 'draftToken', $pageResult );
	}

}