<?php

namespace MediaWiki\Extension\AIBatchEditor;

use MediaWiki\Config\Config;
use MediaWiki\Html\Html;
use MediaWiki\Permissions\PermissionManager;
use PermissionsError;
use SpecialPage;

/**
 * Special:AIBatchEditor
 *
 * Entry point for the AI batch editing workflow.
 *
 * @ingroup SpecialPage
 */
class SpecialAIBatchEditor extends SpecialPage {

	private PermissionManager $permissionManager;
	private Config $config;

	public function __construct( PermissionManager $permissionManager, Config $config ) {
		parent::__construct( 'AIBatchEditor', 'aibatchedit' );
		$this->permissionManager = $permissionManager;
		$this->config = $config;
	}

	protected function getGroupName() {
		return 'wiki';
	}

	/**
	 * @param string|null $subPage
	 * @throws PermissionsError
	 */
	public function execute( $subPage ) {
		$this->setHeaders();
		$this->outputHeader();

		$user = $this->getUser();

		if ( !$this->permissionManager->userHasRight( $user, 'aibatchedit' ) ) {
			throw new PermissionsError(
				'aibatchedit',
				[ $this->msg( 'aibatcheditor-permission-denied', 'aibatchedit' ) ]
			);
		}

		$out = $this->getOutput();
		$out->setPageTitle( $this->msg( 'aibatcheditor' )->text() );

		$enabledOperations = $this->config->get( 'AIBatchEditorEnabledOperations' );
		if ( !is_array( $enabledOperations ) ) {
			$enabledOperations = [];
		}

		$operationProfiles = $this->config->get( 'AIBatchEditorOperationProfiles' );
		if ( !is_array( $operationProfiles ) ) {
			$operationProfiles = [];
		}

		$out->addJsConfigVars( 'wgAIBatchEditor', [
			'maxBatch' => (int)$this->config->get( 'AIBatchEditorMaxBatch' ),
			'maxPageSize' => max( 0, (int)$this->config->get( 'AIBatchEditorMaxPageSize' ) ),
			'defaultProfile' => $this->config->get( 'AIBatchEditorDefaultProfile' ) ?: 'balanced',
			'enabledOperations' => $enabledOperations,
			'operationProfiles' => $operationProfiles,
			'concurrency' => max( 1, (int)$this->config->get( 'AIBatchEditorConcurrency' ) ),
		] );

		$out->addModuleStyles( [
			'ext.aibatchEditor.styles',
			'mediawiki.diff.styles',
		] );
		$out->addModules( 'ext.aibatchEditor' );

		$out->addHTML(
			Html::element(
				'div',
				[
					'id' => 'ext-aibatcheditor-root',
					'class' => 'ext-aibatcheditor-root',
				]
			)
		);

		$out->addHTML(
			Html::rawElement(
				'noscript',
				[],
				Html::element( 'p', [], $this->msg( 'aibatcheditor-nojs' )->text() )
			)
		);
	}
}