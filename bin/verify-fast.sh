#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$PROJECT_DIR"

echo "=== Verify: symfony-auth-boilerplate ==="

# composer audit (gating): fails the build on any known advisory for a required package,
# dev or prod (e.g. CVE-2026-55766 guzzlehttp/psr7 CRLF injection, caught 2026-07-09 —
# nothing before this enforced it, so a vulnerable lockfile could ship silently).
echo "[1/3] Running composer audit..."
composer audit

# Reset the shared test SQLite database to a KNOWN CLEAN, fully-migrated state.
#
# Both .env.test (PHPUnit) and .env.acceptance (the Codeception live server) point
# DATABASE_URL at var/test.db, so the two suites share one file. The acceptance
# DatabaseHelper resets before each of its own tests but never after the suite, so the
# LAST acceptance test's rows — crucially any GLOBAL `config` values written via
# seedConfig() — survive the run. The PHPUnit functional tests only clean the specific
# config keys they each know about, so an unrelated leftover global config row silently
# changes behaviour and fails the next run (the ~6err+30fail Acceptance->Functional
# pollution described in FEATURE-137). Deleting the file (WAL is enabled, so the -wal /
# -shm side files can carry state too) and re-migrating an empty DB gives a deterministic,
# pristine, zero-row schema. Running migrations on the now-empty file is trivially
# idempotent. Called before EACH suite so every suite starts from the same clean state,
# making the whole gate repeatable regardless of what a prior suite/run left behind.
reset_test_db() {
  rm -f var/test.db var/test.db-wal var/test.db-shm
  APP_ENV=test php bin/console doctrine:migrations:migrate --no-interaction --quiet 2>&1
}

# PHPUnit — runs unit + the WebTestCase functional suite (tests/) under strict config
# (failOnDeprecation/Warning/Notice). These are NOT re-run by Codeception (FEATURE-129/C40).
echo "[2/3] Running PHPUnit (unit + functional) on a clean test DB..."
reset_test_db
APP_ENV=test php vendor/bin/phpunit --no-coverage

# Codeception acceptance (live-server E2E) suite only. The old empty Functional suite just
# re-ran the 325 WebTestCase tests already covered above (without PHPUnit's strictness); it
# was removed so those tests run exactly once (FEATURE-129/C40). Reset again so PHPUnit's
# functional fixtures cannot leak into the acceptance run.
echo "[3/3] Running Codeception (acceptance) on a clean test DB..."
reset_test_db
APP_ENV=test php vendor/bin/codecept build --quiet 2>&1
APP_ENV=test php vendor/bin/codecept run --no-colors 2>&1

# ADR tripwire (FEATURE-139): advisory, NON-GATING. Prints human-review flags for any ADR that is
# untagged or that names a test/repo path which no longer exists. `|| true` guarantees it can never
# fail the gate — the enforcing gate is tests/Functional/Meta/AdrTripwireTest.php (run above under
# PHPUnit), which asserts zero flags. This step just surfaces the same signal in the verify output.
echo "[info] ADR tripwire (advisory — does not gate):"
php bin/adr-tripwire.php || true

echo "=== All checks passed ==="
