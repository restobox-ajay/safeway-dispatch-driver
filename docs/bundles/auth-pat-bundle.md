# auth-pat-bundle

User-side Personal Access Tokens (PATs) — Bearer-token authentication for the `api` firewall, managed from each user's account area.

[← Back to main README](../../README.md)

## Overview

This bundle gives end **users** long-lived API credentials (Personal Access Tokens) so
they can call the user-facing REST API without a browser session. A token is presented as
an HTTP `Authorization: Bearer <token>` header and authenticates the owning `User` on the
**`api` firewall** (`pattern: ^/api`, `stateless: true`, provider `app_users`,
custom authenticator `App\Security\TokenAuthenticator`).

Users create, list, and revoke their own tokens from `/account/tokens`. The plaintext
token is shown exactly once at creation; only its SHA-256 hash is stored.

**Distinction from the admin API.** This is *not* the admin REST API. The admin side uses
a **separate `AdminAccessToken` entity** and a **separate `AdminTokenAuthenticator`** on
the **`admin-api` firewall** (`pattern: ^/admin-api`, provider `app_admins`), which is
core boilerplate, not part of this bundle. Keep them straight:

| | This bundle (user PAT) | Admin API (core, separate) |
| --- | --- | --- |
| Firewall | `api` (`^/api`) | `admin_api` (`^/admin-api`) |
| Entity | `PersonalAccessToken` | `AdminAccessToken` |
| Authenticator | `TokenAuthenticator` | `AdminTokenAuthenticator` |
| Identity provider | `app_users` (`User`) | `app_admins` (`Admin`) |
| Managed at | `/account/tokens` | admin tooling |

The two firewalls are ordered so `admin_api` (`^/admin-api`) precedes `admin` (`^/admin`),
because `^/admin` would otherwise also match `/admin-api`.

## Configuration

Config page slug: **`pat`** (title "Personal Access Tokens"), provided by
`App\Config\PATConfigPage`, editable at `/admin/config/pat`. Values are read at
token-creation time via `ConfigService::getInt(...)`.

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `pat.default_expiry_days` | text (int) | `0` | Days until a newly created token expires. `0` = never expires (no `expiresAt` is set). |
| `pat.max_tokens_per_user` | text (int) | `0` | Maximum number of simultaneously active tokens per user. `0` = unlimited. Creation is blocked once the active count reaches this limit. |

## Routes

All user token-management routes live in `App\Controller\AccountController` under the
`/account` prefix (the `^/account` path requires `ROLE_USER`).

| Method | Path | Route name | Action | Purpose |
| --- | --- | --- | --- | --- |
| GET | `/account/tokens` | `app_account_tokens` | `tokens()` | List the current user's active tokens. |
| POST | `/account/tokens/new` | `app_account_tokens_new` | `createToken()` | Create a token; render the plaintext once. |
| POST | `/account/tokens/{id}/revoke` | `app_account_tokens_revoke` | `revokeToken()` | Revoke one of the current user's tokens. |

The API surface that PATs authenticate against is the `api` firewall — e.g.
`GET /api/ping` (`app_api_ping` in `App\Controller\ApiController`), used by the tests as
a protected probe endpoint.

Admins can additionally revoke *all* of a user's tokens (this is admin tooling acting on
PATs, not part of the user routes above):

- `POST /admin/users/{id}/revoke-tokens` — `app_admin_users_revoke_tokens`
  (`AdminUserController::revokeAllTokens`), CSRF-protected, audit-logged.
- `DELETE /admin-api/users/{id}/tokens` — `app_api_admin_users_revoke_tokens`
  (`Api\AdminApiUserController::revokeTokens`), returns `204 No Content`.

Both call `PersonalAccessTokenRepository::revokeAllByUserId()`.

## Controllers

`App\Controller\AccountController` token actions:

- **`tokens(PersonalAccessTokenRepository $tokenRepo): Response`** — reads the current
  user via `getUser()`, fetches active tokens with `findActiveByUserId((int)$user->getId())`,
  and renders `account/tokens.html.twig` with `tokens`.

