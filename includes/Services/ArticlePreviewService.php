<?php

namespace MediaWiki\Extension\AIBatchEditor\Services;

use MediaWiki\Context\IContextSource;
use MediaWiki\Parser\ParserFactory;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Title\Title;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\UUID\GlobalIdGenerator;

/**
 * Short-lived storage and rendering for proposed-article previews.
 */
class ArticlePreviewService {

	private const CACHE_PREFIX = 'aibatcheditor-article-preview';
	private const TTL_SECONDS = 600;

	private BagOStuff $cache;
	private GlobalIdGenerator $idGenerator;
	private ParserFactory $parserFactory;
	private PageContentService $pageContentService;

	public function __construct(
		BagOStuff $cache,
		GlobalIdGenerator $idGenerator,
		ParserFactory $parserFactory,
		PageContentService $pageContentService
	) {
		$this->cache = $cache;
		$this->idGenerator = $idGenerator;
		$this->parserFactory = $parserFactory;
		$this->pageContentService = $pageContentService;
	}

	public function store( string $title, string $proposed, int $userId ): string {
		$token = $this->idGenerator->newUUIDv4();
		$this->cache->set(
			$this->cacheKey( $token ),
			[
				'title' => $title,
				'proposed' => $proposed,
				'userId' => $userId,
			],
			self::TTL_SECONDS
		);
		return $token;
	}

	/**
	 * @return array{title: string, proposed: string}|null
	 */
	public function fetch( string $token, int $userId ): ?array {
		if ( $token === '' ) {
			return null;
		}

		$value = $this->cache->get( $this->cacheKey( $token ) );
		if ( !is_array( $value ) ) {
			return null;
		}

		if ( (int)( $value['userId'] ?? 0 ) !== $userId ) {
			return null;
		}

		$title = (string)( $value['title'] ?? '' );
		$proposed = (string)( $value['proposed'] ?? '' );
		if ( $title === '' ) {
			return null;
		}

		return [
			'title' => $title,
			'proposed' => $proposed,
		];
	}

	public function buildPreviewUrl( string $token ): string {
		$title = Title::newFromText( 'Special:AIBatchEditorArticlePreview' );
		if ( !$title ) {
			return '';
		}
		return $title->getFullURL( [ 'token' => $token ] );
	}

	public function render(
		string $titleText,
		string $proposed,
		IContextSource $context
	): ParserOutput {
		$title = Title::newFromText( $titleText );
		if ( !$title ) {
			throw new \InvalidArgumentException( 'aibatcheditor-error-save-invalid-title' );
		}

		$info = $this->pageContentService->getPageInfo( $titleText, $context->getAuthority() );
		if ( isset( $info['error'] ) ) {
			throw new \InvalidArgumentException( 'aibatcheditor-page-error-' . $info['error'] );
		}

		$parser = $this->parserFactory->create();
		$options = ParserOptions::newFromContext( $context );
		$options->setRenderReason( 'aibatcheditor-article-preview' );

		return $parser->parse( $proposed, $title, $options );
	}

	private function cacheKey( string $token ): string {
		return $this->cache->makeKey( self::CACHE_PREFIX, $token );
	}

}