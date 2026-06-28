<?php

namespace MediaWiki\Extension\AIBatchEditor\Tests;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\AIBatchEditor\Services\DraftTokenService;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AIBatchEditor\Services\DraftTokenService
 */
class DraftTokenServiceTest extends MediaWikiUnitTestCase {

	private function newService(
		string $secret = 'test-secret',
		string $draftSecret = ''
	): DraftTokenService {
		return new DraftTokenService(
			new ServiceOptions( DraftTokenService::CONSTRUCTOR_OPTIONS, [
				'SecretKey' => $secret,
				'AIBatchEditorDraftTokenSecret' => $draftSecret,
			] )
		);
	}

	public function testIssueAndVerifyAcceptsMatchingProposal(): void {
		$service = $this->newService();
		$token = $service->issue( 'Test_Page', 42, "proposed\nwikitext", 7 );

		$this->assertTrue( $service->verify( $token, 'Test_Page', 42, "proposed\nwikitext", 7 ) );
		$this->assertNull( $service->verifyWithReason( $token, 'Test_Page', 42, "proposed\nwikitext", 7 ) );
	}

	public function testVerifyRejectsTamperedProposedText(): void {
		$service = $this->newService();
		$token = $service->issue( 'Test_Page', 42, 'original text', 7 );

		$this->assertFalse( $service->verify( $token, 'Test_Page', 42, 'tampered text', 7 ) );
		$this->assertSame(
			DraftTokenService::REASON_CONTENT_MISMATCH,
			$service->verifyWithReason( $token, 'Test_Page', 42, 'tampered text', 7 )
		);
	}

	public function testVerifyRejectsWrongUser(): void {
		$service = $this->newService();
		$token = $service->issue( 'Test_Page', 42, 'original text', 7 );

		$this->assertFalse( $service->verify( $token, 'Test_Page', 42, 'original text', 99 ) );
		$this->assertSame(
			DraftTokenService::REASON_USER_MISMATCH,
			$service->verifyWithReason( $token, 'Test_Page', 42, 'original text', 99 )
		);
	}

	public function testVerifyRejectsWrongSecret(): void {
		$issuer = $this->newService( 'issuer-secret' );
		$verifier = $this->newService( 'other-secret' );
		$token = $issuer->issue( 'Test_Page', 42, 'original text', 7 );

		$this->assertFalse( $verifier->verify( $token, 'Test_Page', 42, 'original text', 7 ) );
		$this->assertSame(
			DraftTokenService::REASON_BAD_SIGNATURE,
			$verifier->verifyWithReason( $token, 'Test_Page', 42, 'original text', 7 )
		);
	}

	public function testVerifyRejectsExpiredToken(): void {
		$service = $this->newService();
		$token = $service->issue( 'Test_Page', 42, 'original text', 7 );
		$decoded = base64_decode( $token, true );
		$this->assertIsString( $decoded );
		[ $payload, $signature ] = explode( '.', $decoded, 2 );
		$parts = explode( '|', $payload );
		$parts[4] = (string)( time() - 10 );
		$payload = implode( '|', $parts );
		$expiredToken = base64_encode(
			$payload . '.' . hash_hmac( 'sha256', $payload, 'test-secret' )
		);

		$this->assertSame(
			DraftTokenService::REASON_EXPIRED,
			$service->verifyWithReason( $expiredToken, 'Test_Page', 42, 'original text', 7 )
		);
	}

	public function testVerifyRejectsInvalidFormat(): void {
		$service = $this->newService();
		$this->assertSame(
			DraftTokenService::REASON_INVALID_FORMAT,
			$service->verifyWithReason( 'not-a-token', 'Test_Page', 42, 'text', 7 )
		);
	}

	public function testDraftTokenSecretOverridesSecretKey(): void {
		$service = $this->newService( 'wiki-secret', 'draft-secret' );
		$token = $service->issue( 'Test_Page', 1, 'text', 7 );

		$this->assertNull( $service->verifyWithReason( $token, 'Test_Page', 1, 'text', 7 ) );
		$this->assertSame(
			DraftTokenService::REASON_BAD_SIGNATURE,
			$this->newService( 'wiki-secret', 'other-draft-secret' )
				->verifyWithReason( $token, 'Test_Page', 1, 'text', 7 )
		);
	}

	public function testNfcNormalizationAcceptsMatchingUnicodeForms(): void {
		if ( !class_exists( \Normalizer::class ) ) {
			$this->markTestSkipped( 'intl Normalizer not available' );
		}

		$service = $this->newService();
		$nfc = "\u{00E9}";
		$nfd = "\u{0065}\u{0301}";
		$token = $service->issue( 'Test_Page', 1, "caf$nfc", 7 );

		$this->assertNull( $service->verifyWithReason( $token, 'Test_Page', 1, "caf$nfd", 7 ) );
	}

}