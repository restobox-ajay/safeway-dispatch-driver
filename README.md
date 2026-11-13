# FVST Express Driver — web app

The browser version of the FVST Express driver Android app, built on the Symfony Auth Boilerplate (below).
Drivers open **`/driver`** on their phone, log in with the same login and PIN as the Android app, and can
add it to the home screen. Every page and tap goes to the **fvstexpress-admin** driver API; this app stores
no orders or drivers. Full description: [docs/DRIVER_APP.md](docs/DRIVER_APP.md); the API contract:
[docs/admin-driver-api.md](docs/admin-driver-api.md).

### Setup

Add to `.env.local` (gitignored):

```dotenv
# The admin app's driver API — must end with /driver-api/v1/
DRIVER_API_BASE_URL=https://your-admin-domain/driver-api/v1/
# Only when the base URL is an IP address: the admin vhost name to send as the Host header
DRIVER_API_HOST_HEADER=
# "Call Dispatch" number until the admin API's /me sends one
DISPATCH_PHONE=+16045550100
# Test servers only: the hidden GPS tester in the menu (fake location pings). Leave unset in production.
DRIVER_GPS_TESTER=0
```

Then `php bin/console doctrine:migrations:migrate` (adds `driver_api_session`, where each signed-in driver's
admin API tokens are kept server-side) and open `/login` (the driver login; `/driver/login` redirects there). Staff sign in at `/staff/login`.

**On the admin server:** list this app's server in the admin app's `TRUSTED_PROXIES`. This app forwards each
driver's IP in `X-Forwarded-For`; without it the admin app sees every driver as this one server, so the
login rate limit (20 per 15 min per IP) is shared by the whole fleet.

**HTTPS is required** in production: phones only allow the camera, GPS, the service worker and "Add to home
screen" on HTTPS pages (and on `localhost`).

### Differences from the Android app

- GPS is sent only while the app is open on screen (browsers stop location in the background); while Online
  the app keeps the screen awake. Drivers who need full route tracking use the Android app.
- Taps, photos and GPS pings made without signal are kept in the browser and sent when the signal is back,
  once each (the same `Idempotency-Key`), like the Android offline queue.

---

# Symfony Auth Boilerplate

A minimal, auditable authentication foundation for Symfony 7.4. Small, hardened core; optional bundles you opt into. Less code in core = less attack surface, fewer bugs, easier audits.

It ships production-ready auth out of the box — separate user/admin identities, registration, login, password reset, email verification, session management, an admin panel, a token-authenticated admin REST API, and a full audit log. Everything else — 2FA, rate limiting, password policy, magic links, impersonation, IP whitelisting, personal access tokens, webhooks — is a separate bundle.

---

## 📚 Documentation

This README is the hub. Every feature is documented function-by-function on its own page.

### Core (always present)

| Page | What's in it |
|---|---|
| **[Core Reference →](docs/core.md)** | The two-entity model, all 9 core entities, the four firewalls & access control, every auth flow (registration, login, email verification, password reset, remember-me), account & session management, the admin panel, the `/admin-api` REST API, the audit log, login history & notifications, the configuration system, every core service/listener/command, and the security model. |
| [Specification](docs/SPEC.md) | Architecture decisions, bundle contracts, data model, non-goals. |

### Bundles (optional add-ons)

Each is independent — install only what your project needs. Every page is a full function-level reference (config keys, routes, controllers, services + every public method, entities, events, security notes, tests).

| Bundle | Description |
|---|---|
| [`auth-2fa-bundle`](docs/bundles/auth-2fa-bundle.md) | TOTP two-factor auth (replay-protected) with trusted devices and IP bypass |
| [`auth-magic-link-bundle`](docs/bundles/auth-magic-link-bundle.md) | Passwordless login via emailed magic link |
| [`auth-security-bundle`](docs/bundles/auth-security-bundle.md) | Login throttling (fail-closed), endpoint rate limiting, optional account lockout |
| [`auth-password-policy-bundle`](docs/bundles/auth-password-policy-bundle.md) | Length floor/ceiling, complexity, expiry, and no-reuse rules |
| [`auth-ip-whitelist-bundle`](docs/bundles/auth-ip-whitelist-bundle.md) | Restrict login to whitelisted IPs, per-role or per-user |
| [`auth-pat-bundle`](docs/bundles/auth-pat-bundle.md) | Personal access tokens for the user-side `/api` firewall |
| [`auth-impersonate-bundle`](docs/bundles/auth-impersonate-bundle.md) | Admin impersonation of users / super-admin of admins, fully audited |
| [`auth-webhook-bundle`](docs/bundles/auth-webhook-bundle.md) | Webhooks on auth events with SSRF-guarded delivery, retry, and logging |

