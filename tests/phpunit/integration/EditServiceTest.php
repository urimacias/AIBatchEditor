<?php

namespace MediaWiki\Extension\AIBatchEditor\Tests;

use MediaWiki\Extension\AIBatchEditor\Services\EditService;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;

/**
 * @group Database
 * @covers \MediaWiki\Extension\AIBatchEditor\Services\EditService
 */
class EditServiceTest extends \MediaWikiIntegrationTestCase {

	private function getEditService(): EditService {
		return $this->getServiceContainer()->get( 'AIBatchEditor.EditService' );
	}

	public function testSavePageCreatesRevision(): void {
		$page = $this->getExistingTestPage( 'AIBatchEditorEditTest' );
		$titleText = $page->getTitle()->getPrefixedText();
		$revRecord = $page->getRevisionRecord();
		$original = $revRecord->getContent( SlotRecord::MAIN, RevisionRecord::RAW )->getText();
		$proposed = $original . "\n\nAI batch line.";

		$result = $this->getEditService()->savePage(
			$this->getTestSysop()->getAuthority(),
			$titleText,
			$revRecord->getId(),
			$proposed,
			'AI batch test save'
		);

		$this->assertSame( 'saved', $result['status'] );
		$this->assertSame( $revRecord->getId(), $result['revid'] );
		$this->assertGreaterThan( $revRecord->getId(), $result['newrevid'] );
	}

	public function testSavePageOmittedWhenUnchanged(): void {
		$page = $this->getExistingTestPage( 'AIBatchEditorOmitTest' );
		$titleText = $page->getTitle()->getPrefixedText();
		$revRecord = $page->getRevisionRecord();
		$wikitext = $revRecord->getContent( SlotRecord::MAIN, RevisionRecord::RAW )->getText();

		$result = $this->getEditService()->savePage(
			$this->getTestSysop()->getAuthority(),
			$titleText,
			$revRecord->getId(),
			$wikitext,
			'Should omit'
		);

		$this->assertSame( 'omitted', $result['status'] );
		$this->assertArrayNotHasKey( 'newrevid', $result );
	}

	public function testSavePageDetectsEditConflict(): void {
		$page = $this->getExistingTestPage( 'AIBatchEditorConflictTest' );
		$titleText = $page->getTitle()->getPrefixedText();
		$revRecord = $page->getRevisionRecord();
		$staleRevId = $revRecord->getId() - 1;

		$result = $this->getEditService()->savePage(
			$this->getTestSysop()->getAuthority(),
			$titleText,
			$staleRevId > 0 ? $staleRevId : 0,
			$revRecord->getContent( SlotRecord::MAIN, RevisionRecord::RAW )->getText() . "\nConflict test.",
			'Conflict'
		);

		$this->assertSame( 'error', $result['status'] );
		$this->assertSame( 'aibatcheditor-error-save-conflict', $result['error'] );
	}

}