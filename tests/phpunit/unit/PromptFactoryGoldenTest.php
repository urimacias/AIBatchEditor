<?php

namespace MediaWiki\Extension\AIBatchEditor\Tests;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\AIBatchEditor\Services\PromptFactory;
use MediaWiki\MainConfigNames;
use MediaWikiUnitTestCase;

/**
 * Golden structure checks for the surgical system prompt (version 2).
 *
 * @covers \MediaWiki\Extension\AIBatchEditor\Services\PromptFactory
 */
class PromptFactoryGoldenTest extends MediaWikiUnitTestCase {

	private function makeFactory( array $extra = [] ): PromptFactory {
		return new PromptFactory( new ServiceOptions(
			PromptFactory::CONSTRUCTOR_OPTIONS,
			array_merge( [
				MainConfigNames::LanguageCode => 'en',
				'AIBatchEditorOperationProfiles' => [],
				'AIBatchEditorSystemPromptAppend' => [],
			], $extra )
		) );
	}

	private function assertPromptSkeleton( array $prompts, string $operation ): void {
		$system = $prompts['system'];
		$user = $prompts['user'];

		$this->assertStringContainsString( 'ROLE: MediaWiki wikitext editor (en).', $system );
		$this->assertStringContainsString( 'Prompt version: ' . PromptFactory::PROMPT_VERSION . '.', $system );
		$this->assertStringContainsString( 'OUTPUT CONTRACT:', $system );
		$this->assertStringContainsString( 'Minimal edit:', $system );
		$this->assertStringContainsString( 'return the input wikitext unchanged', $system );
		$this->assertStringContainsString( 'PRIORITY (highest first):', $system );
		$this->assertStringContainsString( 'TASK — Operation:', $system );
		$this->assertStringContainsString( 'TASK — Profile:', $system );
		$this->assertStringContainsString( 'SCOPE for this operation:', $system );
		$this->assertStringNotContainsString( 'MANDATORY EDITOR INSTRUCTIONS', $system );
		$this->assertStringNotContainsString( 'STRICT COMPLIANCE RULES', $system );
		$this->assertStringNotContainsString( 'WIKITEXT FORMAT PRESERVATION', $system );

		$this->assertLessThan(
			strpos( $system, 'TASK — Operation:' ),
			strpos( $system, 'OUTPUT CONTRACT:' ),
			"OUTPUT CONTRACT must precede TASK for {$operation}"
		);

		$this->assertSame(
			"Apply the task. Output the full revised wikitext.\n\n=== INPUT ===\n\nSAMPLE",
			$user
		);
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function operationScopeProvider(): array {
		return [
			'wikilinks' => [ 'wikilinks', '[[wikilinks]] only' ],
			'spellcheck' => [ 'spellcheck', 'typos in prose only' ],
			'formatting' => [ 'formatting', 'headings, lists, and paragraph breaks only' ],
			'style' => [ 'style', 'significance' ],
			'templates' => [ 'templates', 'template transclusions' ],
			'custom' => [ 'custom', 'editor instructions explicitly require' ],
		];
	}

	/**
	 * @dataProvider operationScopeProvider
	 */
	public function testGoldenPromptStructurePerOperation( string $operation, string $scopeNeedle ): void {
		$factory = $this->makeFactory();
		$prompts = $factory->buildPrompts( $operation, 'balanced', 'SAMPLE' );
		$this->assertPromptSkeleton( $prompts, $operation );
		$this->assertStringContainsString( $scopeNeedle, $prompts['system'] );
	}

	public function testStyleScopeIncludesAntiPufferyLines(): void {
		$factory = $this->makeFactory();
		$prompts = $factory->buildPrompts( 'style', 'balanced', 'SAMPLE' );

		$this->assertStringContainsString( 'Do not add "significance", "legacy", or promotional framing.', $prompts['system'] );
		$this->assertStringContainsString( 'Prefer shorter, neutral encyclopedic wording over flourish.', $prompts['system'] );
	}

	public function testGoldenPromptWithEditorInstructions(): void {
		$factory = $this->makeFactory();
		$prompts = $factory->buildPrompts( 'custom', 'balanced', 'SAMPLE', 'Add one wikilink' );

		$this->assertStringContainsString( 'TASK — Editor instructions:', $prompts['system'] );
		$this->assertStringContainsString( 'Add one wikilink', $prompts['system'] );
		$this->assertLessThan(
			strpos( $prompts['system'], 'TASK — Editor instructions:' ),
			strpos( $prompts['system'], 'TASK — Operation:' )
		);
	}

	public function testGoldenPromptWithWikiAppendAndTemplates(): void {
		$factory = $this->makeFactory( [
			'AIBatchEditorSystemPromptAppend' => [ 'Never invent dates.' ],
		] );
		$context = "=== Plantilla:Ficha ===\n{{Infobox}}";
		$prompts = $factory->buildPrompts( 'templates', 'balanced', 'SAMPLE', '', $context );

		$this->assertStringContainsString( 'WIKI-SPECIFIC RULES', $prompts['system'] );
		$this->assertStringContainsString( '- Never invent dates.', $prompts['system'] );
		$this->assertStringContainsString( 'Plantilla:Ficha', $prompts['system'] );
	}

}