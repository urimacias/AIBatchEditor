<?php

namespace MediaWiki\Extension\AIBatchEditor\Tests;

use MediaWiki\Extension\AIBatchEditor\EnvFile;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AIBatchEditor\EnvFile
 */
class EnvFileTest extends MediaWikiUnitTestCase {

	protected function tearDown(): void {
		putenv( 'AIBATCHEDITOR_TEST_KEY' );
		unset( $_ENV['AIBATCHEDITOR_TEST_KEY'], $_SERVER['AIBATCHEDITOR_TEST_KEY'] );
		parent::tearDown();
	}

	public function testLoadSetsUnsetVariables(): void {
		$path = tempnam( sys_get_temp_dir(), 'aibe-env-' );
		$this->assertNotFalse( $path );
		file_put_contents( $path, "# comment\nAIBATCHEDITOR_TEST_KEY=secret-value\n" );

		EnvFile::load( $path );
		@unlink( $path );

		$this->assertSame( 'secret-value', getenv( 'AIBATCHEDITOR_TEST_KEY' ) );
	}

	public function testLoadDoesNotOverrideExistingEnv(): void {
		putenv( 'AIBATCHEDITOR_TEST_KEY=existing' );

		$path = tempnam( sys_get_temp_dir(), 'aibe-env-' );
		$this->assertNotFalse( $path );
		file_put_contents( $path, "AIBATCHEDITOR_TEST_KEY=new-value\n" );

		EnvFile::load( $path );
		@unlink( $path );

		$this->assertSame( 'existing', getenv( 'AIBATCHEDITOR_TEST_KEY' ) );
	}

}