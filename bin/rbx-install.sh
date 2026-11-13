#!/usr/bin/env bash
# Installs or updates symfony-auth-boilerplate from the latest GitHub Release (a prebuilt tarball with vendor/).
# Needs only bash, curl, tar, sha256sum and PHP >= 8.2 with pdo_sqlite (the tarball already contains vendor/).
#
#   rbx-install.sh [install|update|rollback|auto] [--dir DIR] [--domain HOST] [--php PATH] [--admin-email EMAIL] [--yes] [--no-cron]
#
# `auto` (default): an existing install in --dir is updated, a missing --dir, or one holding only folders/dot-files/php.ini, is installed.
# Token: $RBX_GITHUB_TOKEN, else RBX_UPDATE_TOKEN in <dir>/.env.local, else the baked value below
# (see bin/bake-installer.sh). Read-only, scoped to this repo.
# $RBX_ALLOW_IP (comma-separated) keeps those IPs able to reach the site during an update.
# $RBX_REPO / $RBX_API_BASE override the repository and API host (used by tests/installer/e2e.sh).
set -euo pipefail

REPO="${RBX_REPO:-axcelmediacorp/symfony-auth-boilerplate}"
API_BASE="${RBX_API_BASE:-https://api.github.com}"
# Committed on purpose (ADR-071): a read-only, this-repo-only fine-grained token so a fresh server installs with no setup.
# Empty until the owner supplies one via bin/bake-installer.sh. Accepted risk, not a leak; don't report it.
BAKED_TOKEN=""
MIN_PHP_ID=80200
MIN_SQLITE="3.26.0"
# Untracked per-server files an update must carry over (hosts/Htaccess Lock edit .htaccess; .env.*.local and the secrets key are gitignored;
# php.ini/.user.ini/.htaccess at the folder root are the hosting panel's).
CARRY_FILES=".env.local public/.htaccess config/secrets/prod/prod.decrypt.private.php php.ini .user.ini .htaccess"

MODE=auto; DIR=""; DOMAIN=""; PHP_BIN=""; YES=0; SKIP_CRON=0; ADMIN_EMAIL=""
WORK=""; PHP=""; TOKEN=""; LOCKDIR=""; STAGING_DIR=""; MAINT=0; EXTRACT_ROOT=""; OLD_DIR=""; BAD_DIR=""; COMMITTED=0

info() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!!\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

# Runs on every exit, including INT/TERM/HUP (below). An abort while maintenance mode is on never leaves the site
# down or on a half-swapped tree: the old code and its pre-update DB come back (best effort), then maintenance is lifted.
cleanup() {
    # COMMITTED: the new release passed its health check; a late signal must not restore the old DB under it.
    if [ "$MAINT" = 1 ] && [ "$COMMITTED" = 0 ] && [ -n "$DIR" ] && [ -d "$(dirname "$DIR")" ]; then
        warn "Aborted during maintenance mode; restoring the previous state."
        MAINT=0
        if [ -n "$OLD_DIR" ] && [ -d "$OLD_DIR" ]; then rollback_swap "$OLD_DIR" || true
        elif [ -f "$DIR/var/backups/.inflight" ]; then restore_inflight || true   # a restore that died after the folders were swapped back
        fi
        if [ -n "$BAD_DIR" ] && [ -d "$BAD_DIR" ] && [ ! -d "$DIR" ]; then mv "$BAD_DIR" "$DIR" || true; fi   # a rollback cut between its two moves
        maintenance_off || true
    fi
    [ -z "$WORK" ] || rm -rf "${WORK:?}"
    [ -z "$STAGING_DIR" ] || rm -rf "${STAGING_DIR:?}"
    [ -z "$EXTRACT_ROOT" ] || rm -rf "${EXTRACT_ROOT:?}"
    [ -z "$LOCKDIR" ] || rm -rf "${LOCKDIR:?}"
}
trap cleanup EXIT
trap 'exit 143' INT TERM HUP

ask() { # ask <prompt> <default> -> echoes answer
    local answer
    if [ "$YES" = 1 ]; then printf '%s' "$2"; return; fi
    read -r -p "$1${2:+ [$2]}: " answer </dev/tty
    printf '%s' "${answer:-$2}"
}

