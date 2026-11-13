# auth-ip-whitelist-bundle

IP allow-listing for form logins — gates user and admin authentication by client IP, with an optional per-user override.

[← Back to main README](../../README.md)

## Overview

This bundle restricts who may log in by client IP address. It hooks into Symfony's
authentication flow via a `CheckPassportEvent` listener and runs only for
`FormLoginAuthenticator` attempts (interactive logins) — token/API or magic-link
authentication is not affected.

Two independent global allow-lists are configured from the admin Config UI:

- `ip_whitelist.user_ips` — applies to logins on the **user** firewall.
- `ip_whitelist.admin_ips` — applies to logins on the **admin** firewall.

Which list applies is decided by the request path: a path starting with `/admin`
is treated as an admin login, everything else as a user login.

For user logins there is an additional **per-user override**: if the authenticating
`User` has a non-empty `allowedIps` value, that value replaces the global user list
for that user. Admin logins have no per-user override.

An empty list (the default) means "allow all" — the gate is a no-op until an
administrator configures at least one entry. Lists are comma-separated and support
both plain IPs and CIDR ranges (e.g. `192.168.1.0/24,10.0.0.1`), matched with
Symfony's `IpUtils::checkIp`. When the client IP is not allowed, the login fails with
a `CustomUserMessageAuthenticationException` carrying the message
`Login from your IP address is not allowed.`

## Configuration

Config page slug: **`ip-whitelist`** (title "IP Whitelist"), provided by
`App\Config\IpWhitelistConfigPage`. It surfaces under `/admin/config` and is editable
at `/admin/config/ip-whitelist`. Values are persisted as `config` rows and read at
login time through `ConfigService::getString(...)`.

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `ip_whitelist.user_ips` | ip_list | `''` (empty) | Comma-separated IPs/CIDRs allowed to log in on the user firewall; every entry is validated on save. Empty = allow all. Overridden per-user by `User.allowedIps`. |
| `ip_whitelist.admin_ips` | ip_list | `''` (empty) | Comma-separated IPs/CIDRs allowed to log in on the admin firewall. Empty = allow all. No per-user override. Every entry is validated on save, and a non-empty list must include the saving admin's own IP (lockout guard). |

There is in addition a per-user column on the `User` entity:

- **`User.allowedIps`** (`#[ORM\Column(type: 'text', nullable: true)]`, default `null`) —
  a per-user comma-separated IP/CIDR list. When non-null and non-empty it **replaces**
  the global `ip_whitelist.user_ips` list for that user during a user-firewall login.
  Accessors: `User::getAllowedIps(): ?string` and `User::setAllowedIps(?string $allowedIps): static`.

  Editable from the **admin user-edit form** (`/admin/users/{id}/edit`, the *Allowed IPs*
  field) — read and persisted by `AdminUserController::edit` (blank clears it to `null`).
  Can also be set programmatically / via DBAL. The column was added by migration
  `Version20260530260000`.

## Services & classes

### `App\EventListener\IpWhitelistListener`

Registered with `#[AsEventListener(event: CheckPassportEvent::class, priority: 100)]`.
Constructor dependencies: `ConfigService $configService`, `RequestStack $requestStack`,
`UserRepository $userRepository`.

- **`__invoke(CheckPassportEvent $event): void`** — the gate. Returns early (no
  enforcement) unless `$event->getAuthenticator()` is a `FormLoginAuthenticator`, and
  again if there is no current request. Resolves the client IP from the current request
  (`getClientIp()`, falling back to `'0.0.0.0'` if unavailable). Detects the firewall by
  path: if `getPathInfo()` starts with `/admin`, it checks the IP against
  `ip_whitelist.admin_ips` and returns. Otherwise (user firewall) it starts from
  `ip_whitelist.user_ips`, then — if the passport carries a `UserBadge` — looks up the
  user by the badge's identifier (email) via `UserRepository::findByEmail()` and, when
  that user has a non-empty `getAllowedIps()`, uses that as the effective list instead.
  Finally delegates to `checkIp()`.

- **`checkIp(string $ip, string $whitelist): void`** *(private)* — no-op when the
  whitelist is `''` or trims to nothing. Otherwise splits the list on commas, trims and
  drops empty entries, and calls `IpUtils::checkIp($ip, $allowed)`. If the IP does not
  match any entry it throws
  `CustomUserMessageAuthenticationException('Login from your IP address is not allowed.')`,
  which aborts the login.

