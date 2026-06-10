<?php

namespace MediaWiki\Extension\AIBatchEditor\Tests;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\AIBatchEditor\Exceptions\LLMServiceException;
use MediaWiki\Extension\AIBatchEditor\Services\AIService;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Status\Status;
use MediaWikiUnitTestCase;
use MWHttpRequest;

/**
 * @covers \MediaWiki\Extension\AIBatchEditor\Services\AIService
 */
class AIServiceTest extends MediaWikiUnitTestCase {

	private function defaultConfig( array $overrides = [] ): array {
		return array_merge( [
			'AIBatchEditorApiUrl' => 'https://api.example.com/v1/chat/completions',
			'AIBatchEditorApiKey' => 'secret',
			'AIBatchEditorModel' => 'grok-test',
			'AIBatchEditorRequestTimeout' => 120,
		], $overrides );
	}

	private function makeService(
		array $config,
		?MWHttpRequest $request = null
	): AIService {
		$factory = $this->createMock( HttpRequestFactory::class );
		if ( $request !== null ) {
			$factory->method( 'create' )->willReturn( $request );
		}

		return new AIService(
			new ServiceOptions( AIService::CONSTRUCTOR_OPTIONS, $config ),
			$factory
		);
	}

	private function makeRequest( int $httpCode, string $body, bool $ok = true ): MWHttpRequest {
		$request = $this->createMock( MWHttpRequest::class );
		$request->method( 'execute' )->willReturn( $ok ? Status::newGood() : Status::newFatal( 'http' ) );
		$request->method( 'getStatus' )->willReturn( $httpCode );
		$request->method( 'getContent' )->willReturn( $body );
		return $request;
	}

	public function testNotConfiguredWhenUrlMissing(): void {
		$service = $this->makeService( $this->defaultConfig( [
			'AIBatchEditorApiUrl' => '',
		] ) );

		$this->expectException( LLMServiceException::class );
		$this->expectExceptionMessage( 'aibatcheditor-error-llm-not-configured' );
		$service->complete( [ 'system' => 'sys', 'user' => 'user' ] );
	}

	public function testSuccessfulCompletion(): void {
		$body = json_encode( [
			'choices' => [
				[ 'message' => [ 'content' => 'Revised wikitext' ] ],
			],
		] );
		$service = $this->makeService(
			$this->defaultConfig(),
			$this->makeRequest( 200, $body )
		);

		$result = $service->complete( [ 'system' => 'sys', 'user' => 'user' ] );
		$this->assertSame( 'Revised wikitext', $result );
	}

	public function testStripsMarkdownFences(): void {
		$body = json_encode( [
			'choices' => [
				[ 'message' => [ 'content' => "```wikitext\n== Heading ==\n```" ] ],
			],
		] );
		$service = $this->makeService(
			$this->defaultConfig( [ 'AIBatchEditorModel' => '' ] ),
			$this->makeRequest( 200, $body )
		);

		$result = $service->complete( [ 'system' => 'sys', 'user' => 'user' ] );
		$this->assertSame( '== Heading ==', $result );
	}

	public function testHttpErrorThrowsWithMessageKey(): void {
		$body = json_encode( [ 'error' => [ 'message' => 'Invalid model' ] ] );
		$service = $this->makeService(
			$this->defaultConfig( [ 'AIBatchEditorModel' => 'bad-model' ] ),
			$this->makeRequest( 400, $body, false )
		);

		try {
			$service->complete( [ 'system' => 'sys', 'user' => 'user' ] );
			$this->fail( 'Expected LLMServiceException' );
		} catch ( LLMServiceException $e ) {
			$this->assertSame( 'aibatcheditor-error-llm-http', $e->getMessageKey() );
			$this->assertSame( '400', $e->getParams()[0] );
			$this->assertSame( 'Invalid model', $e->getParams()[1] );
		}
	}

	public function testInvalidJsonResponse(): void {
		$service = $this->makeService(
			$this->defaultConfig(),
			$this->makeRequest( 200, 'not-json' )
		);

		$this->expectException( LLMServiceException::class );
		$this->expectExceptionMessage( 'aibatcheditor-error-llm-invalid-response' );
		$service->complete( [ 'system' => 'sys', 'user' => 'user' ] );
	}

	public function testEmptyModelContent(): void {
		$body = json_encode( [ 'choices' => [ [ 'message' => [ 'content' => '   ' ] ] ] ] );
		$service = $this->makeService(
			$this->defaultConfig(),
			$this->makeRequest( 200, $body )
		);

		$this->expectException( LLMServiceException::class );
		$this->expectExceptionMessage( 'aibatcheditor-error-llm-empty-response' );
		$service->complete( [ 'system' => 'sys', 'user' => 'user' ] );
	}

}