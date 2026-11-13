# auth-password-policy-bundle
Password complexity, length bounds, expiry, and reuse prevention — enforced at every password-setting entry point.

[← Back to main README](../../README.md)

## Overview

This bundle centralises every rule that governs what a password may be and how long it may live. Two services do the work:

- `PasswordPolicyService` validates a candidate plaintext password against length and character-class rules. It carries a **hard floor of 8 bytes** that admin config can raise but never lower, and a **hard ceiling of 72 bytes** matching bcrypt's truncation boundary.
- `PasswordHistoryService` prevents reuse of recent passwords by comparing the candidate against stored hashes, and records each new hash for future comparison.

A request-time listener (`PasswordExpiryListener`) forces an authenticated user whose password has aged past `expiry_days` onto a dedicated change page until they set a fresh password.

All thresholds are read at runtime from `ConfigService` (keys under `password_policy.*`), which is backed by the admin config UI page declared in `PasswordPolicyConfigPage`. There is no compiled config — changing a value in the admin UI takes effect immediately.

## Configuration

Admin config sub-page slug: **`password-policy`** (title "Password Policy"), declared in `src/Config/PasswordPolicyConfigPage.php`. Fields are persisted under the `password_policy.*` namespace and read back through `ConfigService::getInt()` / `getBool()`.

| Key | Type | Default | Meaning |
|-----|------|---------|---------|
| `password_policy.min_length` | int (text field) | `0` | Minimum length in bytes. `0` means "unset" — the effective minimum then falls back to the hard floor of 8. A value above 8 raises the bar; a value below 8 is clamped up to 8 (see `validate()`). |
| `password_policy.require_uppercase` | bool | `0` (off) | When on, the password must match `/[A-Z]/`. |
| `password_policy.require_number` | bool | `0` (off) | When on, the password must match `/[0-9]/`. |
| `password_policy.require_symbol` | bool | `0` (off) | When on, the password must contain at least one non-alphanumeric character (`/[^A-Za-z0-9]/`). |
| `password_policy.expiry_days` | int (text field) | `0` | Days after `passwordChangedAt` before a password is considered expired. `0` (or less) disables expiry entirely — no forced-change redirect. |
| `password_policy.reuse_count` | int (text field) | `0` | Number of most-recent passwords that may not be reused. `0` (or less) disables both the reuse check and history recording. |

## Services & classes

### `PasswordPolicyService` (`src/Service/PasswordPolicyService.php`)

Stateless validator. Constructor dependency: `ConfigService`.

Public constants:

- `PasswordPolicyService::HARD_MIN_LENGTH = 8` — absolute minimum enforced regardless of admin config. Even with `min_length` unset (or set below 8), shorter passwords are rejected. The policy **fails closed**, not open.
- `PasswordPolicyService::MAX_LENGTH = 72` — absolute maximum. bcrypt silently truncates input at 72 bytes, so any character beyond that contributes nothing to the stored hash and could mask a weaker effective password; anything longer is rejected outright.

Public methods:

- **`validate(string $password): array`**
  Returns a `list<string>` of violation messages; an empty array means the password is valid. Behaviour:
  - Length is measured with `strlen()` (bytes — the exact unit bcrypt truncates on), not character count.
  - Effective minimum is `max(config min_length, HARD_MIN_LENGTH)`. An unset `min_length` (`getInt` returns `0`) clamps up to 8; a configured value below 8 is likewise clamped up; a configured value above 8 takes precedence. Message: `"Password must be at least {min} characters."`
  - If length exceeds 72: `"Password must be at most 72 characters."`
  - If `require_uppercase` and no `[A-Z]`: `"Password must contain at least one uppercase letter."`
  - If `require_number` and no `[0-9]`: `"Password must contain at least one number."`
  - If `require_symbol` and no `[^A-Za-z0-9]`: `"Password must contain at least one symbol."`
  - Each rule is independent and accumulates its own message, so a single call can return multiple violations.

### `PasswordHistoryService` (`src/Service/PasswordHistoryService.php`)

Reuse prevention and history bookkeeping. Constructor dependencies: `PasswordHistoryRepository`, `EntityManagerInterface`, `ConfigService`.

