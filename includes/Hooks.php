<?php

namespace MediaWiki\Extension\AIBatchEditor;

use MediaWiki\ChangeTags\Hook\ChangeTagsListActiveHook;
use MediaWiki\ChangeTags\Hook\ListDefinedTagsHook;

/**
 * Hook handlers for AIBatchEditor.
 */
class Hooks implements
	ChangeTagsListActiveHook,
	ListDefinedTagsHook
{

	public const TAG_NAME = 'aibatcheditor';

	/** @inheritDoc */
	public function onListDefinedTags( &$tags ) {
		$tags[] = self::TAG_NAME;
	}

	/** @inheritDoc */
	public function onChangeTagsListActive( &$tags ) {
		$tags[] = self::TAG_NAME;
	}

}