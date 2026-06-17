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
				],
			] ),
		], null, $performer );

		$save = $data['aibatcheditorsave'];
		$this->assertSame( 'Batch save test', $save['summary'] );
		$this->assertCount( 1, $save['pages'] );
		$this->assertSame( 'saved', $save['pages'][0]['status'] );
		$this->assertGreaterThan( $revRecord->getId(), $save['pages'][0]['newrevid'] );
	}

}