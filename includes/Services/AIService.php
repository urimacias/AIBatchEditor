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
		'AIBatchEditorTemperature',
		'AIBatchEditorReasoningEffort',
	];

	/** @var list<string> */
	public const REASONING_EFFORTS = [ 'low', 'medium', 'high' ];

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
		if ( $apiUrl === '' || $apiKey === '' ) {
			throw new LLMServiceException( 'aibatcheditor-error-llm-not-configured' );
		}

		$timeout = max( 10, (int)$this->options->get( 'AIBatchEditorRequestTimeout' ) );
		$request = $this->httpRequestFactory->create(
			$apiUrl,
			[
				'method' => 'POST',
				'postData' => json_encode( $this->buildPayload( $prompts ) ),
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
			$transportDetail = $apiMessage ?: $status->__toString();
			if ( $httpCode === 0 ) {
				throw new LLMServiceException(
					'aibatcheditor-error-llm-timeout',
					[ (string)$timeout ],
					$transportDetail
				);
			}
			throw new LLMServiceException(
				'aibatcheditor-error-llm-http',
				[ (string)$httpCode, $transportDetail ],
				$body
			);
		}

		return $this->parseCompletionBody( $body );
	}

	/**
	 * Run multiple chat-completions in parallel (curl_multi when available).
	 *
	 * @param array<array-key, array{system: string, user: string}> $promptsByKey
	 * @return array<array-key, string|LLMServiceException>
	 */
	public function completeMany( array $promptsByKey ): array {
		if ( $promptsByKey === [] ) {
			return [];
		}

		$apiUrl = trim( $this->options->get( 'AIBatchEditorApiUrl' ) );
		$apiKey = trim( $this->options->get( 'AIBatchEditorApiKey' ) );
		if ( $apiUrl === '' || $apiKey === '' ) {
			$err = new LLMServiceException( 'aibatcheditor-error-llm-not-configured' );
			$out = [];
			foreach ( array_keys( $promptsByKey ) as $key ) {
				$out[$key] = $err;
			}
			return $out;
		}

		$timeout = max( 10, (int)$this->options->get( 'AIBatchEditorRequestTimeout' ) );
		$reqs = [];
		foreach ( $promptsByKey as $key => $prompts ) {
			$reqs[$key] = [
				'method' => 'POST',
				'url' => $apiUrl,
				'headers' => [
					'Content-Type' => 'application/json',
					'Authorization' => 'Bearer ' . $apiKey,
				],
				'body' => json_encode( $this->buildPayload( $prompts ) ),
			];
		}

		$client = $this->httpRequestFactory->createMultiClient( [
			'maxConnsPerHost' => max( 1, count( $reqs ) ),
			'connTimeout' => min( 30, $timeout ),
			'reqTimeout' => $timeout,
		] );
		$client->runMulti( $reqs );

		$out = [];
		foreach ( $reqs as $key => $req ) {
			$response = $req['response'] ?? [];
			$httpCode = (int)( $response['code'] ?? 0 );
			$body = is_string( $response['body'] ?? null ) ? $response['body'] : '';
			$error = is_string( $response['error'] ?? null ) ? $response['error'] : '';

			if ( $httpCode < 200 || $httpCode >= 300 ) {
				$apiMessage = $this->extractApiErrorMessage( $body );
				$transportDetail = $apiMessage !== '' ? $apiMessage : ( $error !== '' ? $error : 'request failed' );
				if ( $httpCode === 0 ) {
					$out[$key] = new LLMServiceException(
						'aibatcheditor-error-llm-timeout',
						[ (string)$timeout ],
						$transportDetail
					);
					continue;
				}
				$out[$key] = new LLMServiceException(
					'aibatcheditor-error-llm-http',
					[ (string)$httpCode, $transportDetail ],
					$body
				);
				continue;
			}

			try {
				$out[$key] = $this->parseCompletionBody( $body );
			} catch ( LLMServiceException $e ) {
				$out[$key] = $e;
			}
		}

		return $out;
	}

	/**
	 * @param array{system: string, user: string} $prompts
	 * @return array<string, mixed>
	 */
	private function buildPayload( array $prompts ): array {
		$model = trim( $this->options->get( 'AIBatchEditorModel' ) );
		$temperature = $this->options->get( 'AIBatchEditorTemperature' );
		if ( !is_numeric( $temperature ) ) {
			$temperature = 0.1;
		}
		$temperature = max( 0.0, min( 1.0, (float)$temperature ) );

		$payload = [
			'model' => $model !== '' ? $model : 'grok-2-latest',
			'messages' => [
				[ 'role' => 'system', 'content' => $prompts['system'] ],
				[ 'role' => 'user', 'content' => $prompts['user'] ],
			],
			'temperature' => $temperature,
		];

		$effort = strtolower( trim( (string)$this->options->get( 'AIBatchEditorReasoningEffort' ) ) );
		if ( in_array( $effort, self::REASONING_EFFORTS, true ) ) {
			// Chat Completions (OpenAI-compatible) + xAI SDK style.
			$payload['reasoning_effort'] = $effort;
		}

		return $payload;
	}

	/**
	 * @throws LLMServiceException
	 */
	private function parseCompletionBody( string $body ): string {
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