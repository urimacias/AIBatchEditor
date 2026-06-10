#!/usr/bin/env bash
# Run the AIBatchEditor PHPUnit suite from the MediaWiki root.
set -euo pipefail

MW_ROOT="${MW_ROOT:-/var/www/html}"
EXT_PATH="extensions/AIBatchEditor/tests/phpunit"

cd "$MW_ROOT"

if command -v composer >/dev/null 2>&1 && composer phpunit --help >/dev/null 2>&1; then
	RUNNER=( composer phpunit -- )
elif [[ -f tests/phpunit/phpunit.php ]]; then
	RUNNER=( php tests/phpunit/phpunit.php )
else
	echo "MediaWiki PHPUnit runner not found. Install dev dependencies:" >&2
	echo "  composer install --dev" >&2
	exit 1
fi

echo "== Unit tests =="
"${RUNNER[@]}" "$EXT_PATH/unit"

echo "== Integration tests =="
"${RUNNER[@]}" "$EXT_PATH/integration"

echo "All AIBatchEditor tests passed."