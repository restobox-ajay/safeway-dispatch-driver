# auth-2fa-bundle
Self-contained TOTP-based two-factor authentication: setup, login challenge, trusted devices, trusted IPs, and admin reset.

[← Back to main README](../../README.md)

## Overview

This bundle implements time-based one-time-password (TOTP, RFC 6238 style) 2FA from scratch — there is no third-party 2FA bundle. The core pieces are:

- A hand-rolled `TotpService` that generates Base32 secrets, builds `otpauth://` URIs and QR codes (via `endroid/qr-code`), and verifies codes using HMAC-SHA1 over a 30-second time-counter with a ±1 window (6 digits, `SHA1`).
- **Replay protection**: every successful verification returns the matched time-counter, which is persisted in `User::$lastTotpCounter`. Subsequent verifications reject any counter at or below that floor, so a code cannot be re-used within its validity window. Code comparison uses `hash_equals` for constant-time matching.
- **Enforcement modes** (`off` / `optional` / `required`) applied globally by a kernel-request listener that gates every interactive request once a user is authenticated.
- **Trusted devices**: an HMAC-signed cookie (`TRUSTED_DEVICE`, signed with `kernel.secret`) lets a verified browser skip the challenge for a configurable number of days.
- **Trusted IPs**: configured client IPs / CIDR ranges bypass the challenge entirely.
- Account self-service setup/disable, and an admin "reset 2FA" action for locked-out users.

## Configuration

Admin config sub-page slug: **`2fa`** (title "Two-Factor Authentication"), provided by `App\Config\TwoFactorConfigPage`.

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `2fa.enforcement` | text | `optional` | Global enforcement mode. `off` = no challenge ever (even for enabled users); `optional` = challenge only users who enabled 2FA; `required` = challenge enabled users and redirect users without 2FA to setup. |
| `trusted_device.lifetime_days` | text | `30` | Lifetime in days of the trusted-device cookie issued when a user ticks "trust this device". |
| `2fa.trusted_ips` | text | `` (empty) | Comma-separated list of IPs / CIDR ranges whose clients skip the 2FA challenge. Empty disables IP bypass. |

## Routes

| Method | Path | Route name | Auth | Description |
| --- | --- | --- | --- | --- |
| GET, POST | `/account/2fa/setup` | `app_2fa_setup` | `ROLE_USER` | Show QR/secret and confirm-and-enable 2FA; audited `2fa_enable` (context `enrolled`/`reconfigured`). |
| POST | `/account/2fa/disable` | `app_2fa_disable` | `ROLE_USER` | Disable 2FA for the current user; audited `2fa_disable`. |
| GET, POST | `/2fa/challenge` | `app_2fa_challenge` | `ROLE_USER` | Login-time TOTP challenge. |
| POST | `/{id}/reset-2fa` | `app_admin_users_reset_2fa` | admin (AdminUserController) | Admin clears a user's TOTP secret / disables their 2FA. |

`TwoFactorController` is annotated `#[IsGranted('ROLE_USER')]` at the class level. The admin reset route lives in `AdminUserController` under that controller's admin route prefix.

## Controllers

### `TwoFactorController::setup(Request $request, TotpService $totp, EntityManagerInterface $em): Response`

Self-service enable flow for the logged-in user.

- **GET**: generates a fresh Base32 secret via `TotpService::generateSecret()`, stores it in the session under `_2fa_temp_secret`, and renders `account/2fa/setup.html.twig` with the secret and a QR-code data URI.
  - **Already-enrolled guard**: if the user *already has 2FA enabled*, a plain GET does **not** mint a new secret/QR — it renders the "already enabled" status panel (disable button + a "set up a new device" link). Re-enrollment is deliberate via `?reconfigure=1`, which shows a fresh QR with a warning that confirming the new code replaces the current authenticator. The current secret keeps working until the new one is confirmed.