### `App\Config\IpWhitelistConfigPage` (implements `ConfigPageProviderInterface`)

- **`getSlug(): string`** — returns `'ip-whitelist'`.
- **`getTitle(): string`** — returns `'IP Whitelist'`.
- **`getFields(): array`** — returns the two editable config fields
  (`ip_whitelist.user_ips`, `ip_whitelist.admin_ips`), each `type: 'ip_list'` (the admin one with `require_client_ip`) with an empty
  default, plus their admin-facing labels (which document CIDR support and the
  "empty = allow all" semantics).

## How it works

1. A form login is submitted. Symfony builds a passport and dispatches
   `CheckPassportEvent`. `IpWhitelistListener::__invoke` runs at priority 100.
2. The listener ignores anything that is not a `FormLoginAuthenticator`, so API token
   logins (`TokenAuthenticator`), admin API tokens, magic links and impersonation are
   never IP-gated here.
3. **Firewall detection is path-based**, not firewall-name based: a request path under
   `/admin` is the admin login and uses `ip_whitelist.admin_ips`; any other path is the
   user login and uses `ip_whitelist.user_ips`.
4. **Per-user override (user firewall only):** the passport's `UserBadge` identifier
   (the email) is resolved to a `User`; if `User::getAllowedIps()` is non-null and
   non-empty, it replaces the global user list for that login. Admin logins have no such
   override.
5. The effective list is matched with **`IpUtils::checkIp`**, which understands plain
   IPv4/IPv6 addresses and CIDR ranges. A non-match throws and the login fails with the
   "not allowed" message; a match (or an empty list) lets authentication continue.
6. **`X-Forwarded-For` is NOT trusted by default.** The client IP comes from
   `Request::getClientIp()`, which only honours forwarded headers when Symfony's
   `framework.trusted_proxies` is configured. Behind an untrusted proxy the gate sees the
   proxy's IP, not the real client — configure trusted proxies before relying on IP
   allow-listing in production.

## Security notes

- Enforcement is a hard fail at the passport-check stage: a blocked IP never completes
  authentication and never receives a session.
- The gate only constrains **interactive form logins**. It is not a network firewall and
  does not protect already-issued sessions, remember-me cookies, API tokens, or magic
  links.
- Without `trusted_proxies` set, forwarded-header spoofing is irrelevant (the headers are
  ignored) but the effective IP is the proxy's — meaning the list must contain the proxy,
  not the user. With `trusted_proxies` set, ensure only real proxies are trusted so
  `X-Forwarded-For` cannot be spoofed by clients.
- The per-user `allowedIps` override is more permissive than the global list for that
  user (it replaces, not intersects). A user with a per-user list can log in from IPs the
  global list would block.
- Empty lists are fail-open ("allow all"); the bundle adds no protection until configured.
- **Lockout recovery (issue #17):** saving refuses malformed entries (e.g. space-separated lists, `*`
  wildcards, `/33`) and an admin list that would not admit the admin saving it. If admins are locked out anyway
  (e.g. their IP changed), clear the list from the server shell: `php bin/console app:ip-whitelist:clear`
  (`--scope=admin` default, `user`, or `all`). It prints the previous value and is audited
  (`admin.ip_whitelist_clear`).

## Tests

- `tests/Functional/Security/IpWhitelistTest.php` — non-whitelisted IP rejected with the
  clear error; whitelisted IP (`127.0.0.1`) logs in and redirects to `/dashboard`;
  per-user `allowed_ips` override admits a user the global list excludes; admin and user
  lists enforced independently (user blocked, admin allowed, in the same run).
- `tests/Functional/Admin/IpWhitelistConfigPageTest.php` — the `ip-whitelist` sub-page
  appears in `/admin/config`, exposes both `user_ips` and `admin_ips` inputs, and saving
  the form persists both values to the `config` table.
- `tests/Acceptance/Auth/IpWhitelistCest.php` — end-to-end phpBrowser scenarios over live
  HTTP: non-whitelisted IP rejected, whitelisted IP proceeds, and the per-user override
  (seeded via `DatabaseHelper::setUserAllowedIps`) admits an otherwise-blocked user.
