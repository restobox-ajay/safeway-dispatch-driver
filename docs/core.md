# Core Reference

[← Back to main README](../README.md)

This document is the function-level reference for the **core** of the Symfony 7.4 auth
boilerplate — everything that ships independently of the optional bundles (`2fa`,
`magic-link`, `security/rate-limit`, `password-policy`, `ip-whitelist`, `pat`,
`impersonate`, `webhook`). Only behaviour that exists in the source tree is described here.

---

## Overview & Architecture

The application is a Symfony 7.4 authentication boilerplate built around **two fully
independent identity domains** — end *users* and back-office *admins* — each with its own
Doctrine entity, its own security provider, and its own firewall. The core provides:

- Username/password authentication for both domains (separate login pages).
- User self-service registration (open or invitation-only), email verification, and
  password reset.
- A "remember me" implementation whose lifetime is read from runtime configuration.
- Per-user session tracking ("active sessions" / "logout everywhere") and login history
  with optional new-device login-notification emails.
- An admin panel for user CRUD, invitations, audit-log browsing, and runtime configuration.
- A stateless, token-authenticated admin REST API under `/admin-api`.
- A database-backed audit log and a pluggable configuration system that bundles extend.

Cross-cutting behaviour (audit logging, session bookkeeping, login history, login
notifications) is wired through Symfony security events rather than being inlined into
controllers, so it applies uniformly regardless of the entry point.

---

## Two-Entity Model

The single most important architectural decision: **`User` and `Admin` are entirely
separate Doctrine entities, on separate database tables, behind separate firewalls and
providers.** An admin is never a "user with a role"; the two identity types cannot be
conflated.

This boundary is enforced at the lowest level by per-entity **role allowlists**:

- `User::ALLOWED_ROLES = ['ROLE_USER']` — a `User` may carry only `ROLE_USER`. Admin roles
  (`ROLE_ADMIN`, `ROLE_SUPER_ADMIN`) are *not* in the allowlist, so they cannot be written
  onto a `User`.
- `Admin::ALLOWED_ROLES = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']` — an `Admin` may carry only
  admin roles; user-tier roles are dropped.

Both entities implement `setRoles()` as a **default-deny intersection**:

```php
$this->roles = array_values(array_intersect($roles, self::ALLOWED_ROLES));
```

Any role not in the allowlist is silently dropped on write. No caller — controller, REST
API, console command, or future code — can escalate a `User` into admin space, because the
escalation is rejected at the entity write boundary, the single source of truth for roles.
`getRoles()` always appends the baseline role (`ROLE_USER` for users, `ROLE_ADMIN` for
admins) so every authenticated principal has its tier guaranteed.

The admin REST API rests on the same boundary: its bearer tokens (`AdminAccessToken`) belong
to an `Admin`, and the `admin_api` firewall authenticates against the `app_admins` provider,
so a `User` can never reach `/admin-api` regardless of any role.

---

## Data Model (Entities)

| Entity | Table | Key fields | Notable methods |
| --- | --- | --- | --- |
| `User` | `` `user` `` | `id`, `email` (unique, 180), `password`, `name`, `roles` (json), `status` (default `active`), `createdAt`, `loginNotificationsEnabled` (default `true`), `isVerified` (default `false`), `totpSecret`, `isTotpEnabled`, `lastTotpCounter`, `lockedUntil`, `passwordChangedAt`, `allowedIps` (text) | `getRoles()`/`setRoles()` (allowlist), `isLocked()` (true when `lockedUntil` is in the future), `eraseCredentials()`, `isEqualTo()` (compares password + status — invalidates sessions when either changes). Implements `UserInterface`, `PasswordAuthenticatedUserInterface`, `EquatableInterface`. `ALLOWED_ROLES = ['ROLE_USER']`. |
| `Admin` | `admin` | `id`, `email` (unique, 180), `password`, `name`, `roles` (json), `createdAt` | `getRoles()` appends `ROLE_ADMIN`; `setRoles()` allowlist. `ALLOWED_ROLES = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']`. Implements `UserInterface`, `PasswordAuthenticatedUserInterface`. |
| `Config` | `config` | `id`, `key` (column `config_key`, unique), `value` (column `config_value`, nullable text) | Plain key/value store; accessed through `ConfigService`, not directly. |
| `AuditLog` | `audit_log` | `id`, `actor`, `actorType` (column `actor_type`, 10), `ip` (45), `action` (100), `outcome` (20), `context` (nullable text), `createdAt` (column `created_at`, set in constructor) | Append-only record written by `AuditLogger`. |
| `LoginHistory` | `login_history` | `id`, `userId` (column `user_id`), `ip` (45), `userAgent` (column `user_agent`, 512), `fingerprint` (64 — `sha256(ip + userAgent)`), `createdAt` | Written on each successful user login; powers login-history view and known-device detection. |
| `UserSession` | `user_sessions` | `id`, `sessionId` (column `session_id`, unique, 128), `userId` (column `user_id`), `userType` (column `user_type`, 10), `ip`, `userAgent`, `createdAt`, `lastActiveAt` (column `last_active_at`) | One row per active server-side session; deleting the row remotely terminates the session. |
| `Invitation` | `invitations` | `id`, `email` (180), `tokenHash` (64, unique), `expiresAt`, `usedAt` (nullable), `createdAt` | Constructor `(email, tokenHash, expiresAt)`. `markUsed()`, `regenerate(tokenHash, expiresAt)` (resets `usedAt`), `isExpired()`, `isUsed()`, `isValid()`. |
| `PasswordResetToken` | `password_reset_tokens` | `id`, `email` (180), `tokenHash` (64, unique), `expiresAt`, `usedAt` (nullable), `createdAt` | Constructor `(email, tokenHash, expiresAt)`. `markUsed()`, `isExpired()`, `isUsed()`, `isValid()`. |
| `AdminAccessToken` | `admin_access_tokens` | `id`, `adminId`, `name` (100), `tokenHash` (64, unique), `expiresAt` (nullable), `lastUsedAt` (nullable), `revokedAt` (nullable), `createdAt` | Constructor `(adminId, name, tokenHash, expiresAt = null)`. `isExpired()`, `isRevoked()`, `isActive()` (= not revoked and not expired), `revoke()`, `setLastUsedAt()`. Bearer token for `/admin-api`; **belongs to an `Admin`, never a `User`**. |

