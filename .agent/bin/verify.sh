#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

export APP_ENV="${APP_ENV:-test}"
export BASE_URL="${BASE_URL:-http://auth.localhost}"

echo "== Verification start =="
echo "Root: $ROOT"
echo "APP_ENV=$APP_ENV"
echo "BASE_URL=$BASE_URL"

echo "== Git status =="
git status --short || true

# ---------------------------------------------------------------------------
# This script must exit 0 on full pass, non-zero on any failure.
# Anti-cheat scan runs first (cheap, fails fast), then the real test suite.
# ---------------------------------------------------------------------------

echo "== Anti-cheat scan =="
# Word boundaries on the JS skip-calls so `xit(`/`xdescribe(` do not match the
# substring inside PHP's legitimate `exit(`. tests/_output is Codeception's
# generated artifact dir (gitignored failure HTML dumps) — never source code —
# so exclude it to avoid false positives from rendered page content.
BAD_PATTERNS='test\.skip|it\.skip|describe\.skip|markTestSkipped|\bxit\(|\bxdescribe\(|TODO: fake|fake success|placeholder only|return early to pass'
if grep -RInE --exclude-dir=_output "$BAD_PATTERNS" tests src 2>/dev/null; then
  echo "Anti-cheat scan failed."
  exit 1
fi

echo "== Test suite =="
# Single source of truth for the real checks: migrations + PHPUnit (unit +
# functional) + Codeception acceptance. `set -e` propagates a non-zero exit,
# so a failing suite fails this gate. Keeping this delegated to
# bin/verify-fast.sh means the loop's gate and the manual/CLAUDE.md check
# can never drift (this was previously a stub that ran no tests).
bin/verify-fast.sh

echo "== Verification passed =="