confirm() { # confirm <prompt> <default y|n>
    [ "$YES" = 1 ] && { [ "$2" = y ]; return; }
    local answer; read -r -p "$1 [$2]: " answer </dev/tty
    case "${answer:-$2}" in y|Y|yes) return 0 ;; *) return 1 ;; esac
}

parse_args() {
    while [ $# -gt 0 ]; do
        case "$1" in
            install|update|rollback|auto) MODE="$1" ;;
            --dir) DIR="${2:?--dir needs a value}"; shift ;;
            --domain) DOMAIN="${2:?--domain needs a value}"; shift ;;
            --php) PHP_BIN="${2:?--php needs a value}"; shift ;;
            --admin-email) ADMIN_EMAIL="${2:?--admin-email needs a value}"; shift ;;
            --yes|-y) YES=1 ;;
            --no-cron) SKIP_CRON=1 ;;
            -h|--help) sed -n '2,10p' "$0"; exit 0 ;;
            *) die "Unknown argument: $1" ;;
        esac
        shift
    done
}

# ---- preflight -------------------------------------------------------------------------------

find_php() {
    local candidate
    for candidate in "$PHP_BIN" /usr/local/bin/ea-php84 /usr/local/bin/ea-php83 /usr/local/bin/ea-php82 \
        /opt/cpanel/ea-php84/root/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php /opt/cpanel/ea-php82/root/usr/bin/php \
        "$(command -v php || true)"; do
        [ -n "$candidate" ] && [ -x "$candidate" ] || continue
        if "$candidate" -r "exit(PHP_VERSION_ID >= $MIN_PHP_ID ? 0 : 1);" 2>/dev/null; then PHP="$candidate"; return; fi
    done
    die "PHP 8.2 or newer not found. Pass one with --php /path/to/php."
}

check_tools() {
    local tool
    for tool in curl tar sha256sum; do command -v "$tool" >/dev/null || die "'$tool' is required but not installed."; done
    local missing="" modules
    modules="$("$PHP" -m)"   # not piped into `grep -q`: its early exit would SIGPIPE php and trip pipefail
    for tool in ctype iconv pdo_sqlite mbstring; do
        printf '%s\n' "$modules" | grep -qix "$tool" || missing="$missing $tool"
    done
    [ -z "$missing" ] || die "PHP is missing required extension(s):$missing. Enable them in your host's PHP settings."
    "$PHP" -r 'exit(version_compare((new PDO("sqlite::memory:"))->query("SELECT sqlite_version()")->fetchColumn(), $argv[1], ">=") ? 0 : 1);' "$MIN_SQLITE" \
        || die "PHP's SQLite is older than $MIN_SQLITE, which the app requires."
}

# ---- GitHub release --------------------------------------------------------------------------

resolve_token() {
    TOKEN="${RBX_GITHUB_TOKEN:-}"
    if [ -z "$TOKEN" ] && [ -n "$DIR" ] && [ -f "$DIR/.env.local" ]; then
        TOKEN="$(sed -n 's/^RBX_UPDATE_TOKEN=//p' "$DIR/.env.local" | head -n1 | tr -d "\"'")"
    fi
    TOKEN="${TOKEN:-$BAKED_TOKEN}"
    [ -n "$TOKEN" ] || die "No GitHub token. Set RBX_GITHUB_TOKEN or use an installer baked with bin/bake-installer.sh."
}

api() { # api <path> [extra curl args...]
    local path="$1"; shift
    # The token goes in through a config on stdin, not argv, so `ps` cannot show it.
    printf 'header = "Authorization: Bearer %s"\n' "$TOKEN" | curl -fsSL -K - -H "X-GitHub-Api-Version: 2022-11-28" "$@" "$API_BASE$path"
}

# Sets LATEST_TAG, LATEST_VERSION, TAR_ID, SHA_ID.
fetch_latest_release() {
    local json parsed
    json="$(api "/repos/$REPO/releases/latest" -H "Accept: application/vnd.github+json")" \
        || die "Could not read the latest release of $REPO (bad token, no release yet, or no network)."
    parsed="$(printf '%s' "$json" | "$PHP" -r '
        $r = json_decode(stream_get_contents(STDIN), true);
        $tar = $sha = "";
        foreach ($r["assets"] ?? [] as $a) {
            if (str_ends_with($a["name"], ".tar.gz.sha256")) { $sha = $a["id"]; }
            elseif (str_ends_with($a["name"], ".tar.gz")) { $tar = $a["id"]; }
        }
        echo ($r["tag_name"] ?? ""), "\n", $tar, "\n", $sha, "\n";')"
    LATEST_TAG="$(printf '%s' "$parsed" | sed -n 1p)"
    TAR_ID="$(printf '%s' "$parsed" | sed -n 2p)"
    SHA_ID="$(printf '%s' "$parsed" | sed -n 3p)"
    [ -n "$LATEST_TAG" ] || die "The latest release has no tag."
    [ -n "$TAR_ID" ] && [ -n "$SHA_ID" ] || die "Release $LATEST_TAG has no install tarball/checksum attached."
    LATEST_VERSION="${LATEST_TAG#v}"
}

