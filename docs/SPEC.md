# Symfony Auth Boilerplate — Spec

## Non-Goals

- OAuth / social login (Google, GitHub, etc.)
- Multi-tenancy
- Frontend SPA (React, Vue, etc.) — server-rendered forms only
- Business logic — this is auth infrastructure, not an application
- Billing or payments
- i18n / localization

---

## Philosophy

Small, auditable core. Optional features are separate bundles installed per project.
Less code in core = less attack surface, fewer bugs, easier security audits.

---

## Architecture

| Layer | Role |
|---|---|
| **Boilerplate (core)** | Minimal auth flows, user management, admin separation |
| **Bundles (optional)** | Discrete feature addons — installed only when needed |
| **Symfony core** | PasswordHasher, Security, RateLimiter, EventDispatcher, Mailer, Webhook |
| **Third-party (thin-wrapped)** | `symfonycasts/verify-email-bundle` (email verification) |

### Principle: use Symfony built-ins, wrap don't reinvent

Where Symfony or its ecosystem already solves something well, use it directly. Our bundles provide config, UI, audit hooks, and admin integration — not reimplementations. Specifically:

- **Remember me** — Symfony Security `remember_me` firewall option. No custom code.
- **Email verification** — `symfonycasts/verify-email-bundle`. Core wraps it with config and UI.
- **2FA** — rolled from scratch. `TotpService` (RFC 6238, ~30 lines using `hash_hmac`), a custom Symfony authenticator for the challenge step, QR code rendered locally via `endroid/qr-code`. No third-party 2FA dependency, no external service calls.
- **Impersonation** — Symfony Security `switch_user`. `auth-impersonate-bundle` wraps it with UI, role rules, and audit logging.
- **Rate limiting** — Symfony `RateLimiter`. `auth-security-bundle` wires it to login with configurable thresholds.
- **Webhooks** — Symfony Webhook component (7.x). `auth-webhook-bundle` wires auth events to it with retry config and delivery logging.

### Config Page Hook

Each bundle can expose a config sub-page in the admin config section. Bundles register themselves by implementing `ConfigPageProviderInterface`. Symfony's autoconfigure auto-tags any implementing service with `auth.config_page`. The core injects all tagged providers via `#[AutowireIterator('auth.config_page')]` — no manual wiring required.

```
ConfigPageProviderInterface  →  implemented by bundle
    ↓ autoconfigure
auth.config_page tag         →  collected by core ConfigPageRegistry
    ↓ AutowireIterator
Admin config section         →  renders all registered sub-pages
```

All configurable values are stored in the database and editable via the admin config UI.

---

## Core Boilerplate

### Data Model

`user` and `admin` are fully separate database tables with no shared base class. Schema beyond that is left to the implementer.

### User Model

- **Fully separate** tables, entities, firewalls, and login pages for **admin** and **user** — no shared base class, complete isolation for security
- Both `User` and `Admin` have independent session tracking
- Sessions are stored in the database via Symfony's built-in `PdoSessionHandler` — required for "view active sessions" and "logout everywhere" to work. No file sessions.
- **Superadmin** role — full access, can manage all admins and users
- First superadmin provisioned via `bin/console app:create-superadmin --email=X`. If no `--password` is provided, a secure random password is generated and printed once to the console — it is not stored in plaintext anywhere.
- User status: **active / inactive**
- All admin pages under `/admin/` URL prefix — this is intentional: it allows the entire admin surface to be IP-whitelisted at the network or firewall level (Caddy, nginx, Symfony firewall) with a single rule
- **Optional: separate domains** — admin and user interfaces can be served from separate domains (e.g. `admin.example.com` vs `app.example.com`). Configured via `.env` (`ADMIN_DOMAIN`, `APP_DOMAIN`) — this is infrastructure-level config, not something changed at runtime via the admin UI.

### Authentication Flows

- Registration (open or invitation-only, configurable via admin config)
- Email + password login
- Email verification on registration via `symfonycasts/verify-email-bundle` (configurable: required / optional)
- Password reset (self-service via email)
- Remember me / persistent login via Symfony Security `remember_me` firewall option (configurable duration)
- Session management — view active sessions, logout single or all ("logout everywhere")

All flows are web form based. No JavaScript required.

### Invitation Flow

- Admin sends invite via email
- Invite token has a configurable expiry
- If a token expires, the admin can resend the invitation (generates a new token, invalidates the old one)
- Invitation expiry duration configurable via admin config

### Admin Features

- User CRUD — list, create, edit, delete
- Edit user details (email, name, role, status)
- Reset any user's password
- Admin can invite users via email

### Audit Log

- Records all auth events: login, logout, registration, password reset, admin actions
- Applies to both `User` and `Admin` activity
- Stores: actor, IP, timestamp, action, outcome
- Viewable by admin / superadmin
- Configurable retention period (via admin config)

### Login Notifications

- Email user on new login from unrecognized IP or device
- Configurable: on/off globally, on/off per user (via admin config)
- Recognition mode configurable: `ip_only` / `cookie_only` / `both` — determines what constitutes a "recognized" login

### Login History

- User can view their own recent logins (IP, device, timestamp)

---

## Bundles

### `auth-2fa-bundle`

- TOTP-based 2FA (authenticator app)
- 2FA enforcement (off / optional / required per role) — configurable
- Admin can reset any user's 2FA
- Superadmin can reset any admin or user's 2FA
- Trusted device support — skip 2FA on recognized devices
- Trusted IP support — skip 2FA from whitelisted IPs
- Registers config sub-page

### `auth-magic-link-bundle`

- Passwordless login via emailed magic link
- Configurable link expiry
- Registers config sub-page

### `auth-security-bundle`

