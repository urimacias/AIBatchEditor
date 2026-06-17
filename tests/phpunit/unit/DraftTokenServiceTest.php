<?php

namespace MediaWiki\Extension\AIBatchEditor\Tests;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\AIBatchEditor\Services\DraftTokenService;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AIBatchEditor\Services\DraftTokenService
 */
class DraftTokenServiceTest extends MediaWikiUnitTestCase {

	private function newService( string $secret = 'test-secret' ): DraftTokenService {
		return new DraftTokenService(
			new ServiceOptions( DraftTokenService::CONSTRUCTOR_OPTIONS, [
				'SecretKey' => $secret,
			] )
		);
	}

	public function testIssueAndVerifyAcceptsMatchingProposal(): void {
		$service = $this->newService();
		$token = $service->issue( 'Test_Page', 42, "proposed\nwikitext", 7 );

		$this->assertTrue( $service->verify( $token, 'Test_Page', 42, "proposed\nwikitext", 7 ) );
	}

	public function testVerifyRejectsTamperedProposedText(): void {
		$service = $this->newService();
		$token = $service->issue( 'Test_Page', 42, 'original text', 7 );

		$this->assertFalse( $service->verify( $token, 'Test_Page', 42, 'tampered text', 7 ) );
	}

	public function testVerifyRejectsWrongUser(): void {
		$service = $this->newService();
		$token = $service->issue( 'Test_Page', 42, 'original text', 7 );

		$this->assertFalse( $service->verify( $token, 'Test_Page', 42, 'original text', 99 ) );
	}

	public function testVerifyRejectsWrongSecret(): void {
		$issuer = $this->newService( 'issuer-secret' );
		$verifier = $this->newService( 'other-secret' );
		$token = $issuer->issue( 'Test_Page', 42, 'original text', 7 );

		$this->assertFalse( $verifier->verify( $token, 'Test_Page', 42, 'original text', 7 ) );
	}

}