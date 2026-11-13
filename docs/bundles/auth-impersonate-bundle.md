# auth-impersonate-bundle
Lets an admin act as a user — and a super-admin act as an admin — through audit-logged, CSRF-guarded session handshakes built on a custom authenticator.

[← Back to main README](../../README.md)

## Overview

The bundle provides two distinct impersonation flows that share the same design philosophy but use different mechanisms.

- **Admin impersonates user.** An admin clicks "Impersonate" in the user list. The admin controller writes an `_impersonation_request` payload into the session (behind `ROLE_ADMIN` + a per-user CSRF token) and redirects to `/impersonate/start`. There, `ImpersonationAuthenticator` consumes the payload, authenticates the session on the **user** firewall as the target, and records who is impersonating whom in session keys read by the banner. Exiting clears those keys.
- **Super-admin impersonates admin.** A super-admin clicks "Impersonate" on the admins list. The controller serialises the *current* super-admin token, stores it for later restoration, then mints and installs a new `PostAuthenticationToken` for the target admin directly in both the session (`_security_admin`) and the live `TokenStorage`. Exiting deserialises and re-installs the original token.

Both flows are **audit-logged** via `AuditLogger` (start and exit), and both surface a persistent banner on every authenticated page so the operator always knows they are impersonating.

## Configuration

The bundle contributes one page to the admin config UI via `App\Config\ImpersonateConfigPage` (slug `impersonate`, title "Impersonation").

