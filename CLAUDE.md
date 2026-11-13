# Claude Code Project Rules

## Mission

Build the application described in `docs/SPEC.md`.

The project is not complete until every feature in `.agent/feature_list.json` has `"passes": true`, `bin/verify-fast.sh` passes, and the reviewer pass approves the current change.

## Working Rules

- Work on one feature at a time.
- Keep diffs small and reviewable.
- Prefer test-first development.
- Write meaningful tests for behavior, not private implementation details.
- Do not mark a feature complete unless `bin/verify-fast.sh` passes.
- Do not skip, delete, weaken, or fake tests.
- Do not touch production `.env`, production databases, deployment keys, live servers, or real customer data.
- Use local/test environment only.
- If a requirement is ambiguous, record it in `.agent/OPEN_QUESTIONS.md`.
- If an architecture/product decision is made, record it in `.agent/DECISIONS.md`.
- Use `.agent/SCRATCHPAD.md` only for the current feature. It is temporary working memory, not permanent truth.
- Append durable progress to `.agent/claude-progress.txt`.

## Platform Constraints (read before writing SQL, migrations, DB or server config)

- **Database: SQLite only — and production runs SQLite 3.26.0.** Never MySQL/MariaDB/PostgreSQL (a non-SQLite
  `DATABASE_URL` fails at the first connection). The dev/CI sandbox has a NEWER SQLite (3.45), so a green test
  run here does **not** prove 3.26.0 compatibility. Do not use anything added after 3.26.0: `ALTER TABLE …
  DROP COLUMN` (3.35), `RETURNING` (3.35), `UPDATE … FROM` (3.33), generated columns (3.31), `iif()` (3.32),
  `STRICT` tables (3.37), `->`/`->>` and `unixepoch()` (3.38), `RIGHT`/`FULL JOIN` (3.39). To change a table,
  use Doctrine's rebuild idiom (create copy, drop, rename), never `DROP COLUMN`.
- **Verifying against 3.26.0:** build the real thing (`sqlite-amalgamation-3260000.zip` from
  `https://www.sqlite.org/2018/`, `gcc shell.c sqlite3.c -lpthread -ldl -lm -o sqlite326`) and feed it
  `php bin/console doctrine:migrations:migrate --dry-run --write-sql=out.sql` output. Do this for any new
  migration or raw SQL that is not plain ANSI.
- **Connection baseline — every SQLite connection the app opens must run it:** `journal_mode = WAL`,
  `busy_timeout = 5000`, `foreign_keys = ON`, `locking_mode = NORMAL`, `synchronous = FULL` (ADR-056/061).
  The single definition is `App\Doctrine\SqliteConnectionBaseline`. Doctrine connections get it via
  `SqlitePragmaMiddleware`; the session handler opens its own separate PDO, so it gets it via
  `SqlitePdoFactory`. Never write `new PDO('sqlite:…')` directly. The session DSN **is** `DATABASE_URL`.
- **Migrations run with `foreign_keys = OFF`** (`SqliteMigrationForeignKeyGuard`, automatic). With it ON,
  rebuilding a parent table such as `user` cascade-deletes every satellite row (2FA, lockouts, password meta,
  IP whitelist) — proven on 3.26.0. Never run migrations with `--all-or-nothing` (the guard refuses it).
- **Web server: LiteSpeed/Apache reading `.htaccess`. No nginx.** Anything that edits `.htaccess` (Htaccess
  Lock, ADR-059) must be Apache-2.4-compatible `Require` syntax; nginx/Caddy ignore it.
- **API docs are contract-tested.** `docs/api/openapi.yaml` is held to the real routes and responses by
  `OpenApiSpecTest`, `OpenApiContractTest` and `ApiDocsCest` (ADR-060). Add or change an `/admin-api` or `/api`
  endpoint and you must update the spec and add its scenario in the same change, or the build is red.
  Endpoints only tech support may call carry `x-audience: tech-support` (and `#[IsGranted('ROLE_TECH_SUPPORT')]`);
  they are hidden from every other admin's docs, and a test ties the marker to the real role.
- **One gate for the Htaccess Lock (ADR-062).** All reads/changes of the lock — admin page, API, console — go
  through `App\Htaccess\HtaccessLockGate` (validation, lockout guard, file write, audit, change event happen
  only there). Never inject `HtaccessLockManager`/`HtaccessFile`/the validator outside `src/Htaccess/`, never
  write `admin.htaccess_lock_*` audit rows by hand; `HtaccessLockGateArchitectureTest` enforces it. New lock
  capability = a new gate method, then thin adapters.

## Test Strategy

<!-- Customize this section for your stack. Examples below are for a generic web app. -->

### Unit tests

Use unit tests for:
- pure services and utilities
- validators and value objects
- permission rules
- business calculations
- data transformation

### Integration / Functional tests

Use integration tests for:
- routes and controllers
- form handling
- security and permissions
- service integration
- database-backed workflows

### Acceptance / E2E tests

Use acceptance tests for real user workflows:
- login / auth flows
- create/edit/delete important records
- permissions
- major forms and error states

## Anti-Cheat Rules

Forbidden:
- `test.skip`
- `it.skip`
- `describe.skip`
- `markTestSkipped`
- `xit(` / `xdescribe(`
- returning early from tests to hide failure
- tests with no real assertions
- deleting features to make tests pass
- changing test expectations to match broken behavior
- placeholder controllers/views that do not implement the spec
- fake "success" output without real verification

## Verification

Use:

```bash
bin/verify-fast.sh
```

Definition of done for a feature:

1. Tests were added or updated.
2. `bin/verify-fast.sh` passes.
3. Reviewer pass returns `REVIEW_PASS`.
4. `.agent/feature_list.json` is updated with evidence.
5. `.agent/claude-progress.txt` is appended.
6. The work is committed.
