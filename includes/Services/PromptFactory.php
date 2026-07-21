<?php

namespace MediaWiki\Extension\AIBatchEditor\Services;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MainConfigNames;

/**
 * Builds LLM prompts for each batch operation and aggressiveness profile.
 */
class PromptFactory {

	public const PROMPT_VERSION = 6;

	public const CONSTRUCTOR_OPTIONS = [
		MainConfigNames::LanguageCode,
		'AIBatchEditorOperationProfiles',
		'AIBatchEditorSystemPrompt',
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
		];

		// Wiki-wide context first (after ROLE), before contract/task/scope.
		$prefaceLines = $this->getConfigPromptLines( 'AIBatchEditorSystemPrompt' );
		if ( $prefaceLines !== [] ) {
			$systemLines[] = '';
			$systemLines[] = 'WIKI CONTEXT:';
			foreach ( $prefaceLines as $line ) {
				$systemLines[] = '- ' . $line;
			}
		}

		$systemLines = array_merge( $systemLines, [
			'',
			'WIKITEXT (mandatory):',
			'1. The INPUT is MediaWiki wikitext source (editable page text), not rendered HTML, not Markdown, '
				. 'and not plain narrative prose stripped of markup.',
			'2. Read and write MediaWiki syntax. Treat markup as structure, not as typos or noise to clean away.',
			'3. Preserve unless the TASK and SCOPE explicitly require a change: '
				. '[[internal links]], [external links], {{templates|params}}, <ref>…</ref> and <references/>, '
				. '== headings ==, * # ; : lists, {| tables |}, \'\'italics\'\' \'\'\'bold\'\'\', '
				. 'categories [[Category:…]], files [[File:…]] / [[Archivo:…]], HTML allowed in wikitext '
				. '(e.g. <br />, <div>, <!-- comments -->), nowiki/pre/code blocks, magic words, and parser functions.',
			'4. Never convert wikitext to Markdown (*bold*, # headings, [text](url)) or strip markup to plain text.',
			'5. Never invent or "fix" broken markup by replacing MediaWiki constructs with Markdown or HTML dumps.',
			'6. Output must be valid MediaWiki wikitext that can be saved back into the wiki unchanged in format family.',
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
			'TASK — Operation: ' . $operationInstruction,
			'TASK — Profile (intensity within scope): ' . $profileText,
		] );

		$scopeLines = $this->getOperationScopeLines( $operation );
		if ( $scopeLines !== [] ) {
			$systemLines[] = '';
			$systemLines[] = 'SCOPE for this operation (what may change):';
			foreach ( $scopeLines as $line ) {
				$systemLines[] = '- ' . $line;
			}
		}

		if ( $hasInstructions ) {
			$systemLines[] = '';
			foreach ( $this->getInstructionsHeaderLines( $operation ) as $line ) {
				$systemLines[] = $line;
			}
			$systemLines[] = $instructions;
		}

		if ( $templateContext !== '' ) {
			$systemLines[] = '';
			$systemLines[] = 'Reference template definitions from the source wiki '
				. '(use when inserting, upgrading, or cloning):';
			$systemLines[] = $templateContext;
		}

		$appendLines = $this->getConfigPromptLines( 'AIBatchEditorSystemPromptAppend' );
		if ( $appendLines !== [] ) {
			$systemLines[] = '';
			$systemLines[] = 'WIKI-SPECIFIC RULES (supplementary to the sections above):';
			foreach ( $appendLines as $line ) {
				$systemLines[] = '- ' . $line;
			}
		}

		$system = implode( "\n", $systemLines );

		$user = "Apply the task to the MediaWiki wikitext source below. "
			. "Output the full revised wikitext only.\n\n"
			. "{$wikitext}";

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
			'INPUT and OUTPUT are MediaWiki wikitext source; do not convert to Markdown, HTML-only, or plain text.',
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
				'Do not change grammar, wording, structure, wikilinks, templates, or references.',
				'Editor instructions may add focus but must not override the operation task or scope above.',
			] ),
			'wikilinks' => array_merge( $common, [
				'Add or adjust [[wikilinks]] only; do not change prose, headings, lists, templates, or whitespace.',
				'Link only terms named in editor instructions or unambiguously notable in the sentence.',
			] ),
			'formatting' => array_merge( $common, [
				'Adjust headings, lists, paragraph breaks, and whitespace only.',
				'Do not rephrase prose, add wikilinks, or change template parameters.',
				'Editor instructions may add focus but must not override the operation task or scope above.',
			] ),
			'templates' => [
				'You may add or change template transclusions and template definitions as required by the task.',
				'Preserve unrelated markup, refs, and prose not required by the task.',
				'Editor instructions may add focus but must not override the operation task or scope above.',
			],
			'custom' => array_merge( $common, [
				'Change only what the INSTRUCTIONS block explicitly asks for; leave everything else identical.',
				'Do not correct spelling, grammar, capitalization, spacing, or style unless the instructions '
					. 'explicitly ask for those kinds of edits.',
				'Do not alter person names, place names, city names, or other proper nouns unless the '
					. 'instructions explicitly name that change.',
				'Change structure or markup only when the instructions explicitly require it; '
					. 'otherwise preserve the existing format.',
			] ),
			default => $common,
		};
	}

	/**
	 * Header lines for the INSTRUCTIONS block. Framing differs for custom vs other ops:
	 * custom = instructions are the work; others = instructions target/constrain the TASK.
	 *
	 * @return string[]
	 */
	private function getInstructionsHeaderLines( string $operation ): array {
		if ( $operation === 'custom' ) {
			return [
				'INSTRUCTIONS — What to do (this is the operation):',
				'Carry out only what follows. Stay inside SCOPE.',
				'Do not add other kinds of edits (including orthography, capitalization, or renaming places) '
					. 'that are not requested here.',
			];
		}

		return [
			'INSTRUCTIONS — Targets and constraints for the operation above:',
			'Use these lines only to decide where and how to apply the TASK within SCOPE.',
			'They do not authorize a different operation (for example spellcheck rules during a wikilinks-only run).',
		];
	}

	/**
	 * Normalize config prompt lines from string[] (or a single multi-line string).
	 *
	 * @return string[]
	 */
	private function getConfigPromptLines( string $configKey ): array {
		$raw = $this->options->get( $configKey );
		if ( is_string( $raw ) ) {
			$raw = [ $raw ];
		}
		if ( !is_array( $raw ) ) {
			return [];
		}

		$lines = [];
		foreach ( $raw as $item ) {
			if ( !is_string( $item ) ) {
				continue;
			}
			// Allow one config element with internal newlines → multiple bullets.
			foreach ( preg_split( "/\r\n|\n|\r/", $item ) as $line ) {
				$line = trim( $line );
				if ( $line !== '' ) {
					$lines[] = $line;
				}
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