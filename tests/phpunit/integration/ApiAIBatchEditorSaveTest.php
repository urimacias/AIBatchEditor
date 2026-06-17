<?php

namespace MediaWiki\Extension\AIBatchEditor\Tests;

use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;

/**
 * @group Database
 * @covers \MediaWiki\Extension\AIBatchEditor\Api\ApiAIBatchEditorSave
 */
class ApiAIBatchEditorSaveTest extends \ApiTestCase {

	public function testSaveRequiresPermission(): void {
		$this->expectApiErrorCode( 'permissiondenied' );
		$performer = $this->getTestUser()->getAuthority();
		$this->doApiRequest( [
			'action' => 'aibatcheditorsave',
			'summary' => 'Test',
			'edits' => '[]',
		], null, false, $performer );
	}

	public function testSaveRequiresSummary(): void {
		$this->expectApiErrorCode( 'missingparam' );
		$performer = $this->getTestSysop()->getAuthority();
		$this->doApiRequestWithToken( [
			'action' => 'aibatcheditorsave',
			'edits' => '[]',
		], null, $performer );
	}

	public function testSavePersistsApprovedEdit(): void {
		$page = $this->getExistingTestPage( 'AIBatchEditorSaveApiTest' );
		$titleText = $page->getTitle()->getPrefixedText();
		$revRecord = $page->getRevisionRecord();
		$proposed = $revRecord->getContent( SlotRecord::MAIN, RevisionRecord::RAW )->getText()
			. "\n\nSaved via API.";
		$draftToken = $this->issueDraftToken( $titleText, $revRecord->getId(), $proposed );

		$performer = $this->getTestSysop()->getAuthority();
		[ $data ] = $this->doApiRequestWithToken( [
			'action' => 'aibatcheditorsave',
			'summary' => 'Batch save test',
			'operation' => 'spellcheck',
			'profile' => 'balanced',
			'edits' => json_encode( [
				[
					'title' => $titleText,
					'revid' => $revRecord->getId(),
					'proposed' => $proposed,
					'draftToken' => $draftToken,
				],
			] ),
		], null, $performer );

		$save = $data['aibatcheditorsave'];
		$this->assertSame( 'Batch save test', $save['summary'] );
		$this->assertCount( 1, $save['pages'] );
		$this->assertSame( 'saved', $save['pages'][0]['status'] );
		$this->assertGreaterThan( $revRecord->getId(), $save['pages'][0]['newrevid'] );
	}

	public function testSaveRejectsMissingDraftToken(): void {
		$page = $this->getExistingTestPage( 'AIBatchEditorSaveTokenTest' );
		$titleText = $page->getTitle()->getPrefixedText();
		$revRecord = $page->getRevisionRecord();
		$proposed = $revRecord->getContent( SlotRecord::MAIN, RevisionRecord::RAW )->getText()
			. "\n\nToken test.";

		$this->expectApiErrorCode( 'invalid-json' );
		$performer = $this->getTestSysop()->getAuthority();
		$this->doApiRequestWithToken( [
			'action' => 'aibatcheditorsave',
			'summary' => 'Batch save test',
			'edits' => json_encode( [
				[
					'title' => $titleText,
					'revid' => $revRecord->getId(),
					'proposed' => $proposed,
				],
			] ),
		], null, $performer );
	}

	public function testSaveRejectsInvalidDraftToken(): void {
		$page = $this->getExistingTestPage( 'AIBatchEditorSaveBadTokenTest' );
		$titleText = $page->getTitle()->getPrefixedText();
		$revRecord = $page->getRevisionRecord();
		$proposed = $revRecord->getContent( SlotRecord::MAIN, RevisionRecord::RAW )->getText()
			. "\n\nBad token test.";

		$this->expectApiErrorCode( 'invalid-draft-token' );
		$performer = $this->getTestSysop()->getAuthority();
		$this->doApiRequestWithToken( [
			'action' => 'aibatcheditorsave',
			'summary' => 'Batch save test',
			'edits' => json_encode( [
				[
					'title' => $titleText,
					'revid' => $revRecord->getId(),
					'proposed' => $proposed,
					'draftToken' => 'not-a-valid-token',
				],
			] ),
		], null, $performer );
	}

	private function issueDraftToken( string $title, int $revid, string $proposed ): string {
		$service = $this->getServiceContainer()->get( 'AIBatchEditor.DraftTokenService' );
		return $service->issue( $title, $revid, $proposed, $this->getTestSysop()->getUser()->getId() );
	}

}