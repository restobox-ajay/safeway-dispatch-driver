# Code Review Findings — 2026-07-06

Full-codebase review of the Symfony 7.4 auth boilerplate. Findings gathered by five
parallel reviewers (security layer, controllers, services/listeners, data model/templates,
tests/alignment). Every **HIGH** item was hand-verified against the code.

**Status legend:** ☐ open · ☑ fixed · ⊘ won't-fix / accepted

Do **not** start fixing until explicitly told which item to take. Fix one at a time.

> **Reconciled 2026-07-09 (branch `c36-bundle-extraction`).** Every C-finding is resolved by a
> completed feature in `.agent/feature_list.json` — C1–C41 map to FEATURE-096–147. Notes: C11 by
> soft-delete + prune (FEATURE-110/111) with its residue (invitations/webhook_delivery) closed by the
> unified prune harness (FEATURE-147/ADR-048); C13 and C32 folded into the C8 dedup (FEATURE-103);
> C12 and C36 by the real bundle build. The ☑ marks reflect that mapping — see feature_list.json for
> each finding's feature and evidence.

---

## Summary verdict

Foundations are strong: token secrets stored only as SHA-256 hashes with unique constraints,
constant-time comparisons, deliberate anti-enumeration + fail-closed rate limiting, zero
`|raw` in templates, near-blanket CSRF, no god objects. Problems cluster in three areas:

1. **Seams between features** — event listeners, cross-realm interactions, the API twin of web admin.
2. **Lifecycle gaps** — nothing revokes access or cleans up when a user is deactivated/deleted.
3. **Spec/ledger honesty gap** — `feature_list.json` shows 95/95 passing, but the headline
   bundle architecture, admin 2FA, and per-role enforcement are not implemented.

---

## CRITICAL (fix first)

### ☑ C1 — `changeExpiredPassword` is an account-takeover primitive
- **File:** `src/Controller/AccountController.php:93`
- **Severity:** HIGH (verified)
- **Defect:** Any authenticated session can POST a new password without knowing the current
  one; nothing checks the password is actually expired, and `TwoFactorChallengeListener.php:52`
  exempts the route from the 2FA gate.
- **Scenario:** A hijacked or pre-2FA-complete session rotates the account password and locks
  out the real owner. Contrast normal `changePassword` (line ~157) which verifies the current password.
- **Fix direction:** Gate the route on actual expiry state and require the current password
  (or a fresh reset token); reconsider the 2FA-gate exemption.

### ☑ C2 — Login listeners don't filter by firewall → every stateless API request acts like an interactive login
- **Files:** `src/EventListener/AuditLogSecurityListener.php`, `LoginHistoryListener.php`,
  `UserSessionListener.php`, `LoginNotificationListener.php` (none call `$event->getFirewallName()`)
- **Severity:** HIGH (verified — grep confirmed zero firewall checks)
- **Defect:** Global security listeners bubble to every firewall dispatcher; `TokenAuthenticator`
  makes `supports()` true for all `/api` requests.
- **Scenario per successful PAT call:** audit `login/success` row, a `LoginHistory` row (turns
  "recent logins" into an API access log), a phantom `user_sessions` row via delete+flush+insert
  on a stateless request (session id `''`), and a "new login from unrecognized IP" email on first
  call from a new IP. Garbage bearer tokens write unbounded failure audit rows and fire
  `login.failure` webhooks with no `/api` rate limit.
- **Fix direction:** One-line firewall-name filter (`user`/`admin`) at the top of each listener.

### ☑ C3 — "Logout everywhere" and deactivation don't cut existing access
- **Files:** `src/Controller/AccountController.php:54` (`terminateAllSessions`),
  `src/Controller/Api/AdminApiUserController.php:227` (`forceLogout`);
  `config/packages/security.yaml:52-64` (no `user_checker` on `api`/`admin_api`);
  `src/Security/TokenAuthenticator.php:66` (no status/lockout check)
