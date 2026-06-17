<?php

namespace MediaWiki\Extension\AIBatchEditor\Services;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MainConfigNames;

/**
 * Builds LLM prompts for each batch operation and aggressiveness profile.
 */
class PromptFactory {

	public const CONSTRUCTOR_OPTIONS = [
		MainConfigNames::LanguageCode,
		'AIBatchEditorOperationProfiles',
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
			'You are an expert MediaWiki wikitext editor.',
			"Wiki content language code: {$languageCode}.",
			'',
			'STRICT COMPLIANCE RULES:',
			'- Editor instructions override the default operation, profile, and your own judgment.',
			'- When instructions say what to do, do exactly that — completely and literally.',
			'- Do not refuse, water down, skip, or partially apply requested edits.',
			'- Return ONLY the full revised wikitext for the page. No markdown fences, no explanations.',
			'- Keep facts accurate; do not invent citations, dates, or article content.',
		];

		if ( $hasInstructions ) {
			$systemLines[] = '';
			$systemLines[] = '=== MANDATORY EDITOR INSTRUCTIONS (HIGHEST PRIORITY) ===';
			$systemLines[] = $instructions;
			$systemLines[] = '=== END MANDATORY EDITOR INSTRUCTIONS ===';
		}

		$preserveTemplates = $operation !== 'templates';
		$systemLines[] = '';
		$systemLines[] = 'Operation: ' . $operationInstruction;
		$systemLines[] = 'Editing profile: ' . $profileText;

		$preservationLines = $this->getWikitextPreservationRules( $operation );
		if ( $preservationLines !== [] ) {
			$systemLines[] = '';
			$systemLines[] = 'WIKITEXT FORMAT PRESERVATION (MANDATORY):';
			foreach ( $preservationLines as $line ) {
				$systemLines[] = '- ' . $line;
			}
		}

		$systemLines[] = $preserveTemplates
			? 'Preserve existing templates, parser functions, HTML, and references unless instructions or the operation require changes.'
			: 'You may add or change template transclusions and template definitions as required.';

		if ( $templateContext !== '' ) {
			$systemLines[] = '';
			$systemLines[] = 'Reference template definitions from the source wiki (use when inserting, upgrading, or cloning):';
			$systemLines[] = $templateContext;
		}

		if ( $hasInstructions ) {
			$systemLines[] = '';
			$systemLines[] = 'Reminder: the MANDATORY EDITOR INSTRUCTIONS above take precedence over everything else in this message.';
		}

		$system = implode( "\n", $systemLines );

		if ( $hasInstructions ) {
			$user = "Apply the MANDATORY EDITOR INSTRUCTIONS from the system message exactly.\n"
				. "Output the complete revised wikitext for this page.\n\n"
				. "Wikitext to revise:\n\n{$wikitext}";
		} else {
			$user = "Revise the following wikitext according to the system instructions:\n\n{$wikitext}";
		}

		return [
			'system' => $system,
			'user' => $user,
		];
	}

	private function getOperationInstruction( string $operation ): string {
		return match ( $operation ) {
			'wikilinks' => 'Add appropriate MediaWiki wikilinks ([[Page]] or [[Page|label]]) where helpful.',
			'spellcheck' => 'Fix spelling and obvious typographical errors only.',
			'formatting' => 'Improve wikitext structure: headings, lists, paragraph breaks, and whitespace.',
			'style' => 'Improve clarity, tone, and readability of prose ONLY. '
				. 'Change wording inside sentences; never change wikitext structure or markup.',
			'templates' => 'Insert, upgrade, replace, or clone MediaWiki template transclusions ({{...}}). '
				. 'Match parameter names and structure from reference template definitions when provided. '
				. 'On Template-namespace pages, adapt and import full template source from the reference wiki.',
			'custom' => 'Apply ONLY the editor-provided instructions. Make no other changes unless explicitly requested.',
			default => 'Improve the wikitext according to the requested operation.',
		};
	}

	/**
	 * Operation-specific rules to keep wikitext structure intact.
	 *
	 * @return string[]
	 */
	private function getWikitextPreservationRules( string $operation ): array {
		$common = [
			'Do not convert wikitext to Markdown or plain text.',
			'Do not wrap output in code fences or add commentary.',
			'Preserve every {{template}}, <ref>, <references />, HTML tag, parser function, and magic word exactly as written unless the operation explicitly requires a change.',
			'Preserve [[Category:...]], [[File:...]], and other namespace links unless the operation explicitly requires a change.',
		];

		return match ( $operation ) {
			'style' => array_merge( $common, [
				'NEVER change wikitext structure: keep the same headings (==, ===, …), lists (* # : ;), tables, paragraph breaks, and blank lines.',
				'NEVER add, remove, reorder, or reformat structural markup. Style edits are prose-only.',
				'NEVER add or remove wikilinks, templates, categories, images, or references.',
				'Only rephrase visible prose inside existing sentences. If a line is pure markup, copy it unchanged.',
				'When unsure whether a change would alter formatting, leave that line exactly as in the input.',
			] ),
			'spellcheck' => array_merge( $common, [
				'Fix spelling and obvious typos in prose only; do not restructure headings, lists, tables, or whitespace.',
				'Do not add or remove wikilinks, templates, or references.',
			] ),
			'wikilinks' => array_merge( $common, [
				'Add or adjust [[wikilinks]] only; do not change headings, lists, paragraph structure, or whitespace.',
			] ),
			'formatting' => array_merge( $common, [
				'You may adjust headings, lists, and paragraph breaks, but keep template parameters, refs, and categories intact unless instructions require otherwise.',
			] ),
			'custom' => array_merge( $common, [
				'Change structure or markup only when editor instructions explicitly require it; otherwise preserve the existing format.',
			] ),
			default => $common,
		};
	}

	private function getProfileInstruction( string $operation, string $profile ): string {
		$profiles = $this->options->get( 'AIBatchEditorOperationProfiles' );
		if (
			isset( $profiles[$operation][$profile] ) &&
			is_string( $profiles[$operation][$profile] )
		) {
			return $profiles[$operation][$profile];
		}

		return match ( $profile ) {
			'conservative' => 'Make only the smallest changes needed to satisfy the editor instructions and operation.',
			'aggressive' => 'Apply the requested edits thoroughly while keeping facts accurate.',
			default => 'Apply the requested edits completely while preserving author intent.',
		};
	}

}