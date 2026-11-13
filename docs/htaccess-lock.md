# Htaccess Lock

An **IP-whitelist-only lock enforced by the web server itself**, managed from the admin panel
(**Maintainer → Htaccess Lock**, tech-support only — not even superadmins can see or use it; ADR-059).

It edits a *managed block* at the top of `public/.htaccess`. Because the web server answers before PHP
starts, it covers the whole site — static files, the admin panel, the DB-console gateway — not just the
login form (contrast [`auth-ip-whitelist-bundle`](bundles/auth-ip-whitelist-bundle.md), which is an
application-level check on login only). Use it to keep internal/back-office instances off the open internet.

**Target server:** Apache 2.4 / LiteSpeed (anything that honours `.htaccess` and Apache 2.4 `Require`
authorization). Nginx and Caddy ignore `.htaccess` — the page would do nothing there, which is exactly what
the self-test below detects.

## The three settings

| # | Setting | Notes |
|---|---|---|
| 1 | **Lock on / off** (radio) | Off = nothing enforced, block removed from `.htaccess`; the other fields are kept. |
| 2 | **Allowed IPs** (textarea, one per line) | IPv4, IPv6, or CIDR (`203.0.113.0/24`). `/0` is rejected. `#` comment lines allowed. |
| 2 | **Exempt paths** (textarea, one per line) | URL paths with no domain, reachable from *any* IP (`/health`). Trailing `*` = prefix (`/webhooks/*`). `/*` is rejected. |
| 3 | **Status code** | `403`, or `404` (a real 404 status — looks like the site is not there). |
| 3 | **Error file** | A file under the web root shown to blocked visitors (e.g. `/blocked.html`). Blank = empty response. |

With **404** selected, the error file (or blank body) also applies to the web server's *own* 404s while the
lock is on (PHP-generated 404s are unaffected).

## Safety rails

- **Self-lockout guard.** Enabling is refused unless *your own IP* (as the application sees it, shown on the
  page) is covered by the whitelist. An empty whitelist is refused too.
- **Console recovery.** `php bin/console app:htaccess-lock:disable` removes the block from the shell if an
  admin's IP changes. (Or delete the lines between `# ###> app/htaccess-lock ###` and `# ###< app/htaccess-lock ###`.)
- **Only the managed block is touched.** Everything else in `.htaccess` (front-controller rewrites, etc.)
  is preserved byte-for-byte; a file with unbalanced markers is refused rather than guessed at. Writes are
  atomic and serialised.
- **No injection.** Every line is validated against a strict grammar (canonical IPs/CIDRs, a restricted URL-path
  alphabet, 403/404 only, error file must exist under the web root). A line like `1.2.3.4` + newline +
  `Require all granted` is rejected.
- **Audited.** Every change and self-test is written to the audit log — once, by the gate (see below).

## API (tech support only) and the one gate

Everything above is also available over the admin REST API — **tech support only** (a plain admin *and* a
superadmin both get `403`; the section is hidden from their API Docs page). Authenticate with an admin API token
(`php bin/console app:admin:create-api-token`); the calls are in **Admin → API Docs** ("Admin htaccess lock").

| Call | Does |
|---|---|
| `GET /admin-api/htaccess-lock` | Policy, `.htaccess` file health (`in_sync`), your IP as the server sees it |
| `PATCH /admin-api/htaccess-lock` | Change `ips`, `exempt_paths`, `status_code`, `error_file` (only the keys you send) |
| `POST /admin-api/htaccess-lock/enable` · `/disable` | Switch the lock on (guarded) or off |
| `GET` / `POST /admin-api/htaccess-lock/ips` | **List** / **add** a whitelisted IP (`{"ip": "203.0.113.0/24"}`) |
| `DELETE /admin-api/htaccess-lock/ips?ip=…` | **Remove** a whitelisted IP |
| `GET` / `POST` / `DELETE /admin-api/htaccess-lock/exempt-paths` | List / add (`{"path": "/health"}`) / remove (`?path=`) an exempt path |
| `GET` / `POST /admin-api/htaccess-lock/self-test` | Last self-test result / run it now (takes a few seconds; real visitors are blocked meanwhile) |

**One gate (ADR-062).** The admin page, the API and `app:htaccess-lock:disable` are thin adapters over a single
class, `App\Htaccess\HtaccessLockGate`. It alone authorizes, validates (incl. the self-lockout guard — adding or
removing one IP is checked exactly like saving the whole form), writes `.htaccess`, writes the audit row and
fires `HtaccessLockChangedEvent` — each exactly once per change. To react to a lock change (notify, webhook,
…) listen to that event; do not hook an adapter. An architecture test fails the build if anything outside
`src/Htaccess/` reaches past the gate, and a parity test proves the page and the API leave identical
`.htaccess`, config and audit rows.

## Enforcement self-test

A config-file lock fails **open** if the server ignores it (`AllowOverride None`, a proxy in front, an
unsupported directive) — you would never know. The self-test proves enforcement against the real server:

1. Writes a temporary block whitelisting only a reserved address and requests the site from the server itself
   over loopback (`127.0.0.1`) → must be **blocked**.
2. Same block with an exempt path → that path must **not** be blocked.
3. Inserts the server's own IP (`127.0.0.1`) → must be **allowed**.
4. Applies your configured status/file → the blocked response must use **your configured status**.
5. Restores the original `.htaccess` exactly, always — even if a step fails.

Real visitors are blocked for a few seconds while it runs. A failed step means do **not** rely on the lock.

## Operational notes

- The web-server user must be able to write `public/.htaccess` (the page says so if not). That is a real
  privilege: keep the file owned by the deploy user with group-write for the web user only, if you can.
- Behind a CDN/reverse proxy the server sees the *proxy's* address; `Require ip` and the self-lockout guard
  both use that same address, so they agree — but then the whitelist must contain the proxy, which defeats the
  point. Use this on servers that see real client IPs.
- Changes take effect within a second or two (LiteSpeed/Apache re-read `.htaccess` on change).
- PHPUnit runs no Apache, so the test suite pins the generated text and all the logic; real enforcement was
  verified against Apache 2.4 (status, bodies, CIDR, exemptions, rewrite coexistence) — see ADR-059.
