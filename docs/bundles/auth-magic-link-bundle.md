# auth-magic-link-bundle
Passwordless email login: request a single-use, hashed, time-limited link and click it to authenticate.

[← Back to main README](../../README.md)

## Overview

The magic-link bundle lets an existing **active** user log in without a password. The flow is:

1. The user visits `GET /magic-link` and submits their email.
2. If the email belongs to an active user, the controller generates a high-entropy plaintext token, stores only its SHA-256 hash in `magic_link_tokens`, and emails an absolute verification URL containing the plaintext token. Regardless of whether the email matched, the request always redirects to `/magic-link/check` (anti-enumeration).
3. The user clicks the emailed link `GET /magic-link/verify?token=…`. `MagicLinkAuthenticator` intercepts that route, re-hashes the supplied plaintext, looks up the token, validates expiry/single-use, marks it used, and logs the user in.
4. On success the `verify` controller action redirects to the dashboard; on failure the authenticator redirects back to `/magic-link` with a flash error.

Token expiry is configurable from the admin config UI. POST requests to the request endpoint are CSRF-protected and IP rate limited.

## Configuration

Admin config page slug: **`magic-link`** (title "Magic Link"), provided by `App\Config\MagicLinkConfigPage`. Editable at `/admin/config` (saved via `POST /admin/config/magic-link`).

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `magic_link.expiry_minutes` | text | `15` | Minutes a freshly issued magic link remains valid before it expires. Read at request time via `ConfigService::getInt('magic_link.expiry_minutes', 15)`. |

Rate limiting reuses the shared auth-throttle keys (not exposed on this page): `rate_limit.max_attempts` (default `10`) and `rate_limit.window_seconds` (default `300`), read by `EndpointRateLimiter`.

## Routes

| Method | Path | Route name | Auth | Description |
| --- | --- | --- | --- | --- |
| GET, POST | `/magic-link` | `app_magic_link` | PUBLIC_ACCESS | GET renders the email request form; POST validates CSRF + rate limit, issues a token, emails the link, and redirects to the check page. |
| GET | `/magic-link/check` | `app_magic_link_check` | PUBLIC_ACCESS | Neutral confirmation page shown after any request submission (no enumeration signal). |
| GET | `/magic-link/verify` | `app_magic_link_verify` | PUBLIC_ACCESS | Token verification target. `MagicLinkAuthenticator` runs first; on success this action redirects to the dashboard, on failure the authenticator redirects to `/magic-link`. |

All three paths are covered by `access_control` rule `{ path: ^/magic-link, roles: PUBLIC_ACCESS }` in `config/packages/security.yaml`, and the verify route is handled within the `user` firewall, which registers `MagicLinkAuthenticator` as a custom authenticator.

## Controllers

`App\Controller\MagicLinkController` (extends `AbstractController`).

### `request(Request $request, UserRepository $userRepository, EntityManagerInterface $em, MailerInterface $mailer, ConfigService $configService, EndpointRateLimiter $rateLimiter): Response`

Route `app_magic_link` (`/magic-link`, GET+POST).

