<?php

namespace MediaWiki\Extension\AIBatchEditor\Services;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\AIBatchEditor\Exceptions\LLMServiceException;
use MediaWiki\Http\HttpRequestFactory;

/**
 * Calls a Grok-compatible OpenAI chat completions endpoint.
 */
class AIService {

	public const CONSTRUCTOR_OPTIONS = [
		'AIBatchEditorApiUrl',
		'AIBatchEditorApiKey',
		'AIBatchEditorModel',
		'AIBatchEditorRequestTimeout',
	];

	private ServiceOptions $options;
	private HttpRequestFactory $httpRequestFactory;

	public function __construct(
		ServiceOptions $options,
		HttpRequestFactory $httpRequestFactory
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->options = $options;
		$this->httpRequestFactory = $httpRequestFactory;
	}

	/**
	 * @param array{system: string, user: string} $prompts
	 * @throws LLMServiceException
	 */
	public function complete( array $prompts ): string {
		$apiUrl = trim( $this->options->get( 'AIBatchEditorApiUrl' ) );
		$apiKey = trim( $this->options->get( 'AIBatchEditorApiKey' ) );
		$model = trim( $this->options->get( 'AIBatchEditorModel' ) );

		if ( $apiUrl === '' || $apiKey === '' ) {
			throw new LLMServiceException( 'aibatcheditor-error-llm-not-configured' );
		}

		$payload = [
			'model' => $model !== '' ? $model : 'grok-2-latest',
			'messages' => [
				[ 'role' => 'system', 'content' => $prompts['system'] ],
				[ 'role' => 'user', 'content' => $prompts['user'] ],
			],
			'temperature' => 0.2,
		];

		$timeout = max( 10, (int)$this->options->get( 'AIBatchEditorRequestTimeout' ) );
		$request = $this->httpRequestFactory->create(
			$apiUrl,
			[
				'method' => 'POST',
				'postData' => json_encode( $payload ),
				'timeout' => $timeout,
			]
		);
		$request->setHeader( 'Content-Type', 'application/json' );
		$request->setHeader( 'Authorization', 'Bearer ' . $apiKey );

		$status = $request->execute();
		$httpCode = (int)$request->getStatus();
		$body = $request->getContent();

		if ( !$status->isOK() ) {
			$apiMessage = $this->extractApiErrorMessage( $body );
			throw new LLMServiceException(
				'aibatcheditor-error-llm-http',
				[ (string)$httpCode, $apiMessage ?: $status->__toString() ],
				$body
			);
		}

		$data = json_decode( $body, true );
		if ( !is_array( $data ) ) {
			throw new LLMServiceException(
				'aibatcheditor-error-llm-invalid-response',
				[],
				substr( $body, 0, 500 )
			);
		}

		$content = $data['choices'][0]['message']['content'] ?? null;
		if ( !is_string( $content ) || trim( $content ) === '' ) {
			throw new LLMServiceException(
				'aibatcheditor-error-llm-empty-response',
				[],
				$body
			);
		}

		return $this->normalizeModelOutput( $content );
	}

	private function extractApiErrorMessage( string $body ): string {
		$data = json_decode( $body, true );
		if ( !is_array( $data ) ) {
			return '';
		}
		if ( isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ) {
			return $data['error']['message'];
		}
		if ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
			return $data['error'];
		}
		return '';
	}

	private function normalizeModelOutput( string $content ): string {
		$content = trim( $content );
		if ( preg_match( '/^```(?:wikitext|mediawiki|txt)?\s*\n(.*)\n```$/s', $content, $matches ) ) {
			return trim( $matches[1] );
		}
		return $content;
	}

}