| Key | Type | Default | Meaning |
|-----|------|---------|---------|
| `impersonate.enabled` | `bool` | `1` | "Allow Impersonation". When off, admins cannot impersonate users and superadmins cannot impersonate admins: the buttons are hidden, both start actions refuse, and `ImpersonationAuthenticator` refuses even a request queued before the switch (issue #9). |

## Routes

| Method | Path | Route name | Auth | Description |
|--------|------|------------|------|-------------|
| POST | `/admin/users/{id}/impersonate-start` | `app_admin_users_impersonate_start` | `ROLE_ADMIN` (`^/admin`) + CSRF `admin_user_impersonate_{id}` | Plant the `_impersonation_request` session payload, audit-log, redirect to `/impersonate/start`. |
| GET | `/impersonate/start` | `app_impersonate_start` | `PUBLIC_ACCESS` (intercepted by `ImpersonationAuthenticator`) | Trigger the user-firewall authenticator; on success redirect to dashboard. |
| POST | `/impersonate/exit` | `app_impersonate_exit` | `ROLE_USER` + CSRF `impersonate_exit` | Audit-log and clear the user-impersonation session keys; redirect to admin dashboard. |
| POST | `/admin/superadmin/admins/{id}/impersonate` | `app_admin_superadmin_impersonate` | `ROLE_SUPER_ADMIN` (class-level) + CSRF `admin_impersonate_admin_{id}` | Save the original admin token, install the target admin's token, audit-log, redirect to admin dashboard. |
| POST | `/admin/impersonate-admin-exit` | `app_admin_impersonate_admin_exit` | `ROLE_ADMIN` + CSRF `admin_impersonate_exit` | Restore the original super-admin token, audit-log, redirect to the admins list. |

## Controllers

### `AdminUserController::impersonateStart` (admin → user, start)

```php
public function impersonateStart(int $id, Request $request, UserRepository $userRepository, AuditLogger $auditLogger): Response
```

1. Looks up the target `User` by `{id}`; throws 404 if not found.
2. Validates CSRF token `admin_user_impersonate_{id}` from `_token`; throws access-denied if invalid.
3. Resolves the acting admin's identifier (`getUser()?->getUserIdentifier()`, falling back to `'unknown'`).
4. **Writes session key** `_impersonation_request` = `['userId' => $user->getId(), 'adminEmail' => $adminEmail]`.
5. Audit-logs action `admin.impersonate_start`, outcome `success`, context = target email.
6. Redirects to `app_impersonate_start`.

### `ImpersonationController::start` (admin → user, authenticator trampoline)

```php
public function start(): Response
```

A bare trampoline. By the time this action runs, `ImpersonationAuthenticator` has already authenticated the request (or redirected away on failure). It simply redirects to `app_dashboard`.

### `ImpersonationController::exitImpersonation` (admin → user, exit)

```php
#[IsGranted('ROLE_USER')]
public function exitImpersonation(Request $request, AuditLogger $auditLogger): Response
```

1. Validates CSRF token `impersonate_exit`; throws access-denied if invalid.
2. **Reads session keys** `_impersonating_by` (admin email) and `_impersonating_as` (target email), defaulting to `'unknown'`.
3. Audit-logs action `admin.impersonate_exit`, outcome `success`, context = target email.
4. **Removes session keys** `_impersonating_as`, `_impersonating_by`, and `_security_user` (clearing the user-firewall token so the impersonated session is dropped).
5. Redirects to `app_admin_dashboard`.

### `AdminAdminManagementController::list` (super-admin admins list)

```php
#[Route('', name: 'app_admin_superadmin_admins', methods: ['GET'])]
public function list(AdminRepository $adminRepository): Response
```

Renders `admin/superadmin/admins.html.twig` with all admins. The whole controller is guarded by class-level `#[IsGranted('ROLE_SUPER_ADMIN')]` under `/admin/superadmin/admins`.

### `AdminAdminManagementController::impersonateStart` (super-admin → admin, start)

```php
public function impersonateStart(int $id, Request $request, AdminRepository $adminRepository, TokenStorageInterface $tokenStorage, AuditLogger $auditLogger): Response
```

1. Looks up the target `Admin` by `{id}`; throws 404 if not found.
2. Validates CSRF token `admin_impersonate_admin_{id}`; throws access-denied if invalid.
3. Resolves the acting super-admin's identifier.
4. **Writes session keys**: `_original_admin_token` = the current `_security_admin` value (for restoration), `_impersonating_admin_as` = target email, `_impersonating_admin_by` = super-admin email.
5. Builds a new `PostAuthenticationToken($targetAdmin, 'admin', $targetAdmin->getRoles())`.
6. **Writes session key** `_security_admin` = `serialize($newToken)` (read by `ContextListener` on the next request) and calls `$tokenStorage->setToken($newToken)` so the listener persists the new token (not the original) at response time.
7. Audit-logs action `admin.impersonate_admin_start`, outcome `success`, context = target email.
8. Redirects to `app_admin_dashboard`.

### `ImpersonationController::exitAdminImpersonation` (super-admin → admin, exit)

```php
#[IsGranted('ROLE_ADMIN')]
public function exitAdminImpersonation(Request $request, TokenStorageInterface $tokenStorage, AuditLogger $auditLogger): Response
```

1. Validates CSRF token `admin_impersonate_exit`; throws access-denied if invalid.
2. **Reads session keys** `_impersonating_admin_by`, `_impersonating_admin_as`, and `_original_admin_token`.
3. If `_original_admin_token` is a non-empty string: sets `_security_admin` back to it, `unserialize()`s it, and — if it is a `TokenInterface` — re-installs it via `$tokenStorage->setToken()`.
4. Audit-logs action `admin.impersonate_admin_exit`, outcome `success`, context = target email.
5. **Removes session keys** `_original_admin_token`, `_impersonating_admin_as`, `_impersonating_admin_by`.
6. Redirects to `app_admin_superadmin_admins`.

## Services & classes

### `App\Security\ImpersonationAuthenticator extends AbstractAuthenticator`

Registered as a `custom_authenticator` on the **user** firewall (`config/packages/security.yaml`). Constructed with `UserRepository $userRepository`.

| Method | Signature | Purpose |
|--------|-----------|---------|
| `supports` | `supports(Request $request): ?bool` | Returns `true` only when the matched route is `app_impersonate_start`, so the authenticator runs exclusively on `/impersonate/start`. |
| `authenticate` | `authenticate(Request $request): Passport` | Starts the session if needed; reads `_impersonation_request`. Throws `CustomUserMessageAuthenticationException` if the payload is missing/malformed or the target user id is not found. On success: **removes** `_impersonation_request`, **writes** `_impersonating_as` (target email) and `_impersonating_by` (admin email), and returns a `SelfValidatingPassport` with a `UserBadge` resolved via `UserRepository::findByEmail`. |
| `onAuthenticationSuccess` | `onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response` | Redirects to `/dashboard`. |
| `onAuthenticationFailure` | `onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response` | Adds the exception message to the `error` flash bag and redirects to `/admin/login`. |

### `App\Config\ImpersonateConfigPage implements ConfigPageProviderInterface`

| Method | Signature | Purpose |
|--------|-----------|---------|
| `getSlug` | `getSlug(): string` | Returns `'impersonate'` (used for routing and the `data-slug` hook in the admin config UI). |
| `getTitle` | `getTitle(): string` | Returns `'Impersonation'`. |
| `getFields` | `getFields(): array` | Returns the field definition map — currently the single `impersonate.enabled` bool (default `'1'`). |

### `App\Service\AuditLogger` (collaborator)

Every start/exit action calls `log(string $actor, string $actorType, string $ip, string $action, string $outcome, ?string $context = null): void`. For impersonation, `actor` is the operator's email, `actorType` is `'admin'`, `context` is the target's email, and `action` is one of `admin.impersonate_start`, `admin.impersonate_exit`, `admin.impersonate_admin_start`, `admin.impersonate_admin_exit`.

## How it works

**Admin → user (session-handshake).** Symfony cannot authenticate one firewall (user) from a controller running under another (admin), so the bundle uses a two-request handshake:

1. The admin POSTs to `/admin/users/{id}/impersonate-start`. This route is behind `^/admin` (`ROLE_ADMIN`) and a per-user CSRF token, so only an authorised admin can plant the request. The controller drops an `_impersonation_request` payload into the session and redirects.
2. The browser follows the redirect to `/impersonate/start`. `ImpersonationAuthenticator::supports` matches this route on the user firewall; `authenticate` consumes the payload, deletes it (one-shot), looks up the target, and authenticates the session as that user. It records `_impersonating_as` / `_impersonating_by` so the banner can render.

The banner lives in `templates/base.html.twig`: when `_impersonating_as` is set it shows `Impersonating: {target} (as {admin})` plus a CSRF-protected "Exit Impersonation" form (`.impersonation-banner` / `.exit-impersonation-btn`). A parallel `_impersonating_admin_as` block renders `.admin-impersonation-banner` / `.exit-admin-impersonation-btn` for the super-admin flow.

**Exit (user impersonation).** Posting to `/impersonate/exit` clears `_impersonating_as`, `_impersonating_by`, and the user-firewall token (`_security_user`), then returns to the admin dashboard — the admin firewall token was never disturbed, so the admin is still themselves.

**Super-admin → admin (token swap).** Because both operator and target live on the *same* admin firewall, no second request is needed. The controller serialises the live admin token into `_original_admin_token`, then writes a fresh `PostAuthenticationToken` for the target into both `_security_admin` and `TokenStorage`. **Exit** reads `_original_admin_token`, unserialises it, and re-installs it — restoring the original super-admin identity exactly.

**Audit logging.** All four transitions emit an `AuditLogger` entry, so start and exit of both flows appear in `/admin/audit-log` with actor (impersonator) and context (impersonated target).

## Security notes

- **`PUBLIC_ACCESS` on `/impersonate/start` is intentional and safe.** The route is publicly *reachable* but does nothing unless an `_impersonation_request` session key is present — and that key can only be planted by the `^/admin`-protected, CSRF-guarded `impersonateStart` action. Without a valid planted payload, `authenticate` throws and the visitor is bounced to `/admin/login`. The access-control entry is `PUBLIC_ACCESS` precisely because the authenticator (not the firewall ACL) is the gate.
- **CSRF everywhere.** Every state-changing route validates a token: `admin_user_impersonate_{id}`, `impersonate_exit`, `admin_impersonate_admin_{id}`, `admin_impersonate_exit`. The banners render the matching hidden tokens.
- **One-shot payload.** `_impersonation_request` is removed the moment it is consumed, so a stale session key cannot be replayed.
- **Super-admin-over-admin rule.** Impersonating another admin requires `ROLE_SUPER_ADMIN` (class-level `#[IsGranted]` on `AdminAdminManagementController`); a regular admin gets a 403 on `/admin/superadmin/admins` and never sees the impersonate control.
- **Defence in depth.** Per the note in `security.yaml`, admin controllers carry class-level `#[IsGranted('ROLE_ADMIN')]` so they remain protected even in separate-domain mode where a host might match no firewall.

## Tests

| File | Type | Coverage |
|------|------|----------|
| `tests/Functional/Security/ImpersonateTest.php` | Functional | Admin user list shows the impersonate button; start switches the session to the target user; the `impersonation-banner` renders; exit restores the admin session. |
| `tests/Functional/Security/AdminImpersonateAdminTest.php` | Functional | Super-admin sees the impersonate control on the admins page and can switch to an admin (banner shows target); a regular admin gets 403 on `/admin/superadmin/admins`. |
| `tests/Functional/Security/ImpersonationAuditTest.php` | Functional | Start and exit each create an `audit_log` row with the correct actor, `actor_type=admin`, and target context; entries are visible in `/admin/audit-log`. |
| `tests/Functional/Admin/ImpersonateConfigPageTest.php` | Functional | The `impersonate` config sub-page appears in `/admin/config`; saving persists `impersonate.enabled` to the `config` table. |
| `tests/Acceptance/Auth/ImpersonationCest.php` | Acceptance (E2E) | Live-HTTP scenarios: start switches to target, banner on every page, exit restores admin, and start/exit appear in the audit log. |
