<?php

namespace MediaWiki\Extension\AIBatchEditor\Tests;

use MediaWiki\Api\ApiUsageException;

/**
 * @group Database
 * @covers \MediaWiki\Extension\AIBatchEditor\Api\ApiAIBatchEditorDiff
 */
class ApiAIBatchEditorDiffTest extends \ApiTestCase {

	public function testDiffRequiresPermission(): void {
		$this->expectApiErrorCode( 'permissiondenied' );
		$performer = $this->getTestUser()->getAuthority();
		$this->doApiRequest( [
			'action' => 'aibatcheditordiff',
			'original' => 'Old',
			'proposed' => 'New',
		], null, false, $performer );
	}

	public function testDiffReturnsHtmlForChanges(): void {
		$performer = $this->getTestSysop()->getAuthority();
		[ $data ] = $this->doApiRequestWithToken( [
			'action' => 'aibatcheditordiff',
			'original' => 'Alpha',
			'proposed' => 'Alpha beta',
			'title' => 'Diff test',
		], null, $performer );

		$diff = $data['aibatcheditordiff'];
		$this->assertSame( 0, $diff['unchanged'] );
		$this->assertStringContainsString( 'diff', $diff['html'] );
	}

	public function testDiffMarksUnchangedContent(): void {
		$performer = $this->getTestSysop()->getAuthority();
		[ $data ] = $this->doApiRequestWithToken( [
			'action' => 'aibatcheditordiff',
			'original' => 'Same',
			'proposed' => 'Same',
		], null, $performer );

		$diff = $data['aibatcheditordiff'];
		$this->assertSame( 1, $diff['unchanged'] );
		$this->assertSame( '', $diff['html'] );
	}

}