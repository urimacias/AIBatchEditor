<?php

namespace MediaWiki\Extension\AIBatchEditor\Tests;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\AIBatchEditor\Services\PromptFactory;
use MediaWiki\MainConfigNames;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AIBatchEditor\Services\PromptFactory
 */
class PromptFactoryTest extends MediaWikiUnitTestCase {

	public function testCustomOperationIncludesInstructions(): void {
		$factory = new PromptFactory( new ServiceOptions(
			PromptFactory::CONSTRUCTOR_OPTIONS,
			[
				MainConfigNames::LanguageCode => 'es',
				'AIBatchEditorOperationProfiles' => [],
			]
		) );

		$prompts = $factory->buildPrompts(
			'custom',
			'balanced',
			'== Test ==',
			'Add this date as married couple'
		);

		$this->assertStringContainsString( 'MANDATORY EDITOR INSTRUCTIONS', $prompts['system'] );
		$this->assertStringContainsString( 'Add this date as married couple', $prompts['system'] );
		$this->assertStringContainsString( '== Test ==', $prompts['user'] );
	}

	public function testDefaultProfileFallback(): void {
		$factory = new PromptFactory( new ServiceOptions(
			PromptFactory::CONSTRUCTOR_OPTIONS,
			[
				MainConfigNames::LanguageCode => 'en',
				'AIBatchEditorOperationProfiles' => [],
			]
		) );

		$prompts = $factory->buildPrompts( 'style', 'aggressive', 'Some text' );
		$this->assertStringContainsString( 'thoroughly while keeping facts accurate', $prompts['system'] );
	}

	public function testTemplatesOperationIncludesReferenceContext(): void {
		$factory = new PromptFactory( new ServiceOptions(
			PromptFactory::CONSTRUCTOR_OPTIONS,
			[
				MainConfigNames::LanguageCode => 'es',
				'AIBatchEditorOperationProfiles' => [],
			]
		) );

		$context = "Reference templates fetched from https://es.wikipedia.org:\n\n=== Plantilla:Ficha ===\n{{Infobox}}";
		$prompts = $factory->buildPrompts( 'templates', 'balanced', 'Article body', '', $context );

		$this->assertStringContainsString( 'template transclusions', $prompts['system'] );
		$this->assertStringContainsString( 'Plantilla:Ficha', $prompts['system'] );
		$this->assertStringContainsString( 'Article body', $prompts['user'] );
	}

	public function testStyleOperationPreservesWikitextFormat(): void {
		$factory = new PromptFactory( new ServiceOptions(
			PromptFactory::CONSTRUCTOR_OPTIONS,
			[
				MainConfigNames::LanguageCode => 'es',
				'AIBatchEditorOperationProfiles' => [],
			]
		) );

		$prompts = $factory->buildPrompts( 'style', 'balanced', '== Título ==\n\nTexto.' );

		$this->assertStringContainsString( 'prose ONLY', $prompts['system'] );
		$this->assertStringContainsString( 'WIKITEXT FORMAT PRESERVATION', $prompts['system'] );
		$this->assertStringContainsString( 'NEVER change wikitext structure', $prompts['system'] );
		$this->assertStringContainsString( '== Título ==', $prompts['user'] );
	}

	public function testSpellcheckOperation(): void {
		$factory = new PromptFactory( new ServiceOptions(
			PromptFactory::CONSTRUCTOR_OPTIONS,
			[
				MainConfigNames::LanguageCode => 'en',
				'AIBatchEditorOperationProfiles' => [
					'spellcheck' => [
						'conservative' => 'Fix typos only.',
					],
				],
			]
		) );

		$prompts = $factory->buildPrompts( 'spellcheck', 'conservative', 'Hello wrld' );
		$this->assertStringContainsString( 'Fix typos only.', $prompts['system'] );
		$this->assertStringContainsString( 'Hello wrld', $prompts['user'] );
	}

}