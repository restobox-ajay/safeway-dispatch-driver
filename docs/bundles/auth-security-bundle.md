# auth-security-bundle
Fail-closed, DBAL-backed rate limiting for all auth endpoints plus optional account lockout and admin unlock.

[← Back to main README](../../README.md)

## Overview

The auth-security-bundle is the throttling and lockout layer that protects every
authentication entry point against brute force and abuse. It has two
complementary mechanisms:

1. **Sliding-window rate limiting** — counts recent attempts and blocks once a
   configured ceiling is reached within a time window. Login form submissions
   are throttled by `LoginRateLimitListener` (hooked into the Symfony security
   `CheckPassportEvent`); the non-login endpoints (forgot-password, magic-link,
   resend-verification, 2FA challenge) are throttled by the reusable
   `EndpointRateLimiter` service. Both read the **same** admin config keys
   (`rate_limit.max_attempts` / `rate_limit.window_seconds`), so a single knob
   governs all auth throttling.
2. **Temporary account lockout** — after a configurable number of failed logins
   for a given account, that account's `user.locked_until` column is stamped with
   a future timestamp; `UserChecker::checkPreAuth()` then refuses login until the
   timestamp passes. This is account-keyed and **OFF by default** (see
   [Account lockout](#account-lockout) for the DoS rationale).

Both mechanisms are **fail-closed**: when the admin has never configured a value,
the safe non-zero `DEFAULT_MAX_ATTEMPTS = 10` applies rather than running
unthrottled. An *explicit* configured value of `0` disables the relevant
mechanism (because `ConfigService` returns the default only when the key is
absent, not when it is set to `0`).

Storage is plain DBAL against two tables — `login_attempts` (login throttling +
lockout counting) and `endpoint_rate_limits` (non-login endpoints) — both of
which are pruned to a rolling 1-day window on write so they stay bounded.

## Configuration

Config lives on the admin **Security** config page, slug **`security`**
(`App\Config\SecurityConfigPage`), and is read at runtime through
`ConfigService::getInt()`. All values are stored as text and parsed to int.

| Key | Type | Default | Meaning |
|-----|------|---------|---------|
| `rate_limit.max_attempts` | int | `10` | Max auth attempts per window before requests are blocked. Governs login, forgot-password, magic-link, resend-verification, and 2FA challenge. `0` = throttling disabled. Default `10` is the fail-closed value applied when the key is absent. |
| `rate_limit.window_seconds` | int | `300` | Length of the sliding window, in seconds (default 5 minutes). Attempts older than this no longer count toward the limit. |
| `lockout.max_attempts` | int | `0` | Failed logins (per account, counted over the last 24h) that trigger a temporary account lock. `0` = lockout disabled (the default — see [Account lockout](#account-lockout)). |
| `lockout.duration_minutes` | int | `15` | How long an account stays locked once triggered (default 15 minutes). Only consulted when `lockout.max_attempts > 0`. |

## Routes

There is no dedicated "AdminSecurityController" unlock route — manual unlock lives
on the user-admin controllers. `AdminSecurityController` only exposes the admin
login/logout firewall routes.

| Method | Path | Route name | Controller | Purpose |
|--------|------|-----------|-----------|---------|
| POST | `/admin/users/{id}/unlock` | `app_admin_users_unlock` | `AdminUserController::unlock` | Manually clear a locked account: sets `locked_until = null`, writes an `admin.user_unlock` audit entry, flashes success. CSRF-protected with token id `admin_user_unlock_{id}`. |
| POST | `/api/admin/users/{id}/unlock` | `app_api_admin_users_unlock` | `Api\AdminApiUserController::unlock` | JSON API equivalent: sets `locked_until = null` and returns `{"status":"ok"}` (404 `{"error":"User not found."}` if the id is unknown). |

(The admin firewall routes `app_admin_login` / `app_admin_logout` are provided by
`AdminSecurityController` but are not part of this bundle's throttling surface.)

## Services & classes

### `App\Security\LoginRateLimitListener`

Event listener that throttles form-login and (optionally) applies account lockout.
Constructed with `Doctrine\DBAL\Connection`, `ConfigService`, and `RequestStack`.

| Member | Signature | Purpose |
|--------|-----------|---------|
| Constant | `public const DEFAULT_MAX_ATTEMPTS` | Fail-closed default, aliased to `EndpointRateLimiter::DEFAULT_MAX_ATTEMPTS` (= `10`) so login and the other endpoints share one default. |
| Method | `onCheckPassport(CheckPassportEvent $event): void` | Listener on `CheckPassportEvent` at **priority 2048** (ahead of CSRF at 512 and the user checker at 256). Returns early unless the authenticator is a `FormLoginAuthenticator`. Reads `rate_limit.max_attempts` (returns early if `<= 0`). Computes the window cutoff from `rate_limit.window_seconds` (default 300). Performs a **per-IP** count against `login_attempts` and throws `TooManyLoginAttemptsException` if `>= maxAttempts`; then, if the passport carries a `UserBadge`, performs a **per-account** count by email and throws likewise. |
| Method | `onLoginFailure(LoginFailureEvent $event): void` | Listener on `LoginFailureEvent`. Returns early if the failure exception is itself a `TooManyLoginAttemptsException` (so a throttled request is not re-recorded) or if the authenticator is not a `FormLoginAuthenticator`. Otherwise inserts an `(ip, email, attempted_at)` row into `login_attempts`, prunes rows older than 1 day, then calls the private lockout routine. |
| Method (private) | `maybeApplyLockout(?string $email): void` | No-op if email is null. Reads `lockout.max_attempts` and returns if `0` (lockout off). Counts this account's failures over the last 24h; if `>= lockout.max_attempts`, computes `locked_until = now + lockout.duration_minutes` (default 15) and runs `UPDATE "user" SET locked_until = ? WHERE email = ?`. |

### `App\Security\EndpointRateLimiter`

Reusable DBAL-backed sliding-window limiter for non-login auth endpoints.
Constructed with `Doctrine\DBAL\Connection` and `ConfigService`.

| Member | Signature | Purpose |
|--------|-----------|---------|
| Constant | `public const DEFAULT_MAX_ATTEMPTS = 10` | Fail-closed default attempts per window when `rate_limit.max_attempts` is unset. |
| Constant | `public const DEFAULT_WINDOW_SECONDS = 300` | Default window length (5 minutes) when `rate_limit.window_seconds` is unset. |
| Method | `tooManyAttempts(string $action, string $key): bool` | The single public entry point. Reads `rate_limit.max_attempts` and returns `false` (allow) if `<= 0`. Otherwise prunes `endpoint_rate_limits` rows older than 1 day, counts rows matching `(action, rate_key)` inside the window, and returns `true` when the count `>= maxAttempts`. **When the limit is reached the attempt is NOT recorded** (a blocked caller cannot keep growing the table); when under the limit it inserts an `(action, rate_key, hit_at)` row and returns `false`. `$action` is the logical endpoint name (e.g. `forgot_password`); `$key` is the per-actor key (client IP, or user id for the 2FA challenge). |

Callers of `tooManyAttempts()`:

| Controller | `$action` | `$key` |
|-----------|-----------|--------|
| `PasswordResetController` | `forgot_password` | client IP |
| `MagicLinkController` | `magic_link` | client IP |
| `EmailVerificationController` | `resend_verification` | client IP |
| `TwoFactorController` | `2fa_challenge` | `(string) $user->getId()` |

### `App\Security\TooManyLoginAttemptsException`

`final` class extending `CustomUserMessageAuthenticationException`.

| Member | Signature | Purpose |
|--------|-----------|---------|
| Constructor | `__construct()` | Builds the exception with the fixed user-facing message *"Too many login attempts. Please try again later."* Thrown by `LoginRateLimitListener::onCheckPassport()` and surfaced to the login form as an authentication error. |

### `App\Security\UserChecker` (lockout enforcement)

`final` class implementing `UserCheckerInterface`. Not exclusive to this bundle
(it also handles `status` and email-verification gating), but it is where the
lockout is *enforced*.

| Member | Signature | Purpose |
|--------|-----------|---------|
| Method | `checkPreAuth(UserInterface $user): void` | If `user.locked_until` is set and still in the future, throws `CustomUserMessageAuthenticationException` with *"Your account is temporarily locked. Please try again in N minute(s)."* (N = remaining minutes, floored at 1). Also enforces `status === 'active'` and required email verification. |
| Method | `checkPostAuth(UserInterface $user): void` | Re-checks `status` and email verification after credentials are validated. Does not re-check lockout (it is a pre-auth gate). |

### `App\Config\SecurityConfigPage`

`ConfigPageProviderInterface` implementation that registers the Security admin
page (slug `security`, title "Security") and declares the four config fields in
the [Configuration](#configuration) table via `getFields()`.

## How throttling works

**Login form** is throttled by `LoginRateLimitListener::onCheckPassport()`, which
hooks `CheckPassportEvent` at **priority 2048** and only acts on the
`FormLoginAuthenticator`. Throttling is keyed two ways and either can block:

- **Per-IP**: count of `login_attempts` rows for the request's `getClientIp()`
  within `rate_limit.window_seconds`.
- **Per-account**: when the passport carries a `UserBadge`, count of
  `login_attempts` rows for the submitted email within the same window.

If either count reaches `rate_limit.max_attempts`, a
`TooManyLoginAttemptsException` is thrown and the credentials are never checked.

**Throttle-first ordering.** The throttle runs on `CheckPassportEvent` at priority 2048 — ahead of
Symfony's `CsrfProtectionListener` (512) and `UserCheckerListener` (256), like Symfony's own
`LoginThrottlingListener`. It reads only the submitted identifier from the `UserBadge` and never loads the
user, so a throttled request gets the same "Too many login attempts" answer whether or not the account
exists (issue #25: at the old priority 200 it ran after the user lookup, and an unknown email still got
"Invalid credentials" — an account-existence oracle for anyone who had throttled their own IP).

**Recording failures.** On `LoginFailureEvent`, any failure *other than* an
already-thrown `TooManyLoginAttemptsException` is recorded as a new
`login_attempts` row, the table is pruned to 1 day, and lockout is evaluated.
A *successful* login records nothing, so good logins never count toward the
threshold.

**Non-login endpoints** (forgot-password, magic-link, resend-verification, 2FA
challenge) call `EndpointRateLimiter::tooManyAttempts($action, $key)` at the top
of their handlers. IP-keyed endpoints use `getClientIp()`; the 2FA challenge is
keyed by user id because the user is already partially authenticated at that
point.

**Fail-closed default = 10.** Because `ConfigService::getInt()` returns the
supplied default only when the key is *absent*, an installation that has never
touched the Security page still enforces a 10-attempts-per-5-minutes ceiling.
Setting `rate_limit.max_attempts` to `0` is the explicit, deliberate way to turn
throttling off.

**X-Forwarded-For is not trusted by default.** Keying uses Symfony's
`Request::getClientIp()`, which only honors `X-Forwarded-For` for proxies listed
in `framework.trusted_proxies`. With no trusted proxies configured (the default),
the raw `REMOTE_ADDR` is used, so a client cannot spoof its way past the per-IP
limit by sending a forged `X-Forwarded-For` header. If you deploy behind a real
load balancer you must configure trusted proxies for the per-IP key to reflect
the true client.

## Account lockout

Account lockout is a **temporary, account-keyed** hard block layered on top of
rate limiting. When enabled, a run of failed logins for one account stamps
`user.locked_until` with `now + lockout.duration_minutes`, and `UserChecker`
then refuses that account's logins (with a "try again in N minutes" message)
until the timestamp passes — at which point it auto-expires with no cleanup job
needed. An admin can clear it early via the [unlock route](#routes).

**It is OFF by default (`lockout.max_attempts = 0`), and that is the safe
choice — not a fail-open gap.** The reasoning, straight from the code comment in
`maybeApplyLockout()`:

- Brute force is **already** throttled fail-closed by the rate limiter
  (`rate_limit.max_attempts`, default 10), which is per-IP *and* per-account, so
  there is no unthrottled-brute-force window when lockout is off.
- The lockout is **account-keyed** (`UPDATE "user" SET locked_until WHERE
  email = ?`). Enabling it by default would hand any attacker a
  **username-targeted denial of service**: simply spamming failed logins for a
  victim's email would keep that victim locked out indefinitely.

So lockout only activates when an operator deliberately sets
`lockout.max_attempts > 0`, accepting the DoS trade-off for their environment.

## Security notes

- **Fail-closed, not fail-open.** Missing config yields the protective default
  (10/300s), never "no limit". Only an explicit `0` disables a mechanism.
- **Blocked endpoint attempts are not recorded** by `EndpointRateLimiter`, and
  throttled login failures are skipped in `onLoginFailure`, so an attacker who is
  already over the limit cannot inflate the counter tables (no write amplification
  / table-growth DoS).
- **Bounded storage.** Both `login_attempts` and `endpoint_rate_limits` are
  pruned to a rolling 1-day window on every write.
- **Spoofing resistance.** Per-IP keying relies on `getClientIp()` and ignores
  untrusted `X-Forwarded-For` (see above) — configure trusted proxies when behind
  a load balancer.
- **Lockout DoS awareness.** Account lockout is intentionally opt-in to avoid a
  username-targeted lockout-DoS; turn it on only when that trade-off is acceptable.
- **CSRF on unlock.** The admin web unlock route validates a per-user CSRF token
  (`admin_user_unlock_{id}`) and writes an `admin.user_unlock` audit log entry.
- **Throttle ordering.** Login throttling runs at `CheckPassportEvent` priority
  2048, ahead of CSRF and the user checker, so a throttled request never reveals whether
  the account exists and the cheap counting check gates the expensive work.

## Tests

| Test file | Coverage |
|-----------|----------|
| `tests/Functional/Security/RateLimitTest.php` | Per-IP threshold returns lockout response; window/max-attempts are configurable; per-account threshold blocks from any IP; default rate limit is fail-closed when config is absent; admin login is throttled; successful login does not count toward the failure threshold. |
| `tests/Functional/Security/EndpointRateLimitTest.php` | Forgot-password, magic-link, resend-verification, and 2FA challenge endpoints are each throttled. |
| `tests/Functional/Security/AccountLockoutTest.php` | `User` entity has a `locked_until` field; after N failed attempts `locked_until` is set; a locked account shows the error with remaining time; lockout expires automatically. |
| `tests/Functional/Admin/AdminUnlockTest.php` | Locked user shows a locked indicator in the list; the unlock action appears for a locked user; clicking unlock sets `locked_until` to null; after unlock the user can log in immediately. |
| `tests/Functional/Admin/SecurityConfigPageTest.php` | The Security admin config page renders and persists the rate-limit / lockout fields. |
| `tests/Functional/Api/AdminApiBundleEndpointsTest.php` | JSON admin unlock endpoint behavior. |
| `tests/Acceptance/Auth/SecurityBundleCest.php` | End-to-end security/throttling user flows. |

Run with `bin/verify-fast.sh`.
