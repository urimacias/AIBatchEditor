<?php

namespace MediaWiki\Extension\AIBatchEditor\Services;

/**
 * Server-side heuristics that flag risky AI proposals before approval.
 */
class ProposalAnalyzer {

	private const MAJOR_DELETION_RATIO = 0.30;
	private const LARGE_GROWTH_RATIO = 0.50;
	private const NEAR_EMPTY_BYTES = 64;

	/**
	 * @return list<string> Warning codes for the client (localized in the UI).
	 */
	public function analyze( string $original, string $proposed ): array {
		$warnings = [];
		$originalLen = strlen( $original );
		$proposedLen = strlen( $proposed );

		if ( $originalLen > 0 && $proposedLen < $originalLen ) {
			$removed = $originalLen - $proposedLen;
			if ( $removed / $originalLen >= self::MAJOR_DELETION_RATIO ) {
				$warnings[] = 'major-deletion';
			}
		}

		if ( $originalLen >= 200 && $proposedLen <= self::NEAR_EMPTY_BYTES ) {
			$warnings[] = 'near-empty';
		}

		if ( $originalLen > 0 && $proposedLen > $originalLen ) {
			$added = $proposedLen - $originalLen;
			if ( $added / $originalLen >= self::LARGE_GROWTH_RATIO ) {
				$warnings[] = 'large-growth';
			}
		}

		if ( $this->hasRemovedHeadings( $original, $proposed ) ) {
			$warnings[] = 'section-removed';
		}

		return array_values( array_unique( $warnings ) );
	}

	private function hasRemovedHeadings( string $original, string $proposed ): bool {
		$originalHeadings = $this->extractHeadings( $original );
		if ( $originalHeadings === [] ) {
			return false;
		}

		$proposedHeadings = $this->extractHeadings( $proposed );
		foreach ( $originalHeadings as $heading ) {
			if ( !in_array( $heading, $proposedHeadings, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return list<string>
	 */
	private function extractHeadings( string $wikitext ): array {
		$headings = [];
		if ( preg_match_all( '/^=+([^=]+)=+\s*$/m', $wikitext, $matches ) ) {
			foreach ( $matches[1] as $heading ) {
				$normalized = trim( $heading );
				if ( $normalized !== '' ) {
					$headings[] = $normalized;
				}
			}
		}
		return $headings;
	}

}