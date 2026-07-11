<?php

namespace MediaWiki\Extension\AIBatchEditor;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\AIBatchEditor\Services\AIService;
use MediaWiki\Extension\AIBatchEditor\Services\ArticlePreviewService;
use MediaWiki\Extension\AIBatchEditor\Services\BatchLogService;
use MediaWiki\Extension\AIBatchEditor\Services\BatchRunService;
use MediaWiki\Extension\AIBatchEditor\Services\DiffService;
use MediaWiki\Extension\AIBatchEditor\Services\DraftTokenService;
use MediaWiki\Extension\AIBatchEditor\Services\EditService;
use MediaWiki\Extension\AIBatchEditor\Services\PageContentService;
use MediaWiki\Extension\AIBatchEditor\Services\PageProcessorService;
use MediaWiki\Extension\AIBatchEditor\Services\ProposalAnalyzer;
use MediaWiki\Extension\AIBatchEditor\Services\PromptFactory;
use MediaWiki\Extension\AIBatchEditor\Services\RateLimiterService;
use MediaWiki\Extension\AIBatchEditor\Services\StubAIService;
use MediaWiki\Extension\AIBatchEditor\Services\TemplateSourceService;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

return [
	'AIBatchEditor.PageContentService' => static function ( MediaWikiServices $services ): PageContentService {
		return new PageContentService(
			$services->getWikiPageFactory(),
			$services->getPermissionManager(),
			$services->getTitleFactory(),
			$services->getNamespaceInfo(),
			$services->getConnectionProvider()
		);
	},
	'AIBatchEditor.PromptFactory' => static function ( MediaWikiServices $services ): PromptFactory {
		return new PromptFactory(
			new ServiceOptions(
				PromptFactory::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			)
		);
	},
	'AIBatchEditor.AIService' => static function ( MediaWikiServices $services ): AIService {
		if ( $services->getMainConfig()->get( 'AIBatchEditorStubMode' ) ) {
			return new StubAIService();
		}
		return new AIService(
			new ServiceOptions(
				AIService::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			),
			$services->getHttpRequestFactory()
		);
	},
	'AIBatchEditor.RateLimiterService' => static function ( MediaWikiServices $services ): RateLimiterService {
		return new RateLimiterService(
			new ServiceOptions(
				RateLimiterService::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			),
			$services->getObjectCacheFactory()->getLocalClusterInstance()
		);
	},
	'AIBatchEditor.BatchLogService' => static function (): BatchLogService {
		return new BatchLogService(
			LoggerFactory::getInstance( 'aibatcheditor' )
		);
	},
	'AIBatchEditor.DiffService' => static function (): DiffService {
		return new DiffService();
	},
	'AIBatchEditor.ArticlePreviewService' => static function ( MediaWikiServices $services ): ArticlePreviewService {
		return new ArticlePreviewService(
			$services->getObjectCacheFactory()->getLocalClusterInstance(),
			$services->getGlobalIdGenerator(),
			$services->getParserFactory(),
			$services->get( 'AIBatchEditor.PageContentService' )
		);
	},
	'AIBatchEditor.EditService' => static function ( MediaWikiServices $services ): EditService {
		return new EditService(
			$services->getWikiPageFactory(),
			$services->getPermissionManager()
		);
	},
	'AIBatchEditor.TemplateSourceService' => static function ( MediaWikiServices $services ): TemplateSourceService {
		return new TemplateSourceService(
			new ServiceOptions(
				TemplateSourceService::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			),
			$services->getHttpRequestFactory()
		);
	},
	'AIBatchEditor.DraftTokenService' => static function ( MediaWikiServices $services ): DraftTokenService {
		return new DraftTokenService(
			new ServiceOptions(
				DraftTokenService::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			)
		);
	},
	'AIBatchEditor.ProposalAnalyzer' => static function (): ProposalAnalyzer {
		return new ProposalAnalyzer();
	},
	'AIBatchEditor.PageProcessorService' => static function ( MediaWikiServices $services ): PageProcessorService {
		return new PageProcessorService(
			$services->get( 'AIBatchEditor.PageContentService' ),
			$services->get( 'AIBatchEditor.AIService' ),
			$services->get( 'AIBatchEditor.PromptFactory' ),
			$services->get( 'AIBatchEditor.RateLimiterService' ),
			$services->get( 'AIBatchEditor.BatchLogService' ),
			$services->get( 'AIBatchEditor.DraftTokenService' ),
			$services->get( 'AIBatchEditor.ProposalAnalyzer' ),
			$services->getMainConfig()
		);
	},
	'AIBatchEditor.BatchRunService' => static function ( MediaWikiServices $services ): BatchRunService {
		// Persist batch progress in the DB objectcache table so long runs survive
		// APCu eviction, PHP-FPM recycle, and multi-hour sequential LLM jobs.
		return new BatchRunService(
			new ServiceOptions(
				BatchRunService::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			),
			$services->getObjectCacheFactory()->getInstance( CACHE_DB ),
			$services->getGlobalIdGenerator(),
			$services->get( 'AIBatchEditor.PageProcessorService' ),
			$services->getMainConfig()
		);
	},
];