**Also in core:** [Htaccess Lock](docs/htaccess-lock.md) — a tech-support-only, web-server-level IP-whitelist lock managed from the admin panel (writes a managed block of `.htaccess`; Apache/LiteSpeed).

---

## Requirements

- PHP 8.3+
- **SQLite ≥ 3.26.0** (production runs 3.26.0; the app is SQLite-only — see `CLAUDE.md` "Platform Constraints")
- Symfony 7.4
- Doctrine ORM on SQLite (every environment — see ADR-001 / ADR-013)
- A Symfony Mailer transport

## Quick start

```bash
composer create-project your-vendor/symfony-auth-boilerplate myapp
cd myapp
# set DATABASE_URL, MAILER_DSN, and APP_SECRET in .env.local (gitignored, never committed)
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
php bin/console app:create-superadmin --email=admin@example.com
symfony serve
```

`APP_SECRET` must be supplied via the environment (or an untracked `.env.*.local`), never committed.

### Local development (SQLite, no server setup)

This repo is wired to run locally at **http://auth.localhost** against a SQLite database — no HTTPS, no port juggling:

```bash
sudo bin/serve-auth-localhost.sh          # installs the Caddy site + /etc/hosts entry, chmods the SQLite db
```

Every environment uses SQLite — dev, test, acceptance and production alike. The single-writer
whole-DB lock is designed around rather than worked around (ADR-001 / ADR-013: throttled
session writes, `lock_mode: 0` on the session handler), so swapping in a networked engine is a
real change, not a config edit. See the Core Reference for the firewall and config details.

## Background worker (Messenger)

Notification **emails** and **webhook deliveries** are handled asynchronously via Symfony Messenger
so a slow/unreachable SMTP server or webhook endpoint can never hang or 500 a login (ADR-023). They
are enqueued on a **Doctrine transport** (`messenger_messages` table, same DB) and delivered by a
worker — **you must run one, or email/webhooks will silently queue and never send:**

```bash
# long-running worker (recommended: run under systemd / supervisor and auto-restart)
php bin/console messenger:consume async -vv

# or, if you prefer cron, a bounded run every minute:
* * * * * cd /path/to/app && php bin/console messenger:consume async --time-limit=55 --quiet
```

`MESSENGER_TRANSPORT_DSN` (in `.env`) selects the transport; it defaults to
`doctrine://default?auto_setup=0` (the `messenger_messages` table is created by a migration).
Delivery retries use Messenger's `retry_strategy` (exponential backoff, see
`config/packages/messenger.yaml`); messages that exhaust their retries land in the `failed`
transport and can be inspected/retried with `messenger:failed:show` / `messenger:failed:retry`.

## Maintenance (pruning)

A single unified command reclaims expired / retention-exceeded rows across every table that has a
registered pruner — the audit log, ephemeral reset/magic-link tokens, expired rows of the `sessions`
table itself plus dead session cross-references, terminal invitations, and the webhook delivery log
(bundle-owned pruners appear only when their bundle is installed). Schedule it via cron (ADR-048) — it is
what guarantees expired sessions are deleted, since PHP's own session GC depends on `php.ini`
(`session.gc_probability` is 0 on Debian/Ubuntu):

```bash
# preview what would be deleted, then prune for real
php bin/console app:prune --dry-run
php bin/console app:prune

# limit to specific pruners
php bin/console app:prune --only=audit_log --only=invitations

# nightly cron
0 3 * * * cd /path/to/app && php bin/console app:prune --quiet
```

Retention windows are admin-configurable (`audit_log.retention_days` 90, `invitation.retention_days`
30, `webhook.delivery_retention_days` 30). `login_history` / `password_history` are retained by policy
and never pruned (ADR-020).

The Messenger **failed** queue is deliberately **operator-owned**, not a pruner: inspect with
`messenger:failed:show`, retry with `messenger:failed:retry`, and remove dead-lettered messages with
`messenger:failed:remove` on your own schedule.