Public methods:

- **`checkReuse(User $user, string $newPlaintextPassword): ?string`**
  Returns an error string if the new password matches one of the user's recent stored hashes, otherwise `null`.
  - Reads `reuse_count`; returns `null` immediately (feature disabled) when `count <= 0` **or** the user has no ID yet (e.g. a not-yet-persisted user).
  - Loads the most recent `count` history rows via `PasswordHistoryRepository::findRecentByUserId()` and compares the plaintext against each stored hash with `password_verify()`.
  - On a match: `"You cannot reuse one of your last {count} passwords."`

- **`storeHash(User $user, string $hashedPassword): void`**
  Records the already-hashed password in history, then prunes older entries beyond the retained window. Must be called **after** the new password has been hashed, set on the user, and the user flushed (so the user has an ID).
  - No-op when `reuse_count <= 0` or the user has no ID.
  - Persists a new `PasswordHistory(userId, hashedPassword)` and flushes, then calls `PasswordHistoryRepository::pruneOldEntries(userId, count)` to keep only the most recent `count` rows.

### `PasswordHistory` entity + repository

- `src/Entity/PasswordHistory.php` — table `password_history`, columns `id`, `user_id`, `password_hash` (255), `created_at` (immutable timestamp set in the constructor). Indexed on `(user_id, created_at)` as `idx_ph_user_id_created`. Read-only getters only; the row is immutable once constructed. Note `user_id` is a plain int column (not a Doctrine association).
- `src/Repository/PasswordHistoryRepository.php`:
  - `findRecentByUserId(int $userId, int $count): PasswordHistory[]` — most recent `count` rows for a user, ordered `created_at DESC`.
  - `pruneOldEntries(int $userId, int $keepCount): void` — keeps the newest `keepCount` rows and deletes the rest via raw SQL (selects the ids to keep, then `DELETE ... WHERE user_id = ? AND id NOT IN (...)`). No-op if the user has no rows.

### `PasswordExpiryListener` (`src/EventListener/PasswordExpiryListener.php`)

`kernel.request` listener (priority `-25`, main requests only). Dependencies: `TokenStorageInterface`, `RouterInterface`, `ConfigService`. See "Password history & expiry" below.

### `PasswordPolicyConfigPage` (`src/Config/PasswordPolicyConfigPage.php`)

