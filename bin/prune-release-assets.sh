#!/usr/bin/env bash
# Deletes the attached assets (tarball + checksum) of every release except the newest KEEP (default 10).
# Releases and tags are never touched. Dry run unless DRY_RUN=false. Run weekly by .github/workflows/prune-release-assets.yml.
#   GITHUB_TOKEN=... GITHUB_REPOSITORY=owner/repo [GITHUB_API_URL=...] [KEEP=10] [DRY_RUN=false] bin/prune-release-assets.sh
set -euo pipefail

: "${GITHUB_TOKEN:?GITHUB_TOKEN is required}" "${GITHUB_REPOSITORY:?GITHUB_REPOSITORY is required}"
API="${GITHUB_API_URL:-https://api.github.com}"
KEEP="${KEEP:-10}"; DRY_RUN="${DRY_RUN:-true}"
gh_api() { curl -fsSL -H "Authorization: Bearer $GITHUB_TOKEN" -H "Accept: application/vnd.github+json" -H "X-GitHub-Api-Version: 2022-11-28" "$@"; }

# Published releases, newest first (the API's order); drafts are not counted.
releases="[]"; page=1
while :; do
    chunk="$(gh_api "$API/repos/$GITHUB_REPOSITORY/releases?per_page=100&page=$page")"
    [ "$(jq length <<<"$chunk")" -gt 0 ] || break
    releases="$(jq -s 'add' <(printf '%s' "$releases") <(printf '%s' "$chunk"))"
    page=$((page + 1))
done

# Computed first so a jq failure aborts the script instead of silently reporting zero.
rows="$(jq -r --argjson keep "$KEEP" '[.[] | select(.draft | not)] | .[$keep:][] | .tag_name as $t | .assets[] | [$t, .id, .name] | @tsv' <<<"$releases")"
count=0
while IFS=$'\t' read -r tag asset_id name; do
    [ -n "$tag" ] || continue
    count=$((count + 1))
    if [ "$DRY_RUN" = false ]; then
        gh_api -X DELETE "$API/repos/$GITHUB_REPOSITORY/releases/assets/$asset_id"
        echo "deleted $tag: $name"
    else
        echo "[dry run] would delete $tag: $name"
    fi
done <<<"$rows"
echo "$count asset(s) $([ "$DRY_RUN" = false ] && echo deleted || echo would be deleted) (kept the newest $KEEP releases' assets)."