## Production deployment checklist

Work through this in order. Items marked **(silent if missed)** fail without an error message —
they're the ones that bite.

**1. Environment & secrets**
- [ ] `APP_ENV=prod`, strong random `APP_SECRET` — env or untracked `.env.local` only, never committed.
- [ ] `DATABASE_URL` → the SQLite file for the deploy, on persistent storage that survives
      redeploys, backed up, and NOT inside the deployment artifact. This project is SQLite-only;
      do not point it at PostgreSQL/MySQL without revisiting ADR-001 / ADR-013.
- [ ] `MAILER_DSN` → a real transport (password reset / verification / notifications all depend on it).
- [ ] `DEFAULT_URI`, `ADMIN_DOMAIN`, `APP_DOMAIN` set — **required**; the kernel refuses to boot
      without them, and they pin `trusted_hosts` against Host-header attacks (ADR-029).
      Single-domain deploys: set both domain vars to the same host.

**2. TLS & proxy** *(silent if missed)*
- [ ] TLS terminates at your proxy (Caddy/nginx); HSTS + security headers set there.
- [ ] Uncomment `trusted_proxies` in `config/packages/framework.yaml` and set your proxy CIDR.
      Without it the app sees plain HTTP, so session/remember-me/trusted-device cookies are
      **never marked `Secure`** and `cookie_secure: auto` silently does nothing.

**3. Database**
- [ ] `php bin/console doctrine:migrations:migrate`
- [ ] `php bin/console app:create-superadmin --email=...` (console is the only bootstrap path — no setup UI).
- [ ] `php bin/console app:create-tech-support --email=...` — provisions the maintainer admin tier
      (`ROLE_TECH_SUPPORT`, superadmin powers via role hierarchy). Use this instead of
      `app:create-superadmin` for the very first account on a new instance: it can create every
      other admin — including superadmins — from the UI afterward.
- [ ] `php bin/console app:admin:set-role --email=... --role=...` changes an EXISTING admin's role
      from the shell (`ROLE_ADMIN`, `ROLE_SUPER_ADMIN`, or `ROLE_TECH_SUPPORT`) — use this instead of
      editing the database by hand.

