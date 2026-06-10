<?php

namespace MediaWiki\Extension\AIBatchEditor\Services;

use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\Authority;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\NamespaceInfo;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Loads page metadata and wikitext for the batch editor workflow.
 */
class PageContentService {

	/** Safety cap on category rows scanned per request. */
	public const MAX_CATEGORY_SCAN = 10000;

	private const CATEGORY_PAGE_BATCH = 200;

	private WikiPageFactory $wikiPageFactory;
	private PermissionManager $permissionManager;
	private TitleFactory $titleFactory;
	private NamespaceInfo $namespaceInfo;
	private IConnectionProvider $connectionProvider;

	public function __construct(
		WikiPageFactory $wikiPageFactory,
		PermissionManager $permissionManager,
		TitleFactory $titleFactory,
		NamespaceInfo $namespaceInfo,
		IConnectionProvider $connectionProvider
	) {
		$this->wikiPageFactory = $wikiPageFactory;
		$this->permissionManager = $permissionManager;
		$this->titleFactory = $titleFactory;
		$this->namespaceInfo = $namespaceInfo;
		$this->connectionProvider = $connectionProvider;
	}

	/**
	 * @param string|null $titles Pipe- or newline-separated titles
	 * @param string|null $category Category name (without prefix)
	 * @param string|null $prefix Optional title prefix filter for category members
	 * @param int $maxBatch Maximum titles to return
	 * @return string[] Canonical page titles (DB key form with namespace prefix where needed)
	 */
	public function resolveTitleTexts(
		?string $titles,
		?string $category,
		?string $prefix,
		int $maxBatch,
		Authority $performer
	): array {
		if ( $category !== null && $category !== '' ) {
			return $this->resolveTitlesFromCategory( $category, $prefix, $maxBatch, $performer );
		}

		if ( $titles === null || trim( $titles ) === '' ) {
			return [];
		}

		$parts = preg_split( '/[\n|]+/', $titles ) ?: [];
		$resolved = [];
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( $part === '' ) {
				continue;
			}
			$resolved[] = $part;
			if ( count( $resolved ) >= $maxBatch ) {
				break;
			}
		}