Implements `ConfigPageProviderInterface`; supplies the admin config sub-page (`getSlug()`, `getTitle()`, `getFields()`). `getFields()` returns the six fields tabulated under [Configuration](#configuration).

## Enforcement points

Every place that accepts a new password runs `PasswordPolicyService::validate()` before hashing. Reuse and history recording (`checkReuse` / `storeHash`) apply only on the user-facing change/reset flows, not on admin-driven creation.

| Flow / route | Controller method | `validate()` | `checkReuse()` | `storeHash()` |
|--------------|-------------------|--------------|----------------|---------------|
| Registration — `POST /register` | `RegistrationController` | `RegistrationController.php:96` | — | `RegistrationController.php:117` |
| Password reset — `GET/POST /reset-password/{token}` (`app_reset_password`) | `PasswordResetController::reset` | `PasswordResetController.php:130` | `PasswordResetController.php:149` | `PasswordResetController.php:163` |
| Forced expired-password change — `GET/POST /account/change-expired-password` (`app_account_change_expired_password`) | `AccountController::changeExpiredPassword` | `AccountController.php:114` | `AccountController.php:118` | `AccountController.php:127` |
| Admin create user — `GET/POST /admin/users/new` (`app_admin_users_new`) | `AdminUserController::new` | `AdminUserController.php:398` | — | — |
| Admin API create user — `POST /admin-api/users` (`app_api_admin_users_create`) | `AdminApiUserController::create` | `AdminApiUserController.php:83` | — | — |

Message handling differs slightly by call site: the registration and admin web/API flows surface only the **first** violation (`$policyErrors[0]`) keyed under the `password` field, while the reset and expired-change flows join all violations with a space (`implode(' ', $policyErrors)`).

## Password history & expiry

**Reuse prevention.** History stores only the bcrypt **hash** of each accepted password (never plaintext). On a reset or change, `checkReuse()` loads the most recent `reuse_count` hashes and runs `password_verify($candidatePlaintext, $storedHash)` against each — a positive match rejects the new password. After a successful change, `storeHash()` records the new hash and prunes everything older than the newest `reuse_count` entries, so the window stays bounded. Setting `reuse_count = 0` disables both recording and checking; users registered or created while the feature was off simply have no history rows to compare against.

**Expiry.** `PasswordExpiryListener` runs on each main request. It short-circuits (no redirect) when any of the following hold:

- the path is internal/infrastructure: starts with `/_`, `/logout`, `/2fa`, `/account/change-expired-password` (the change page itself — avoids a redirect loop), `/api` (stateless token firewall), or `/impersonate`;
- the signed-in user is the one being impersonated (`_impersonating_as` equals their identifier). The flag is compared with the user, never just checked for presence, because it survives a later sign-in as someone else in the same session (ADR-063);
- `expiry_days <= 0` (feature disabled);
- there is no authenticated `User` token;
- the user's `passwordChangedAt` is `null` (no recorded change date — treated as not expired);
- `passwordChangedAt + expiry_days` is still in the future.

Otherwise it issues a `RedirectResponse` to `app_account_change_expired_password`, trapping the user on the forced-change page until they submit a compliant, non-reused new password. That handler re-runs `validate()` and `checkReuse()`, then on success updates the hash, stamps a fresh `passwordChangedAt`, records the new hash, and redirects to login.

## Security notes

- **Fail-closed hard floor.** With completely empty config (`min_length` unset → `getInt` returns `0`), the effective minimum is still `max(0, 8) = 8`. A misconfigured or never-configured policy can never permit passwords shorter than 8 bytes. A configured value below 8 is clamped up rather than honoured.
- **72-byte ceiling.** Passwords are rejected above 72 bytes because bcrypt truncates there; without this check, extra characters would give a false sense of strength while contributing nothing to the stored hash.
- **Byte-accurate length.** `strlen()` measures bytes — the same unit bcrypt truncates on — so multibyte characters are counted exactly as bcrypt sees them.
- **No plaintext at rest.** Password history persists only bcrypt hashes; reuse detection is done with `password_verify()` rather than by storing or comparing plaintext.
- **Expiry respects auth/2FA/impersonation order.** The listener defers to in-progress 2FA and active impersonation and ignores the stateless API firewall, so the forced-change redirect never interferes with those flows.

## Tests

- `tests/Unit/Service/PasswordPolicyServiceTest.php` — unit coverage of `validate()`: hard floor enforced when `min_length` unset, password exactly at floor passes, configured minimum above floor enforced, configured minimum below floor clamped up to floor, over-max rejected, exactly-max accepted, and a character-class (uppercase) regression.
- `tests/Functional/Security/PasswordPolicyTest.php` — end-to-end policy enforcement through controllers: registration rejects short passwords, hard minimum enforced with empty config, maximum enforced with empty config, missing required character class rejected, password reset enforces policy, error messages identify the specific rule, and all rules toggle independently.
- `tests/Functional/Security/PasswordReuseTest.php` — `password_history` schema columns, reuse blocked when enabled, only the most-recent entries retained (pruning), and `reuse_count = 0` disables the check.
- `tests/Functional/Security/PasswordExpiryTest.php` — `passwordChangedAt` field exists, expired password redirects to the forced-change page, setting a new password lets the user proceed, a non-expired password does not redirect, and impersonating an expired user still skips the check while signing in as another expired user in the same session does not (`testImpersonationFlagDoesNotSkipTheExpiryCheckForAnotherUser`, ADR-063).
- `tests/Functional/Admin/PasswordPolicyConfigPageTest.php` — the config sub-page appears in admin config, exposes the required fields, and persists submitted values.
- `tests/Acceptance/Auth/PasswordPolicyCest.php` — user-level acceptance flows: registration rejects sub-minimum passwords, names each missing character class, expired password forces change on login, reusing a recent password is rejected, and a compliant new password lets the user proceed.
