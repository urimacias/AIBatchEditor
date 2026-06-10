<?php

namespace MediaWiki\Extension\AIBatchEditor\Tests;

use MediaWiki\Extension\AIBatchEditor\Exceptions\LLMServiceException;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AIBatchEditor\Exceptions\LLMServiceException
 */
class LLMServiceExceptionTest extends MediaWikiUnitTestCase {

	public function testStoresMessageKeyParamsAndLogDetail(): void {
		$exception = new LLMServiceException(
			'aibatcheditor-error-llm-http',
			[ '429', 'Rate limited' ],
			'raw body'
		);

		$this->assertSame( 'aibatcheditor-error-llm-http', $exception->getMessageKey() );
		$this->assertSame( [ '429', 'Rate limited' ], $exception->getParams() );
		$this->assertSame( 'raw body', $exception->getLogDetail() );
	}

}