- Pulls any `magic_link_error` flash message (set by the authenticator's failure handler) and uses the first one as `$error`.
- **On GET:** renders `security/magic_link.html.twig` with `error`.
- **On POST:**
  1. Resolves the client IP (falls back to `0.0.0.0`). If `EndpointRateLimiter::tooManyAttempts('magic_link', $ip)` is true, re-renders the form with error "Too many requests. Please try again later." and does not proceed.
  2. Validates the CSRF token `magic_link_request` against POST field `_token`; on mismatch re-renders the form with error "Invalid security token. Please try again."
  3. Trims the `email` POST field and looks up the user via `UserRepository::findByEmail($email)`.
  4. **Only if** the user exists **and** `getStatus() === 'active'`:
     - Reads `magic_link.expiry_minutes` (default 15) from config.
     - Generates `$plaintextToken = bin2hex(random_bytes(32))` (64 hex chars).
     - Computes `$tokenHash = hash('sha256', $plaintextToken)`.
     - Computes `$expiresAt = now + expiryMinutes`.
     - Persists a new `MagicLinkToken($email, $tokenHash, $expiresAt)` and flushes.
     - Builds an **absolute** verify URL for `app_magic_link_verify` carrying the **plaintext** token.
     - Sends an `Email` to the address (subject "Your magic login link") rendered from `security/email/magic_link_email.html.twig` with `verifyUrl`, `expiresAt`, `expiryMinutes`.
  5. **Always** redirects to `app_magic_link_check`, whether or not an email was sent (anti-enumeration).

### `check(): Response`

Route `app_magic_link_check` (`/magic-link/check`, GET). Renders `security/magic_link_check.html.twig`. No input, no DB access.

### `verify(): Response`

Route `app_magic_link_verify` (`/magic-link/verify`, GET). Redirects to `app_dashboard`. This action only runs when `MagicLinkAuthenticator` has already succeeded (the authenticator returns `null` from its success handler so the controller runs); on authenticator failure this body never executes because the failure handler returns a redirect.

## Services & classes

### `App\Security\MagicLinkAuthenticator` (extends `AbstractAuthenticator`, `final`)

Constructor: `__construct(EntityManagerInterface $em, UserRepository $userRepository)`.

- `supports(Request $request): ?bool` — returns true only when the matched `_route` is `app_magic_link_verify`, so the authenticator engages exclusively on the verify endpoint.
- `authenticate(Request $request): Passport` — explicitly starts the session if one exists and is not yet started (required so the session ID migrates on a GET and the auth cookie is persisted). Reads the `token` query param, computes `hash('sha256', $plaintext)`, and looks up the `MagicLinkToken` by `tokenHash`. Throws `CustomUserMessageAuthenticationException` with a user-facing message when the token is missing ("This magic link is invalid."), expired ("This magic link has expired. Please request a new one."), or already used ("This magic link has already been used."). On success it calls `markUsed()`, flushes, and returns a `SelfValidatingPassport` whose `UserBadge` loads the user via `UserRepository::findByEmail()` keyed on the token's email.
- `onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response` — returns `null` so the matched controller action (`verify`) runs and the session is saved through the normal response pipeline.
- `onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response` — adds the exception message to the `magic_link_error` flash bag and returns a `RedirectResponse('/magic-link')`.

### `App\Config\MagicLinkConfigPage` (implements `ConfigPageProviderInterface`)

- `getSlug(): string` — returns `'magic-link'` (used as the admin config sub-page identifier / `data-slug`).
- `getTitle(): string` — returns `'Magic Link'`.
- `getFields(): array` — returns the editable field map: a single `magic_link.expiry_minutes` field (label "Magic Link Expiry (minutes)", type `text`, default `'15'`).

### `App\Security\EndpointRateLimiter` (shared, used by this bundle)

Constructor: `__construct(Connection $connection, ConfigService $configService)`. Constants: `DEFAULT_MAX_ATTEMPTS = 10`, `DEFAULT_WINDOW_SECONDS = 300`.

- `tooManyAttempts(string $action, string $key): bool` — DBAL-backed sliding-window limiter over the `endpoint_rate_limits` table. The magic-link controller calls it with `action = 'magic_link'` and `key = client IP`. Reads `rate_limit.max_attempts` / `rate_limit.window_seconds` from config; a configured `max_attempts <= 0` disables throttling (returns false). Prunes rows older than one day, counts hits for `(action, key)` inside the window, and returns true when the count has reached the limit (in which case the current attempt is **not** recorded); otherwise records the attempt and returns false.

## Entity fields

`App\Entity\MagicLinkToken` — table `magic_link_tokens`.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | int, auto-generated | Primary key. |
| `email` | string(180) | Email of the requesting user; used to load the user on verify. |
| `tokenHash` | string(64), **unique** | SHA-256 hex hash of the plaintext token. Plaintext is never stored. |
| `expiresAt` | `DateTimeImmutable` | Absolute expiry timestamp (issue time + `magic_link.expiry_minutes`). |
| `usedAt` | `DateTimeImmutable` \| null | Set when the link is consumed; null while unused. Enforces single-use. |
| `createdAt` | `DateTimeImmutable` | Set in the constructor at creation time. |

Constructor: `__construct(string $email, string $tokenHash, \DateTimeImmutable $expiresAt)` — sets email/hash/expiry and stamps `createdAt = now`.

Key methods:

- `getId(): ?int`
- `getEmail(): string`
- `getTokenHash(): string`
- `getExpiresAt(): \DateTimeImmutable`
- `getUsedAt(): ?\DateTimeImmutable`
- `getCreatedAt(): \DateTimeImmutable`
- `markUsed(): void` — single-use enforcement; stamps `usedAt = now`.
- `isExpired(): bool` — expiry check; true when `expiresAt < now`.
- `isUsed(): bool` — true when `usedAt !== null`.
- `isValid(): bool` — convenience: `!isExpired() && !isUsed()`.

> Note: token hashing happens in the controller/authenticator (`hash('sha256', $plaintext)`), not inside the entity — the entity stores and compares the already-hashed value. There is no dedicated repository class for `MagicLinkToken`; lookups use the default Doctrine repository via `findOneBy(['tokenHash' => …])`.

## Security notes

- **Token entropy:** plaintext token is `bin2hex(random_bytes(32))` — 32 cryptographically-random bytes (256 bits) rendered as a 64-char hex string. Sent only in the emailed link.
- **Hashed at rest:** only `hash('sha256', $plaintext)` is persisted in `tokenHash` (unique column). The plaintext is never stored, so a DB read cannot reconstruct usable links. Verification re-hashes the supplied plaintext and matches on the hash.
- **Single-use:** `markUsed()` stamps `usedAt` and is flushed during `authenticate()`; a second verify of the same token throws "This magic link has already been used."
- **Expiry:** every token carries `expiresAt`; `isExpired()` rejects stale links with "This magic link has expired. Please request a new one." Expiry duration is admin-configurable (`magic_link.expiry_minutes`, default 15).
- **Anti-enumeration:** the request endpoint always redirects to `/magic-link/check` and only sends mail for an existing **active** user, so a caller cannot distinguish registered from unregistered (or inactive) emails by response.
- **Rate limiting:** POST `/magic-link` is throttled per client IP via `EndpointRateLimiter::tooManyAttempts('magic_link', $ip)` using the shared `rate_limit.*` config; exceeding the limit returns the "Too many requests" error instead of issuing a token.
- **CSRF:** the request form is protected by the `magic_link_request` CSRF token; an invalid token returns "Invalid security token."
- **Session handling:** `authenticate()` explicitly starts the session on the GET verify request so the session ID migrates and the auth cookie persists after the post-login redirect.
- **Inactive users:** only users with `getStatus() === 'active'` are issued links; the `user` firewall also runs `App\Security\UserChecker` on the loaded user.

## Tests

- `tests/Functional/Security/MagicLinkTest.php` — functional `WebTestCase` covering: GET renders the email form (AC1); POST sends mail to a registered active user and redirects to `/magic-link/check` (AC2); unregistered email yields the same confirmation with no mail (AC2 anti-enumeration); a valid link logs in and redirects to `/dashboard` (AC3); expired and already-used links redirect to `/magic-link` with a `.error` containing the relevant message (AC4); and admin-configured expiry (30 min) is honored on the stored token (AC5).
- `tests/Acceptance/Auth/MagicLinkCest.php` — end-to-end (real HTTP) coverage of the same flow (FEATURE-075): request page renders the input; known email shows the neutral confirmation and writes exactly one token; a valid link authenticates and keeps `/dashboard` accessible while marking the token used; expired and already-used links show a clear `.error` and grant no access (redirect to `/login`).
- `tests/Functional/Admin/MagicLinkConfigPageTest.php` — admin config page tests: the `magic-link` sub-page appears at `/admin/config` (AC1); it exposes the `fields[magic_link.expiry_minutes]` input (AC2); and saving the form persists the value to the `config` table (AC3).
- `tests/Functional/Security/EndpointRateLimitTest.php` — `testMagicLinkIsThrottled` asserts that after `rate_limit.max_attempts` (set to 3) submissions within the window, the next `/magic-link` POST renders a `.error` containing "Too many requests".
