<?php

namespace MediaWiki\Extension\AIBatchEditor\Services;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MainConfigNames;

/**
 * Builds LLM prompts for each batch operation and aggressiveness profile.
 */
class PromptFactory {

	public const PROMPT_VERSION = 3;

	public const CONSTRUCTOR_OPTIONS = [
		MainConfigNames::LanguageCode,
		'AIBatchEditorOperationProfiles',
		'AIBatchEditorSystemPromptAppend',
	];

	public const OPERATIONS = [ 'wikilinks', 'spellcheck', 'formatting', 'style', 'templates', 'custom' ];
	public const PROFILES = [ 'conservative', 'balanced', 'aggressive' ];

	private ServiceOptions $options;

	public function __construct( ServiceOptions $options ) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->options = $options;
	}

	/**
	 * @return array{system: string, user: string}
	 */
	public function buildPrompts(
		string $operation,
		string $profile,
		string $wikitext,
		string $instructions = '',
		string $templateContext = ''
	): array {
		$languageCode = $this->options->get( MainConfigNames::LanguageCode );
		$profileText = $this->getProfileInstruction( $operation, $profile );
		$operationInstruction = $this->getOperationInstruction( $operation );
		$instructions = trim( $instructions );
		$templateContext = trim( $templateContext );
		$hasInstructions = $instructions !== '';

		$systemLines = [
			'ROLE: MediaWiki wikitext editor (' . $languageCode . ').',
			'Prompt version: ' . self::PROMPT_VERSION . '.',
			'',
			'OUTPUT CONTRACT:',
			'1. Return the complete revised wikitext only. No code fences, markdown wrappers, or commentary.',
			'2. Minimal edit: change the smallest amount needed to complete the task.',
			'3. Fidelity: do not alter facts, numbers, dates, names, quotes, template parameters, references, '
				. 'or markup outside the task scope.',
			'4. Do not invent citations, dates, names, or article content. '
				. 'Do not add content unless editor instructions explicitly ask for it.',
			'5. If the page already satisfies the task, return the input wikitext unchanged.',
			'',
			'PRIORITY (highest first): editor instructions, then operation + profile, '
				. 'then wiki-specific rules, then defaults in this message.',
		];

		if ( $hasInstructions ) {
			$systemLines[] = '';
			$systemLines[] = 'TASK — Editor instructions:';
			$systemLines[] = $instructions;
		}

		$systemLines[] = '';
		$systemLines[] = 'TASK — Operation: ' . $operationInstruction;
		$systemLines[] = 'TASK — Profile (intensity within scope): ' . $profileText;

		$scopeLines = $this->getOperationScopeLines( $operation );
		if ( $scopeLines !== [] ) {
			$systemLines[] = '';
			$systemLines[] = 'SCOPE for this operation (what may change):';
			foreach ( $scopeLines as $line ) {
				$systemLines[] = '- ' . $line;
			}
		}

		$appendLines = $this->getSystemPromptAppendLines();
		if ( $appendLines !== [] ) {
			$systemLines[] = '';
			$systemLines[] = 'WIKI-SPECIFIC RULES (supplementary; editor instructions still take precedence):';
			foreach ( $appendLines as $line ) {
				$systemLines[] = '- ' . $line;
			}
		}

		if ( $templateContext !== '' ) {
			$systemLines[] = '';
			$systemLines[] = 'Reference template definitions from the source wiki '
				. '(use when inserting, upgrading, or cloning):';
			$systemLines[] = $templateContext;
		}

		$system = implode( "\n", $systemLines );

		$user = "Apply the task. Output the full revised wikitext.\n\n=== INPUT ===\n\n{$wikitext}";

		return [
			'system' => $system,
			'user' => $user,
		];
	}

	private function getOperationInstruction( string $operation ): string {
		return match ( $operation ) {
			'wikilinks' => 'Add internal wikilinks for targets named in editor instructions '
				. 'or clearly notable terms in context.',
			'spellcheck' => 'Fix misspellings and obvious typos in prose.',
			'formatting' => 'Improve wikitext structure (headings, lists, paragraphs, whitespace).',
			'style' => 'Improve clarity and readability of prose.',
			'templates' => 'Insert, upgrade, or clone template transclusions per editor instructions and references.',
			'custom' => 'Apply only what editor instructions request.',
			default => 'Improve the wikitext according to the requested operation.',
		};
	}

	/**
	 * Operation-specific scope limits (what may change).
	 *
	 * @return string[]
	 */
	private function getOperationScopeLines( string $operation ): array {
		$common = [
			'Do not convert wikitext to Markdown or plain text.',
		];

		return match ( $operation ) {
			'style' => array_merge( $common, [
				'Rephrase visible prose inside existing sentences only; do not change any markup line, '
					. 'link, template, heading, list, or table.',
				'Do not add "significance", "legacy", or promotional framing.',
				'Prefer shorter, neutral encyclopedic wording over flourish.',
			] ),
			'spellcheck' => array_merge( $common, [
				'Fix misspellings and obvious typos in visible prose only.',
				'Do not change grammar, wording, structure, wikilinks, templates, or references '
					. 'unless editor instructions require it.',
			] ),
			'wikilinks' => array_merge( $common, [
				'Add or adjust [[wikilinks]] only; do not change prose, headings, lists, templates, or whitespace.',
				'Link only terms named in editor instructions or unambiguously notable in the sentence.',
			] ),
			'formatting' => array_merge( $common, [
				'Adjust headings, lists, paragraph breaks, and whitespace only.',
				'Do not rephrase prose, add wikilinks, or change template parameters unless editor instructions require it.',
			] ),
			'templates' => [
				'You may add or change template transclusions and template definitions as required by the task.',
				'Preserve unrelated markup, refs, and prose unless editor instructions require changes.',
			],
			'custom' => array_merge( $common, [
				'Change structure or markup only when editor instructions explicitly require it; '
					. 'otherwise preserve the existing format.',
			] ),
			default => $common,
		};
	}

	/**
	 * @return string[]
	 */
	private function getSystemPromptAppendLines(): array {
		$append = $this->options->get( 'AIBatchEditorSystemPromptAppend' );
		if ( !is_array( $append ) ) {
			return [];
		}

		$lines = [];
		foreach ( $append as $line ) {
			if ( !is_string( $line ) ) {
				continue;
			}
			$line = trim( $line );
			if ( $line !== '' ) {
				$lines[] = $line;
			}
		}

		return $lines;
	}

	private function getProfileInstruction( string $operation, string $profile ): string {
		$profiles = $this->options->get( 'AIBatchEditorOperationProfiles' );
		if (
			isset( $profiles[$operation][$profile] ) &&
			is_string( $profiles[$operation][$profile] )
		) {
			return $profiles[$operation][$profile];
		}

		return $this->getDefaultProfileIntensity( $profile );
	}

	private function getDefaultProfileIntensity( string $profile ): string {
		return match ( $profile ) {
			'conservative' => 'Apply the fewest changes needed within scope.',
			'aggressive' => 'Apply changes thoroughly throughout the page within scope.',
			default => 'Apply changes for all clear cases within scope.',
		};
	}

}