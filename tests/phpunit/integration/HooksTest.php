<?php

namespace MediaWiki\Extension\AIBatchEditor\Tests;

use MediaWiki\Extension\AIBatchEditor\Hooks;

/**
 * @covers \MediaWiki\Extension\AIBatchEditor\Hooks
 */
class HooksTest extends \MediaWikiIntegrationTestCase {

	public function testChangeTagIsRegistered(): void {
		$tags = [];
		( new Hooks() )->onListDefinedTags( $tags );
		$this->assertContains( Hooks::TAG_NAME, $tags );

		$active = [];
		( new Hooks() )->onChangeTagsListActive( $active );
		$this->assertContains( Hooks::TAG_NAME, $active );
	}

}