- **Severity:** HIGH (verified)
- **Defect:** (a) Terminate-all only deletes `user_sessions` rows; the remember-me cookie
  re-authenticates on next request because its HMAC binds only identifier+expiry+password-hash,
  none of which change. (b) Deactivation/lockout doesn't revoke PATs, and the stateless firewalls
  have no user checker, so a deactivated user's tokens keep authenticating `/api/*` indefinitely.
- **Scenario:** Stolen laptop; user clicks "terminate all sessions"; thief's browser silently
  re-authenticates via REMEMBERME and mints a fresh session. Terminated employee's PATs keep working.
- **Fix direction:** Invalidate remember-me on terminate-all (rotate a per-user token/series or
  bump a value the HMAC binds); add a `user_checker` (or status/lockout check) to the token
  authenticators; revoke PATs on deactivate.

### ☑ C4 — Admin REST API writes zero audit-log entries
- **Files:** all 12 mutations in `src/Controller/Api/AdminApiUserController.php`
  (create :50, update :125, delete :185, activate :199, deactivate :213, force-logout :227,
  password-reset :243, unlock :283, revoke-tokens :297, reset-2fa :313) and
  `src/Controller/Api/AdminApiInvitationController.php` (send :25, resend :71)
- **Severity:** HIGH (verified)
- **Defect:** No `AuditLogger` calls at all, while every web twin logs.
- **Scenario:** A leaked admin token deletes users / resets 2FA with no forensic trace. Spec
  requires the audit log to record "admin actions."
- **Fix direction:** Best done as part of the service extraction (C8) so audit logging lives in one place.

### ☑ C5 — Cross-realm lockout corruption
- **File:** `src/Security/LoginRateLimitListener.php:107-136` (`maybeApplyLockout`, UPDATE at :134)
- **Severity:** MEDIUM (verified) — opt-in, which bounds impact
- **Defect:** `UPDATE "user" SET locked_until … WHERE email = ?` runs for *any* form-login
  failure including the **admin** firewall, against the shared `login_attempts` table. `Admin`
  has no `lockedUntil` and `AdminChecker` never checks lockout.
- **Scenario:** Failed admin-login attempts against an email that also belongs to a `User` lock
  out that user's account; admins themselves are never lockable. Violates firewall isolation (ADR-003).
- **Fix direction:** Key lockout by realm; give admin its own lockout column/path or scope the
  UPDATE to the firewall that failed.

### ☑ C6 — Invitation resend resurrects consumed invitations + email not bound to invite
- **Files:** `src/Entity/Invitation.php:76` (`regenerate()` sets `usedAt = null`);
  `src/Controller/AdminInvitationController.php:104` (no used/expired guard);
  `src/Controller/Api/AdminApiInvitationController.php:84` (guards expiry, not used-ness);
  `src/Controller/RegistrationController.php:71,77` (invited email captured for display only, never compared to POSTed email)
- **Severity:** HIGH (verified)
- **Scenario:** In invitation-only mode, resend on a consumed invite mints a second account;
  and anyone holding an invite link registers an arbitrary email, so per-person invites aren't per-person.
- **Fix direction:** Guard resend on `!isUsed()`; bind the registered email to the invitation email.

### ☑ C7 — Stale reset token hits a recreated same-email account
- **Files:** `src/Controller/PasswordResetController.php:117,144` (lookup by hash, then resolve
  user by email at consumption time); no FK/cascade cleanup on user delete
  (`AdminUserController.php:154`, `Api/AdminApiUserController.php:185`)
- **Severity:** HIGH (verified)
- **Scenario:** Unused/unexpired reset token issued to a since-deleted user still resolves — by
  email — to a newly created account with the same email, within the 1-hour window. A stale link
  in an old inbox resets the new account's password.
- **Fix direction:** Bind tokens to user id (or invalidate on delete); add cascade cleanup for
  the six sibling tables (see C11).

---

## DESIGN / CLEAN-CODE

### ☑ C8 — Massive web/API duplication with observable drift (the extraction case)
- **Files:** `AdminUserController.php` (444 lines) vs `Api/AdminApiUserController.php` (339);
  reset-token block copy-pasted in 3 places (`PasswordResetController.php:56-78`,
  `AdminUserController.php:188-210`, `Api/AdminApiUserController.php:256-279`);
  invitation send/resend inlined in 4 places
