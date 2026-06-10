<?php

namespace MediaWiki\Extension\AIBatchEditor\Services;

use MediaWiki\Config\ServiceOptions;
use Wikimedia\ObjectCache\BagOStuff;

/**
 * Per-user hourly cap on AI completion requests.
 */
class RateLimiterService {

	public const CONSTRUCTOR_OPTIONS = [
		'AIBatchEditorRateLimitPerHour',
	];

	private const CACHE_KEY_PREFIX = 'aibatcheditor-rl';
	private const WINDOW_SECONDS = 3600;

	private ServiceOptions $options;
	private BagOStuff $cache;

	public function __construct( ServiceOptions $options, BagOStuff $cache ) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->options = $options;
		$this->cache = $cache;
	}

	public function getLimit(): int {
		return max( 1, (int)$this->options->get( 'AIBatchEditorRateLimitPerHour' ) );
	}

	/**
	 * @return array{limit: int, used: int, remaining: int}
	 */
	public function getStatus( int $userId ): array {
		$limit = $this->getLimit();
		$used = $this->getUsedCount( $userId );
		return [
			'limit' => $limit,
			'used' => $used,
			'remaining' => max( 0, $limit - $used ),
		];
	}

	public function canConsume( int $userId, int $count = 1 ): bool {
		$status = $this->getStatus( $userId );
		return $count <= $status['remaining'];
	}

	public function consume( int $userId, int $count = 1 ): void {
		if ( $count < 1 ) {
			return;
		}
		$key = $this->cache->makeKey( self::CACHE_KEY_PREFIX, (string)$userId );
		$this->cache->merge(
			$key,
			static function ( $cache, $key, $current ) use ( $count ) {
				$used = is_int( $current ) ? $current : 0;
				return $used + $count;
			},
			self::WINDOW_SECONDS
		);
	}

	private function getUsedCount( int $userId ): int {
		$key = $this->cache->makeKey( self::CACHE_KEY_PREFIX, (string)$userId );
		$value = $this->cache->get( $key );
		return is_int( $value ) ? $value : 0;
	}

}