**4. Required background processes** *(silent if missed)*
- [ ] Messenger worker under systemd/supervisor: `messenger:consume async` — **without it, all
      email and webhooks queue forever and never send** (see [Background worker](#background-worker-messenger)).
- [ ] Nightly cron: `app:prune --quiet` (see [Maintenance](#maintenance-pruning)).
- [ ] Monitor the failed queue on your own schedule: `messenger:failed:show` / `:retry` / `:remove`.

**5. Dependencies & build**
- [ ] `composer audit` is clean (add it to CI — advisories land between releases).
- [ ] `composer install --no-dev --optimize-autoloader`, then `php bin/console cache:warmup`.

**6. Runtime config review** (`/admin/config` — defaults are safe but generic)
- [ ] Registration mode (open vs invitation-only).
- [ ] Rate limits & lockout, 2FA enforcement per role, password policy, retention windows.
- [ ] Webhook `block_internal_targets` stays **ON** unless you knowingly deliver to internal hosts.

**7. Backups & scale assumptions**
- [ ] Back up the DB and the env secrets **as one unit**. The deliberate no-TOTP-encryption decision
      (see [Security posture](#security-posture)) assumes key and data always share a backup — if you
      ever split them (managed DB snapshots, separate secret store), revisit ADR-018 first.
- [ ] Multi-host or networked-DB deployment? Revisit the documented single-host assumptions
      (ADR-013 rate-limit storage, ADR-018, ADR-030 race handling, ADR-048 no-locking pruning)
      before scaling out — they are load-bearing, not incidental.

**8. Before real users**
- [ ] Run `bin/verify-fast.sh` on the deploy artifact/branch — the full gate must be green.
- [ ] Commission an independent penetration test of the auth surface.

## Installing a bundle

```bash
composer require your-vendor/auth-2fa-bundle
php bin/console doctrine:migrations:migrate
```

The bundle auto-registers its admin config sub-page (via `ConfigPageProviderInterface` + Symfony autoconfigure) — no manual wiring. See [Configuration System](docs/core.md#configuration-system).

## Admin REST API

The admin API lives under **`/admin-api/*`** on a dedicated **stateless firewall that authenticates `Admin` entities only** (the `app_admins` provider via `AdminTokenAuthenticator`). A normal `User` — whatever roles it carries — can never reach it. Issue a token with:

```bash
php bin/console app:admin:create-api-token --email=admin@example.com   # expires in 365 days (--expires-in-days=N, --no-expiry)
# → use as:  Authorization: Bearer <token>
php bin/console app:admin:list-api-tokens [--email=…] [--active]       # id, owner, name, last 6 chars, status
php bin/console app:admin:revoke-api-token --id=12                     # or: --email=… --all
```

Every admin sees and revokes their own tokens at **API Tokens** in the panel (`/admin/api-tokens`); tech support can open
any admin's token page from **Manage Admins → API Tokens**. Only a hash is stored, so tokens are identified by their
last 6 characters. Deactivating or deleting an admin revokes its tokens for good (ADR-064).

This is distinct from [`auth-pat-bundle`](docs/bundles/auth-pat-bundle.md), which issues **user** tokens for the separate user-side `/api` firewall. Full endpoint list: [Admin REST API](docs/core.md#admin-rest-api). **Interactive docs:** admin panel → *API Docs* (any admin class), backed by the contract-tested OpenAPI spec [`docs/api/openapi.yaml`](docs/api/openapi.yaml) — a test calls every documented endpoint for real and fails if the docs and the code disagree.

## Testing

```bash
bin/verify-fast.sh          # the canonical gate: migrate test db, phpunit, codeception, anti-cheat scan
php bin/phpunit             # unit + functional only
php vendor/bin/codecept run # E2E (Codeception PhpBrowser — no JS engine required)
```

Tests run against SQLite — no database server needed. The suite is fully no-JS so E2E needs no headless browser.

---

## Architecture at a glance

- **Two fully separate identities.** `User` and `Admin` are distinct entities, tables, firewalls, providers, and login pages — no shared base class. Admin authorization is **entity/firewall based**, never a role string on a user. See [Two-Entity Model](docs/core.md#two-entity-model).
- **Four firewalls:** `admin_api` (`^/admin-api`, stateless, Admin tokens) → `admin` (`^/admin`, session) → `api` (`^/api`, user PATs) → `user` (session). Order matters; `admin_api` precedes `admin`.
- **Role single source of truth.** `User::ALLOWED_ROLES` / `Admin::ALLOWED_ROLES` enforce a default-deny allowlist in `setRoles()`, so no caller can escalate a `User` into admin space.
- **Config-page hook.** Bundles implement `ConfigPageProviderInterface`; autoconfigure tags them `auth.config_page`; the core injects all of them via `#[AutowireIterator]`. Each bundle's settings appear in the admin config UI automatically.
- **Optional separate-domain mode.** `ADMIN_DOMAIN` / `APP_DOMAIN` env vars switch the firewalls to host matchers; unset = single-domain (no change).

## Security posture

The codebase has been through a security review; notable hardening (all documented on the relevant pages):

- Admin API re-homed onto **entity-based auth** so a leaked/elevated user role can't reach it ([core](docs/core.md#admin-rest-api)).
- **Login throttling fail-closed** by default; rate limiting on forgot-password, magic-link, resend-verification, and the 2FA challenge — applied *before* CSRF so it can't be bypassed ([security bundle](docs/bundles/auth-security-bundle.md)).
- **CSRF** on every state-changing admin/account POST, including admin config save and forgot-password.
- **TOTP replay protection** (last-counter tracking) + constant-time compare ([2fa bundle](docs/bundles/auth-2fa-bundle.md)).
- **Webhook SSRF guard** — http/https only, private/reserved/loopback IPs rejected, no redirects ([webhook bundle](docs/bundles/auth-webhook-bundle.md)).
- Password reset **invalidates sibling reset tokens, active sessions, and remember-me cookies**.
- **Password policy hard floor** (min 8, enforced even with empty config) on every password-setting path including admin create.
- Session cookies pinned (`httponly`, `samesite=lax`, `secure=auto`); `APP_SECRET` kept out of version control.

> **Deliberately *not* implemented:** TOTP-secret-at-rest encryption. For this deployment shape (single host, SQLite file beside `.env`, no separate DB-backup pipeline) the key and ciphertext always share a backup, so at-rest encryption buys nothing. Revisit only on a networked DB with separately-handled backups.

## Project layout

```
src/
  Controller/        core + admin + Api/ (admin-api) controllers
  Entity/            User, Admin, Config, AuditLog, LoginHistory, UserSession,
                     Invitation, PasswordResetToken, AdminAccessToken, + bundle entities
  Security/          UserChecker, AdminTokenAuthenticator, authenticators, rate limiting
  Service/           AuditLogger, ConfigService, password policy, webhook dispatchers, TOTP
  Config/            ConfigPageProviderInterface + per-bundle config pages
  EventListener/     login history, notifications, sessions, audit, 2FA, IP whitelist, webhooks
  Command/           create-superadmin, create-tech-support, admin:set-role, admin:create-api-token, prune
config/packages/security.yaml   the four firewalls + access control
docs/                core.md + bundles/*.md (this documentation)
bin/verify-fast.sh   the test/lint/anti-cheat gate
```

## Releases

Versioning is automatic via [semantic-release](https://semantic-release.gitbook.io/) (`.github/workflows/release.yml`)
on every push to `master`, driven by [Conventional Commits](https://www.conventionalcommits.org/) (PR titles too, since
a squash-merge becomes the commit):

- `fix:` → patch
- `feat:` → minor
- `feat!:` / a `BREAKING CHANGE:` footer → major
- `chore:` / `docs:` (and anything non-conventional) → no release

A release prepends `CHANGELOG.md`, writes `VERSION`, commits both back as `chore(release): X.Y.Z [skip ci]`, tags
`vX.Y.Z` and publishes a GitHub Release with an install tarball (`symfony-auth-boilerplate-X.Y.Z.tar.gz`: tracked files
minus dev/CI paths plus production `vendor/`, built on PHP 8.2) and its `.sha256`. Never tag by hand. With no prior tag
the first release is `v1.0.0`. A weekly workflow deletes the assets (never the releases or tags) of all but the newest 10
releases (ADR-072).

## Installing and updating on a server (ADR-068 / ADR-069)

`bin/rbx-install.sh` needs only bash, curl, tar, sha256sum and PHP >= 8.2 with pdo_sqlite (the tarball already contains
`vendor/`; no git, Node, Composer or root):

```bash
# first install on a fresh server (token = a read-only token for this repo)
curl -fsSL -H "Authorization: Bearer $RBX_GITHUB_TOKEN" -H "Accept: application/vnd.github.raw" \
  https://api.github.com/repos/axcelmediacorp/symfony-auth-boilerplate/contents/bin/rbx-install.sh \
  | bash -s -- install --dir ~/app --domain app.example.com

# every later upgrade, one line (a no-op when already on the latest release)
bash ~/app/bin/rbx-install.sh update --dir ~/app --yes
```

Modes: `install | update | rollback | auto` (default; updates an existing install, installs otherwise); flags `--dir`,
`--domain`, `--php`, `--admin-email`, `--yes`, `--no-cron`. The token is read from `$RBX_GITHUB_TOKEN`, then
`RBX_UPDATE_TOKEN` in `<dir>/.env.local` (the installer saves it there), then the committed `BAKED_TOKEN` (a read-only,
single-repo fine-grained token written by `bin/bake-installer.sh`, ADR-071).

- **Install** verifies the checksum, writes `.env.local` (mode 600, generated `APP_SECRET`), migrates, offers to create the
  first tech-support admin and the cron jobs (Messenger worker, nightly `app:prune`), and prints what is left by hand:
  Document Root → `<dir>/public`, HTTPS, `MAILER_DSN`, trusted proxies.
- **Update** rehearses the new release on a copy of the SQLite DB while the site stays live, then turns on maintenance
  mode (a 503 served by `public/index.php` before the app boots; `$RBX_ALLOW_IP` keeps your IP through), backs up the DB,
  swaps the code (keeping `var/`, `.env.local`, `public/.htaccess`), migrates and health-checks. Any failure restores code
  and DB. The old code stays as `<dir>.previous`; the last 3 DB backups stay in `var/backups/`.
- **Rollback** (`rbx-install.sh rollback --dir <dir>`) restores `.previous` and the pre-update DB after confirmation;
  data written since the update is lost.

The updater supports only the default SQLite file under `var/`. Test it end to end against a fake GitHub API with
`bash tests/installer/e2e.sh` (slow; needs composer and network).

## License

MIT
