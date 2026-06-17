<?php

namespace MediaWiki\Extension\AIBatchEditor\Tests;

use MediaWiki\Extension\AIBatchEditor\Services\ProposalAnalyzer;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AIBatchEditor\Services\ProposalAnalyzer
 */
class ProposalAnalyzerTest extends MediaWikiUnitTestCase {

	private ProposalAnalyzer $analyzer;

	protected function setUp(): void {
		parent::setUp();
		$this->analyzer = new ProposalAnalyzer();
	}

	public function testNoWarningsForMinorEdit(): void {
		$original = str_repeat( 'word ', 100 );
		$proposed = $original . 'extra';

		$this->assertSame( [], $this->analyzer->analyze( $original, $proposed ) );
	}

	public function testDetectsMajorDeletion(): void {
		$original = str_repeat( 'x', 1000 );
		$proposed = str_repeat( 'x', 500 );

		$this->assertContains( 'major-deletion', $this->analyzer->analyze( $original, $proposed ) );
	}

	public function testDetectsRemovedSectionHeading(): void {
		$original = "Intro\n\n== History ==\n\nOld facts.";
		$proposed = "Intro\n\nOld facts.";

		$this->assertContains( 'section-removed', $this->analyzer->analyze( $original, $proposed ) );
	}

	public function testDetectsNearEmptyProposal(): void {
		$original = str_repeat( 'Long article body. ', 50 );
		$proposed = 'stub';

		$this->assertContains( 'near-empty', $this->analyzer->analyze( $original, $proposed ) );
	}

}