#!/usr/bin/env bash
# Run Playwright E2E tests against the local MediaWiki docker stack.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
E2E_DIR="$ROOT/tests/e2e"

if [[ -z "${MW_E2E_USER:-}" || -z "${MW_E2E_PASSWORD:-}" ]]; then
	echo "Set MW_E2E_USER and MW_E2E_PASSWORD to a sysop account." >&2
	exit 1
fi

export AIBATCHEDITOR_E2E_STUB=1
export MW_E2E_BASE_URL="${MW_E2E_BASE_URL:-http://localhost:8080}"

echo "== E2E prerequisites =="
echo "Stub mode: AIBATCHEDITOR_E2E_STUB=1 (enable \$wgAIBatchEditorStubMode in LocalSettings)"
echo "Wiki URL: $MW_E2E_BASE_URL"

cd "$E2E_DIR"
if [[ ! -d node_modules ]]; then
	npm install
fi
npx playwright install chromium
npm test

echo "All AIBatchEditor E2E tests passed."