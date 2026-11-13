#!/usr/bin/env bash
# PHP/Caddy local dev server setup. Only needed for PHP projects using Caddy.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SITE_HOST="${SITE_HOST:-auth.localhost}"
PROJECT_SLUG="$(basename "$ROOT")"
CADDY_CONF_DIR="/etc/caddy/conf.d"
CADDY_CONF_FILE="$CADDY_CONF_DIR/$PROJECT_SLUG.caddy"

if ! command -v caddy >/dev/null 2>&1; then
  echo "caddy not found. Install: https://caddyserver.com/docs/install"
  exit 1
fi

PHP_SOCK="$(ls /run/php/php*-fpm.sock 2>/dev/null | sort -V | tail -n 1 || true)"
if [ -z "$PHP_SOCK" ]; then
  echo "No PHP-FPM socket found at /run/php/php*-fpm.sock"
  echo "Install PHP-FPM: sudo apt install php-fpm"
  exit 1
fi

TMP_FILE="$(mktemp)"
sed \
  -e "s#__REPO_ROOT__#$ROOT#g" \
  -e "s#__PHP_FPM_SOCKET__#$PHP_SOCK#g" \
  -e "s#__SITE_HOST__#$SITE_HOST#g" \
  caddy/Caddyfile.template > "$TMP_FILE"

echo "Generated site config:"
cat "$TMP_FILE"

# Ensure conf.d exists and main Caddyfile imports it
if [ ! -d "$CADDY_CONF_DIR" ]; then
  echo "Creating $CADDY_CONF_DIR"
  sudo mkdir -p "$CADDY_CONF_DIR"
fi

if [ ! -f /etc/caddy/Caddyfile ] || ! grep -q "import.*conf.d" /etc/caddy/Caddyfile; then
  echo "Updating /etc/caddy/Caddyfile to import conf.d/"
  echo "import $CADDY_CONF_DIR/*.caddy" | sudo tee /etc/caddy/Caddyfile >/dev/null
fi

echo ""
echo "Installing to $CADDY_CONF_FILE"
sudo cp "$TMP_FILE" "$CADDY_CONF_FILE"
rm "$TMP_FILE"

if ! grep -q "$SITE_HOST" /etc/hosts; then
  echo "Adding $SITE_HOST to /etc/hosts"
  echo "127.0.0.1 $SITE_HOST" | sudo tee -a /etc/hosts >/dev/null
fi

sudo systemctl reload caddy

echo "Caddy reloaded."
echo "Local URL: http://$SITE_HOST"
