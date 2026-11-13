#!/usr/bin/env bash
# Writes a GitHub token into bin/rbx-install.sh's BAKED_TOKEN (the token never touches stdout).
# The token must be a read-only fine-grained PAT scoped to this one repo (ADR-071).
#   bin/bake-installer.sh /path/to/token-file      # or: RBX_GITHUB_TOKEN=... bin/bake-installer.sh
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

if [ -n "${1:-}" ]; then TOKEN="$(tr -d '[:space:]' < "$1")"; else TOKEN="${RBX_GITHUB_TOKEN:-}"; fi
[ -n "$TOKEN" ] || { echo "usage: bin/bake-installer.sh <token-file>  (or set RBX_GITHUB_TOKEN)" >&2; exit 1; }
case "$TOKEN" in github_pat_*) ;; *) echo "Expected a fine-grained token (github_pat_...)." >&2; exit 1 ;; esac
case "$TOKEN" in *[!A-Za-z0-9_]*) echo "Token has unexpected characters." >&2; exit 1 ;; esac

sed -i "s/^BAKED_TOKEN=.*/BAKED_TOKEN=\"$TOKEN\"/" bin/rbx-install.sh
echo "Baked token into bin/rbx-install.sh (${#TOKEN} chars)."