- **Severity:** MEDIUM (structural)
- **Defect:** Same operations implemented twice+ with hand-rolled validation, token minting,
  email dispatch. Drift already real: API missing audit logs (C4), invitation guards differ (C6),
  admin-created users skip password-lifecycle bookkeeping (C13).
- **Fix direction:** Extract `UserAccountAdminService`, `InvitationService`, and a user-side
  `PasswordResetService` (mirroring existing `AdminPasswordResetService`). Collapses ~400 lines
  and makes C4/C6 structurally impossible instead of per-endpoint fixes.

### ☑ C9 — Blocking side-channels in the login path
- **Files:** `src/Service/HttpWebhookDispatcher.php:23-45` (sync `file_get_contents` with retries),
  `src/EventListener/WebhookListener.php`, `src/Security/LoginRateLimitListener.php:141`,
  `src/EventListener/LoginNotificationListener.php:67` (sync SMTP send); `backoff()` is a no-op at `HttpWebhookDispatcher.php:79-82`
- **Severity:** HIGH (verified)
- **Scenario:** Down webhook hangs every login (and failed login) ~15s; password-spray forces
  outbound HTTP + a `WebhookDelivery` persist per attempt → worker-pool exhaustion. SMTP outage
  turns a valid "unrecognized device" login into a 500. "Configurable retries" burn in
  milliseconds because backoff does nothing.
