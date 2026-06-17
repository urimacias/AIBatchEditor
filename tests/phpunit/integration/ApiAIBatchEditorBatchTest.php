<?php

namespace MediaWiki\Extension\AIBatchEditor\Tests;

use MediaWiki\Extension\AIBatchEditor\Services\AIService;

/**
 * @group Database
 * @covers \MediaWiki\Extension\AIBatchEditor\Api\ApiAIBatchEditorBatchStart
 * @covers \MediaWiki\Extension\AIBatchEditor\Api\ApiAIBatchEditorBatchStatus
 */
class ApiAIBatchEditorBatchTest extends \ApiTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->setService( 'AIBatchEditor.AIService', $this->createMockAIService() );
	}

	private function createMockAIService(): AIService {
		return new class() extends AIService {
			public function __construct() {
			}

			public function complete( array $prompts ): string {
				$wikitext = self::extractWikitextFromUserPrompt( $prompts['user'] );
				return trim( $wikitext ) . "\n\nAI revised.";
			}

			private static function extractWikitextFromUserPrompt( string $user ): string {
				if ( preg_match( '/Wikitext to revise:\n\n(.*)$/s', $user, $m ) ) {
					return $m[1];
				}
				if ( preg_match( '/Revise the following wikitext according to the system instructions:\n\n(.*)$/s', $user, $m ) ) {
					return $m[1];
				}
				return $user;
			}
		};
	}

	public function testBatchStartRequiresPermission(): void {
		$this->expectApiErrorCode( 'permissiondenied' );
		$performer = $this->getTestUser()->getAuthority();
		$this->doApiRequest( [
			'action' => 'aibatcheditorbatchstart',
			'titles' => 'Main_Page',
			'operation' => 'spellcheck',
		], null, false, $performer );
	}

	public function testServerBatchProcessesPagesToCompletion(): void {
		$page = $this->getExistingTestPage( 'AIBatchEditorServerBatch' );
		$performer = $this->getTestSysop()->getAuthority();
		$titleText = $page->getTitle()->getPrefixedText();

		[ $startData ] = $this->doApiRequestWithToken( [
			'action' => 'aibatcheditorbatchstart',
			'titles' => $titleText,
			'operation' => 'spellcheck',
			'profile' => 'balanced',
		], null, $performer );

		$batchId = $startData['aibatcheditorbatchstart']['batchId'];
		$this->assertNotEmpty( $batchId );
		$this->assertSame( 'running', $startData['aibatcheditorbatchstart']['status'] );

		$result = null;
		for ( $i = 0; $i < 20; $i++ ) {
			[ $statusData ] = $this->doApiRequestWithToken( [
				'action' => 'aibatcheditorbatchstatus',
				'batchid' => $batchId,
			], null, $performer );
			$result = $statusData['aibatcheditorbatchstatus'];
			if ( ( $result['status'] ?? '' ) === 'complete' ) {
				break;
			}
		}

		$this->assertNotNull( $result );
		$this->assertSame( 'complete', $result['status'] );
		$this->assertCount( 1, $result['pages'] );
		$this->assertSame( 'changed', $result['pages'][0]['status'] );
		$this->assertNotEmpty( $result['pages'][0]['draftToken'] );
	}

	public function testBatchStatusRejectsForeignBatch(): void {
		$page = $this->getExistingTestPage( 'AIBatchEditorBatchForeign' );
		$owner = $this->getTestSysop()->getAuthority();
		$other = $this->getTestUser( [ 'sysop' ] )->getAuthority();

		[ $startData ] = $this->doApiRequestWithToken( [
			'action' => 'aibatcheditorbatchstart',
			'titles' => $page->getTitle()->getPrefixedText(),
			'operation' => 'spellcheck',
		], null, $owner );

		$batchId = $startData['aibatcheditorbatchstart']['batchId'];
		$this->expectApiErrorCode( 'batch-not-found' );
		$this->doApiRequestWithToken( [
			'action' => 'aibatcheditorbatchstatus',
			'batchid' => $batchId,
		], null, $other );
	}

}