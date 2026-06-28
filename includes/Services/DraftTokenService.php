<?php

namespace MediaWiki\Extension\AIBatchEditor\Services;

use MediaWiki\Config\ServiceOptions;
use Normalizer;

/**
 * HMAC-signed tokens that bind a draft proposal to a user, page revision, and content hash.
 */
class DraftTokenService {

	public const CONSTRUCTOR_OPTIONS = [
		'SecretKey',
		'AIBatchEditorDraftTokenSecret',
	];

	public const REASON_INVALID_FORMAT = 'invalid-format';
	public const REASON_BAD_SIGNATURE = 'bad-signature';
	public const REASON_TITLE_MISMATCH = 'title-mismatch';
	public const REASON_REVID_MISMATCH = 'revid-mismatch';
	public const REASON_CONTENT_MISMATCH = 'content-mismatch';
	public const REASON_USER_MISMATCH = 'user-mismatch';
	public const REASON_EXPIRED = 'expired';

	private const TTL_SECONDS = 86400;

	private ServiceOptions $options;

	public function __construct( ServiceOptions $options ) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->options = $options;
	}

	public function issue( string $title, int $revid, string $proposed, int $userId ): string {
		$proposed = $this->normalizeProposed( $proposed );
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
		return $this->verifyWithReason( $token, $title, $revid, $proposed, $userId ) === null;
	}

	public function verifyWithReason(
		string $token,
		string $title,
		int $revid,
		string $proposed,
		int $userId
	): ?string {
		$proposed = $this->normalizeProposed( $proposed );
		$decoded = base64_decode( $token, true );
		if ( $decoded === false || !str_contains( $decoded, '.' ) ) {
			return self::REASON_INVALID_FORMAT;
		}

		[ $payload, $signature ] = explode( '.', $decoded, 2 );
		if ( !hash_equals( $this->sign( $payload ), $signature ) ) {
			return self::REASON_BAD_SIGNATURE;
		}

		$parts = explode( '|', $payload );
		if ( count( $parts ) !== 5 ) {
			return self::REASON_INVALID_FORMAT;
		}

		[ $tokenTitle, $tokenRevid, $tokenHash, $tokenUserId, $tokenExpires ] = $parts;
		if ( $tokenTitle !== $title ) {
			return self::REASON_TITLE_MISMATCH;
		}
		if ( (int)$tokenRevid !== $revid ) {
			return self::REASON_REVID_MISMATCH;
		}
		if ( !hash_equals( $tokenHash, hash( 'sha256', $proposed ) ) ) {
			return self::REASON_CONTENT_MISMATCH;
		}
		if ( (int)$tokenUserId !== $userId ) {
			return self::REASON_USER_MISMATCH;
		}
		if ( (int)$tokenExpires < time() ) {
			return self::REASON_EXPIRED;
		}

		return null;
	}

	private function normalizeProposed( string $proposed ): string {
		if ( class_exists( Normalizer::class ) ) {
			$normalized = Normalizer::normalize( $proposed, Normalizer::FORM_C );
			if ( is_string( $normalized ) ) {
				return $normalized;
			}
		}
		return $proposed;
	}

	private function sign( string $payload ): string {
		$secret = trim( (string)$this->options->get( 'AIBatchEditorDraftTokenSecret' ) );
		if ( $secret === '' ) {
			$secret = (string)$this->options->get( 'SecretKey' );
		}
		return hash_hmac( 'sha256', $payload, $secret );
	}

}