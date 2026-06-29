#!/usr/bin/env bash
# Measure xAI chat-completions latency with a minimal prompt (no MediaWiki).
# Usage:
#   export XAI_API_KEY='xai-...'
#   ./extensions/AIBatchEditor/tests/benchmark-xai.sh
# Optional: MODEL=grok-4.3 TIMEOUT=90 ./extensions/AIBatchEditor/tests/benchmark-xai.sh
set -euo pipefail

API_URL="${API_URL:-https://api.x.ai/v1/chat/completions}"
MODEL="${MODEL:-grok-4.3}"
TIMEOUT="${TIMEOUT:-90}"

if [[ -z "${XAI_API_KEY:-}" ]]; then
	echo "Set XAI_API_KEY (same key as WikiHistoria .env)." >&2
	exit 1
fi

PAYLOAD=$(cat <<EOF
{
  "model": "$MODEL",
  "messages": [
    { "role": "system", "content": "Reply with exactly: OK" },
    { "role": "user", "content": "Ping" }
  ],
  "temperature": 0.1
}
EOF
)

echo "Model: $MODEL  Timeout: ${TIMEOUT}s  URL: $API_URL"
echo "Sending minimal prompt..."

START_MS=$(python3 -c 'import time; print(int(time.time()*1000))')
HTTP_CODE=$(curl -sS -o /tmp/aibatch-xai-bench.json -w '%{http_code}' \
	--max-time "$TIMEOUT" \
	-H "Content-Type: application/json" \
	-H "Authorization: Bearer $XAI_API_KEY" \
	-d "$PAYLOAD" \
	"$API_URL" || echo "000")
END_MS=$(python3 -c 'import time; print(int(time.time()*1000))')
ELAPSED=$(( END_MS - START_MS ))

echo "HTTP $HTTP_CODE  Elapsed: ${ELAPSED} ms"
if [[ -f /tmp/aibatch-xai-bench.json ]]; then
	python3 - <<'PY'
import json, sys
try:
    with open("/tmp/aibatch-xai-bench.json") as f:
        data = json.load(f)
    content = data.get("choices", [{}])[0].get("message", {}).get("content", "")
    print("Response preview:", (content or "(empty)")[:120].replace("\n", " "))
except Exception as e:
    print("Body parse error:", e, file=sys.stderr)
PY
fi

if [[ "$HTTP_CODE" != "200" ]]; then
	echo "Benchmark failed. See /tmp/aibatch-xai-bench.json" >&2
	exit 1
fi

echo "Done. Compare llmDurationMs in aibatcheditor.log after a real batch page."