# Downloads, verifies the checksum, then extracts into $EXTRACT_ROOT (or $WORK/extract); sets RELEASE_DIR.
download_release() {
    info "Downloading $LATEST_TAG"
    api "/repos/$REPO/releases/assets/$TAR_ID" -H "Accept: application/octet-stream" -o "$WORK/release.tar.gz" || die "Tarball download failed."
    api "/repos/$REPO/releases/assets/$SHA_ID" -H "Accept: application/octet-stream" -o "$WORK/release.sha256" || die "Checksum download failed."
    local expected actual
    expected="$(cut -d' ' -f1 "$WORK/release.sha256")"
    actual="$(sha256sum "$WORK/release.tar.gz" | cut -d' ' -f1)"
    [ -n "$expected" ] && [ "$expected" = "$actual" ] || die "Checksum mismatch for the downloaded release; aborting."
    local root="${EXTRACT_ROOT:-$WORK/extract}"
    mkdir -p "$root"
    tar -xzf "$WORK/release.tar.gz" -C "$root"
    RELEASE_DIR="$(find "$root" -mindepth 1 -maxdepth 1 -type d | head -n1)"
    [ -f "$RELEASE_DIR/bin/console" ] || die "The release tarball looks wrong (no bin/console)."
}

# The bundled vendor/ records the PHP version and extensions it needs; refuse a host that cannot run it.
check_platform() { # check_platform <release dir>
    ( cd "$1" && "$PHP" -r 'require "vendor/composer/platform_check.php";' ) >/dev/null 2>&1 \
        || die "$LATEST_VERSION needs a newer PHP or extensions than this server provides. Nothing was changed."
}

# ---- app steps -------------------------------------------------------------------------------

console() { (cd "$1" && shift && "$PHP" bin/console "$@" --env=prod --no-interaction); }

