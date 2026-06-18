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
		$this->assertSame( 3, PromptFactory::PROMPT_VERSION );
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

	public function testDefaultProfileIntensityFallback(): void {
		$factory = new PromptFactory( new ServiceOptions(
			PromptFactory::CONSTRUCTOR_OPTIONS,
			[
				MainConfigNames::LanguageCode => 'en',
				'AIBatchEditorOperationProfiles' => [],
				'AIBatchEditorSystemPromptAppend' => [],
			]
		) );

		$prompts = $factory->buildPrompts( 'style', 'aggressive', 'Some text' );
		$this->assertStringContainsString(
			'TASK — Profile (intensity within scope): Apply changes thoroughly throughout the page within scope.',
			$prompts['system']
		);
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

		$this->assertStringContainsString( 'Improve clarity and readability of prose.', $prompts['system'] );
		$this->assertStringContainsString( 'SCOPE for this operation (what may change):', $prompts['system'] );
		$this->assertStringContainsString( '== Título ==', $prompts['user'] );
	}

	public function testSpellcheckScopeExcludesGrammar(): void {
		$factory = $this->makeFactory();
		$prompts = $factory->buildPrompts( 'spellcheck', 'balanced', 'Hello wrld' );

		$this->assertStringContainsString( 'Fix misspellings and obvious typos in prose.', $prompts['system'] );
		$this->assertStringContainsString( 'Do not change grammar, wording, structure', $prompts['system'] );
		$this->assertStringNotContainsString( 'grammar issues', $prompts['system'] );
	}

	public function testSpellcheckUsesConfiguredProfileIntensity(): void {
		$factory = new PromptFactory( new ServiceOptions(
			PromptFactory::CONSTRUCTOR_OPTIONS,
			[
				MainConfigNames::LanguageCode => 'en',
				'AIBatchEditorOperationProfiles' => [
					'spellcheck' => [
						'conservative' => 'Only clear typos named in instructions.',
					],
				],
				'AIBatchEditorSystemPromptAppend' => [],
			]
		) );

		$prompts = $factory->buildPrompts( 'spellcheck', 'conservative', 'Hello wrld' );
		$this->assertStringContainsString( 'Only clear typos named in instructions.', $prompts['system'] );
	}

	public function testWikilinksOperationMentionsNotableTerms(): void {
		$factory = $this->makeFactory();
		$prompts = $factory->buildPrompts( 'wikilinks', 'balanced', 'Article text' );

		$this->assertStringContainsString( 'clearly notable terms in context', $prompts['system'] );
		$this->assertStringContainsString( 'unambiguously notable in the sentence', $prompts['system'] );
		$this->assertStringNotContainsString( 'plausible link', $prompts['system'] );
	}

	public function testSpellcheckDefaultProfiles(): void {
		$profiles = [
			'spellcheck' => [
				'conservative' => 'Only clear typos and errors named in editor instructions.',
				'balanced' => 'All obvious typos in prose.',
				'aggressive' => 'Every misspelling found in prose within scope.',
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