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
	 * @param string $operation
	 * @param string $profile
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

		$operationInstruction = match ( $operation ) {
			'wikilinks' => 'Add appropriate MediaWiki wikilinks ([[Page]] or [[Page|label]]) where helpful.',
			'spellcheck' => 'Fix spelling and obvious typographical errors only.',
			'formatting' => 'Improve wikitext structure: headings, lists, paragraph breaks, and whitespace.',
			'style' => 'Improve clarity, tone, and readability while preserving factual meaning.',
			'templates' => 'Insert, upgrade, replace, or clone MediaWiki template transclusions ({{...}}). '
				. 'Match parameter names and structure from reference template definitions when provided. '
				. 'On Template-namespace pages, adapt and import full template source from the reference wiki.',
			'custom' => 'Apply only the editor-provided instructions. Make no other changes unless explicitly requested.',
			default => 'Improve the wikitext according to the requested operation.',
		};

		$preserveTemplates = $operation !== 'templates';
		$systemLines = [
			'You are an expert MediaWiki wikitext editor.',
			"Write in the wiki content language (language code: {$languageCode}).",
			'Return ONLY the revised wikitext. Do not wrap it in markdown fences or add commentary.',
			$preserveTemplates
				? 'Preserve templates, parser functions, HTML tags, and references unless the operation requires changing them.'
				: 'You may add or change template transclusions and template definitions as required by the operation.',
			"Operation: {$operationInstruction}",
			"Editing profile: {$profileText}",
		];

		$templateContext = trim( $templateContext );
		if ( $templateContext !== '' ) {
			$systemLines[] = 'Use these reference template definitions from another wiki when inserting, upgrading, or cloning templates:';
			$systemLines[] = $templateContext;
		}

		$instructions = trim( $instructions );
		if ( $instructions !== '' ) {
			$systemLines[] = 'The editor provided mandatory instructions. Follow them exactly, even if they go beyond the default operation:';
			$systemLines[] = $instructions;
		}

		$system = implode( "\n", $systemLines );

		$user = $instructions !== ''
			? "Apply the editor instructions above, then revise the following wikitext:\n\n{$wikitext}"
			: "Revise the following wikitext:\n\n{$wikitext}";

		return [
			'system' => $system,
			'user' => $user,
		];
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
			'conservative' => 'Make only the smallest necessary changes.',
			'aggressive' => 'Apply thorough improvements while keeping facts accurate.',
			default => 'Apply moderate improvements while preserving author intent.',
		};
	}

}