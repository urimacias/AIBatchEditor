<?php

namespace MediaWiki\Extension\AIBatchEditor\Services;

use MediaWiki\Config\ServiceOptions;

/**
 * HMAC-signed tokens that bind a draft proposal to a user, page revision, and content hash.
 */
class DraftTokenService {

	public const CONSTRUCTOR_OPTIONS = [
		'SecretKey',
	];

	private const TTL_SECONDS = 86400;

	private ServiceOptions $options;

	public function __construct( ServiceOptions $options ) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->options = $options;
	}

	public function issue( string $title, int $revid, string $proposed, int $userId ): string {
		$expires = time() + self::TTL_SECONDS;
		$payload = implode( '|', [
			$title,
			(string)$revid,
			hash( 'sha256', $proposed ),
			(string)$userId,
			(string)$expires,
		] );
		$signature = $this->sign( $payload );
		return base64_encode( $payload . '.' . $signature );
	}

	public function verify(
		string $token,
		string $title,
		int $revid,
		string $proposed,
		int $userId
	): bool {
		$decoded = base64_decode( $token, true );
		if ( $decoded === false || !str_contains( $decoded, '.' ) ) {
			return false;
		}

		[ $payload, $signature ] = explode( '.', $decoded, 2 );
		if ( !hash_equals( $this->sign( $payload ), $signature ) ) {
			return false;
		}

		$parts = explode( '|', $payload );
		if ( count( $parts ) !== 5 ) {
			return false;
		}

		[ $tokenTitle, $tokenRevid, $tokenHash, $tokenUserId, $tokenExpires ] = $parts;
		if ( $tokenTitle !== $title ) {
			return false;
		}
		if ( (int)$tokenRevid !== $revid ) {
			return false;
		}
		if ( !hash_equals( $tokenHash, hash( 'sha256', $proposed ) ) ) {
			return false;
		}
		if ( (int)$tokenUserId !== $userId ) {
			return false;
		}
		if ( (int)$tokenExpires < time() ) {
			return false;
		}

		return true;
	}

	private function sign( string $payload ): string {
		$secret = (string)$this->options->get( 'SecretKey' );
		return hash_hmac( 'sha256', $payload, $secret );
	}

}