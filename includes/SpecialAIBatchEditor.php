<?php

namespace MediaWiki\Extension\AIBatchEditor;

use MediaWiki\Config\Config;
use MediaWiki\Extension\AIBatchEditor\Services\RateLimiterService;
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
	private RateLimiterService $rateLimiter;

	public function __construct(
		PermissionManager $permissionManager,
		Config $config,
		RateLimiterService $rateLimiter
	) {
		parent::__construct( 'AIBatchEditor', 'aibatchedit' );
		$this->permissionManager = $permissionManager;
		$this->config = $config;
		$this->rateLimiter = $rateLimiter;
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

		$llmConfigured = $this->isLlmConfigured();
		if ( !$llmConfigured ) {
			$out->addHTML(
				Html::rawElement(
					'div',
					[ 'class' => 'mw-message-box warning ext-aibatcheditor-config-warning' ],
					$this->msg( 'aibatcheditor-error-llm-not-configured' )->parse()
				)
			);
		} else {
			$out->addHTML(
				Html::rawElement(
					'div',
					[ 'class' => 'mw-message-box notice ext-aibatcheditor-privacy-notice' ],
					$this->msg( 'aibatcheditor-privacy-notice' )->parse()
				)
			);
		}

		$enabledOperations = $this->config->get( 'AIBatchEditorEnabledOperations' );
		if ( !is_array( $enabledOperations ) ) {
			$enabledOperations = [];
		}

		$operationProfiles = $this->config->get( 'AIBatchEditorOperationProfiles' );
		if ( !is_array( $operationProfiles ) ) {
			$operationProfiles = [];
		}

		$rateLimit = $this->rateLimiter->getStatus( $user->getId() );

		$out->addJsConfigVars( 'wgAIBatchEditor', [
			'maxBatch' => (int)$this->config->get( 'AIBatchEditorMaxBatch' ),
			'maxPageSize' => max( 0, (int)$this->config->get( 'AIBatchEditorMaxPageSize' ) ),
			'defaultProfile' => $this->config->get( 'AIBatchEditorDefaultProfile' ) ?: 'balanced',
			'enabledOperations' => $enabledOperations,
			'operationProfiles' => $operationProfiles,
			'concurrency' => max( 1, (int)$this->config->get( 'AIBatchEditorConcurrency' ) ),
			'requestTimeout' => max( 10, (int)$this->config->get( 'AIBatchEditorRequestTimeout' ) ),
			'pollIntervalMs' => 800,
			'templateSourceWiki' => $this->config->get( 'AIBatchEditorTemplateSourceWiki' ) ?: 'https://es.wikipedia.org',
			'promptPreview' => (bool)$this->config->get( 'AIBatchEditorPromptPreview' ),
			'llmConfigured' => $llmConfigured,
			'rateLimit' => [
				'limit' => $rateLimit['limit'],
				'used' => $rateLimit['used'],
				'remaining' => $rateLimit['remaining'],
			],
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
				],
				$this->msg( 'aibatcheditor-loading' )->text()
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

	private function isLlmConfigured(): bool {
		$apiUrl = trim( $this->config->get( 'AIBatchEditorApiUrl' ) );
		$apiKey = trim( $this->config->get( 'AIBatchEditorApiKey' ) );
		return $apiUrl !== '' && $apiKey !== '';
	}
}