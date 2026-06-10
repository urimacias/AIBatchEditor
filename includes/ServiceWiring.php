<?php

namespace MediaWiki\Extension\AIBatchEditor;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\AIBatchEditor\Services\AIService;
use MediaWiki\Extension\AIBatchEditor\Services\BatchLogService;
use MediaWiki\Extension\AIBatchEditor\Services\DiffService;
use MediaWiki\Extension\AIBatchEditor\Services\EditService;
use MediaWiki\Extension\AIBatchEditor\Services\PageContentService;
use MediaWiki\Extension\AIBatchEditor\Services\PromptFactory;
use MediaWiki\Extension\AIBatchEditor\Services\RateLimiterService;
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
	'AIBatchEditor.EditService' => static function ( MediaWikiServices $services ): EditService {
		return new EditService(
			$services->getWikiPageFactory(),
			$services->getPermissionManager()
		);
	},
];