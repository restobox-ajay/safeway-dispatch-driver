#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

export APP_ENV="${APP_ENV:-test}"
export BASE_URL="${BASE_URL:-http://auth.localhost}"

echo "== Init environment =="
echo "Root: $ROOT"
echo "APP_ENV=$APP_ENV"
echo "BASE_URL=$BASE_URL"

# ---------------------------------------------------------------------------
# Customize for your stack. Common patterns:
#
# Node:
#   npm ci
#
# PHP/Composer:
#   composer install --no-interaction --prefer-dist
#
# Python:
#   pip install -r requirements.txt
#
# Database reset (PHP/Symfony example):
#   php bin/console doctrine:database:drop --force --env=test || true
#   php bin/console doctrine:database:create --env=test
#   php bin/console doctrine:migrations:migrate --no-interaction --env=test
# ---------------------------------------------------------------------------

echo "== Local URL check =="
if command -v curl >/dev/null 2>&1; then
  if curl -fsS -o /dev/null "$BASE_URL" 2>/dev/null; then
    echo "Local app responds at $BASE_URL"
  else
    echo "Warning: $BASE_URL did not respond. Check your local server."
  fi
fi

echo "== Init complete =="
