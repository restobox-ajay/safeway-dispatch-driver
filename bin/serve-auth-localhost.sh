#!/usr/bin/env bash
# One-shot installer: serve this project at http://auth.localhost via Caddy.
# Plain HTTP only (no HTTPS). Run with: sudo bash bin/serve-auth-localhost.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SITE_HOST="auth.localhost"
SRC_CONF="$ROOT/caddy/auth.localhost.caddy"
DEST_CONF="/etc/caddy/conf.d/symfony-auth-boilerplate.caddy"
MAIN_CADDYFILE="/etc/caddy/Caddyfile"

if [ "$(id -u)" -ne 0 ]; then
  echo "This script needs root. Re-run: sudo bash bin/serve-auth-localhost.sh"
  exit 1
fi

if [ ! -f "$SRC_CONF" ]; then
  echo "Missing $SRC_CONF"
  exit 1
fi

# 1. Ensure /etc/hosts maps auth.localhost -> 127.0.0.1
if ! grep -q "[[:space:]]$SITE_HOST\([[:space:]]\|$\)" /etc/hosts; then
  echo "Adding $SITE_HOST to /etc/hosts"
  echo "127.0.0.1 $SITE_HOST" >> /etc/hosts
fi

# 2. Ensure the main Caddyfile imports conf.d/*.caddy
mkdir -p /etc/caddy/conf.d
if [ ! -f "$MAIN_CADDYFILE" ] || ! grep -q "import .*conf.d" "$MAIN_CADDYFILE"; then
  echo "Adding 'import conf.d/*.caddy' to $MAIN_CADDYFILE"
  echo "import /etc/caddy/conf.d/*.caddy" >> "$MAIN_CADDYFILE"
fi

# 3. Install the site config
echo "Installing $DEST_CONF"
install -m 644 "$SRC_CONF" "$DEST_CONF"

# 4. Make the SQLite dev DB writable by php-fpm (www-data)
if [ -f "$ROOT/var/data_dev.db" ]; then
  chmod 666 "$ROOT/var/data_dev.db"
fi

# 5. Validate then reload Caddy
echo "Validating Caddy config..."
caddy validate --config "$MAIN_CADDYFILE" --adapter caddyfile >/dev/null
echo "Reloading Caddy..."
systemctl reload caddy

# 6. Smoke test
sleep 1
CODE="$(curl -s -o /dev/null -w '%{http_code}' http://$SITE_HOST/login || echo 000)"
echo ""
echo "http://$SITE_HOST/login -> HTTP $CODE"
if [ "$CODE" = "200" ]; then
  echo "Ready. Log in at http://$SITE_HOST/login as ken@restobox.com / password123"
else
  echo "Unexpected status. Check: journalctl -u caddy -n 30 --no-pager"
  exit 1
fi