- **`createToken(Request $request, PersonalAccessTokenRepository $tokenRepo, EntityManagerInterface $em, ConfigService $configService): Response`** —
  1. Validates the `token_create` CSRF token; on failure flashes an error and redirects
     to `app_account_tokens`.
  2. Trims the `name` parameter; if empty, re-renders `account/tokens.html.twig` with
     `error: 'Token name is required.'` and the current token list.
  3. Enforces `pat.max_tokens_per_user`: if `> 0` and
     `countActiveByUserId(...) >= $maxTokens`, re-renders the list with an error
     ("You have reached the maximum of N active token(s).") and does not create a token.
  4. Computes expiry from `pat.default_expiry_days`: `> 0` → `now + N days`, else `null`
     (never).
  5. Generates the secret `bin2hex(random_bytes(32))` (64 hex chars), hashes it with
     `hash('sha256', $plaintext)`, persists a new `PersonalAccessToken(userId, name, hash, expiresAt)`,
     and flushes.
  6. Renders `account/token_created.html.twig` with `token_name` and `token_plaintext` —
     the only time the plaintext is exposed.

- **`revokeToken(int $id, Request $request, PersonalAccessTokenRepository $tokenRepo, EntityManagerInterface $em): Response`** —
  validates the per-token `token_revoke_<id>` CSRF token (on failure: flash + redirect).
  Loads the token by id; if it does not exist **or** its `getUserId()` does not match the
  current user's id, throws `createNotFoundException('Token not found.')` (ownership
  check). Otherwise calls `$token->revoke()`, flushes, flashes "Token revoked.", and
  redirects to `app_account_tokens`.

## Services & classes

### `App\Entity\PersonalAccessToken`

Table `personal_access_tokens`. Columns: `id`, `userId` (int), `name` (string, 100),
`tokenHash` (string, 64, unique), `expiresAt` (?DateTimeImmutable, nullable),
`lastUsedAt` (?DateTimeImmutable, nullable), `revokedAt` (?DateTimeImmutable, nullable),
`createdAt` (DateTimeImmutable).

- **`__construct(int $userId, string $name, string $tokenHash, ?\DateTimeImmutable $expiresAt = null)`** —
  sets owner, name, hash and optional expiry; stamps `createdAt = new \DateTimeImmutable()`.
- **`getId(): ?int`** — primary key (null before persistence).
- **`getUserId(): int`** — owning user's id (used for ownership checks).
- **`getName(): string`** — human-readable token label.
- **`getTokenHash(): string`** — stored SHA-256 hash of the secret (never the plaintext).
- **`getExpiresAt(): ?\DateTimeImmutable`** — expiry instant, or null for "never".
- **`getLastUsedAt(): ?\DateTimeImmutable`** — last successful auth time, or null if unused.
- **`setLastUsedAt(\DateTimeImmutable $lastUsedAt): void`** — records last use (set by the
  authenticator on each successful auth).
- **`getRevokedAt(): ?\DateTimeImmutable`** — revocation instant, or null if not revoked.
- **`getCreatedAt(): \DateTimeImmutable`** — creation instant.
- **`isExpired(): bool`** — true iff `expiresAt !== null` and `expiresAt < now`.
- **`isRevoked(): bool`** — true iff `revokedAt !== null`.
- **`isActive(): bool`** — true iff `!isRevoked() && !isExpired()`.
- **`revoke(): void`** — sets `revokedAt = new \DateTimeImmutable()`.

### `App\Repository\PersonalAccessTokenRepository` (`ServiceEntityRepository<PersonalAccessToken>`)

- **`__construct(ManagerRegistry $registry)`** — binds the repository to
  `PersonalAccessToken`.
- **`findActiveByUserId(int $userId): array`** — returns `PersonalAccessToken[]` for the
  user that are not revoked and not expired (`expiresAt IS NULL OR expiresAt > now`),
  ordered by `createdAt DESC`.
- **`countActiveByUserId(int $userId): int`** — count of that same active set for one user
  (used to enforce `pat.max_tokens_per_user`).
- **`findByTokenHash(string $hash): ?PersonalAccessToken`** — single token by its unique
  `tokenHash`, or null (the authenticator's lookup).
- **`revokeAllByUserId(int $userId): int`** — bulk DQL `UPDATE` setting `revokedAt = now`
  on all of the user's not-yet-revoked tokens; returns the number of rows affected (used
  by admin revoke-all).
- **`countActiveByUserIds(array $userIds): array`** — `@param int[] $userIds`,
  `@return array<int,int>` mapping userId → active token count, in a single grouped query;
  returns `[]` for an empty input (used to show per-user counts in the admin user list).

