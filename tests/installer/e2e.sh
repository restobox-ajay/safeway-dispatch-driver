#!/usr/bin/env bash
# End-to-end test of bin/rbx-install.sh against a fake local GitHub API (ADR-068/069); never touches production.
# Builds a real release tarball from the git-tracked tree (stage new files with `git add` first), then exercises
# fresh install, update, no-op, rehearsal failure, failed migration after the swap + rollback, checksum mismatch,
# crash recovery, manual `rollback`, cron handling and the lock. Slow (~1-2 min); not part of verify-fast.sh.
# Needs composer + network for the tarball build.
#   COMPOSER_ALLOW_SUPERUSER=1 (root only, so Composer plugins run) PHP=/path/to/php RBX_COMPOSER_ARGS=--ignore-platform-req=ext-redis bash tests/installer/e2e.sh
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP="${PHP:-php}"
T="$(mktemp -d)"; REL="$T/releases"; SITE="$T/site"; mkdir -p "$REL" "$T/bin"
PASS=0; FAIL=0; SERVER_PID=""
trap '[ -z "$SERVER_PID" ] || kill "$SERVER_PID" 2>/dev/null; rm -rf "${T:?}" "$ROOT/dist"' EXIT

ok()   { PASS=$((PASS + 1)); printf '  ok   %s\n' "$1"; }
bad()  { FAIL=$((FAIL + 1)); printf '  FAIL %s\n' "$1"; tail -n 12 "$T/last.log" | sed 's/^/       | /'; }
check() { if eval "$2"; then ok "$1"; else bad "$1"; fi; }
inst() { PATH="$T/bin:$PATH" RBX_API_BASE=http://127.0.0.1:18791 RBX_REPO=x/y RBX_GITHUB_TOKEN=fake bash "$ROOT/bin/rbx-install.sh" --php "$PHP" "$@" >"$T/last.log" 2>&1; }
serve() { echo "$1" > "$REL/current"; }
sql() { "$PHP" -r '$p = new PDO("sqlite:" . $argv[1]); $r = $p->query($argv[2]); if ($r) { echo $r->fetchColumn(); }' "$SITE/var/data_prod.db" "$1"; }
canary() { sql "SELECT count(*) FROM canary"; }

# A stand-in crontab that keeps its table in a file.
cat > "$T/bin/crontab" <<EOS
#!/bin/sh
case "\$1" in -l) cat "$T/cron" 2>/dev/null ;; -) cat > "$T/cron" ;; esac
EOS
chmod +x "$T/bin/crontab"

