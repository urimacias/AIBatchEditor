<?php

namespace MediaWiki\Extension\AIBatchEditor;

/**
 * Load KEY=VALUE pairs from a .env file into the PHP process environment.
 *
 * Used on bare-metal/cPanel installs where Docker env_file is unavailable.
 * Existing environment variables are never overwritten.
 */
class EnvFile {

	/**
	 * @param string $path Absolute path to a .env file
	 */
	public static function load( string $path ): void {
		if ( !is_readable( $path ) ) {
			return;
		}

		$lines = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( $lines === false ) {
			return;
		}

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( $line === '' || $line[0] === '#' ) {
				continue;
			}

			$equalsAt = strpos( $line, '=' );
			if ( $equalsAt === false ) {
				continue;
			}

			$name = trim( substr( $line, 0, $equalsAt ) );
			$value = self::unquote( trim( substr( $line, $equalsAt + 1 ) ) );
			if ( $name === '' ) {
				continue;
			}

			if ( getenv( $name ) !== false ) {
				continue;
			}

			putenv( "$name=$value" );
			$_ENV[$name] = $value;
			$_SERVER[$name] = $value;
		}
	}

	private static function unquote( string $value ): string {
		$len = strlen( $value );
		if ( $len < 2 ) {
			return $value;
		}

		$quote = $value[0];
		if ( ( $quote === '"' || $quote === "'" ) && $value[$len - 1] === $quote ) {
			return substr( $value, 1, -1 );
		}

		return $value;
	}

}