# DATABASE_URL must be the SQLite file under var/ (the default); prints its path relative to the install folder.
db_relpath() { # db_relpath <dir>
    local url prefix='sqlite:///%kernel.project_dir%/var/' rel
    url="$(sed -n 's/^DATABASE_URL=//p' "$1/.env.local" 2>/dev/null | head -n1 | tr -d "\"'")"
    url="${url:-${prefix}data_%kernel.environment%.db}"
    case "$url" in "$prefix"*) ;; *) die "The updater only supports the SQLite file under var/ that this app installs with (DATABASE_URL=$url)." ;; esac
    rel="${url#"$prefix"}"
    case "$rel" in */*|"") die "Unsupported DATABASE_URL: $url" ;; esac
    printf 'var/%s' "${rel//%kernel.environment%/prod}"
}

# Fails when a setting the kernel needs is missing from the compiled environment; only warns about mail.
check_env() { # check_env <dir>
    ( cd "$1" && "$PHP" -r '
        $e = require ".env.local.php"; $bad = [];
        foreach (["APP_SECRET", "DEFAULT_URI", "ADMIN_DOMAIN", "APP_DOMAIN"] as $k) { if (trim((string) ($e[$k] ?? "")) === "") { $bad[] = $k; } }
        if (($e["APP_ENV"] ?? "") !== "prod") { $bad[] = "APP_ENV"; }
        if (!str_starts_with((string) ($e["DATABASE_URL"] ?? ""), "sqlite:")) { $bad[] = "DATABASE_URL"; }
        if ($bad) { fwrite(STDERR, "Missing or invalid: " . implode(", ", $bad) . "\n"); exit(1); }
        if (str_starts_with((string) ($e["MAILER_DSN"] ?? "null:"), "null:")) { fwrite(STDERR, "MAILER_DSN is the null transport: no email will be sent.\n"); }' )
}

finalize_app() { # finalize_app <dir>
    local dir="$1"
    mkdir -p "$dir/var/log" "$dir/var/share"
    info "Compiling production environment"
    console "$dir" dotenv:dump prod >/dev/null || die "dotenv:dump failed."
    chmod 600 "$dir/.env.local.php"   # holds APP_SECRET, the update token and MAILER_DSN
    check_env "$dir" || die "The production environment check failed."
    console "$dir" assets:install public >/dev/null || die "assets:install failed."
    info "Running database migrations"
    console "$dir" doctrine:migrations:migrate --allow-no-migration >/dev/null || die "Database migrations failed."
    console "$dir" cache:clear >/dev/null || die "cache:clear failed."
    console "$dir" cache:warmup >/dev/null || die "cache:warmup failed."
}

# Everything migrated, the container boots in prod, and the SQLite file is sound.
health_check() { # health_check <dir>
    local dir="$1" db
    console "$dir" doctrine:migrations:up-to-date >/dev/null || return 1
    console "$dir" lint:container >/dev/null || return 1
    db="$dir/$(db_relpath "$dir")"
    "$PHP" -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec("PRAGMA busy_timeout = 5000");
        exit($p->query("PRAGMA quick_check")->fetchColumn() === "ok" && $p->query("PRAGMA foreign_key_check")->fetch() === false ? 0 : 1);' "$db"
}

write_env_local() { # write_env_local <dir> <domain>
    local dir="$1" domain="$2" secret
    secret="$("$PHP" -r 'echo bin2hex(random_bytes(32));')"
    umask 077
    cat > "$dir/.env.local" <<EOF
APP_ENV=prod
APP_SECRET=$secret
DEFAULT_URI=https://$domain
ADMIN_DOMAIN=$domain
APP_DOMAIN=$domain
# Read-only GitHub token this installer uses to fetch new releases.
RBX_UPDATE_TOKEN=$TOKEN
# Set a real transport before real use, then run: php bin/console dotenv:dump prod --env=prod
# MAILER_DSN=smtp://user:pass@smtp.example.com:587
# MAILER_FROM=noreply@$domain
EOF
    umask 022
}

# Two jobs (README "Background worker" / "Maintenance"): the Messenger worker every minute, the nightly prune.
cron_lines() { # cron_lines <dir>
    local lock=""; command -v flock >/dev/null && lock="flock -n var/log/messenger-consume.lock "
    printf '* * * * * cd %s && [ ! -f var/maintenance.json ] && %s%s bin/console messenger:consume async --time-limit=55 --memory-limit=128M --quiet --env=prod >> var/log/messenger.log 2>&1\n' "$1" "$lock" "$PHP"
    printf '0 3 * * * cd %s && %s bin/console app:prune --quiet --env=prod >> var/log/prune.log 2>&1\n' "$1" "$PHP"
}

setup_cron() { # setup_cron <dir>
    [ "$SKIP_CRON" = 0 ] || return 0
    if command -v crontab >/dev/null && confirm "Add the background-worker and nightly-prune cron jobs now?" y; then
        # Replace only this folder's own jobs, never anything else in the crontab.
        local current table rc=0
        current="$(crontab -l 2>&1)" || rc=$?
        # "no crontab for user" is normal; any other failure must not end in replacing the whole table.
        if [ "$rc" -eq 0 ] || case "$current" in *[Nn]o\ crontab*) true ;; *) false ;; esac; then
            [ "$rc" -eq 0 ] || current=""
            table="$(printf '%s\n' "$current" | awk -v d="cd $1 && " 'index($0, d) && /messenger:consume|app:prune/ {next} {print}'; cron_lines "$1")"
            if printf '%s\n' "$table" | crontab -; then
                info "Cron jobs installed."; return
            fi
        fi
        warn "Could not edit the crontab automatically."
    fi
    warn "Add these cron entries yourself:"; cron_lines "$1"
}

# A new hosting folder already holds .htaccess/.user.ini/php.ini, so those (and folders) are OK.
check_install_dir() { # check_install_dir <dir>
    [ -d "$1" ] || return 0
    local found blockers
    found="$(find "$1" -mindepth 1 -maxdepth 1 -printf '%f\n' | sort)"
    [ -n "$found" ] || return 0
    blockers="$(find "$1" -mindepth 1 -maxdepth 1 ! -type d ! -name '.*' ! -name php.ini -printf '%f\n' | sort)"
    if [ -n "$blockers" ]; then
        die "$1 is not empty (only folders, dot-files and php.ini are allowed). Found: $(printf '%s' "$blockers" | tr '\n' ' ')"
    fi
    warn "$1 is not empty; installing anyway. Existing files kept: $(printf '%s' "$found" | tr '\n' ' ')"
}

# ---- modes -----------------------------------------------------------------------------------

do_install() {
    [ -n "$DIR" ] || DIR="$(ask "Install folder" "$HOME/symfony-auth-boilerplate")"
    check_install_dir "$DIR"
    if [ -z "$DOMAIN" ]; then DOMAIN="$(ask "Public domain name (e.g. app.example.com)" "")"; fi
    DOMAIN="$(printf '%s' "$DOMAIN" | sed -E 's#^https?://##; s#/.*$##')"
    printf '%s' "$DOMAIN" | grep -Eq '^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?\.[A-Za-z]{2,}$' || die "\"$DOMAIN\" is not a valid domain name."

    resolve_token
    fetch_latest_release
    download_release
    check_platform "$RELEASE_DIR"
    mkdir -p "$DIR"
    # Merge into $DIR (it may hold starter files); keep any file already there.
    local f
    for f in $(find "$DIR" -mindepth 1 -maxdepth 1 ! -type d -printf '%f\n'); do rm -f "${RELEASE_DIR:?}/$f"; done
    cp -a "$RELEASE_DIR"/. "$DIR"/
    DIR="$(cd "$DIR" && pwd)"
    # public/.htaccess is untracked per server (the Htaccess Lock edits it), so seed it from the template once.
    [ -f "$DIR/public/.htaccess" ] || cp "$DIR/config/deploy/public.htaccess" "$DIR/public/.htaccess"
    write_env_local "$DIR" "$DOMAIN"
    finalize_app "$DIR"

    local email="$ADMIN_EMAIL"
    if [ -z "$email" ] && [ "$YES" = 0 ] && confirm "Create the first tech-support admin now?" y; then
        email="$(ask "Admin email" "")"
    fi
    if [ -n "$email" ]; then
        console "$DIR" app:create-tech-support --email="$email" || warn "Admin creation failed; run: cd $DIR && $PHP bin/console app:create-tech-support --env=prod"
    else
        warn "Create one later: cd $DIR && $PHP bin/console app:create-tech-support --env=prod"
    fi
    setup_cron "$DIR"

    cat <<EOF

Installed $LATEST_VERSION in $DIR

Left for you to do by hand:
  1. Point the Document Root of $DOMAIN at:  $DIR/public   (and enable HTTPS)
  2. Set a real MAILER_DSN / MAILER_FROM in $DIR/.env.local, then: cd $DIR && $PHP bin/console dotenv:dump prod --env=prod
  3. Behind a proxy/CDN? trusted_proxies lives in config/packages/framework.yaml (README checklist); updates replace that
     tracked file, so re-apply the edit after each update.
  4. Open https://$DOMAIN/admin
EOF
}

# ---- update helpers --------------------------------------------------------------------------

abs_path() { printf '%s/%s' "$(cd "$(dirname "$1")" && pwd)" "$(basename "$1")"; }

acquire_lock() {
    local lock="${DIR:?}.update.lock" pid
    if ! mkdir "$lock" 2>/dev/null; then
        pid="$(cat "$lock/pid" 2>/dev/null || true)"
        if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then die "Another update is already running (pid $pid)."; fi
        warn "Removing a stale update lock."
        rm -rf "${lock:?}"; mkdir "$lock" || die "Could not take the update lock."
    fi
    LOCKDIR="$lock"; echo $$ > "$lock/pid"
}

require_installed() {
    [ -f "$DIR/bin/console" ] && [ -f "$DIR/.env.local" ] || die "$DIR is not an installation (no bin/console / .env.local)."
    [ -f "$DIR/$(db_relpath "$DIR")" ] || die "$DIR has no database: an earlier install did not finish. Empty the folder and install again."
}

# A consistent copy of a live SQLite file on any SQLite >= 3.26 (VACUUM INTO needs 3.27, so it is not used):
# hold the write lock so no commit or checkpoint can run, then copy the database and its WAL together.
copy_db() { # copy_db <src> <dest>
    "$PHP" -r '
        $p = new PDO("sqlite:" . $argv[1]); $p->exec("PRAGMA busy_timeout = 5000");
        $p->exec("BEGIN IMMEDIATE");
        $ok = copy($argv[1], $argv[2]) && (!is_file($argv[1] . "-wal") || copy($argv[1] . "-wal", $argv[2] . "-wal"));
        $p->exec("ROLLBACK");
        exit($ok ? 0 : 1);' "$1" "$2" || die "Could not copy the database $1."
}

backup_dbs() { # backup_dbs <dest dir>
    local rel; rel="$(db_relpath "$DIR")"
    mkdir -p "$1"; chmod 700 "$1"
    cp -p "$DIR/.env.local" "$DIR/.env.local.php" "$1/" 2>/dev/null || true
    [ ! -f "$DIR/$rel" ] || copy_db "$DIR/$rel" "$1/$(basename "$rel")"
}

restore_dbs() { # restore_dbs <backup dir>
    local rel name; rel="$(db_relpath "$DIR")"; name="$(basename "$rel")"
    [ -f "$1/$name" ] || return 0
    rm -f "${DIR:?}/$rel-wal" "${DIR:?}/$rel-shm"
    cp -p "$1/$name" "$DIR/$rel" || return 1
    if [ -f "$1/$name-wal" ]; then cp -p "$1/$name-wal" "$DIR/$rel-wal" || return 1; fi
}

preflight() {
    local rel need_kb avail_kb
    [ -w "$(dirname "$DIR")" ] || die "$(dirname "$DIR") is not writable, so the code folder cannot be swapped."
    need_kb=$(( $(du -sk --exclude=backups "$DIR" | cut -f1) * 2 + 102400 ))
    avail_kb="$(df -Pk "$(dirname "$DIR")" | awk 'NR==2 {print $4}')"
    [ "$avail_kb" -ge "$need_kb" ] || die "Not enough free disk space (need about $((need_kb / 1024)) MB, have $((avail_kb / 1024)) MB)."
    rel="$(db_relpath "$DIR")"
    if [ -f "$DIR/$rel" ]; then
        "$PHP" -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec("PRAGMA busy_timeout = 5000"); exit($p->query("PRAGMA quick_check")->fetchColumn() === "ok" ? 0 : 1);' "$DIR/$rel" \
            || die "SQLite integrity check failed for $(basename "$rel"); refusing to update a damaged database."
    fi
}

# var/maintenance.json makes public/index.php answer 503 before the Kernel boots (App\Maintenance\MaintenanceGate); it self-expires.
maintenance_on() { # maintenance_on <message>
    mkdir -p "$DIR/var"; MAINT=1
    RBX_M_FILE="$DIR/var/maintenance.json" RBX_M_ALLOW="${RBX_ALLOW_IP:-}" RBX_M_MSG="$1" "$PHP" -r '
        $now = time();
        file_put_contents(getenv("RBX_M_FILE"), json_encode([
            "message" => getenv("RBX_M_MSG"), "since" => $now, "expires" => $now + 1800,
            "allow_ips" => array_values(array_filter(explode(",", getenv("RBX_M_ALLOW")))),
        ]), LOCK_EX);'
}
maintenance_off() { MAINT=0; rm -f "${DIR:?}/var/maintenance.json"; }

# Moves var/'s entries one by one (never the dir) so the maintenance flag is always present in the live tree.
move_var_contents() { # move_var_contents <from var> <to var>
    local entry name; mkdir -p "$2"
    for entry in "$1"/* "$1"/.[!.]*; do
        [ -e "$entry" ] || continue
        name="$(basename "$entry")"
        if [ -d "$entry" ] && [ ! -L "$entry" ] && [ -d "$2/$name" ]; then
            move_var_contents "$entry" "$2/$name"; rmdir "$entry"   # merge; mv can't overwrite a non-empty dir
        else
            mv -f "$entry" "$2/"
        fi
    done
    return 0
}

# The newest <dir>.old-<stamp> that is really a release folder (never a sibling folder the user named .old-something).
find_interrupted() {
    local c old=""
    # the 14-digit stamp do_update writes
    for c in "${DIR:?}".old-[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]; do [ -f "$c/bin/console" ] && old="$c"; done
    printf '%s' "$old"
}

# Restores the DB backup an in-flight update recorded (never "the newest one"); the marker goes only afterwards, so a restore that dies can be retried.
restore_inflight() {
    local backup
    backup="$(cat "$DIR/var/backups/.inflight" 2>/dev/null || true)"
    if [ -n "$backup" ] && [ -d "$backup" ]; then
        restore_dbs "$backup" || return 1   # explicit: callers in a trap run with errexit off, and the marker must outlive a failed restore
        # A restored backup belongs to a failed attempt; keep it, but not as a `rollback` target.
        mv "$backup" "$(dirname "$backup")/failed-$(basename "$backup")" || return 1
    fi
    rm -f "${DIR:?}/var/backups/.inflight"
}

# Put the previous code back and restore its DB (only ever used while maintenance blocks all writes).
rollback_swap() { # rollback_swap <old code dir>
    local old="${1:?}"
    [ ! -d "$DIR/var" ] || move_var_contents "$DIR/var" "$old/var"
    rm -rf "${DIR:?}"; mv "$old" "$DIR"
    restore_inflight
    rm -rf "${DIR:?}/var/cache"
    console "$DIR" cache:clear >/dev/null 2>&1 || true
}

# A kill mid-swap leaves <dir>.old-*; the next run restores it before doing anything else.
recover_interrupted() {
    local old; old="$(find_interrupted)"
    if [ -n "$old" ]; then
        warn "A previous update was interrupted; restoring the previous version first."
        rollback_swap "$old"
        maintenance_off
    elif [ -f "$DIR/var/backups/.inflight" ]; then
        warn "A previous update died while restoring its backup; finishing that first."
        restore_inflight
        maintenance_off
    fi
}

# Runs the new release against a COPY of the database while the live site keeps serving.
rehearse() { # rehearse <new code dir>
    local new="${1:?}" rel; rel="$(db_relpath "$DIR")"
    mkdir -p "$new/var"; cp -p "$DIR/.env.local" "$new/.env.local"
    [ ! -f "$DIR/$rel" ] || copy_db "$DIR/$rel" "$new/$rel"
    ( finalize_app "$new" && health_check "$new" ) || return 1
    rm -rf "${new:?}/var" "${new:?}/.env.local.php"
}

do_update() {
    [ -n "$DIR" ] || DIR="$(ask "Existing install folder" "$PWD")"
    DIR="$(abs_path "$DIR")"
    acquire_lock
    recover_interrupted
    require_installed
    db_relpath "$DIR" >/dev/null

    resolve_token   # after recovery: that needs no network, and .env.local may only exist again once it has run
    info "Checking for updates"
    # An instance without a VERSION file counts as 0.0.0 and is upgraded like any other.
    local current; current=""; [ ! -f "$DIR/VERSION" ] || current="$(tr -d '[:space:]' < "$DIR/VERSION")"; current="${current:-0.0.0}"
    fetch_latest_release
    if ! "$PHP" -r "exit(version_compare('$LATEST_VERSION', '$current', '>') ? 0 : 1);"; then
        info "Already up to date ($current)."; return
    fi
    info "Updating $current -> $LATEST_VERSION"

    local stamp new old backup f
    stamp="$(date +%Y%m%d%H%M%S)"; new="$DIR.new-$stamp"; old="$DIR.old-$stamp"
    backup="$DIR/var/backups/pre-$LATEST_VERSION-$stamp"

    info "Checking disk space and database health"
    preflight

    EXTRACT_ROOT="$DIR.extract-$stamp"
    download_release
    STAGING_DIR="$new"
    mv "$RELEASE_DIR" "$new"; rm -rf "${EXTRACT_ROOT:?}"; EXTRACT_ROOT=""
    check_platform "$new"

    info "Rehearsing $LATEST_VERSION on a copy of the database (site still live)"
    rehearse "$new" || die "The rehearsal of $LATEST_VERSION failed. Nothing was changed; the live site was never touched."

    info "Maintenance mode on"
    maintenance_on "Updating to $LATEST_VERSION"
    console "$DIR" messenger:stop-workers >/dev/null 2>&1 || true
    sleep 3

    info "Backing up the database"
    backup_dbs "$backup"
    echo "$backup" > "$DIR/var/backups/.inflight"

    info "Swapping in $LATEST_VERSION"
    mkdir -p "$new/var" "$new/public"; cp -p "$DIR/var/maintenance.json" "$new/var/"
    # Instances that predate the installer have no token yet; later updates need one.
    if ! grep -q '^RBX_UPDATE_TOKEN=' "$DIR/.env.local"; then
        [ -z "$(tail -c1 "$DIR/.env.local")" ] || echo >> "$DIR/.env.local"   # never glue it onto an unterminated last line
        printf 'RBX_UPDATE_TOKEN=%s\n' "$TOKEN" >> "$DIR/.env.local"
    fi
    # Per-server files that are not in the release travel with the swap.
    for f in $CARRY_FILES $(cd "$DIR" && ls -1 .env.*.local 2>/dev/null || true); do
        [ -f "$DIR/$f" ] || continue
        mkdir -p "$new/$(dirname "$f")"; cp -p "$DIR/$f" "$new/$f"
    done
    OLD_DIR="$old"
    mv "$DIR" "$old"; mv "$new" "$DIR"; STAGING_DIR=""
    move_var_contents "$old/var" "$DIR/var"
    # The old release's compiled container must never serve the new code (it would miss new services/commands).
    rm -rf "${DIR:?}/var/cache"

    if ! ( finalize_app "$DIR" && health_check "$DIR" ); then
        warn "Update failed; rolling back to $current"
        rollback_swap "$old"
        maintenance_off
        die "Update to $LATEST_VERSION failed and was rolled back to $current."
    fi

    COMMITTED=1
    rm -rf "${DIR:?}.previous"; mv "$old" "$DIR.previous"; OLD_DIR=""
    rm -f "${DIR:?}/var/backups/.inflight"
    maintenance_off
    ls -1dt "$DIR"/var/backups/pre-[0-9]* 2>/dev/null | tail -n +4 | xargs -r rm -rf
    info "Updated to $LATEST_VERSION. DB backup: $backup (previous code kept for: rbx-install.sh rollback)"
}

do_rollback() {
    [ -n "$DIR" ] || DIR="$(ask "Existing install folder" "$PWD")"
    DIR="$(abs_path "$DIR")"
    acquire_lock
    recover_interrupted
    require_installed
    [ -d "$DIR.previous" ] || die "No previous release is kept next to $DIR, so there is nothing to roll back to."
    local previous stamp bad backup
    previous="earlier release"; [ ! -f "$DIR.previous/VERSION" ] || previous="$(tr -d '[:space:]' < "$DIR.previous/VERSION")"
    stamp="$(date +%Y%m%d%H%M%S)"; bad="$DIR.rolledback-$stamp"
    [ "$YES" = 1 ] || confirm "Roll back to $previous? Data saved since the update is lost (the DB returns to its pre-update backup)." n || die "Rollback cancelled."

    backup="$(ls -1dt "$DIR"/var/backups/pre-[0-9]* 2>/dev/null | head -n1 || true)"
    [ -n "$backup" ] || die "No pre-update database backup found; refusing to roll back."

    maintenance_on "Rolling back to $previous"
    backup_dbs "$DIR/var/backups/rollback-$stamp"

    info "Rolling back to $previous"
    BAD_DIR="$bad"
    mv "$DIR" "$bad"; mv "$DIR.previous" "$DIR"; BAD_DIR=""
    move_var_contents "$bad/var" "$DIR/var"
    restore_dbs "$backup"; rm -rf "${DIR:?}/var/cache"
    # The restored release keeps its own compiled env, so only its cache is cleared.
    console "$DIR" cache:clear >/dev/null 2>&1 || warn "cache:clear failed on the restored release; clear var/cache by hand."
    rm -rf "${bad:?}"; maintenance_off
    info "Rolled back to $previous."
}

main() {
    parse_args "$@"
    WORK="$(mktemp -d)"
    find_php
    check_tools
    [ -z "$DIR" ] || DIR="${DIR/#\~/$HOME}"
    if [ "$MODE" = auto ]; then
        [ -n "$DIR" ] || DIR="$(ask "Install folder" "$HOME/symfony-auth-boilerplate")"
        DIR="${DIR/#\~/$HOME}"
        # An interrupted swap leaves <dir>.old-* (and maybe no <dir>); that is an update to recover, never a fresh install.
        if { [ -f "$DIR/bin/console" ] && [ -f "$DIR/.env.local" ]; } || [ -n "$(find_interrupted)" ]; then MODE=update; else MODE=install; fi
    fi
    case "$MODE" in install) do_install ;; update) do_update ;; rollback) do_rollback ;; esac
}

main "$@"