- **POST**:
  1. Validates CSRF token `2fa_setup`; on failure flashes an error and redirects back to `app_2fa_setup`.
  2. Reads the pending secret from session `_2fa_temp_secret`; if missing, flashes "Setup session expired" and redirects back.
  3. Calls `TotpService::verifyCode($secret, $code)` on the submitted `_code`.
  4. On success: persists `totpSecret`, sets `isTotpEnabled = true`, seeds `lastTotpCounter` with the matched counter (so the enabling code can't be replayed at login), flushes, removes `_2fa_temp_secret`, sets session `_2fa_verified` to the user's id, flashes success, and redirects to `app_account_settings`.
  5. On failure: re-renders the setup page with an "Invalid code" error.

### `TwoFactorController::disable(Request $request, EntityManagerInterface $em): Response`

- Validates CSRF token `2fa_disable`; on failure flashes error and redirects to `app_account_settings`.
- Clears `totpSecret`, sets `isTotpEnabled = false`, clears `lastTotpCounter`, flushes.
- Removes session `_2fa_verified`, flashes success, redirects to `app_account_settings`.

### `TwoFactorController::challenge(Request $request, TotpService $totp, TrustedDeviceManager $trustedDeviceManager, ConfigService $configService, EntityManagerInterface $em, EndpointRateLimiter $rateLimiter): Response`

Login-time challenge shown by the listener.

- **GET**: renders `security/2fa/challenge.html.twig` with the configured trusted-device lifetime (`trusted_device.lifetime_days`, default 30) and no error.
- **POST**:
  1. Rate limit: `EndpointRateLimiter::tooManyAttempts('2fa_challenge', userId)` — keyed per account. If exceeded, re-renders the challenge with "Too many attempts" (still 200, with trusted-device days).
  2. Validates CSRF token `2fa_challenge`; on failure flashes error and redirects to `app_2fa_challenge`.
  3. Verifies the submitted `_code` against the user's `totpSecret` via `TotpService::verifyCode($secret, $code, $lastTotpCounter ?? PHP_INT_MIN)` (replay floor passed in).
  4. On success: persists the matched counter to `lastTotpCounter`, flushes, sets session `_2fa_verified` to the user's id, reads and clears `_2fa_target_url` (default `/dashboard`), and redirects there. If `_trust_device === '1'`, generates a trusted-device cookie via `TrustedDeviceManager::generateCookie()` and attaches it to the response.
  5. On failure: re-renders the challenge with an "Invalid authentication code" error.

### `AdminUserController::resetTwoFactor(int $id, Request $request, UserRepository $userRepository, EntityManagerInterface $em, AuditLogger $auditLogger): Response`

Admin recovery action for a user who lost their authenticator.

- 404 if the target user is not found.
- Validates per-user CSRF token `admin_user_reset_2fa_<id>`; throws access-denied on failure.
- If the user does not have 2FA enabled, flashes an info message and returns to the users list (no-op).
- Otherwise clears `totpSecret`, sets `isTotpEnabled = false`, flushes, writes an `admin.user_reset_2fa` audit-log entry, flashes success, and redirects to `app_admin_users`.

## Services & classes

### `App\Service\TotpService`

| Signature | Purpose |
| --- | --- |
| `generateSecret(): string` | Generate a new Base32-encoded TOTP secret from 20 random bytes. |
| `getOtpAuthUri(string $secret, string $email): string` | Build the `otpauth://totp/...` provisioning URI (issuer `SymfonyAuth`, 6 digits, 30s period, SHA1). |
| `getQrCodeDataUri(string $secret, string $email): string` | Render the provisioning URI as an SVG QR code and return it as a data URI. |
| `verifyCode(string $secret, string $code, int $afterCounter = PHP_INT_MIN): ?int` | Verify a code across the ±1 time-window; returns the matched time-counter, or `null` if no counter matches or every match is `<= $afterCounter` (replay). Uses `hash_equals`. |
| `generateCode(string $secret, ?int $counter = null): string` | Compute the 6-digit TOTP for the given counter (defaults to the current time-counter). |

Private helpers (not part of the public API): `base32Encode(string): string`, `base32Decode(string): string`.

Constants: `ISSUER = 'SymfonyAuth'`, `DIGITS = 6`, `PERIOD = 30`, `WINDOW = 1`.

### `App\Security\TrustedDeviceManager`

| Signature | Purpose |
| --- | --- |
| `generateCookie(int $userId, int $lifetimeDays): Cookie` | Build a signed `TRUSTED_DEVICE` cookie (`userId:expires:hmac`, Base64-encoded) valid for the given days; HttpOnly, SameSite=Strict, path `/`. |
| `isDeviceTrusted(Request $request, int $userId): bool` | Validate the request's `TRUSTED_DEVICE` cookie: decode, check it belongs to `$userId`, not expired, and HMAC matches (`hash_equals`). |

Public constant: `COOKIE_NAME = 'TRUSTED_DEVICE'`. Constructor takes the app `kernel.secret` (autowired) as the HMAC key. Private helpers: `buildCookieValue(int, int): string`, `computeHmac(string, string): string` (HMAC-SHA256).

### `App\EventListener\TwoFactorChallengeListener`

| Signature | Purpose |
| --- | --- |
| `__construct(TokenStorageInterface $tokenStorage, RouterInterface $router, ConfigService $configService, TrustedDeviceManager $trustedDeviceManager)` | Inject dependencies for the gate. |
| `onKernelRequest(RequestEvent $event): void` | Kernel-request gate that enforces the 2FA challenge / setup redirect (see Events & listeners). |

### `App\Config\TwoFactorConfigPage` (implements `ConfigPageProviderInterface`)

| Signature | Purpose |
| --- | --- |
| `getSlug(): string` | Returns `2fa`. |
| `getTitle(): string` | Returns `Two-Factor Authentication`. |
| `getFields(): array` | Returns the config field definitions (see Configuration table). |

## Entity fields

TOTP-related columns on `App\Entity\User`:

| Field | Type | Getter / setter |
| --- | --- | --- |
| `$totpSecret` (default `null`) | nullable string | `getTotpSecret(): ?string` / `setTotpSecret(?string $secret): static` |
| `$isTotpEnabled` (default `false`) | bool | `isTotpEnabled(): bool` / `setIsTotpEnabled(bool $enabled): static` |
| `$lastTotpCounter` (default `null`) | nullable int | `getLastTotpCounter(): ?int` / `setLastTotpCounter(?int $counter): static` — highest TOTP time-counter already accepted; codes at or below it are rejected (replay protection). |

`$lockedUntil` (`?\DateTimeImmutable`) with `getLockedUntil()` / `setLockedUntil()` and an `isLocked()` helper also exists on `User`, but it belongs to the account-lockout feature, not the 2FA flow (the 2FA challenge uses `EndpointRateLimiter` for throttling, not `lockedUntil`).

## Events & listeners

`TwoFactorChallengeListener` hooks **`kernel.request`** via `#[AsEventListener(event: 'kernel.request', method: 'onKernelRequest', priority: -20)]`.

Gate logic (only on the main request):

1. **Skip** non-main requests, and any path starting with `/_` (profiler/assets), `/2fa` (avoid redirect loops), `/logout`, `/account/change-expired-password` (avoid cross-listener loops), `/api` (stateless firewall handled by token auth), or `/impersonate`. Also skip when the session flag `_impersonating_as` names the signed-in user (2FA is skipped during active impersonation). The flag is compared with the user's identifier, never just checked for presence, because it survives a later sign-in as someone else in the same session (ADR-063).
2. Read `2fa.enforcement`. If **`off`**, return immediately — no challenge even for users who have 2FA enabled.
3. If there's no auth token, or the user isn't a `User`, or there's no session, return.
4. **User without 2FA enabled**: under `required`, redirect to `app_2fa_setup` (unless already on `/account/2fa`); otherwise (`optional`) return.
5. **User with 2FA enabled**:
   - If `2fa.trusted_ips` is non-empty and the client IP matches (via `IpUtils::checkIp`, CIDR supported), return (bypass).
   - If `TrustedDeviceManager::isDeviceTrusted()` is true for this user, return (bypass).
   - If session `_2fa_verified` equals this user's id, return (already challenged this session). The marker holds the id of the user who passed, never a bare `true`, so signing in as another account in the same session (no logout) is challenged again (ADR-063).
   - Otherwise save the current URI to session `_2fa_target_url` and redirect to `app_2fa_challenge`.

## Security notes

- **Replay protection**: `verifyCode()` returns the matched time-counter; the controller persists it in `User::$lastTotpCounter` and passes the stored value back as the `$afterCounter` floor on the next challenge. Any counter `<= $afterCounter` is skipped, so a code (and the codes for earlier windows) cannot be replayed once consumed. The enabling code is also "burned" at setup time by seeding `lastTotpCounter`.
- **Constant-time comparison**: both TOTP code matching (`TotpService::verifyCode`) and trusted-device cookie validation (`TrustedDeviceManager::isDeviceTrusted`) use `hash_equals`.
- **Trusted-device cookie integrity**: signed with HMAC-SHA256 keyed on `kernel.secret`; tampering with the userId or expiry invalidates the HMAC. Cookie is HttpOnly and SameSite=Strict.
- **Challenge enforcement** is centralised in `TwoFactorChallengeListener` at `kernel.request` priority `-20`, so it applies to all interactive routes (subject to the skip list above) rather than per-controller.
- **Brute-force throttling**: the challenge POST is rate-limited per account via `EndpointRateLimiter::tooManyAttempts('2fa_challenge', userId)`.
- **CSRF**: setup (`2fa_setup`), disable (`2fa_disable`), challenge (`2fa_challenge`), and admin reset (`admin_user_reset_2fa_<id>`) are all CSRF-protected.

## Tests

- **`tests/Functional/Security/TwoFactorAuthTest.php`** — core setup, challenge, and replay flow:
  - `testSetupPageRendersQrCodeAndSecret` — setup page shows the QR data URI and Base32 secret.
  - `testUserMustEnterValidTotpCodeToConfirmSetup` — a valid code enables 2FA.
  - `testConfirmSetupWithInvalidCodeShowsError` — wrong code does not enable 2FA and shows an error.
  - `testLoginWith2faEnabledRedirectsToChallengePage` — enabled users are redirected to `/2fa/challenge`.
  - `testValidTotpCodeGrantsAccess` — a valid challenge code grants access to the target URL.
  - `testReplayedTotpCodeIsRejected` — re-submitting a previously accepted code is rejected (replay protection / `lastTotpCounter`).
  - `testInvalidTotpCodeShowsError` — wrong challenge code shows an error.
  - `testUserCanDisable2fa` — disable flow clears 2FA.
  - `testPassedChallengeDoesNotCarryOverToAnotherAccountInTheSameSession` — passing your own challenge, then signing in as another account without logging out, still challenges that account (ADR-063).
  - `testImpersonationFlagDoesNotSkipTheChallengeForAnotherUser` — an admin impersonating one user, then signing in as another enrolled user in the same session, is challenged for that user (ADR-063).
- **`tests/Functional/Security/AdminTwoFactorTest.php`** — the admin-realm mirror of the same two cases: `testPassedChallengeDoesNotCarryOverToAnotherAdminInTheSameSession` and `testImpersonationFlagDoesNotSkipTheChallengeForAnotherAdmin`; `testAdminCanEnrolInTwoFactor` also proves enrolment counts as passing the challenge.
- **`tests/Functional/Security/TwoFactorEnforcementTest.php`** — enforcement modes:
  - `testAdminConfigHas2faEnforcementSetting` — the admin config exposes the `2fa.enforcement` setting.
  - `testRequiredEnforcementRedirectsUserWithout2faToSetup` — `required` redirects users without 2FA to setup.
  - `testOptionalEnforcementDoesNotForce2faSetup` — `optional` does not force setup.
  - `testOffEnforcementSkipsChallengeEvenWhenEnabled` — `off` skips the challenge even for enabled users.
- **`tests/Functional/Security/TrustedDeviceTest.php`** — trusted-device behaviour:
  - `testChallengePageHasTrustCheckbox` — the challenge form offers a "trust this device" checkbox.
  - `testCheckingTrustStoresCookie` — checking the box sets the `TRUSTED_DEVICE` cookie.
  - `testTrustedDeviceCookieSkipsChallenge` — a valid trusted-device cookie bypasses the challenge.
  - `testTrustedDeviceCookieExpiresAfterConfiguredDuration` — the cookie stops working past its configured lifetime.
