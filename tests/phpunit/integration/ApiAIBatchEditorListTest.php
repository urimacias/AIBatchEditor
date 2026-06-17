<?php

namespace MediaWiki\Extension\AIBatchEditor\Tests;

/**
 * @group Database
 * @covers \MediaWiki\Extension\AIBatchEditor\Api\ApiAIBatchEditorList
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

}