All tokens (`Invitation`, `PasswordResetToken`, `AdminAccessToken`) store only a SHA-256
**hash** of the secret; the plaintext is shown once at creation and never persisted.

---

## Firewalls & Access Control

Configured in `config/packages/security.yaml`.

### Providers

| Provider | Entity | Property |
| --- | --- | --- |
| `app_users` | `App\Entity\User` | `email` |
| `app_admins` | `App\Entity\Admin` | `email` |

### Firewalls (in declaration order)

| Firewall | Pattern | Provider | Stateless | Authentication |
| --- | --- | --- | --- | --- |
| `dev` | `^/(_profiler\|_wdt\|assets\|build)/` | — | — | `security: false` |
| `admin_api` | `^/admin-api` | `app_admins` | yes | `AdminTokenAuthenticator` (bearer token) |
| `admin` | `^/admin` | `app_admins` | no | `form_login` (`app_admin_login`), `logout` (`app_admin_logout`) |
| `api` | `^/api` | `app_users` | yes | `TokenAuthenticator` (user PAT — *bundle*) |
| `user` | (all hosts by default) | `app_users` | no | `form_login` (`app_login`), `logout` (`app_logout`), `remember_me`, `user_checker: UserChecker` |

**Ordering matters:** `admin_api` is declared before `admin` because `^/admin` also matches
`/admin-api`; the stateless token firewall must win for API paths.

The `user` firewall also lists the optional `MagicLinkAuthenticator` and
`ImpersonationAuthenticator` (bundle features) as custom authenticators; in the core, the
relevant pieces are `form_login`, `remember_me` (handled by `ConfigAwareRememberMeHandler`),
and `UserChecker`.

**Separate-domain mode (FEATURE-091):** the `admin_api`, `admin`, and `user` firewalls carry
a `host` constraint from `%env(ADMIN_DOMAIN)%` / `%env(APP_DOMAIN)%`. An empty value compiles
to a regex matching every host (single-domain default, no regression); when set, each
firewall only matches its host. The remember-me / session cookie `Domain` comes from
`%env(SESSION_COOKIE_DOMAIN)%`.

### Role hierarchy

```yaml
role_hierarchy:
    ROLE_SUPER_ADMIN: [ROLE_ADMIN]
```

A super-admin therefore satisfies any `ROLE_ADMIN` check.

### Access control

| Path | Required role |
| --- | --- |
| `^/admin/login$` | `PUBLIC_ACCESS` |
| `^/admin-api` | `ROLE_ADMIN` |
| `^/admin` | `ROLE_ADMIN` |
| `^/login$`, `^/register$`, `^/forgot-password`, `^/reset-password`, `^/verify-email`, `^/resend-verification`, `^/magic-link`, `^/impersonate/start$` | `PUBLIC_ACCESS` |
| `^/impersonate/exit$`, `^/2fa`, `^/account`, `^/dashboard` | `ROLE_USER` |

**Defense in depth:** in separate-domain mode `access_control` only runs when a firewall
matches the request host. Because of that, every admin controller *also* carries a class-level
`#[IsGranted('ROLE_ADMIN')]` (or `ROLE_SUPER_ADMIN`) attribute that is enforced independently
of firewall matching. Any new admin/admin-api controller must keep that attribute.

In the `test` environment CSRF on the `admin` and `user` form logins is disabled and the
password hasher cost is lowered.

### Security checker — `App\Security\UserChecker`

Implements `UserCheckerInterface`; bound to the `user` firewall. No-ops for non-`User`
principals.

- **`checkPreAuth(UserInterface $user): void`** — throws `DisabledException` if status is not
  `active`; throws a `CustomUserMessageAuthenticationException` with a remaining-minutes
  message if `lockedUntil` is in the future; if the account is unverified and
  `email_verification.mode` is `required`, throws a "not verified" message.
- **`checkPostAuth(UserInterface $user): void`** — re-checks status (`DisabledException` if
  not active) and the unverified+`required` case after credential verification.

### Remember-me handler — `App\Security\ConfigAwareRememberMeHandler`

Implements `RememberMeHandlerInterface`, wired as the `remember_me` service for the `user`
firewall. Cookie name `REMEMBERME`, default lifetime 30 days.

- **`createRememberMeCookie(UserInterface $user): void`** — reads lifetime from
  `remember_me.lifetime_days` (default 30), computes an HMAC-SHA256 value over
  `identifier:expires:passwordHash` keyed by `%kernel.secret%`, and writes the cookie.
- **`consumeRememberMeCookie(RememberMeDetails $details): UserInterface`** — rejects expired
  cookies, loads the user via `UserRepository::findByEmail`, recomputes the HMAC and compares
  with `hash_equals`, then **rolling-refreshes** by re-issuing a cookie with the current
  configured lifetime. Returns the user.
