<?php

namespace MediaWiki\Extension\AIBatchEditor\Tests;

use MediaWiki\Extension\AIBatchEditor\Services\AIService;
use MediaWiki\Permissions\Authority;

/**
 * @group Database
 * @covers \MediaWiki\Extension\AIBatchEditor\Api\ApiAIBatchEditorBatchStart
 * @covers \MediaWiki\Extension\AIBatchEditor\Api\ApiAIBatchEditorBatchStatus
 * @covers \MediaWiki\Extension\AIBatchEditor\Api\ApiAIBatchEditorBatchCancel
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
				if ( str_contains( $wikitext, 'unchanged marker' ) ) {
					return trim( $wikitext );
				}
				return trim( $wikitext ) . "\n\nAI revised.";
			}

			private static function extractWikitextFromUserPrompt( string $user ): string {
				if ( preg_match( '/=== INPUT ===\n\n(.*)$/s', $user, $m ) ) {
					return $m[1];
				}
				return $user;
			}
		};
	}

	private function createShrinkingMockAIService(): AIService {
		return new class() extends AIService {
			public function __construct() {
			}

			public function complete( array $prompts ): string {
				$wikitext = self::extractWikitextFromUserPrompt( $prompts['user'] );
				return substr( trim( $wikitext ), 0, (int)( strlen( $wikitext ) * 0.4 ) );
			}

			private static function extractWikitextFromUserPrompt( string $user ): string {
				if ( preg_match( '/=== INPUT ===\n\n(.*)$/s', $user, $m ) ) {
					return $m[1];
				}
				return $user;
			}
		};
	}

	/**
	 * @param Authority $performer
	 * @param array<string, mixed> $startParams
	 * @return array<string, mixed>
	 */
	private function runBatchToCompletion( Authority $performer, array $startParams ): array {
		[ $startData ] = $this->doApiRequestWithToken( array_merge( [
			'action' => 'aibatcheditorbatchstart',
		], $startParams ), null, $performer );

		$batchId = $startData['aibatcheditorbatchstart']['batchId'];
		$this->assertNotEmpty( $batchId );

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
		return $result;
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

		$result = $this->runBatchToCompletion( $performer, [
			'titles' => $titleText,
			'operation' => 'spellcheck',
			'profile' => 'balanced',
		] );

		$this->assertSame( 'spellcheck', $result['operation'] );
		$this->assertCount( 1, $result['pages'] );
		$this->assertSame( 'changed', $result['pages'][0]['status'] );
		$this->assertStringContainsString( 'AI revised.', $result['pages'][0]['proposed'] );
		$this->assertNotEmpty( $result['pages'][0]['draftToken'] );
	}

	public function testBatchReturnsWarningsForRiskyProposal(): void {
		$this->setService( 'AIBatchEditor.AIService', $this->createShrinkingMockAIService() );
		$body = str_repeat( 'Keep this sentence. ', 40 );
		$this->editPage( 'AIBatchEditorBatchWarnings', $body );
		$page = $this->getExistingTestPage( 'AIBatchEditorBatchWarnings' );
		$performer = $this->getTestSysop()->getAuthority();

		$result = $this->runBatchToCompletion( $performer, [
			'titles' => $page->getTitle()->getPrefixedText(),
			'operation' => 'spellcheck',
			'profile' => 'balanced',
		] );

		$this->assertSame( 'changed', $result['pages'][0]['status'] );
		$this->assertContains( 'major-deletion', $result['pages'][0]['warnings'] );
	}

	public function testBatchReturnsOmittedWhenUnchanged(): void {
		$this->editPage(
			'AIBatchEditorBatchOmitted',
			"unchanged marker\n\nNo edits expected."
		);
		$page = $this->getExistingTestPage( 'AIBatchEditorBatchOmitted' );
		$performer = $this->getTestSysop()->getAuthority();

		$result = $this->runBatchToCompletion( $performer, [
			'titles' => $page->getTitle()->getPrefixedText(),
			'operation' => 'formatting',
			'profile' => 'conservative',
		] );

		$this->assertSame( 'omitted', $result['pages'][0]['status'] );
		$this->assertArrayNotHasKey( 'proposed', $result['pages'][0] );
	}

	public function testBatchRejectsOversizedPage(): void {
		$this->overrideConfigValue( 'AIBatchEditorMaxPageSize', 20 );
		$this->editPage( 'AIBatchEditorBatchTooLarge', str_repeat( 'x', 200 ) );
		$page = $this->getExistingTestPage( 'AIBatchEditorBatchTooLarge' );
		$performer = $this->getTestSysop()->getAuthority();

		$result = $this->runBatchToCompletion( $performer, [
			'titles' => $page->getTitle()->getPrefixedText(),
			'operation' => 'spellcheck',
			'profile' => 'balanced',
		] );

		$this->assertSame( 'error', $result['pages'][0]['status'] );
		$this->assertSame( 'aibatcheditor-error-page-too-large', $result['pages'][0]['error'] );
	}

	public function testSpellcheckRejectsWhenDisabled(): void {
		$this->overrideConfigValue( 'AIBatchEditorEnabledOperations', [
			'wikilinks' => true,
			'spellcheck' => false,
			'formatting' => true,
			'style' => true,
			'templates' => true,
			'custom' => true,
		] );
		$this->expectApiErrorCode( 'operation-disabled' );
		$performer = $this->getTestSysop()->getAuthority();
		$this->doApiRequestWithToken( [
			'action' => 'aibatcheditorbatchstart',
			'titles' => 'Página principal',
			'operation' => 'spellcheck',
			'profile' => 'balanced',
		], null, $performer );
	}

	public function testCustomOperationRequiresInstructions(): void {
		$this->expectApiErrorCode( 'custom-needs-instructions' );
		$performer = $this->getTestSysop()->getAuthority();
		$this->doApiRequestWithToken( [
			'action' => 'aibatcheditorbatchstart',
			'titles' => 'Página principal',
			'operation' => 'custom',
			'profile' => 'balanced',
			'instructions' => '',
		], null, $performer );
	}

	public function testBatchCancelStopsRunningBatch(): void {
		$page = $this->getExistingTestPage( 'AIBatchEditorBatchCancel' );
		$performer = $this->getTestSysop()->getAuthority();

		[ $startData ] = $this->doApiRequestWithToken( [
			'action' => 'aibatcheditorbatchstart',
			'titles' => $page->getTitle()->getPrefixedText(),
			'operation' => 'spellcheck',
		], null, $performer );

		$batchId = $startData['aibatcheditorbatchstart']['batchId'];

		[ $cancelData ] = $this->doApiRequestWithToken( [
			'action' => 'aibatcheditorbatchcancel',
			'batchid' => $batchId,
		], null, $performer );

		$this->assertSame( 'cancelled', $cancelData['aibatcheditorbatchcancel']['status'] );

		[ $statusData ] = $this->doApiRequestWithToken( [
			'action' => 'aibatcheditorbatchstatus',
			'batchid' => $batchId,
		], null, $performer );

		$this->assertSame( 'cancelled', $statusData['aibatcheditorbatchstatus']['status'] );
	}

	public function testBatchCancelRejectsForeignBatch(): void {
		$page = $this->getExistingTestPage( 'AIBatchEditorBatchCancelForeign' );
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
			'action' => 'aibatcheditorbatchcancel',
			'batchid' => $batchId,
		], null, $other );
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