- Rate limiting on login attempts (per IP, per account)
- Brute force protection
- Account lockout after X failed attempts (configurable threshold + duration)
- Admin can manually unlock accounts
- Registers config sub-page

### `auth-password-policy-bundle`

- Minimum length
- Complexity requirements (uppercase, numbers, symbols)
- Password expiry (force reset after X days)
- No reuse of last X passwords
- All thresholds configurable
- Registers config sub-page

### `auth-ip-whitelist-bundle`

- Restrict login to whitelisted IPs
- Configurable per user or globally
- Configurable per role (admin, user)
- Registers config sub-page

### `auth-pat-bundle` (Personal Access Tokens)

- Users can generate and manage personal access tokens for API access
- Tokens are separate from session tokens and stored independently
- Configurable: token expiry, max tokens per user
- Admin can revoke any user's tokens
- Registers config sub-page

**Realized as a real optional bundle (FEATURE-138 — the C36 Option A proof-of-concept).** It lives
under `src/Bundle/AuthPat/` (`App\Bundle\AuthPat`) with its own `AuthPatBundle` class, DI extension,
`Resources/config/{services,routes}.php`, the moved `PersonalAccessToken` entity + repository +
`TokenAuthenticator` + `ApiController` + `AccountTokenController`, and its own migration under a
bundle-owned `Migrations/` path — the sole, unconditional creator of `personal_access_tokens` (the
legacy app-owned create it used to guard against was retired by the migrations/ squash, ADR-054). It
is toggled in `config/bundles.php`; when
**not** registered its routes 404 and its
services are absent from the container (see `AuthPatBundleModularityTest`). Core depends only on the
`App\Security\UserTokenRevokerInterface` port (null-object default; the bundle's compiler pass upgrades
it), and the `api` firewall references the stable `app.api_authenticator` alias. See ADR-010.

### `auth-impersonate-bundle`

- Admin can impersonate any user (login as them)
- Superadmin can impersonate any admin or user
- All impersonation actions recorded in audit log
- Registers config sub-page

### `auth-webhook-bundle`

- Fires webhooks on configurable auth events (login, registration, password reset, lockout, token events, etc.)
- Webhook URL configurable per event or globally
- Retries on failure (configurable attempts)
- Logs webhook delivery status
- Auth event subscribers available for custom extension
- Registers config sub-page

---

## API

All auth flows are web form only. The API layer handles token-authenticated access and admin operations — no business logic, boilerplate only.

### Authentication

- API token auth is provided by `auth-pat-bundle` — the core ships no standalone token mechanism
- All API requests authenticated via `Authorization: Bearer <token>` header
- The PAT bundle registers a `TokenAuthenticator` on Symfony's `api` firewall. Any route added under the `/api/` prefix — including application-level business logic routes — is automatically protected by the same auth. No extra wiring required.

### Admin API Endpoints

> **Admin API auth (ADR-047):** the admin API is served on its own **stateless `admin_api` firewall** under the `/admin-api/` prefix, authenticated by **admin-issued** Bearer tokens (`admin_access_tokens`, provider `app_admins`, `AdminTokenAuthenticator`) — **not** user PATs. Only `Admin` entities can reach it; a `User` that somehow holds `ROLE_ADMIN` cannot (401). This is the security-hardened replacement for the original `/api/admin/*` design, which authenticated a `User` with `ROLE_ADMIN` on the `api` firewall and collapsed the user/admin boundary. Verified by `AdminApiBoundaryTest`.

| Method | Path | Description |
|---|---|---|
| `GET` | `/admin-api/users` | List users (paginated, filterable) |
| `POST` | `/admin-api/users` | Create user |
| `GET` | `/admin-api/users/{id}` | Get user details |
| `PATCH` | `/admin-api/users/{id}` | Edit user (email, name, role, status) |
| `DELETE` | `/admin-api/users/{id}` | Delete user |
| `POST` | `/admin-api/users/{id}/activate` | Activate user |
| `POST` | `/admin-api/users/{id}/deactivate` | Deactivate user |
| `POST` | `/admin-api/users/{id}/force-logout` | Revoke all sessions |
| `POST` | `/admin-api/users/{id}/password-reset` | Trigger password reset email |
| `POST` | `/admin-api/invitations` | Send invitation |
| `POST` | `/admin-api/invitations/{id}/resend` | Resend expired invitation |
| `GET` | `/admin-api/audit-log` | View audit log (paginated, filterable) |

Bundle-specific endpoints (installed only with bundle):

| Method | Path | Bundle | Description |
|---|---|---|---|
| `POST` | `/admin-api/users/{id}/unlock` | security | Unlock locked account |
| `DELETE` | `/admin-api/users/{id}/tokens` | pat | Revoke all user tokens |
| `DELETE` | `/admin-api/users/{id}/2fa` | 2fa | Reset user's 2FA |

---

## Testing

| Layer | Tool | Coverage |
|---|---|---|
| Unit | PHPUnit | Individual service methods, token generation, policy validation |
| Functional | PHPUnit | Form submissions, redirects, email dispatch, DB state, role enforcement |
| E2E | Codeception PhpBrowser | Full flows — register, verify email, login, reset password, invitation, session management |

- **Default DB for tests: SQLite** — fast, zero config, no server required
- **No JavaScript required** — all flows work with PhpBrowser (no JS engine). JS is an addon, never a dependency of core or any bundle.
- Each bundle ships with its own unit, functional, and E2E tests.

---

## Tech Stack

- Symfony 7.4 (LTS)
- PHP 8.3+
- Symfony Security component
- Symfony PasswordHasher
- Symfony RateLimiter
- Symfony Mailer
- Symfony EventDispatcher
- Doctrine ORM
- SQLite (test environment)
- Codeception + PhpBrowser (E2E tests)
- PHPUnit (unit + functional tests)
