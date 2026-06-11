<?php

namespace MediaWiki\Extension\AIBatchEditor\Services;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Http\HttpRequestFactory;
use RuntimeException;

/**
 * Fetches template wikitext from a remote MediaWiki site (e.g. es.wikipedia.org).
 */
class TemplateSourceService {

	public const CONSTRUCTOR_OPTIONS = [
		'AIBatchEditorTemplateSourceWiki',
		'AIBatchEditorTemplateSourceAllowHosts',
		'AIBatchEditorMaxPageSize',
	];

	private const DEFAULT_ALLOW_HOSTS = [
		'es.wikipedia.org',
		'en.wikipedia.org',
		'www.mediawiki.org',
	];

	private const MAX_TEMPLATES_PER_REQUEST = 5;
	private const REQUEST_TIMEOUT = 30;

	private ServiceOptions $options;
	private HttpRequestFactory $httpRequestFactory;

	/** @var array<string, string|null> */
	private array $templateNamespaceCache = [];

	public function __construct(
		ServiceOptions $options,
		HttpRequestFactory $httpRequestFactory
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->options = $options;
		$this->httpRequestFactory = $httpRequestFactory;
	}

	/**
	 * @param string $templatesParam Pipe- or newline-separated template names
	 * @param string $sourceWikiOverride Optional wiki base URL override
	 * @return array{context: string, wiki: string, fetched: string[]}
	 */
	public function buildReferenceContext( string $templatesParam, string $sourceWikiOverride = '' ): array {
		$names = $this->parseTemplateNames( $templatesParam );
		if ( $names === [] ) {
			throw new RuntimeException( 'aibatcheditor-error-templates-empty' );
		}
		if ( count( $names ) > self::MAX_TEMPLATES_PER_REQUEST ) {
			throw new RuntimeException( 'aibatcheditor-error-templates-too-many' );
		}

		$wikiBase = $this->resolveWikiBase( $sourceWikiOverride );
		$apiUrl = $this->buildApiUrl( $wikiBase );
		$namespaceName = $this->getTemplateNamespaceName( $apiUrl );

		$titles = [];
		foreach ( $names as $name ) {
			$titles[] = $this->toTemplateTitle( $name, $namespaceName );
		}

		$contents = $this->fetchPageContents( $apiUrl, $titles );
		$fetched = [];
		$blocks = [];

		foreach ( $names as $index => $name ) {
			$title = $titles[ $index ];
			if ( !isset( $contents[ $title ] ) ) {
				throw new RuntimeException( 'aibatcheditor-error-template-not-found' );
			}
			$wikitext = $contents[ $title ];
			$this->assertTemplateSize( $wikitext, $title );
			$fetched[] = $title;
			$blocks[] = "=== {$title} ===\n{$wikitext}";
		}

		$context = "Reference templates fetched from {$wikiBase}:\n\n" . implode( "\n\n", $blocks );

		return [
			'context' => $context,
			'wiki' => $wikiBase,
			'fetched' => $fetched,
		];
	}