- **Fix direction:** Move webhook + notification dispatch off the request path (Messenger async
  transport, per the spec's Webhook-component intent), implement real backoff.

### ☑ C10 — `flush()` footguns: mid-request full-unit-of-work commits
- **Files:** `src/Service/AuditLogger.php:26-27`; `src/EventListener/UserSessionRequestListener.php:70-71`
- **Severity:** MEDIUM (verified)
- **Defect:** Both flush the *entire* unit of work. Any entity dirtied earlier in the request is
  silently persisted; audit rows commit *before* the business change they describe, so a later
  failure leaves an audit entry for an action that never happened.
- **Fix direction:** Isolate the audit insert (dedicated EM/DBAL insert) or document a
  flush-clean contract; scope the session-activity write.

### ☑ C11 — No cleanup/cascade on user delete; nothing but audit log is ever pruned
- **Files:** `AdminUserController.php:154`, `Api/AdminApiUserController.php:185`; only
  `AuditLogPruneCommand` exists
- **Severity:** HIGH / MEDIUM (verified)
- **Defect:** Deleting a user strands rows in `personal_access_tokens`, `user_sessions`,
  `login_history`, `password_history`, and email-keyed `password_reset_tokens` (no FKs anywhere).
  No pruning path for expired/used tokens, magic links, invitations, dead `user_sessions`, or
  `webhook_delivery`.
- **Scenario:** PII (IPs, UAs) persists forever after deletion; ties into C7; anti-enumeration
  endpoints allow steady unbounded table growth; "Active Sessions" shows phantom rows forever
  (rows only deleted on explicit logout, not PdoSessionHandler GC).
- **Fix direction:** Cascade cleanup on delete; extend pruning to the other token/log tables.
- **Resolved:** the "orphan rows on delete" half is moot by soft-delete + intentional retention
  (ADR-020, FEATURE-110); ephemeral tokens/sessions pruned by FEATURE-111. Residue closed 2026-07-09:
  invitations (expired-or-used + `invitation.retention_days` grace) and `webhook_delivery`
  (`webhook.delivery_retention_days`) now pruned via the unified `app:prune` harness
  (FEATURE-147/ADR-048); the Messenger `failed` queue is operator-owned by decision
  (`messenger:failed:remove`, documented in README).

### ☑ C12 — Nine ConfigPage classes are pure-data boilerplate
- **Files:** `src/Config/*ConfigPage.php` (~350 lines total)
- **Severity:** LOW
- **Defect:** Every class is slug + title + field array with zero behavior; the bundle premise
  justifying separate classes doesn't exist (no `src/Bundle/`).
- **Fix direction:** Collapse to a single generic `ConfigPage(slug, title, fields)` value object
  (~60 lines) while keeping the `ConfigPageProviderInterface`/`AutowireIterator` extension point intact.
- **Resolved (branch `c36-bundle-extraction`, 2026-07-09):** superseded by the real bundle build (C36) —
  each `ConfigPage` moved into its owning bundle (`GeneralConfigPage` stays in core), registered via
  `ConfigPageProviderInterface`, and its `/admin/config` sub-page disappears when the bundle is
  uninstalled. No longer boilerplate-in-core.

### ☑ C13 — Admin-created users exempt from password expiry + initial reuse check
- **Files:** `AdminUserController.php:415-420`, `Api/AdminApiUserController.php:101-106` (neither
  sets `passwordChangedAt` nor stores a `PasswordHistory` entry; registration does)
- **Severity:** MEDIUM (verified)
- **Scenario:** With expiry active, every admin-provisioned account keeps its initial password
  forever, and that password never enters the reuse history. (Fold into C8.)

### ☑ C14 — Duplicated fingerprint formula coupled by undocumented listener priority
- **Files:** `LoginHistoryListener.php:28` and `LoginNotificationListener.php:43` both compute
  `hash('sha256', $ip . $userAgent)`; notification (priority 10) must run before history (implicit 0)
- **Severity:** MEDIUM (verified)
- **Defect:** If history inserts first, the current login always matches its own fingerprint and
  notifications silently never fire. Nothing documents the ordering; no test tripwire ties the
  two formulas together.
- **Fix direction:** Extract a shared fingerprint helper; pin/document the priority.

---

## SECURITY LAYER (additional)

### ☑ C15 — Trusted-device 2FA-bypass cookie is not marked `Secure`
- **File:** `src/Security/TrustedDeviceManager.php:24-34` (`$secure` hardcoded `false`)
- **Severity:** MEDIUM (verified)
- **Defect:** A bearer credential that skips the entire 2FA challenge is transmitted over plaintext
  HTTP on a mixed HTTP/HTTPS deployment (stack serves `http://auth.localhost` per ADR-017). The
  remember-me cookie correctly uses `$request->isSecure()`.
- **Fix direction:** Set `$secure` from `$request->isSecure()` like the remember-me handler.

### ☑ C16 — Trusted-device cookies are stateless, unrevocable, not bound to `totpSecret`
- **File:** `src/Security/TrustedDeviceManager.php:69-81` (binds only `userId:expires` under HMAC of `kernel.secret`)
- **Severity:** MEDIUM (verified)
- **Scenario:** After an admin resets/re-enrolls a user's 2FA post-compromise, any previously
  issued `TRUSTED_DEVICE` cookie for that `userId` still returns `true` until its own expiry —
  attacker's device keeps bypassing 2FA. Disabling 2FA and password reset also don't invalidate it.
- **Fix direction:** Bind the cookie to the TOTP secret (or a per-user device epoch) so resets invalidate it.

### ☑ C17 — Post-2FA redirect trusts `Request::getUri()` with no `trusted_hosts`
- **Files:** `TwoFactorChallengeListener.php:122`, `TwoFactorController.php:157`; no `framework.trusted_hosts`
- **Severity:** LOW (verified)
- **Scenario:** A spoofed `Host` header could bend the post-2FA redirect off-origin.
- **Fix direction:** Set `framework.trusted_hosts` and/or store a relative path, not the full URI.

### ☑ C18 — `unserialize()` of a session-stored token on impersonation exit
- **File:** `src/Controller/ImpersonationController.php:71` (no `allowed_classes`)
- **Severity:** LOW (verified) — sessions are server-side, so not directly attacker-controlled
- **Fix direction:** Prefer `TokenStorage`/explicit re-auth over serialize/unserialize of tokens.

### ☑ C19 — SSRF guard has a DNS-rebinding TOCTOU
- **File:** `src/Service/HttpWebhookDispatcher.php:100` (resolve in `isAllowedUrl`) vs :69 (`file_get_contents` re-resolves)
- **Severity:** MEDIUM (verified)
- **Scenario:** Short-TTL record answering a public IP first and `169.254.169.254` second defeats
  the private/reserved-range check for an admin-configured webhook URL.
- **Fix direction:** Pin the vetted resolved IP (connect to it with a `Host` header) instead of resolving twice.

### ☑ C20 — Magic-link login bypasses the IP whitelist
- **File:** `src/EventListener/IpWhitelistListener.php:29` (early-returns unless `FormLoginAuthenticator`)
- **Severity:** MEDIUM (verified)
- **Scenario:** A user restricted to `allowedIps` (or a global `ip_whitelist.user_ips` deployment)
  logs in from any IP by requesting a magic link. `ImpersonationAuthenticator` bypass is arguably
  intentional; the magic-link one is not documented.
- **Fix direction:** Apply the IP check to `MagicLinkAuthenticator` too (or check on `CheckPassportEvent` regardless of authenticator).

---

## CONTROLLERS / API (additional)

### ☑ C21 — Registration POST has no CSRF and no rate limit
- **File:** `src/Controller/RegistrationController.php:76-163` (only state-changing form without
  `isCsrfTokenValid()`; not behind `EndpointRateLimiter` despite sending a verification email)
- **Severity:** MEDIUM (verified)
- **Scenario:** Scripted POSTs flood arbitrary mailboxes with verification emails and fill the
  user table; login-CSRF setup also possible.

### ☑ C22 — PATCH validation contract inconsistent; `role` edit is a silent no-op
- **File:** `src/Controller/Api/AdminApiUserController.php:162-174`; also web `AdminUserController.php:97-99,108,406-408,419`
- **Severity:** MEDIUM (verified)
- **Defect:** Invalid `email`/`name` → 422, but invalid `role`/`status` are silently dropped
  (200 OK, nothing changed). A *valid* role triggers `setRoles([])` and since
  `User::ALLOWED_ROLES = ['ROLE_USER']` (`User.php:102`) role editing can never do anything —
  validated-then-discarded dead code. Callers get success responses for edits that never happened.
- **Fix direction:** Reject unknown role/status; remove or implement the role-edit path per spec intent.

### ☑ C23 — Superadmin→admin impersonation forges a token bypassing AdminChecker; demotions don't touch live sessions
- **File:** `src/Controller/AdminAdminManagementController.php:274-319` (hand-builds
  `PostAuthenticationToken`, writes to `_security_admin`, skips the checker); `edit()` :168-173
- **Severity:** MEDIUM (verified)
- **Defect:** An *inactive* admin can be impersonated into a working session (user-side flow
  correctly goes through `ImpersonationAuthenticator` + `UserChecker` — asymmetric). Demoting/
  deactivating an admin doesn't invalidate live sessions; `Admin::isEqualTo` is hash-only so a
  demoted superadmin keeps `ROLE_SUPER_ADMIN` in-session until logout.

### ☑ C24 — Audit-log date filters 500 on garbage input (both surfaces); filter block duplicated
- **Files:** `AdminAuditLogController.php:22-31`, `Api/AdminApiAuditLogController.php:24-33`,
  `AuditLogRepository.php:65,70` (raw string into `new \DateTimeImmutable(...)`)
- **Severity:** MEDIUM / LOW (verified)
- **Scenario:** `GET /admin/audit-log?date_from=banana` → uncaught exception → HTTP 500 (API
  should return 422). The 8-line filter block is copy-pasted between the two controllers.

### ☑ C25 — API user list not filterable, page size hardcoded (contra spec)
- **File:** `src/Controller/Api/AdminApiUserController.php:32-48` (`UserRepository::findPaginated`
  takes no filters; `PAGE_SIZE = 10`, no `per_page`)
- **Severity:** MEDIUM (verified) — spec: "List users (paginated, **filterable**)"

### ☑ C26 — Impersonation-exit endpoints log garbage when not impersonating
- **File:** `src/Controller/ImpersonationController.php:27-52,55-90`
- **Severity:** LOW (verified)
- **Scenario:** Any user/admin POSTing them produces `unknown → unknown` audit rows (and, for
  exit, self-logout) with no in-progress impersonation.

### ☑ C27 — Check-then-insert races
- **Files:** email uniqueness (`AdminUserController.php:389`, `Api:72`, `RegistrationController.php:85`);
  PAT per-user cap (`AccountController.php:229-247`); invitation-token double-registration (`RegistrationController.php:59`)
- **Severity:** LOW (verified)
- **Scenario:** Concurrent duplicates yield a 500 or exceed the token cap; two concurrent
  token registrations both pass the `isUsed()` check.

### ☑ C28 — Config save accepts arbitrary unvalidated values
- **File:** `src/Controller/AdminConfigController.php:54-63`
- **Severity:** LOW (verified)
- **Scenario:** `rate_limit.max_attempts=banana` is persisted; behavior then depends on
  `ConfigService::getInt` coercion.

---

## DATA MODEL / ENTITIES (additional)

### ☑ C29 — Entity metadata drifted from migrations; `doctrine:schema:validate` fails
- **Files:** entities missing declared indexes (`IDX_PAT_USER_ID`, `IDX_AAT_ADMIN_ID`,
  `idx_login_history_user_id`, `IDX_user_sessions_user_id`, `IDX_APRT_EMAIL`); DBAL tables
  (`login_attempts`, `endpoint_rate_limits`, `sessions`) unmapped
- **Severity:** MEDIUM (verified — `schema:validate` fails)
- **Scenario:** A future `doctrine:migrations:diff` generates `DROP TABLE login_attempts;
  DROP TABLE endpoint_rate_limits;` plus index drops — one careless accepted diff destroys the
  rate-limiting store.
- **Fix direction:** Declare indexes on entities; mark DBAL-only tables as unmanaged.

### ☑ C30 — Missing indexes on hot columns
- **Files:** user `password_reset_tokens` has no email index though `invalidateOtherUnusedTokens()`
  (`PasswordResetTokenRepository.php:23`) filters by email (admin twin *has* `IDX_APRT_EMAIL` — drift);
  `audit_log` has no index at all on `created_at`/`action`
- **Severity:** MEDIUM (verified)
- **Scenario:** Full-table scans on every reset completion and every audit-log view/prune, on
  ever-growing unpruned tables.

### ☑ C31 — Admin session tracking is one-sided (dead `user_type='admin'` schema)
- **Files:** `UserSessionListener.php:22-23,45` (hardcodes `'user'`, early-returns for `Admin`);
  ADR-006 defines `user_type enum(user|admin)`
- **Severity:** MEDIUM (verified)
- **Scenario:** Admins have no active-sessions view and no "logout everywhere"; enum column is
  dead weight. Spec: "Both User and Admin have independent session tracking."

### ☑ C32 — Shared token-entity duplication with visible drift
- **Files:** `PasswordResetToken.php` (lacks `declare(strict_types=1)`) vs `AdminPasswordResetToken.php`
  (lacks `getCreatedAt()`), `MagicLinkToken.php`, `Invitation.php`; `PersonalAccessToken` vs
  `AdminAccessToken` 95% identical; missing `repositoryClass` on some `#[ORM\Entity]` attributes
- **Severity:** LOW (verified)
- **Fix direction:** A shared `ExpirableEmailTokenTrait` for the hash/expiry/used lifecycle would
  remove the drift surface without violating the spec's User/Admin-only no-shared-base-class rule.
- **Resolved:** dumb-data traits per ADR-037 AXIS-2 (FEATURE-103). The last sub-point — missing
  `repositoryClass` on `Invitation` — closed 2026-07-09 (residue sweep); every `#[ORM\Entity]`
  now declares its repository.

### ☑ C33 — Free-string status/enum fields (no backed enums)
- **Files:** `User.php:145-149`, `Admin.php:117-121` (`setStatus()` accepts any string while
  `setRoles()` enforces an allowlist); `status`, `userType`, `actorType`, `outcome` all free strings
- **Severity:** LOW (verified)
- **Scenario:** A future write path can persist `status='banned'` which `isLocked`/`AdminChecker`
  treats as active-ish. The roles field proves the project already knows the right pattern.

### ☑ C34 — Unpaginated invitations list
- **File:** `src/Controller/AdminInvitationController.php:97` (`findBy([], ['createdAt' => 'DESC'])`)
- **Severity:** LOW (verified) — combined with no pruning (C11), renders every invitation ever sent.

### ☑ C35 — Admin login page swallows the reset-success flash
- **Files:** `templates/admin/security/login.html.twig:8-10` (renders only security `error`,
  never flashes) vs `templates/security/login.html.twig:12-19`
- **Severity:** LOW (verified)
- **Scenario:** After an admin resets their password they land on a login page with no
  confirmation; the flash dies in the bag. Copy-paste drift from the user template.

---

## TESTS / OBJECTIVE ALIGNMENT

### ☑ C36 — Spec's "separate bundles" architecture is not implemented
- **Files:** no `src/Bundle/`; `config/bundles.php` has only framework bundles; ADR-010 describes
  a layout that was never built
- **Severity:** HIGH (alignment, verified)
- **Defect:** Every "auth-*-bundle" feature is baked into the monolithic `src/` tree, yet 30+
  bundle features claim `passes: true`. FEATURE-062 AC5 waved through on a SCRATCHPAD-only decision
  (violates CLAUDE.md rule that architecture decisions go in DECISIONS.md). "Install only what you
  need" / "each bundle ships its own tests" deliverable is not met. ADR-010 is factually false.
- **Resolved (branch `c36-bundle-extraction`, 2026-07-09):** Option A — all 8 bundles built for real
  under `src/Bundle/` (FEATURE-137–146), each proven optional by a 404-when-uninstalled test; the four
  `user`-column bundles moved their data into bundle-owned satellite tables (core `User` decoupled).
  FEATURE-062 AC5 rewritten from the "always present in the monorepo" redefinition to a genuine
  uninstall test; ADR-010 corrected; an ADR tripwire (`bin/adr-tripwire.php`) now flags any verifiable
  ADR lacking a test. `verify.sh` green across two consecutive runs.

### ☑ C37 — Admin 2FA and per-role enforcement missing; other spec deviations unrecorded
- **Files:** `Admin.php` (no TOTP fields); `TwoFactorChallengeListener.php:70` (single global
  `2fa.enforcement` key, not per-role); custom `HttpWebhookDispatcher` instead of Symfony Webhook
  component; `/admin-api/*` vs spec's `/api/admin/*`
- **Severity:** MEDIUM (alignment, verified)
- **Note:** The `/admin-api` re-homing and admin-token mechanism was a deliberate, *good* security
  fix (separate firewall so only Admin entities reach it) — but it's recorded only in a commit/
  feature entry; DECISIONS.md has no ADR (numbering jumps 017→019) and the spec table is stale.
  Admin 2FA absence means the most privileged accounts have no second factor.
- **Resolved:** admin TOTP + per-role strictest-wins enforcement via ADR-036 (FEATURE-126). The
  documentation residue closed 2026-07-09: the `/admin-api` decision now has a dedicated ADR-047
  and the `docs/SPEC.md` route table was corrected to `/admin-api/*` (all 16 rows verified against
  actual `#[Route]` attributes).

### ☑ C38 — Feature evidence cites deleted tests
- **Files:** FEATURE-051 (`ImpersonateTest.php` has 4 tests, evidence claims 5; AC5 still listed),
  FEATURE-033 (`AdminReset2faTest.php` missing two cited tests; AC4 untestable — no admin 2FA),
  FEATURE-078 (`ImpersonationCest.php:9-27` docblock advertises 5 scenarios, file has 4)
- **Severity:** MEDIUM (alignment, verified)
- **Note:** Tests deleted in commit `2030c94` (FEATURE-081) were justified, but older features
  weren't reconciled; all still claim `passes: true`.

### ☑ C39 — Weak adversarial coverage on security-critical seams
- **Files:** `tests/Functional/Security/TrustedDeviceTest.php` (happy-path only — never tampers/
  replays the cookie; a "any cookie present → skip 2FA" regression stays green);
  `AdminImpersonateAdminTest.php:112-121` (tests 403 at the page layer, never POSTs the
  impersonate route as non-superadmin nor forges the `_impersonation_request` payload);
  `WebhookSsrfGuardTest` (IP literals only, no hostname resolving to private); IP-whitelist CIDR
  path (every client is 127.0.0.1)
- **Severity:** MEDIUM / LOW (verified)

### ☑ C40 — Functional suite runs twice per verify; live acceptance flake
- **Files:** `phpunit.dist.xml:21-23` runs `tests/` (325 WebTestCase tests); Codeception
  Functional suite (`tests/Functional.suite.yml`) re-runs the identical 325 — verified empirically.
  Codeception pass runs without PHPUnit's `failOnDeprecation/Warning/Notice`. Live flake artifact:
  `tests/_output/App.Tests.Acceptance.Auth.PasswordPolicyCest.compliantNewPasswordLetsUserProceed.fail.html`
  dated 2026-07-06 (expiry intercept didn't fire; suspect cross-process SQLite visibility or
  interaction with commit `bc4866a`'s authenticated-login redirect)
- **Severity:** MEDIUM / LOW (verified)
- **Fix direction:** Drop the Functional suite from `codecept run` (or delete the empty suite yml);
  diagnose the acceptance flake before using the suite as a gate.

### ☑ C41 — Fixture copy-paste across the functional suite
- **Files:** ~30 files define their own `loginUser`/`loginAsAdmin`; 59 duplicate bcrypt-cost-4
  user creation + try/catch DBAL cleanup (e.g. `TwoFactorAuthTest.php:31-83` repeated near-verbatim
  in TwoFactorEnforcementTest, TrustedDeviceTest, TrustedIpTest, ImpersonateTest, …)
- **Severity:** LOW (verified)
- **Fix direction:** Shared WebTestCase base/trait (~40 lines/file). Acceptance layer already does
  this well via `tests/Support/Helper/DatabaseHelper.php`.
- **Resolved:** shared `tests/Support/AuthenticationTestTrait` (FEATURE-130); the last 5 stragglers
  migrated 2026-07-09 (commit `75632fa`) — no bespoke `loginUser`/`loginAsAdmin` remains outside the
  trait. (Known residue, out of scope: 5 files keep a bespoke `createTestUser` and one an equivalent
  `loginAsSuperadmin`; queue separately if wanted.)

---

## Things checked and found SOUND (not findings)

- Token secrets (magic-link, password-reset, admin-reset, PAT, admin-PAT) stored as SHA-256
  hashes; random material `random_bytes(32)`/`random_bytes(20)`; all secret comparisons use `hash_equals`.
- User enumeration: forgot-password / magic-link always redirect to the same confirmation;
  rate-limited above the CSRF check (ADR-016 honored).
- TOTP: RFC 6238 correct, constant-time compare, replay floor via `lastTotpCounter`.
- Remember-me × 2FA: lands in a fresh session with `_2fa_verified` unset — no bypass.
- Impersonation entry: `/impersonate/start` public but consumes a session key only a
  CSRF-protected ROLE_ADMIN POST can write.
- Password reset (user flow): single-use, 1-hour, sibling-token invalidation, session teardown,
  reuse check — all correct.
- No anti-cheat violations in tests; `ControlledHttpWebhookDispatcher` is a legitimate seam;
  `AdminApiBoundaryTest` is exemplary (adversarial DB-injected roles + control assertion).
- Templates: zero `|raw`, auto-escaping intact, per-id CSRF on admin action forms.
- `ConfigService` is not a god object; `AuditLogger` is thin; the `ConfigPageProviderInterface` /
  `AutowireIterator` registry mechanism matches the spec exactly.
- `verify-fast.sh` runs all suites — nothing excluded (though it double-runs functional, C40).
- Consciously decided per DECISIONS.md (do not re-flag): plaintext `totpSecret` (ADR-018 reversed),
  User/Admin table + firewall separation (ADR-003), DBAL rate-limit tables (ADR-013/016),
  fingerprint-only device recognition (ADR-012), `/admin-api` path (security fix).

### Stale memory corrected during this review
- The previously-tracked flaky `EncryptorTest` no longer exists — the Encryptor was deleted when
  ADR-018 was reversed (commit `48a6d4b`). The live flake is now `PasswordPolicyCest` (see C40).