- **`clearRememberMeCookie(): void`** — writes a cleared cookie.

Because the HMAC binds to the **password hash**, changing the password silently invalidates
all outstanding remember-me cookies — this is what makes "password reset = logout everywhere"
work for remember-me without any explicit cookie revocation.

### Admin API authenticator — `App\Security\AdminTokenAuthenticator`

Extends `AbstractAuthenticator`; the sole authenticator for the `admin_api` firewall.

- **`supports(Request): ?bool`** — returns `true` (the firewall pattern already scopes it to
  `/admin-api`).
- **`authenticate(Request): Passport`** — requires an `Authorization: Bearer <token>` header,
  SHA-256-hashes the token, looks it up via `AdminAccessTokenRepository::findByTokenHash`, and
  rejects missing / revoked / expired tokens with `CustomUserMessageAuthenticationException`.
  On success it stamps `lastUsedAt`, flushes, and returns a `SelfValidatingPassport` whose
  `UserBadge` loads the **`Admin`** by id from `AdminRepository`.
- **`onAuthenticationSuccess()`** — returns `null` (request continues to the controller).
- **`onAuthenticationFailure()`** — returns a `401` JSON `{ "error": ... }`.

It mirrors the user-side PAT `TokenAuthenticator` but loads `Admin` entities, keeping the
admin API on the admin boundary.

---

## Authentication Flows

### Registration — `RegistrationController`

| Method | Path | Route name | Auth | Description |
| --- | --- | --- | --- | --- |
| GET/POST | `/register` | `app_register` | public | Self-service registration. |

`register(...)` behaviour:

1. Reads `registration.mode` (`open` by default).
2. If mode is `invitation-only`: requires a `token` (query string on GET, hidden
   `_invite_token` on POST). Missing token → `403`. The token is SHA-256-hashed and matched
   via `InvitationRepository::findByTokenHash`; missing/used → `403`; expired → form re-render
   with an error. A valid invitation pre-fills the email.
3. On POST: validates email (present, valid, not already registered), name (present), and
   password (present, passes `PasswordPolicyService::validate`).
4. On success: creates a `User` with status `active`, hashes the password,
   sets `passwordChangedAt`, marks the invitation used (if any), persists, stores the hash in
   password history, writes an audit `register/success` entry, optionally dispatches a
   `registration` webhook (bundle), and — if `email_verification.mode` is not `disabled` —
   emails a signed verification link (SymfonyCasts VerifyEmail). Redirects to `app_login`.

### Login & logout — `SecurityController` / `AdminSecurityController`

These controllers only render the login form and read the last authentication error /
username via `AuthenticationUtils`; the actual credential check and logout are handled by the
firewall's `form_login` / `logout`. The logout actions throw `LogicException` (never reached).

| Method | Path | Route name | Auth | Description |
| --- | --- | --- | --- | --- |
| any | `/login` | `app_login` | public | User login form. |
| any | `/logout` | `app_logout` | user | Intercepted by firewall. |
| any | `/admin/login` | `app_admin_login` | public | Admin login form. |
| any | `/admin/logout` | `app_admin_logout` | admin | Intercepted by firewall. |