### `App\Security\TokenAuthenticator` (extends `AbstractAuthenticator`)

The custom authenticator for the `api` firewall. Constructor:
`PersonalAccessTokenRepository $tokenRepository`, `UserRepository $userRepository`,
`EntityManagerInterface $em`.

- **`supports(Request $request): ?bool`** — always returns `true`: the authenticator is
  active on every `api`-firewall request, so missing/invalid credentials surface as a 401
  via `onAuthenticationFailure` rather than falling through.
- **`authenticate(Request $request): Passport`** — requires an
  `Authorization: Bearer <token>` header (otherwise
  `CustomUserMessageAuthenticationException('Missing or invalid Authorization header.')`).
  Strips the `Bearer ` prefix, hashes the plaintext with `hash('sha256', ...)`, and looks
  it up via `findByTokenHash()`. Throws a `CustomUserMessageAuthenticationException` for:
  no match (`'Invalid token.'`), revoked (`'Token has been revoked.'`), or expired
  (`'Token has expired.'`). On success it stamps `setLastUsedAt(now)` and flushes, then
  returns a `SelfValidatingPassport` whose `UserBadge` carries the token's `userId`; the
  badge's loader resolves the `User` via `UserRepository::find()`, throwing
  `'User not found.'` if absent.
- **`onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response`** —
  returns `null`, letting the request continue to the controller.
- **`onAuthenticationFailure(Request $request, AuthenticationException $exception): Response`** —
  returns a `JsonResponse` `{ "error": <messageKey> }` with HTTP `401 Unauthorized`.

## Security notes

- The secret is `bin2hex(random_bytes(32))` — 32 cryptographically random bytes rendered
  as 64 hex characters.
- **Only the SHA-256 hash is persisted** (`tokenHash`, unique). The plaintext is shown
  **once** on the creation screen (`account/token_created.html.twig`) and is never stored
  or recoverable; a lost token must be revoked and recreated.
- Authentication hashes the presented Bearer value and matches it against the stored hash,
  so the database never contains usable credentials.
- Tokens carry an optional **expiry** (`pat.default_expiry_days`) and can be **revoked**;
  both are checked on every request, and `findActiveByUserId`/`countActiveByUserId` exclude
  revoked and expired tokens.
- **Ownership is enforced** on revoke: `revokeToken` 404s unless the token's `userId`
  matches the current user. All user-facing token mutations are CSRF-protected
  (`token_create`, `token_revoke_<id>`).
- **Admins can revoke a user's tokens** out-of-band — via the admin UI
  (`POST /admin/users/{id}/revoke-tokens`, CSRF-protected and audit-logged) or the admin
  API (`DELETE /admin-api/users/{id}/tokens`), both delegating to `revokeAllByUserId`.
  Because the `api` firewall is stateless, a revoked token fails on its very next request.
- The `api` firewall is stateless: a Bearer token never creates or relies on a session,
  and is independent of any admin/user browser session on the same client.

## Tests

- `tests/Functional/Account/PersonalAccessTokenTest.php` — token list renders active
  tokens; creation shows the plaintext once and stores only the hash; the stored value is
  the SHA-256 of the shown plaintext (plaintext never stored); a user can revoke their own
  token (`revoked_at` set); `pat.max_tokens_per_user` blocks creation past the limit;
  expired tokens do not appear in the active list.
- `tests/Functional/Security/TokenAuthenticatorTest.php` — missing `Authorization` header
  → 401 JSON with `error`; invalid and revoked tokens → 401 JSON; a valid token
  authenticates `/api/ping` successfully; an expired token → 401 JSON; `last_used_at` is
  updated on a successful authenticated request.
- `tests/Functional/Admin/AdminRevokeUserTokensTest.php` — admin user list shows the active
  token count and a "Revoke All Tokens" form; revoking marks all of a user's active tokens
  revoked immediately; a subsequent `/api/ping` call with the now-revoked Bearer token
  returns 401.
- `tests/Functional/Security/AdminApiBoundaryTest.php` and
  `tests/Acceptance/Admin/AdminApiCest.php` — exercise the separate admin API boundary
  (the `admin-api` firewall / `AdminAccessToken`), confirming it is distinct from user PATs.
