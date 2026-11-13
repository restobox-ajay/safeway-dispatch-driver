#!/usr/bin/env bash
# Builds the installable release tarball (code + production vendor/) that bin/rbx-install.sh
# downloads. Run by semantic-release (.releaserc.json) after VERSION is bumped; safe to run locally.
# RBX_COMPOSER_ARGS adds composer flags (e.g. --ignore-platform-req=ext-redis on a sandbox with an old extension).
set -euo pipefail

VERSION="${1:?usage: bin/build-release.sh <version>}"
cd "$(dirname "${BASH_SOURCE[0]}")/.."

NAME="symfony-auth-boilerplate-${VERSION}"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/$NAME" dist

# Tracked files only (from the working tree, so the bumped VERSION is included), minus dev/CI-only paths.
# docs/api/openapi.yaml stays: the admin "API Docs" page serves it at runtime (ADR-060).
git ls-files -z \
    | grep -zvE '^(tests/|public/testing/|\.agent/|\.github/|caddy/|docs/(bundles/|[^/]+$)|package(-lock)?\.json$|\.releaserc\.json$|CLAUDE\.md$|codeception\.yml$|verify\.log$|\.claudeignore$|\.editorconfig$|\.gitattributes$|\.gitignore$|phpunit\.dist\.xml$|bin/(phpunit|adr-tripwire\.php|verify-fast\.sh|serve-auth-localhost\.sh|build-release\.sh|bake-installer\.sh|prune-release-assets\.sh)$|\.env\.(test|acceptance)$)' \
    | tar --null -T - -cf - | tar -xf - -C "$STAGE/$NAME"
echo "$VERSION" > "$STAGE/$NAME/VERSION"

# Production dependencies only; scripts are skipped because the installer runs them (cache/assets) on the target box.
(cd "$STAGE/$NAME" && composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader ${RBX_COMPOSER_ARGS:-} >&2)
# symfony/runtime is a Composer plugin that writes this file; without it (plugins disabled, e.g. root) every request would fatal.
[ -f "$STAGE/$NAME/vendor/autoload_runtime.php" ] || { echo "vendor/autoload_runtime.php missing: Composer plugins did not run." >&2; exit 1; }

tar -czf "dist/$NAME.tar.gz" -C "$STAGE" "$NAME"
(cd dist && sha256sum "$NAME.tar.gz" > "$NAME.tar.gz.sha256")

echo "dist/$NAME.tar.gz"