On a successful login the security events fire `LoginHistoryListener`,
`LoginNotificationListener`, `UserSessionListener`, and `AuditLogSecurityListener`
(see [Event Listeners](#event-listeners)).

### Email verification — `EmailVerificationController`

| Method | Path | Route name | Auth | Description |
| --- | --- | --- | --- | --- |
| GET | `/verify-email` | `app_verify_email` | public | Validates the signed link; sets `isVerified = true`. |
| GET | `/resend-verification` | `app_resend_verification` | public | Renders the resend form. |
| POST | `/resend-verification` | `app_resend_verification_post` | public | Re-sends a verification email. |

- `verifyUserEmail(...)` — requires `id`, loads the user, validates the signature via
  `VerifyEmailHelper::validateEmailConfirmationFromRequest`, sets `isVerified(true)`, flushes,
  and redirects to login. Any failure adds a flash error and redirects to login.
- `resendVerification(...)` — rate-limited (`resend_verification`, bundle) **above** a CSRF
  check (`resend_verification` token). Only sends if the user exists, is unverified, and
  `email_verification.mode` is not `disabled`. Always shows the same neutral flash message
  (no account enumeration).

### Password reset — `PasswordResetController`

| Method | Path | Route name | Auth | Description |
| --- | --- | --- | --- | --- |
| GET/POST | `/forgot-password` | `app_forgot_password` | public | Request a reset link. |
| GET | `/forgot-password/check` | `app_forgot_password_check` | public | Neutral confirmation page. |
| GET/POST | `/reset-password/{token}` | `app_reset_password` | public | Set a new password with a token; audited `password_reset`. |

- `request(...)` — on POST, first **rate-limits** (`forgot_password`, bundle) *above* the CSRF
  check (per ADR-016, so a missing token can't bypass throttling), then validates the
  `forgot_password` CSRF token. If the email matches a user, it mints a random 32-byte token,
  stores a `PasswordResetToken` holding the SHA-256 hash with a **1-hour** expiry, emails the
  reset link, and writes an audit entry. Always redirects to the neutral check page (no
  enumeration).
- `reset(...)` — SHA-256-hashes the path token and loads the matching `PasswordResetToken`.
  Invalid/used/expired tokens render an error. On POST: validates the new password against
  `PasswordPolicyService` and `PasswordHistoryService::checkReuse`, then:
  1. Hashes and sets the new password, sets `passwordChangedAt`, marks the token used.
  2. Stores the new hash in password history.
  3. `PasswordResetTokenRepository::invalidateOtherUnusedTokens(email, currentId)` — kills any
     **sibling** reset tokens issued in earlier requests for the same email.
  4. `UserSessionRepository::deleteAllByUserId(userId, 'user')` — drops all active session rows
     ("logout everywhere"); `UserSessionRequestListener` tears down each orphaned session on
     its next request.
  5. Remember-me cookies are invalidated **implicitly** because the HMAC binds to the
     now-changed password hash.

  Redirects to login on success.

### Remember-me

Configured on the `user` firewall via `ConfigAwareRememberMeHandler` (see above). Lifetime is
runtime-configurable (`remember_me.lifetime_days`), cookies roll forward on consumption, and
they self-invalidate on password change.

---

## Account & Session Management

`AccountController` is class-level `#[IsGranted('ROLE_USER')]` and prefixed `/account`. Core
routes (the PAT token routes are part of the `auth-pat-bundle` and are out of scope here):

| Method | Path | Route name | Auth | Description |
| --- | --- | --- | --- | --- |
| GET | `/account/login-history` | `app_account_login_history` | user | Recent logins for the current user (`LoginHistoryRepository::findRecentByUserId`). |
| GET | `/account/sessions` | `app_account_sessions` | user | Lists active sessions; marks the current session id. |
| POST | `/account/sessions/terminate-all` | `app_account_sessions_terminate_all` | user | CSRF `session_terminate_all`; deletes all this user's session rows, redirects to login. |
| POST | `/account/sessions/{id}/terminate` | `app_account_sessions_terminate` | user | CSRF `session_terminate_<id>`; removes one session row (404 if not owned by the user). |
| GET/POST | `/account/change-expired-password` | `app_account_change_expired_password` | user | CSRF `change_expired_password`; validates policy + reuse, updates password and `passwordChangedAt`, redirects to login; audited `password_change` (context `expired`). Only reached when the password is force-expired. |
| GET/POST | `/account/password` | `app_account_change_password` | user | Self-service password change. CSRF `change_password`; requires the correct **current** password + new + confirm, enforces the password policy + reuse rules, updates the hash and `passwordChangedAt`. The change invalidates the current session and all remember-me cookies (both bound to the hash), so it redirects to login. Audited `password_change` (context `self-service`). |
| GET/POST | `/account/settings` | `app_account_settings` | user | CSRF `account_settings`; toggles `loginNotificationsEnabled`. |

Active sessions and login history are populated by the session/login-history event listeners.

### Dashboard — `DashboardController`

| Method | Path | Route name | Auth | Description |
| --- | --- | --- | --- | --- |
| any | `/dashboard` | `app_dashboard` | `ROLE_USER` | Renders the user dashboard. Class-level `#[IsGranted('ROLE_USER')]`. |

---

## Admin Panel

All admin controllers are class-level `#[IsGranted('ROLE_ADMIN')]` (or `ROLE_SUPER_ADMIN`).

### `AdminDashboardController`

| Method | Path | Route name | Auth | Description |
| --- | --- | --- | --- | --- |
| any | `/admin/dashboard` | `app_admin_dashboard` | `ROLE_ADMIN` | Admin landing page. |

### `AdminUserController` (prefix `/admin/users`)

User management. The `reset-2fa` and `impersonate-start` actions belong to bundles and are
omitted below; core actions:

| Method | Path | Route name | CSRF token | Description |
| --- | --- | --- | --- | --- |
| GET | `/admin/users` | `app_admin_users` | — | Paginated user list (10/page) plus active-PAT counts per user. |
| GET/POST | `/admin/users/new` | `app_admin_users_new` | `admin_user_create` | Create a user (validates email/name/password, role coerced into `User::ALLOWED_ROLES`, status into `active`/`inactive`); audit `admin.user_create`. |
| GET/POST | `/admin/users/{id}/edit` | `app_admin_users_edit` | `admin_user_edit` | Edit email/name/status (role validated against `User::ALLOWED_ROLES` then `setRoles([])`); audit `admin.user_edit`. |
| POST | `/admin/users/{id}/delete` | `app_admin_users_delete` | `admin_user_delete_<id>` | Delete a user; audit `admin.user_delete`. |
| POST | `/admin/users/{id}/password-reset` | `app_admin_users_password_reset` | `admin_user_password_reset_<id>` | Mint a 1-hour reset token and email the user; audit `admin.user_password_reset`. |
| POST | `/admin/users/{id}/unlock` | `app_admin_users_unlock` | `admin_user_unlock_<id>` | Clear `lockedUntil`; audit `admin.user_unlock`. |
| POST | `/admin/users/{id}/revoke-tokens` | `app_admin_users_revoke_tokens` | `admin_user_revoke_tokens_<id>` | Revoke all the user's active PATs; audit `admin.user_revoke_tokens`. |

Note: even on the create/edit paths, role input is validated against `User::ALLOWED_ROLES` and
the entity is then written with `setRoles([])`, so the admin UI can never grant a user an
admin role.

### `AdminInvitationController` (prefix `/admin/users`)

| Method | Path | Route name | CSRF token | Description |
| --- | --- | --- | --- | --- |
| GET/POST | `/admin/users/invite` | `app_admin_users_invite` | `admin_invite` | Create an `Invitation` (expiry from `invitation.expiry_days`, default 7), email the registration link; audit `admin.user_invite`. |
| GET | `/admin/users/invitations` | `app_admin_users_invitations` | — | List invitations, newest first. |
| POST | `/admin/users/invitations/{id}/resend` | `app_admin_users_invitations_resend` | `admin_invite_resend_<id>` | Regenerate the token + expiry and re-email; audit `admin.user_invite_resend`. |

### `AdminAuditLogController`

| Method | Path | Route name | Description |
| --- | --- | --- | --- |
| GET | `/admin/audit-log` | `app_admin_audit_log` | Single invokable controller. Paginated (20/page) audit-log browser filtered by `actor`, `action`, `date_from`, `date_to` query params. |

### `AdminConfigController`

| Method | Path | Route name | CSRF token | Description |
| --- | --- | --- | --- | --- |
| GET | `/admin/config` | `app_admin_config` | — | Renders every registered config page; for each field shows the current value (`ConfigService::getString` with the field default). |
| POST | `/admin/config/{slug}` | `app_admin_config_save` | `admin_config_save_<slug>` | Resolves the provider by slug (404 if unknown), validates CSRF, and persists each field. Form fields use `fields[config.key]` (PHP would otherwise collapse top-level dots); `bool` fields store `'1'`/`'0'` by presence, others store the raw string via `ConfigService::set`. A save that changes anything writes one `admin.config_update` audit row (same transaction): `page=<slug>` plus each changed key, with old -> new values for `bool`/`int`/`enum`/`ip_list` fields and only the key name for free-text fields (which may hold secrets, e.g. webhook URLs). |

### `SuperAdminController`

| Method | Path | Route name | Auth | Description |
| --- | --- | --- | --- | --- |
| any | `/admin/superadmin` | `app_superadmin_index` | `ROLE_SUPER_ADMIN` | Super-admin landing page. Class-level `#[IsGranted('ROLE_SUPER_ADMIN')]`. |

### `AdminAdminManagementController` (prefix `/admin/superadmin/admins`)

Superadmin-only (class-level `#[IsGranted('ROLE_SUPER_ADMIN')]`) CRUD over **Admin** accounts. Roles are constrained to `Admin::ALLOWED_ROLES` (`ROLE_ADMIN`/`ROLE_SUPER_ADMIN`); `status` (`active`/`inactive`) is enforced at login by `AdminChecker` and mid-session by `Admin::isEqualTo`.

| Method | Path | Route name | CSRF token | Description |
| --- | --- | --- | --- | --- |
| GET | `/admin/superadmin/admins` | `app_admin_superadmin_admins` | — | List admins (name, email, roles, status) with edit/impersonate/delete actions. |
| GET/POST | `/admin/superadmin/admins/new` | `app_admin_superadmin_admins_new` | `admin_admin_create` | Create an admin (email/name unique, password via `PasswordPolicyService`, role). Audit `admin.admin_create`. |
| GET/POST | `/admin/superadmin/admins/{id}/edit` | `app_admin_superadmin_admins_edit` | `admin_admin_edit_<id>` | Edit email/name/role/status. Audit `admin.admin_edit`. |
| POST | `/admin/superadmin/admins/{id}/delete` | `app_admin_superadmin_admins_delete` | `admin_admin_delete_<id>` | Delete an admin. Audit `admin.admin_delete`. |
| POST | `/admin/superadmin/admins/{id}/reset-password` | `app_admin_superadmin_admins_reset_password` | `admin_admin_reset_password_<id>` | Email the admin a reset link (`AdminPasswordResetService`). Audit `admin.admin_password_reset`. |
| GET/POST | `/admin/superadmin/admins/{id}/password` | `app_admin_superadmin_admins_set_password` | `admin_admin_set_password_<id>` | **Tech support only** (superadmins get 403): set another admin's password directly. Not allowed on your own account (use *Change My Password*) or on an inactive admin, so the link is not shown on those rows. Goes through `AdminPasswordGate` (ADR-073); audit `admin.password_set`. |
| POST | `/admin/superadmin/admins/{id}/impersonate` | `app_admin_superadmin_impersonate` | `admin_impersonate_admin_<id>` | Impersonate a (non-super) admin (auth-impersonate-bundle). |

**Anti-lockout guards:** the panel refuses to strip the **last active superadmin** of its role or deactivate/delete it, and a superadmin cannot deactivate or delete **their own** account — so the panel can never lock every superadmin out.

### Admin password changes — `AdminPasswordGate` (ADR-073)

An existing admin's password changes in exactly one place, `App\AdminPassword\AdminPasswordGate::change()`, whichever way it is asked for: the emailed reset link, **Change My Password** (`GET/POST /admin/password`, `app_admin_account_change_password`, every admin, proves the current password, throttled per account), or tech support's **Set Password** above. The gate does the permission check, validation (the password policy floor and a confirmation match), the write, the audit row (`admin.password_reset` / `admin.password_change` / `admin.password_set`) and the revocations — in one transaction it also retires every other pending reset link, deletes the admin's `admin_sessions` rows and closes any open DB console. Controllers only collect input; an architecture test keeps it that way. Sending a reset link and creating accounts are not changes and live elsewhere.

### `AdminPasswordResetController` (self-service admin reset, ADR-009)

`PUBLIC_ACCESS` on the `admin` firewall — mirrors the hardened user reset (rate-limited above CSRF, anti-enumeration, single-use 1-hour SHA-256-hashed tokens in a **separate** `admin_password_reset_tokens` table, sibling-token invalidation). The password change invalidates a live admin session via `Admin::isEqualTo`.

| Method | Path | Route name | Description |
| --- | --- | --- | --- |
| GET/POST | `/admin/forgot-password` | `app_admin_forgot_password` | Request a reset link (CSRF `admin_forgot_password`); always redirects to the check page. Linked from the admin login page. |
| GET | `/admin/forgot-password/check` | `app_admin_forgot_password_check` | Neutral confirmation. |
| GET/POST | `/admin/reset-password/{token}` | `app_admin_reset_password` | Set a new password (policy-enforced) against a valid token; audited `admin.password_reset`; redirects to `/admin/login`. Admin 2FA enrol/reconfigure and disable are audited `admin.2fa_enable` (context `enrolled`/`reconfigured`) and `admin.2fa_disable`. |

---

## Admin REST API

Served under `/admin-api`. Stateless JSON API behind the `admin_api` firewall, authenticated with an
`Authorization: Bearer <token>` `AdminAccessToken`. All controllers also carry class-level
`#[IsGranted('ROLE_ADMIN')]`. Responses are JSON; validation errors return `422` with an
`errors` map; not-found returns `404` with an `error` field.

### `AdminApiUserController` (prefix `/admin-api/users`)

| Method | Path | Route name | Description |
| --- | --- | --- | --- |
| GET | `/admin-api/users` | `app_api_admin_users_list` | Paginated list (10/page) with `data` + `meta` (`total`, `page`, `total_pages`). |
| POST | `/admin-api/users` | `app_api_admin_users_create` | Create from JSON (`email`, `name`, `password`, `role`, `status`); policy-validated; `201` with serialized user; role coerced into `User::ALLOWED_ROLES`, then `setRoles([])`. |
| GET | `/admin-api/users/{id}` | `app_api_admin_users_detail` | Serialized user or `404`. |
| PATCH | `/admin-api/users/{id}` | `app_api_admin_users_update` | Partial update of `email`/`name`/`role`/`status` (only present keys); role write goes through `setRoles([])`. |
| DELETE | `/admin-api/users/{id}` | `app_api_admin_users_delete` | `204` on delete. |
| POST | `/admin-api/users/{id}/activate` | `app_api_admin_users_activate` | Set status `active`. |
| POST | `/admin-api/users/{id}/deactivate` | `app_api_admin_users_deactivate` | Set status `inactive`. |
| POST | `/admin-api/users/{id}/force-logout` | `app_api_admin_users_force_logout` | Delete all the user's session rows. |
| POST | `/admin-api/users/{id}/password-reset` | `app_api_admin_users_password_reset` | Mint a 1-hour reset token and email the user. |
| POST | `/admin-api/users/{id}/unlock` | `app_api_admin_users_unlock` | Clear `lockedUntil`. |
| DELETE | `/admin-api/users/{id}/tokens` | `app_api_admin_users_revoke_tokens` | Revoke all the user's PATs (`204`). |
| DELETE | `/admin-api/users/{id}/2fa` | `app_api_admin_users_reset_2fa` | Clear TOTP secret/flag (`204`). |

User serialization: `id`, `email`, `name`, `status`, `roles`, `created_at` (ATOM).

### `AdminApiInvitationController` (prefix `/admin-api/invitations`)

| Method | Path | Route name | Description |
| --- | --- | --- | --- |
| POST | `/admin-api/invitations` | `app_api_admin_invitations_send` | Create + email an invitation (`422` on missing/invalid email); `201` with serialized invitation. |
| POST | `/admin-api/invitations/{id}/resend` | `app_api_admin_invitations_resend` | Resend — **only if the invitation is expired** (otherwise `409`); regenerates token/expiry and re-emails. |

Invitation serialization: `id`, `email`, `expires_at`, `used_at`, `created_at` (ATOM).

### `AdminApiAuditLogController` (prefix `/admin-api/audit-log`)

| Method | Path | Route name | Description |
| --- | --- | --- | --- |
| GET | `/admin-api/audit-log` | `app_api_admin_audit_log` | Paginated (20/page) audit entries with the same `actor`/`action`/`date_from`/`date_to` filters as the UI; `data` + `meta`. |

Entry serialization: `id`, `actor`, `actor_type`, `ip`, `action`, `outcome`, `context`,
`created_at` (ATOM).

---

## Audit Log

The audit log is an append-only `AuditLog` table written by the `AuditLogger` service and the
`AuditLogSecurityListener`. Each row records the `actor` (identifier or `unknown`), the
`actorType` (`user` / `admin`), source `ip`, an `action` string, an `outcome`
(`success` / `failure`), and optional `context`. Authentication events
(`login`/`logout` success, `login` failure) are recorded automatically by the security
listener; admin mutations record `admin.*` actions from the controllers. Retention is
enforced by the `app:prune` command (audit_log pruner) using `audit_log.retention_days`.

---

## Login History & Notifications

Every successful **user** login writes a `LoginHistory` row
(`LoginHistoryListener`, on `LoginSuccessEvent`) capturing IP, user-agent, and a
`sha256(ip + userAgent)` fingerprint.

On the same event, `LoginNotificationListener` (priority 10) optionally emails a "new login"
notice. It sends only when **both** the global switch (`login_notifications.enabled`, default
on) and the user's own preference (`User::isLoginNotificationsEnabled()`) are true — gated by
`LoginNotificationChecker::shouldNotifyUser()` — **and** the login looks unfamiliar. Known-ness
is determined by `login_notifications.recognition_mode`: `ip_only` checks for a prior login
from the same IP, anything else (default `fingerprint`) checks for a prior login with the same
fingerprint. Familiar logins are silent.

Users can view their own login history at `/account/login-history` and toggle notifications at
`/account/settings`.

---

## Configuration System

Runtime configuration is a database-backed key/value store (`Config` entity, `config` table)
accessed exclusively through `ConfigService`. Keys are dotted strings (e.g.
`email_verification.mode`); values are stored as text and coerced on read.

### Pluggable config pages

The admin `/admin/config` UI is assembled from **config-page providers** so that optional
bundles can contribute their own sub-pages without touching the core controller.

- **`App\Config\ConfigPageProviderInterface`** — annotated `#[AutoconfigureTag('auth.config_page')]`,
  so any service implementing it is automatically tagged. Methods:
  - `getSlug(): string` — URL slug (used in `/admin/config/{slug}`).
  - `getTitle(): string` — display title.
  - `getFields(): array` — field definitions keyed by config key, each
    `['label' => ..., 'type' => 'text'|'bool', 'default' => ...]`.
- **`App\Config\ConfigPageRegistry`** — receives all tagged providers via
  `#[AutowireIterator('auth.config_page')]`. Methods:
  - `getAll(): array` — returns the providers as a list.
  - `getBySlug(string $slug): ?ConfigPageProviderInterface` — lookup for the save action.
- **`App\Config\GeneralConfigPage`** — the core provider (slug `general`, title `General`)
  defining the core config keys below.

A bundle registers a sub-page simply by shipping a service that implements
`ConfigPageProviderInterface`; the autoconfigure tag and `AutowireIterator` wire it into the
registry, and `AdminConfigController` renders and persists it generically.

### Core config keys (`GeneralConfigPage`)

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `registration.mode` | text | `open` | `open` or `invitation-only`. |
| `audit_log.retention_days` | text | `90` | Days kept by `app:prune` (audit_log pruner). |
| `login_notifications.enabled` | bool | `1` | Global new-login email switch. |
| `login_notifications.recognition_mode` | text | `fingerprint` | `fingerprint` or `ip_only`. |
| `email_verification.mode` | text | `disabled` | `disabled`, `optional`, or `required`. |
| `remember_me.lifetime_days` | text | `30` | Remember-me cookie lifetime. |
| `invitation.expiry_days` | text | `7` | Invitation link lifetime. |

Other keys referenced by core code (read with defaults even if not surfaced on the General
page): `pat.max_tokens_per_user`, `pat.default_expiry_days`, `webhook.registration_url`,
`webhook.global_url` — these belong to optional bundles.

### `App\Service\ConfigService`

| Method | Purpose |
| --- | --- |
| `getString(string $key, string $default = ''): string` | Stored value or default. |
| `getBool(string $key, bool $default = false): bool` | True when stored value is one of `'1'`, `'true'`, `'yes'`, `'on'`; default if the key is absent. |
| `getInt(string $key, int $default = 0): int` | `(int)` cast of the stored value; default if absent. |
| `set(string $key, string $value): void` | Upserts the key (creates a `Config` row if missing) and flushes. |

---

## Core Services

### `App\Service\AuditLogger`

- **`log(string $actor, string $actorType, string $ip, string $action, string $outcome, ?string $context = null): void`**
  — constructs an `AuditLog`, sets all fields (omitting `context` when null), persists, and
  flushes immediately.

### `App\Service\ConfigService`

See [Configuration System](#appserviceconfigservice) above for the full method table
(`getString`, `getBool`, `getInt`, `set`).

### `App\Service\LoginNotificationChecker`

- **`shouldNotifyUser(User $user): bool`** — returns `false` if the global
  `login_notifications.enabled` config (default `true`) is off; otherwise returns the user's
  own `isLoginNotificationsEnabled()` preference. (Whether the device is *known* is decided
  separately in `LoginNotificationListener`.)

### `App\Config\ConfigPageRegistry`

- **`getAll(): array`** — all registered `ConfigPageProviderInterface` providers as a list.
- **`getBySlug(string $slug): ?ConfigPageProviderInterface`** — provider whose `getSlug()`
  matches, or `null`.

### `App\Config\GeneralConfigPage` (implements `ConfigPageProviderInterface`)

- **`getSlug(): string`** → `'general'`.
- **`getTitle(): string`** → `'General'`.
- **`getFields(): array`** → the core field definitions in the table above.

### `App\Config\ConfigPageProviderInterface`

- **`getSlug(): string`**, **`getTitle(): string`**, **`getFields(): array`** — see
  [Pluggable config pages](#pluggable-config-pages).

---

## Event Listeners

All are attribute-registered (`#[AsEventListener]`).

| Listener | Event(s) | Behaviour |
| --- | --- | --- |
| `LoginHistoryListener` | `LoginSuccessEvent` | For `User` logins, writes a `LoginHistory` row (IP, user-agent truncated to 512, `sha256(ip+ua)` fingerprint). |
| `LoginNotificationListener` | `LoginSuccessEvent` (priority 10) | For `User` logins, when `LoginNotificationChecker::shouldNotifyUser()` passes and the device is *not* recognized (per `login_notifications.recognition_mode`: `ip_only` vs fingerprint), emails a new-login notice. |
| `UserSessionListener` | `LoginSuccessEvent`, `LogoutEvent` | On login, removes any stale row for the same `session_id` (flushed before insert to avoid a unique-constraint clash since Doctrine orders inserts before deletes), then inserts a fresh `UserSession` (`userType = 'user'`). On logout, removes the current session's row. |
| `UserSessionRequestListener` | `kernel.request` (priority −10) | On the main request, skips `/api` (stateless PAT) and `/impersonate` paths. For an authenticated `User` with a session, if no matching `UserSession` row exists the session has been terminated remotely → invalidates the session and clears the token (forces logout); otherwise refreshes `lastActiveAt`. This is what makes "terminate session" and "logout everywhere" take effect. |
| `AuditLogSecurityListener` | `LoginSuccessEvent`, `LoginFailureEvent`, `LogoutEvent` | Writes audit entries: login success (`actorType` from `Admin` vs `User`), login failure (actor from posted `email` or `unknown`, `actorType` from `/admin` path prefix), logout success. |

---

## Console Commands

| Command | Options | Behaviour |
| --- | --- | --- |
| `app:create-superadmin` | `--email` (required), `--password` (optional) | Provisions an `Admin` named `Superadmin` with `ROLE_SUPER_ADMIN`. Fails if `--email` is missing or already taken. If no password is given, generates a random one and prints it. |
| `app:create-tech-support` | `--email` (required), `--password` (optional) | Provisions an `Admin` named `Tech Support` with `ROLE_TECH_SUPPORT` — the maintainer tier, hidden from every other admin on admin-management surfaces but granted superadmin powers via `role_hierarchy` (ADR-050). Same duplicate-email/auto-generated-password behaviour as `app:create-superadmin`; use it instead for the very first account on a new instance. |
| `app:admin:set-role` | `--email` (required), `--role` (required: `ROLE_ADMIN` \| `ROLE_SUPER_ADMIN` \| `ROLE_TECH_SUPPORT`) | Changes an **existing** admin's role from the shell — the console counterpart to the role field on the admin-edit screen, including its anti-lockout guard (refuses to strip `ROLE_SUPER_ADMIN` from the last active superadmin). |
| `app:admin:create-api-token` | `--email` (required), `--name` (optional, default `Admin API Token`), `--expires-in-days` (default 365, 1–3650), `--no-expiry` | Issues an `AdminAccessToken` bound to the `Admin` with that email (fails if no such admin). Prints the plaintext bearer token **once**; only the SHA-256 hash and the last 6 characters (to identify it later) are stored. Audited as `admin.api_token_create`. |
| `app:admin:list-api-tokens` | `--email`, `--active` | Table of admin API tokens: id, owner, name, last 6 characters, created, expires, last used, status (active / expired / revoked). Never prints a secret. |
| `app:admin:revoke-api-token` | `--id=N`, or `--email=… --all` | Revokes one token, or every token of one admin (ADR-064). A revoked token is answered 401 at once and never comes back, even if the admin is reactivated. Audited. |
| `app:prune` | `--dry-run`, `--only=<name>` (repeatable) | Unified prune harness (ADR-048). Runs every registered `PrunerInterface` (`auth.pruner`) — audit_log (`audit_log.retention_days`, default 90), password_reset_tokens + admin_password_reset_tokens (expired-or-used), user_sessions (dead vs `sessions` lifetime), invitations (terminal + past `invitation.retention_days`, default 30), plus bundle pruners magic_link_tokens and webhook_delivery (`webhook.delivery_retention_days`, default 30) when their bundles are installed. Prints per-pruner counts + a total; `--dry-run` reports without deleting; a real deleting run writes one `maintenance.prune` audit row. Supersedes the former `app:audit-log:prune` / `app:maintenance:prune`. |

---

## Security Notes

- **Entity-based admin boundary.** Admins and users are different entities, tables, providers,
  and firewalls. There is no shared identity and no "admin is a user with a role" shortcut. The
  admin REST API authenticates `Admin` entities via `AdminAccessToken` bearer tokens, so a
  `User` can never reach `/admin-api`. The `admin_api` firewall is declared before `admin`
  because `^/admin` would otherwise swallow `/admin-api`.
- **Roles are a single source of truth.** `User::ALLOWED_ROLES` and `Admin::ALLOWED_ROLES` are
  enforced inside each entity's `setRoles()` via a default-deny intersection. Every write path
  (registration, admin UI, admin API) routes role changes through `setRoles()`, so a user can
  never be escalated into admin space and admin controllers reduce user roles to the baseline
  with `setRoles([])`.
- **CSRF everywhere it matters.** Form logins use CSRF (disabled only under `when@test`). All
  state-changing admin and account POST actions validate a per-action CSRF token (e.g.
  `admin_user_delete_<id>`, `session_terminate_<id>`, `admin_config_save_<slug>`,
  `account_settings`). Public POST endpoints (`forgot-password`, `resend-verification`) validate
  CSRF **after** rate-limiting (ADR-016), so a missing token cannot bypass the throttle.
- **Token hashing.** Invitation, password-reset, and admin-API tokens persist only a SHA-256
  hash; plaintext is shown once and matched by re-hashing on use.
- **Session / token invalidation on password reset.** Resetting a password (`/reset-password`)
  marks the token used, invalidates sibling unused reset tokens, deletes all active session rows
  (so `UserSessionRequestListener` logs the user out everywhere on the next request), and
  implicitly voids all remember-me cookies because their HMAC binds to the password hash. The
  same hash binding means `User::isEqualTo()` (password + status) invalidates the active session
  whenever either changes.
- **No account enumeration.** `forgot-password` and `resend-verification` always return the same
  neutral response whether or not the email exists.
