<?php

namespace MediaWiki\Extension\AIBatchEditor\Services;

/**
 * Deterministic AI stub for automated browser tests (no HTTP).
 */
class StubAIService extends AIService {

	public function __construct() {
	}

	public function complete( array $prompts ): string {
		$wikitext = self::extractWikitextFromUserPrompt( $prompts['user'] );
		if ( str_contains( $wikitext, 'unchanged marker' ) ) {
			return trim( $wikitext );
		}
		return trim( $wikitext ) . "\n\nAI revised.";
	}

	private static function extractWikitextFromUserPrompt( string $user ): string {
		if ( preg_match( '/Output the full revised wikitext only\.\n\n(.*)$/s', $user, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '/=== INPUT ===\n\n(.*)$/s', $user, $m ) ) {
			return $m[1];
		}
		return $user;
	}

}