echo "Building the release"
rm -rf "$ROOT/dist"
(cd "$ROOT" && bash bin/build-release.sh 9.9.9 >/dev/null 2>&1) || { echo "build failed"; exit 1; }
mv "$ROOT"/dist/* "$REL/"; rmdir "$ROOT/dist"
TAR="$REL/symfony-auth-boilerplate-9.9.9.tar.gz"
LIST="$(tar -tzf "$TAR")"
check "tarball has one top-level folder"      '[ "$(printf "%s\n" "$LIST" | cut -d/ -f1 | sort -u)" = symfony-auth-boilerplate-9.9.9 ]'
check "tarball ships prod vendor, VERSION and the OpenAPI spec" 'printf "%s\n" "$LIST" | grep -q "/vendor/autoload.php$" && printf "%s\n" "$LIST" | grep -q "/docs/api/openapi.yaml$" && printf "%s\n" "$LIST" | grep -q "/VERSION$"'
check "tarball has no dev dependencies or dev paths" '! printf "%s\n" "$LIST" | grep -qE "/vendor/phpunit/|/tests/|/\.agent/|/\.github/|/package\.json$|/CLAUDE\.md$"'
check "checksum file matches"                 '(cd "$REL" && sha256sum -c symfony-auth-boilerplate-9.9.9.tar.gz.sha256 >/dev/null)'

variant() { # variant <version> <shell snippet run inside the extracted release>
    local w="$T/w"; mkdir -p "$w"; tar -xzf "$TAR" -C "$w"
    mv "$w/symfony-auth-boilerplate-9.9.9" "$w/symfony-auth-boilerplate-$1"; echo "$1" > "$w/symfony-auth-boilerplate-$1/VERSION"
    (cd "$w/symfony-auth-boilerplate-$1" && eval "$2")
    tar -czf "$REL/symfony-auth-boilerplate-$1.tar.gz" -C "$w" "symfony-auth-boilerplate-$1"
    (cd "$REL" && sha256sum "symfony-auth-boilerplate-$1.tar.gz" > "symfony-auth-boilerplate-$1.tar.gz.sha256"); rm -rf "${w:?}"
}
MIG='<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20990102000000 extends AbstractMigration {
    public function isTransactional(): bool { return false; }
    public function up(Schema $schema): void {
        // Fails only against the live tree (the marker lives in the live var/), after already damaging the data.
        if (is_file(dirname(__DIR__) . "/var/fail-migration")) { $this->connection->executeStatement("DELETE FROM canary"); throw new \RuntimeException("boom"); }
        $this->addSql("SELECT 1");
    }
}'
variant 9.9.8 "true"
variant 9.9.10 "printf '<?php\nthis is not php;\n' > migrations/Version20990101000000.php"
variant 9.9.11 "printf '%s\n' \"\$MIG\" > migrations/Version20990102000000.php"
KILL='<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20990103000000 extends AbstractMigration {
    public function isTransactional(): bool { return false; }
    public function up(Schema $schema): void {
        // Live tree only: damage the data, then SIGTERM the installer (its pid is in the update lock) while it is mid-update.
        $marker = dirname(__DIR__) . "/var/kill-me";
        if (is_file($marker)) { $this->connection->executeStatement("DELETE FROM canary"); exec("kill -TERM " . (int) file_get_contents(dirname(__DIR__) . ".update.lock/pid")); }
        $this->addSql("SELECT 1");
    }
}'
variant 9.9.13 "printf '%s\n' \"\$KILL\" > migrations/Version20990103000000.php"
variant 9.9.12 "true"

FAKE_RELEASES_DIR="$REL" "$PHP" -S 127.0.0.1:18791 "$ROOT/tests/installer/router.php" >"$T/server.log" 2>&1 &
SERVER_PID=$!; sleep 1

echo "Install"
serve 9.9.8
printf '%s\n' '* * * * * unrelated-job' "* * * * * cd $SITE && php bin/console messenger:consume async --old" > "$T/cron"
inst install --yes --dir "$SITE" --domain app.example.com --admin-email t@example.com
check "installs 9.9.8"                    '[ "$(cat "$SITE/VERSION")" = 9.9.8 ]'
check "writes prod env, domains and update token" 'grep -q "^APP_ENV=prod" "$SITE/.env.local" && grep -q "^ADMIN_DOMAIN=app.example.com" "$SITE/.env.local" && grep -q "^RBX_UPDATE_TOKEN=fake" "$SITE/.env.local"'
check ".env.local is mode 600 with a generated secret" '[ "$(stat -c %a "$SITE/.env.local")" = 600 ] && grep -Eq "^APP_SECRET=[0-9a-f]{64}$" "$SITE/.env.local"'
check ".env.local.php is private"         '[ "$(stat -c %a "$SITE/.env.local.php")" = 600 ]'
check "migrated the database"             '[ "$(sql "SELECT count(*) FROM doctrine_migration_versions")" -ge 1 ]'
check "created the first admin"           '[ "$(sql "SELECT count(*) FROM admin WHERE email = '"'t@example.com'"'")" = 1 ]'
check "seeds public/.htaccess from the template" 'grep -q "RewriteEngine On" "$SITE/public/.htaccess"'
check "installs cron jobs, replacing only this folder's own" '[ "$(grep -c "messenger:consume" "$T/cron")" = 1 ] && grep -q "app:prune" "$T/cron" && grep -q unrelated-job "$T/cron" && ! grep -q -- "--old" "$T/cron"'
check "prints the manual leftovers"       'grep -q "Document Root" "$T/last.log" && grep -q MAILER_DSN "$T/last.log"'
check "refuses a non-empty folder"        '! inst install --yes --dir "$SITE" --domain app.example.com --no-cron'
CP="$T/hosting"; mkdir -p "$CP/cgi-bin"; echo "host htaccess" > "$CP/.htaccess"; echo y > "$CP/php.ini"
inst install --yes --dir "$CP" --domain app.example.com --no-cron
check "installs into a folder holding only dot-files, php.ini and folders" '[ "$(cat "$CP/VERSION")" = 9.9.8 ] && [ -d "$CP/cgi-bin" ] && [ "$(cat "$CP/php.ini")" = y ] && grep -q "host htaccess" "$CP/.htaccess"'
mkdir -p "$T/stray"; echo hi > "$T/stray/index.html"
check "refuses a folder with a stray file" '! inst install --yes --dir "$T/stray" --domain app.example.com --no-cron && grep -q "index.html" "$T/last.log"'
"$PHP" -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec("CREATE TABLE canary(v TEXT)"); $p->exec("INSERT INTO canary VALUES (1)");' "$SITE/var/data_prod.db"
echo keep > "$SITE/var/marker.txt"; echo "# server edit" >> "$SITE/public/.htaccess"

echo "Update and no-op"
inst update --yes --dir "$SITE"
check "no-op when already current"        'grep -q "Already up to date" "$T/last.log"'
# Make the install look older than the VERSION file, give it a stale compiled cache and three old DB backups.
rm -f "$SITE/VERSION"
sed -i '/^RBX_UPDATE_TOKEN=/d' "$SITE/.env.local"; printf '%s' "$(cat "$SITE/.env.local")" > "$SITE/.env.local"   # no token, no trailing newline
mkdir -p "$SITE/var/cache/prod"; echo stale > "$SITE/var/cache/prod/old-container"
for i in 1 2 3; do mkdir -p "$SITE/var/backups/pre-9.0.$i-2020010100000$i"; sleep 1; done
serve 9.9.9; inst update --yes --dir "$SITE"
check "updates to 9.9.9"                  '[ "$(cat "$SITE/VERSION")" = 9.9.9 ]'
check "upgrades an install with no VERSION file" 'grep -q "Updating 0.0.0 -> 9.9.9" "$T/last.log"'
check "keeps var/ contents and data"      '[ "$(cat "$SITE/var/marker.txt")" = keep ] && [ "$(canary)" = 1 ]'
check "re-adds the token on its own line, not glued to the last one" 'grep -qx "RBX_UPDATE_TOKEN=fake" "$SITE/.env.local" && grep -qx "# MAILER_FROM=noreply@app.example.com" "$SITE/.env.local"'
check "keeps the server's .htaccess edits and .env.local" 'grep -q "server edit" "$SITE/public/.htaccess" && grep -q "^APP_SECRET=" "$SITE/.env.local"'
check "never reuses the old compiled cache" '[ ! -e "$SITE/var/cache/prod/old-container" ]'
check "maintenance is off afterwards"     '[ ! -f "$SITE/var/maintenance.json" ]'
check "keeps previous code for rollback"  '[ -f "$SITE.previous/bin/console" ]'
check "keeps this update's DB backup and only the last 3" 'ls "$SITE/var/backups" | grep -q "^pre-9.9.9-" && [ "$(ls -d "$SITE"/var/backups/pre-[0-9]* | wc -l)" = 3 ]'

echo "Bad release is caught by the rehearsal"
serve 9.9.10
inst update --yes --dir "$SITE"; rc=$?
check "exits non-zero"                    '[ "$rc" -ne 0 ]'
check "live site untouched"               '[ "$(cat "$SITE/VERSION")" = 9.9.9 ] && [ ! -f "$SITE/var/maintenance.json" ] && [ "$(canary)" = 1 ]'
check "no staging dirs left behind"       '[ -z "$(ls -d "$SITE".new-* "$SITE".extract-* 2>/dev/null)" ]'

echo "Checksum mismatch"
serve 9.9.12; touch "$REL/corrupt"
inst update --yes --dir "$SITE"; rc=$?
check "refuses the tampered download"     '[ "$rc" -ne 0 ] && grep -q "Checksum mismatch" "$T/last.log"'
check "nothing was changed or left behind" '[ "$(cat "$SITE/VERSION")" = 9.9.9 ] && [ -z "$(ls -d "$SITE".new-* "$SITE".extract-* "$SITE".old-* 2>/dev/null)" ]'
rm -f "$REL/corrupt"

echo "Failed migration after the swap rolls back code and DB"
serve 9.9.11; touch "$SITE/var/fail-migration"
inst update --yes --dir "$SITE"; rc=$?
check "exits non-zero"                    '[ "$rc" -ne 0 ] && grep -q "rolled back" "$T/last.log"'
check "rolled back to 9.9.9"              '[ "$(cat "$SITE/VERSION")" = 9.9.9 ] && [ ! -f "$SITE/var/maintenance.json" ]'
check "DB restored although the migration had deleted rows" '[ "$(canary)" = 1 ] && [ "$(cat "$SITE/var/marker.txt")" = keep ]'
check "failed backup is not a rollback target" 'ls "$SITE/var/backups" | grep -q "^failed-pre-9.9.11"'
rm -f "$SITE/var/fail-migration"

echo "SIGTERM during an update restores code and DB and lifts maintenance"
serve 9.9.13; touch "$SITE/var/kill-me"
inst update --yes --dir "$SITE"; rc=$?
check "exits non-zero"                    '[ "$rc" -ne 0 ] && grep -q "Aborted during maintenance" "$T/last.log"'
check "back on 9.9.9, maintenance off"    '[ "$(cat "$SITE/VERSION")" = 9.9.9 ] && [ ! -f "$SITE/var/maintenance.json" ] && [ -z "$(ls -d "$SITE".old-* "$SITE".new-* 2>/dev/null)" ]'
check "data restored"                     '[ "$(canary)" = 1 ] && [ "$(cat "$SITE/var/marker.txt")" = keep ] && [ ! -f "$SITE/var/backups/.inflight" ]'
rm -f "$SITE/var/kill-me"

echo "Unsupported database"
cp "$SITE/.env.local" "$T/env.bak"; echo 'DATABASE_URL=mysql://u:p@h/db' >> "$SITE/.env.local"
serve 9.9.12; inst update --yes --dir "$SITE"; rc=$?
check "refuses a non-SQLite DATABASE_URL"  '[ "$rc" -ne 0 ] && grep -q "only supports the SQLite" "$T/last.log" && [ "$(cat "$SITE/VERSION")" = 9.9.9 ]'
cp "$T/env.bak" "$SITE/.env.local"

echo "Recovery from an interrupted swap"
mkdir -p "$SITE/var/backups/pre-9.9.12-interrupted"; cp -p "$SITE"/var/data_prod.db "$SITE/var/backups/pre-9.9.12-interrupted/"
echo "$SITE/var/backups/pre-9.9.12-interrupted" > "$SITE/var/backups/.inflight"
mv "$SITE" "$SITE.old-20200101000000"   # killed between the two swap moves: no live folder at all
mkdir "$SITE.old-backup"; echo precious > "$SITE.old-backup/note"   # a sibling the user named themselves is never mistaken for it
inst --yes --dir "$SITE"
check "auto mode recovers instead of installing fresh, then updates to 9.9.12" '[ "$(cat "$SITE/VERSION")" = 9.9.12 ] && [ -f "$SITE/bin/console" ] && [ "$(canary)" = 1 ]'
check "leaves a foreign .old-* sibling alone" '[ "$(cat "$SITE.old-backup/note")" = precious ]'
rm -rf "$SITE.old-backup"

echo "Manual rollback"
"$PHP" -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec("INSERT INTO canary VALUES (2)");' "$SITE/var/data_prod.db"
inst rollback --yes --dir "$SITE"
check "returns to the previous version"   '[ "$(cat "$SITE/VERSION")" = 9.9.9 ]'
check "restores the pre-update database"  '[ "$(canary)" = 1 ]'

echo "Lock"
mkdir "$SITE.update.lock"; echo $$ > "$SITE.update.lock/pid"
inst update --yes --dir "$SITE"; rc=$?
check "refuses while another update runs" '[ "$rc" -ne 0 ] && grep -q "already running" "$T/last.log"'
check "leaves the other lock alone"       '[ -d "$SITE.update.lock" ]'

echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