		return $resolved;
	}

	/**
	 * @return string[]
	 */
	private function resolveTitlesFromCategory(
		string $category,
		?string $prefix,
		int $maxBatch,
		Authority $performer
	): array {
		$catTitle = $this->titleFactory->makeTitle( NS_CATEGORY, $category );
		if ( !$catTitle || !$catTitle->exists() ) {
			return [];
		}

		return $this->collectCategoryMembers( $catTitle->getDBkey(), $prefix, $maxBatch, $performer );
	}

	/**
	 * Count readable, content-namespace wikitext members in a category (up to MAX_CATEGORY_SCAN).
	 */
	public function countEligibleCategoryMembers(
		string $category,
		?string $prefix,
		Authority $performer
	): int {
		$catTitle = $this->titleFactory->makeTitle( NS_CATEGORY, $category );
		if ( !$catTitle || !$catTitle->exists() ) {
			return 0;
		}

		return count( $this->collectCategoryMembers( $catTitle->getDBkey(), $prefix, null, $performer ) );
	}

	/**
	 * @param int|null $maxResults Stop after this many matches; null returns all scanned.
	 * @return string[]
	 */
	private function collectCategoryMembers(
		string $categoryDbKey,
		?string $prefix,
		?int $maxResults,
		Authority $performer
	): array {
		$prefixKey = $this->resolvePrefixDbKey( $prefix );
		$dbr = $this->connectionProvider->getReplicaDatabase();
		$sortKeyOffset = '';
		$scanned = 0;
		$resolved = [];

		while ( $scanned < self::MAX_CATEGORY_SCAN ) {
			if ( $maxResults !== null && count( $resolved ) >= $maxResults ) {
				break;
			}

			$queryBuilder = $dbr->newSelectQueryBuilder()
				->select( [ 'page_namespace', 'page_title', 'cl_sortkey' ] )
				->from( 'categorylinks' )
				->join( 'page', null, [ 'cl_from = page_id' ] )
				->join( 'linktarget', null, 'cl_target_id = lt_id' )
				->where( [
					'lt_title' => $categoryDbKey,
					'lt_namespace' => NS_CATEGORY,
					'cl_type' => 'page',
				] )
				->orderBy( 'cl_sortkey' )
				->limit( self::CATEGORY_PAGE_BATCH );

			if ( $sortKeyOffset !== '' ) {
				$queryBuilder->andWhere( $dbr->expr( 'cl_sortkey', '>', $sortKeyOffset ) );
			}

			$result = $queryBuilder->caller( __METHOD__ )->fetchResultSet();
			if ( !$result->numRows() ) {
				break;
			}

			foreach ( $result as $row ) {
				$scanned++;
				$sortKeyOffset = $row->cl_sortkey;

				$title = $this->titleFactory->makeTitle( (int)$row->page_namespace, $row->page_title );
				if ( !$title ) {
					continue;
				}
				if ( $prefixKey !== null && !str_starts_with( $title->getDBkey(), $prefixKey ) ) {
					continue;
				}
				if ( !$this->namespaceInfo->isContent( $title->getNamespace() ) ) {
					continue;
				}
				if ( !$this->permissionManager->quickUserCan( 'read', $performer, $title ) ) {
					continue;
				}
				$resolved[] = $title->getPrefixedText();
				if ( $maxResults !== null && count( $resolved ) >= $maxResults ) {
					break 2;
				}
			}
		}

		return $resolved;
	}

	private function resolvePrefixDbKey( ?string $prefix ): ?string {
		if ( $prefix === null || trim( $prefix ) === '' ) {
			return null;
		}
		$title = Title::newFromText( trim( $prefix ) );
		return $title ? $title->getDBkey() : str_replace( ' ', '_', trim( $prefix ) );
	}

	/**
	 * @param string $titleText
	 * @param Authority $performer
	 * @return array{
	 *   title: string,
	 *   exists: bool,
	 *   editable: bool,
	 *   revid: int|null,
	 *   size: int,
	 *   wikitext?: string,
	 *   error?: string
	 * }
	 */
	public function getPageInfo( string $titleText, Authority $performer, bool $includeWikitext = false ): array {
		$title = Title::newFromText( $titleText );
		if ( !$title ) {
			return [
				'title' => $titleText,
				'exists' => false,
				'editable' => false,
				'revid' => null,
				'size' => 0,
				'error' => 'invalid-title',
			];
		}

		$prefixedTitle = $title->getPrefixedText();
		if ( !$title->exists() ) {
			return [
				'title' => $prefixedTitle,
				'exists' => false,
				'editable' => false,
				'revid' => null,
				'size' => 0,
				'error' => 'not-exists',
			];
		}

		if ( $title->isRedirect() ) {
			return [
				'title' => $prefixedTitle,
				'exists' => true,
				'editable' => false,
				'revid' => null,
				'size' => 0,
				'error' => 'redirect',
			];
		}

		if ( !$this->permissionManager->quickUserCan( 'read', $performer, $title ) ) {
			return [
				'title' => $prefixedTitle,
				'exists' => true,
				'editable' => false,
				'revid' => null,
				'size' => 0,
				'error' => 'not-readable',
			];
		}

		$wikiPage = $this->wikiPageFactory->newFromTitle( $title );
		$revRecord = $wikiPage->getRevisionRecord();
		if ( !$revRecord ) {
			return [
				'title' => $prefixedTitle,
				'exists' => true,
				'editable' => false,
				'revid' => null,
				'size' => 0,
				'error' => 'no-revision',
			];
		}

		$mainSlot = $revRecord->getSlot( SlotRecord::MAIN );
		if ( $mainSlot->getModel() !== CONTENT_MODEL_WIKITEXT ) {
			return [
				'title' => $prefixedTitle,
				'exists' => true,
				'editable' => false,
				'revid' => $revRecord->getId(),
				'size' => $mainSlot->getSize(),
				'error' => 'not-wikitext',
			];
		}

		$editable = $this->permissionManager->quickUserCan( 'edit', $performer, $title );
		$size = $mainSlot->getSize();

		$result = [
			'title' => $prefixedTitle,
			'exists' => true,
			'editable' => $editable,
			'revid' => $revRecord->getId(),
			'size' => $size,
		];

		if ( !$editable ) {
			$result['error'] = 'not-editable';
			return $result;
		}

		if ( $includeWikitext ) {
			$content = $revRecord->getContent( SlotRecord::MAIN, RevisionRecord::RAW );
			$result['wikitext'] = $content ? $content->getText() : '';
		}

		return $result;
	}

}