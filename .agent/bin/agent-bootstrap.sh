#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

mkdir -p .agent

if [ ! -f docs/SPEC.md ]; then
  echo "Missing docs/SPEC.md"
  exit 1
fi

if ! command -v claude >/dev/null 2>&1; then
  echo "claude CLI not found"
  exit 1
fi

if ! command -v jq >/dev/null 2>&1; then
  echo "jq not found. Install with: sudo apt install jq  OR  brew install jq"
  exit 1
fi

echo "Bootstrap start — waiting for Claude (this can take a few minutes)..."

TMP_OUT="$(mktemp)"
TMP_ERR="$(mktemp)"

claude -p "
Read:
- CLAUDE.md
- docs/SPEC.md
- .agent/DECISIONS.md
- .agent/OPEN_QUESTIONS.md

Planning only. Do not edit application code.

Create or replace .agent/feature_list.json.

The JSON must have this shape:

{
  \"project\": \"...\",
  \"generated_from\": \"docs/SPEC.md\",
  \"features\": [
    {
      \"id\": \"FEATURE-001\",
      \"title\": \"...\",
      \"description\": \"...\",
      \"priority\": \"must|should|could\",
      \"depends_on\": [],
      \"acceptance_criteria\": [],
      \"test_type\": [\"unit\", \"functional\", \"acceptance\"],
      \"passes\": false,
      \"evidence\": []
    }
  ]
}

Rules:
- Break the spec into small independently testable features.
- Prefer vertical slices over vague large modules.
- Every must-have user workflow must have acceptance coverage.
- Do not mark anything passes=true.
- If the spec is ambiguous, write entries to .agent/OPEN_QUESTIONS.md.
- If architecture assumptions are needed, write them to .agent/DECISIONS.md.
- Initialize .agent/claude-progress.txt with a concise summary of the plan.
" >"$TMP_OUT" 2>"$TMP_ERR" &

CLAUDE_PID=$!
SECONDS_ELAPSED=0

while kill -0 "$CLAUDE_PID" 2>/dev/null; do
  sleep 30
  SECONDS_ELAPSED=$((SECONDS_ELAPSED + 30))
  echo "  ... still running (${SECONDS_ELAPSED}s elapsed)"
done

wait "$CLAUDE_PID"
EXIT_CODE=$?

cat "$TMP_OUT"
[ -s "$TMP_ERR" ] && cat "$TMP_ERR" >&2
rm -f "$TMP_OUT" "$TMP_ERR"

[ $EXIT_CODE -ne 0 ] && { echo "Claude exited with error $EXIT_CODE"; exit $EXIT_CODE; }

jq empty .agent/feature_list.json

echo "Bootstrap complete."
echo "Review .agent/feature_list.json before running .agent/bin/agent-loop.sh"
