<?php

namespace MediaWiki\Extension\AIBatchEditor\Services;

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Extension\AIBatchEditor\Hooks;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\Authority;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;

/**
 * Saves AI-proposed wikitext through MediaWiki's normal edit pipeline.
 */
class EditService {

	private WikiPageFactory $wikiPageFactory;
	private PermissionManager $permissionManager;

	public function __construct(
		WikiPageFactory $wikiPageFactory,
		PermissionManager $permissionManager
	) {
		$this->wikiPageFactory = $wikiPageFactory;
		$this->permissionManager = $permissionManager;
	}

	/**
	 * @return array{
	 *   title: string,
	 *   status: string,
	 *   revid?: int,
	 *   newrevid?: int,
	 *   error?: string
	 * }
	 */
	public function savePage(
		Authority $performer,
		string $titleText,
		int $baseRevId,
		string $proposedWikitext,
		string $summary
	): array {
		$title = Title::newFromText( $titleText );
		if ( !$title ) {
			return $this->errorResult( $titleText, 'aibatcheditor-error-save-invalid-title' );
		}

		$prefixedTitle = $title->getPrefixedText();
		if ( !$title->exists() ) {
			return $this->errorResult( $prefixedTitle, 'aibatcheditor-error-save-not-exists' );
		}

		if ( $title->isRedirect() ) {
			return $this->errorResult( $prefixedTitle, 'aibatcheditor-error-save-redirect' );
		}

		if ( !$this->permissionManager->quickUserCan( 'edit', $performer, $title ) ) {
			return $this->errorResult( $prefixedTitle, 'aibatcheditor-error-save-not-editable' );
		}

		$wikiPage = $this->wikiPageFactory->newFromTitle( $title );
		$revRecord = $wikiPage->getRevisionRecord();
		if ( !$revRecord ) {
			return $this->errorResult( $prefixedTitle, 'aibatcheditor-error-save-no-revision' );
		}

		$currentRevId = $revRecord->getId();
		if ( $currentRevId !== $baseRevId ) {
			return $this->errorResult( $prefixedTitle, 'aibatcheditor-error-save-conflict' );
		}

		$mainSlot = $revRecord->getSlot( SlotRecord::MAIN );
		if ( $mainSlot->getModel() !== CONTENT_MODEL_WIKITEXT ) {
			return $this->errorResult( $prefixedTitle, 'aibatcheditor-error-save-not-wikitext' );
		}

		$content = $revRecord->getContent( SlotRecord::MAIN, RevisionRecord::RAW );
		$currentWikitext = $content ? $content->getText() : '';
		if ( $proposedWikitext === $currentWikitext ) {
			return [
				'title' => $prefixedTitle,
				'status' => 'omitted',
				'revid' => $currentRevId,
			];
		}

		$updater = $wikiPage->newPageUpdater( $performer );
		if ( $updater->hasEditConflict( $baseRevId ) ) {
			return $this->errorResult( $prefixedTitle, 'aibatcheditor-error-save-conflict' );
		}

		$updater->setContent(
			SlotRecord::MAIN,
			ContentHandler::makeContent( $proposedWikitext, $title )
		);
		$updater->addTag( Hooks::TAG_NAME );

		$comment = CommentStoreComment::newUnsavedComment( $summary );
		$newRev = $updater->saveRevision( $comment, EDIT_UPDATE );
		$status = $updater->getStatus();

		if ( $newRev === null || !$status->isOK() ) {
			if ( $status->hasMessage( 'edit-conflict' ) ) {
				return $this->errorResult( $prefixedTitle, 'aibatcheditor-error-save-conflict' );
			}
			return $this->errorResult( $prefixedTitle, 'aibatcheditor-error-save-failed' );
		}

		return [
			'title' => $prefixedTitle,
			'status' => 'saved',
			'revid' => $baseRevId,
			'newrevid' => $newRev->getId(),
		];
	}

	/**
	 * Validate that a page can receive a refreshed draft token before save.
	 *
	 * @return array{
	 *   title: string,
	 *   status: string,
	 *   revid?: int,
	 *   error?: string
	 * }
	 */
	public function validateForDraftRefresh(
		Authority $performer,
		string $titleText,
		int $baseRevId
	): array {
		$title = Title::newFromText( $titleText );
		if ( !$title ) {
			return $this->errorResult( $titleText, 'aibatcheditor-error-save-invalid-title' );
		}

		$prefixedTitle = $title->getPrefixedText();
		if ( !$title->exists() ) {
			return $this->errorResult( $prefixedTitle, 'aibatcheditor-error-save-not-exists' );
		}

		if ( $title->isRedirect() ) {
			return $this->errorResult( $prefixedTitle, 'aibatcheditor-error-save-redirect' );
		}

		if ( !$this->permissionManager->quickUserCan( 'edit', $performer, $title ) ) {
			return $this->errorResult( $prefixedTitle, 'aibatcheditor-error-save-not-editable' );
		}

		$wikiPage = $this->wikiPageFactory->newFromTitle( $title );
		$revRecord = $wikiPage->getRevisionRecord();
		if ( !$revRecord ) {
			return $this->errorResult( $prefixedTitle, 'aibatcheditor-error-save-no-revision' );
		}

		$currentRevId = $revRecord->getId();
		if ( $currentRevId !== $baseRevId ) {
			return [
				'title' => $prefixedTitle,
				'status' => 'conflict',
				'error' => 'aibatcheditor-error-save-conflict',
			];
		}

		$mainSlot = $revRecord->getSlot( SlotRecord::MAIN );
		if ( $mainSlot->getModel() !== CONTENT_MODEL_WIKITEXT ) {
			return $this->errorResult( $prefixedTitle, 'aibatcheditor-error-save-not-wikitext' );
		}

		return [
			'title' => $prefixedTitle,
			'status' => 'ok',
			'revid' => $currentRevId,
		];
	}

	/**
	 * @return array{title: string, status: string, error: string}
	 */
	private function errorResult( string $title, string $errorCode ): array {
		return [
			'title' => $title,
			'status' => 'error',
			'error' => $errorCode,
		];
	}

}