	/**
	 * @return string[]
	 */
	public function parseTemplateNames( string $templatesParam ): array {
		$parts = preg_split( '/[\n|]+/', $templatesParam ) ?: [];
		$names = [];
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( $part === '' ) {
				continue;
			}
			$names[] = $part;
		}
		return array_values( array_unique( $names ) );
	}

	public function getDefaultWikiBase(): string {
		return $this->resolveWikiBase( '' );
	}

	private function resolveWikiBase( string $override ): string {
		$wiki = trim( $override );
		if ( $wiki === '' ) {
			$wiki = trim( $this->options->get( 'AIBatchEditorTemplateSourceWiki' ) );
		}
		if ( $wiki === '' ) {
			$wiki = 'https://es.wikipedia.org';
		}

		if ( !preg_match( '#^https://#i', $wiki ) ) {
			throw new RuntimeException( 'aibatcheditor-error-template-source-invalid' );
		}

		$wiki = rtrim( $wiki, '/' );
		$host = parse_url( $wiki, PHP_URL_HOST );
		if ( !is_string( $host ) || $host === '' ) {
			throw new RuntimeException( 'aibatcheditor-error-template-source-invalid' );
		}

		if ( !$this->isHostAllowed( $host ) ) {
			throw new RuntimeException( 'aibatcheditor-error-template-source-not-allowed' );
		}

		return $wiki;
	}

	private function isHostAllowed( string $host ): bool {
		$configured = $this->options->get( 'AIBatchEditorTemplateSourceAllowHosts' );
		$allowed = is_array( $configured ) && $configured !== []
			? $configured
			: self::DEFAULT_ALLOW_HOSTS;

		$host = strtolower( $host );
		foreach ( $allowed as $entry ) {
			if ( !is_string( $entry ) || $entry === '' ) {
				continue;
			}
			$entry = strtolower( $entry );
			if ( $host === $entry || str_ends_with( $host, '.' . $entry ) ) {
				return true;
			}
		}
		return false;
	}

	private function buildApiUrl( string $wikiBase ): string {
		return $wikiBase . '/w/api.php';
	}

	private function getTemplateNamespaceName( string $apiUrl ): string {
		if ( isset( $this->templateNamespaceCache[ $apiUrl ] ) ) {
			$ns = $this->templateNamespaceCache[ $apiUrl ];
			return $ns ?? 'Template';
		}

		$data = $this->apiGet( $apiUrl, [
			'action' => 'query',
			'meta' => 'siteinfo',
			'siprop' => 'namespaces',
		] );

		$name = 'Template';
		$namespaces = $data['query']['namespaces'] ?? [];
		if ( is_array( $namespaces ) && isset( $namespaces['10']['canonical'] ) ) {
			$name = (string)$namespaces['10']['canonical'];
		}

		$this->templateNamespaceCache[ $apiUrl ] = $name;
		return $name;
	}

	private function toTemplateTitle( string $name, string $namespaceName ): string {
		if ( str_contains( $name, ':' ) ) {
			return $name;
		}
		return $namespaceName . ':' . $name;
	}

	/**
	 * @param string[] $titles
	 * @return array<string, string>
	 */
	private function fetchPageContents( string $apiUrl, array $titles ): array {
		$data = $this->apiGet( $apiUrl, [
			'action' => 'query',
			'prop' => 'revisions',
			'rvprop' => 'content',
			'rvslots' => 'main',
			'titles' => implode( '|', $titles ),
		] );

		$pages = $data['query']['pages'] ?? [];
		if ( !is_array( $pages ) ) {
			return [];
		}

		$result = [];
		foreach ( $pages as $page ) {
			if ( !is_array( $page ) ) {
				continue;
			}
			if ( isset( $page['missing'] ) ) {
				continue;
			}
			$title = $page['title'] ?? '';
			$slot = $page['revisions'][0]['slots']['main'] ?? null;
			$content = is_array( $slot ) ? ( $slot['content'] ?? '' ) : '';
			if ( is_string( $title ) && $title !== '' && is_string( $content ) && $content !== '' ) {
				$result[ $title ] = $content;
			}
		}

		return $result;
	}

	/**
	 * @param array<string, string> $params
	 * @return array<string, mixed>
	 */
	private function apiGet( string $apiUrl, array $params ): array {
		$params['format'] = 'json';
		$params['formatversion'] = '2';

		$url = $apiUrl . '?' . wfArrayToCgi( $params );
		$request = $this->httpRequestFactory->create( $url, [
			'method' => 'GET',
			'timeout' => self::REQUEST_TIMEOUT,
		] );

		$status = $request->execute();
		if ( !$status->isOK() ) {
			throw new RuntimeException( 'aibatcheditor-error-template-fetch-failed' );
		}

		$body = $request->getContent();
		$data = json_decode( $body, true );
		if ( !is_array( $data ) ) {
			throw new RuntimeException( 'aibatcheditor-error-template-fetch-failed' );
		}
		if ( isset( $data['error'] ) ) {
			throw new RuntimeException( 'aibatcheditor-error-template-fetch-failed' );
		}

		return $data;
	}

	private function assertTemplateSize( string $wikitext, string $title ): void {
		$maxSize = max( 0, (int)$this->options->get( 'AIBatchEditorMaxPageSize' ) );
		if ( $maxSize > 0 && strlen( $wikitext ) > $maxSize ) {
			throw new RuntimeException( 'aibatcheditor-error-template-too-large' );
		}
	}

}