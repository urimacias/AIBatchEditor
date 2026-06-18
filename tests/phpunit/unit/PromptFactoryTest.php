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

	private function makeFactory( array $extra = [] ): PromptFactory {
		return new PromptFactory( new ServiceOptions(
			PromptFactory::CONSTRUCTOR_OPTIONS,
			array_merge( [
				MainConfigNames::LanguageCode => 'es',
				'AIBatchEditorOperationProfiles' => [],
				'AIBatchEditorSystemPromptAppend' => [],
			], $extra )
		) );
	}

	public function testPromptVersionConstant(): void {
		$this->assertSame( 2, PromptFactory::PROMPT_VERSION );
	}

	public function testCustomOperationIncludesInstructions(): void {
		$factory = $this->makeFactory();
		$prompts = $factory->buildPrompts(
			'custom',
			'balanced',
			'== Test ==',
			'Add this date as married couple'
		);

		$this->assertStringContainsString( 'TASK — Editor instructions:', $prompts['system'] );
		$this->assertStringContainsString( 'Add this date as married couple', $prompts['system'] );
		$this->assertStringContainsString( '=== INPUT ===', $prompts['user'] );
		$this->assertStringContainsString( '== Test ==', $prompts['user'] );
	}

	public function testDefaultProfileFallback(): void {
		$factory = new PromptFactory( new ServiceOptions(
			PromptFactory::CONSTRUCTOR_OPTIONS,
			[
				MainConfigNames::LanguageCode => 'en',
				'AIBatchEditorOperationProfiles' => [],
				'AIBatchEditorSystemPromptAppend' => [],
			]
		) );

		$prompts = $factory->buildPrompts( 'style', 'aggressive', 'Some text' );
		$this->assertStringContainsString( 'thoroughly while keeping facts accurate', $prompts['system'] );
	}

	public function testTemplatesOperationIncludesReferenceContext(): void {
		$factory = $this->makeFactory();
		$context = "Reference templates fetched from https://es.wikipedia.org:\n\n=== Plantilla:Ficha ===\n{{Infobox}}";
		$prompts = $factory->buildPrompts( 'templates', 'balanced', 'Article body', '', $context );

		$this->assertStringContainsString( 'template transclusions', $prompts['system'] );
		$this->assertStringContainsString( 'Plantilla:Ficha', $prompts['system'] );
		$this->assertStringContainsString( 'Article body', $prompts['user'] );
	}

	public function testStyleOperationScopeIsProseOnly(): void {
		$factory = $this->makeFactory();
		$prompts = $factory->buildPrompts( 'style', 'balanced', '== Título ==\n\nTexto.' );

		$this->assertStringContainsString( 'prose ONLY', $prompts['system'] );
		$this->assertStringContainsString( 'SCOPE for this operation:', $prompts['system'] );
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
				'AIBatchEditorSystemPromptAppend' => [],
			]
		) );

		$prompts = $factory->buildPrompts( 'spellcheck', 'conservative', 'Hello wrld' );
		$this->assertStringContainsString( 'Fix typos only.', $prompts['system'] );
		$this->assertStringContainsString( 'typographical errors only', $prompts['system'] );
		$this->assertStringContainsString( 'typos in prose only', $prompts['system'] );
		$this->assertStringContainsString( 'Hello wrld', $prompts['user'] );
	}

	public function testSpellcheckDefaultProfiles(): void {
		$profiles = [
			'spellcheck' => [
				'conservative' => 'Fix only errors explicitly mentioned in editor instructions or clear typos.',
				'balanced' => 'Fix spelling and grammar requested in editor instructions and obvious mistakes.',
				'aggressive' => 'Fix all spelling and grammar issues while honoring editor instructions first.',
			],
		];
		$factory = $this->makeFactory( [
			'AIBatchEditorOperationProfiles' => $profiles,
		] );

		foreach ( PromptFactory::PROFILES as $profile ) {
			$prompts = $factory->buildPrompts( 'spellcheck', $profile, 'Texto con eror.' );
			$this->assertStringContainsString(
				$profiles['spellcheck'][$profile],
				$prompts['system'],
				"Profile {$profile} instruction missing from system prompt"
			);
			$this->assertStringContainsString( 'MediaWiki wikitext editor (es).', $prompts['system'] );
			$this->assertStringContainsString( 'Texto con eror.', $prompts['user'] );
		}
	}

	public function testSystemPromptAppendAddsWikiSpecificRules(): void {
		$factory = new PromptFactory( new ServiceOptions(
			PromptFactory::CONSTRUCTOR_OPTIONS,
			[
				MainConfigNames::LanguageCode => 'en',
				'AIBatchEditorOperationProfiles' => [],
				'AIBatchEditorSystemPromptAppend' => [
					'Never invent genealogical dates.',
					'Prefer [[Template:Person]] for biographies.',
					'',
					42,
				],
			]
		) );

		$prompts = $factory->buildPrompts( 'wikilinks', 'balanced', 'Article text' );

		$this->assertStringContainsString( 'WIKI-SPECIFIC RULES', $prompts['system'] );
		$this->assertStringContainsString( '- Never invent genealogical dates.', $prompts['system'] );
		$this->assertStringContainsString( '- Prefer [[Template:Person]] for biographies.', $prompts['system'] );
		$this->assertStringContainsString( 'OUTPUT CONTRACT:', $prompts['system'] );
	}

	public function testSystemPromptAppendOmittedWhenEmpty(): void {
		$factory = $this->makeFactory( [
			MainConfigNames::LanguageCode => 'en',
		] );

		$prompts = $factory->buildPrompts( 'formatting', 'balanced', 'Body' );
		$this->assertStringNotContainsString( 'WIKI-SPECIFIC RULES', $prompts['system'] );
	}

}