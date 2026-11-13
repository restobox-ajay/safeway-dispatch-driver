# Open Questions

## OQ-007: Pre-existing ledger drift — FEATURE-106 cites a non-existent test method (blocks verify-fast)
**Discovered 2026-07-06 during FEATURE-126.** `bin/verify-fast.sh` (PHPUnit step) fails on
`tests/Unit/Meta/LedgerEvidenceAuditTest::testEveryCitedTestMethodResolvesOrIsDocumentedAsRemoved`:
FEATURE-106's evidence (AC2 happy path) cites `testAdminCreateWritesExactlyOneAuditRow()`, which
does not exist — `tests/Functional/AuditLog/AuditFlushFootgunTest.php` only defines
`testLoggingDoesNotCommitUnrelatedDirtyEntity`, `testBusinessRollbackLeavesNoSuccessAuditRow`, and
`testLoginFailureStillWritesAuditRow`. This is a pre-existing citation drift left by the FEATURE-106
round (the workspace was already RED before FEATURE-126), NOT caused by the admin-2FA work.
**Assumed resolution:** the FEATURE-106 owner (or a FEATURE-127-style ledger-reconciliation pass)
re-cites the missing method to the real test name(s), or drops the stale citation with an AC note.
Deliberately left untouched here to keep FEATURE-126 scoped to one feature.

**RESOLVED (FEATURE-103 completion, 2026-07-06):** FEATURE-106's AC2-happy-path evidence citation
was corrected from the non-existent `testAdminCreateWritesExactlyOneAuditRow()` to the real
`tests/Functional/AuditLog/AuditLogTest.php::testAdminUserCreateCreatesAuditLogEntry`, which makes
the identical assertion (`assertSame(1, countAuditEntries('admin.user_create','success'))` after
POST /admin/users/new). No code, no test-coverage change — a pure one-line ledger reconciliation
(the assertion always existed under the correct method name). `LedgerEvidenceAuditTest` now passes
and `bin/verify-fast.sh` is unqualified-green. Done as part of FEATURE-103's DoD because that
feature had reached the passes=true point where verify-fast-green (AC7) is the blocking gate.


## OQ-001: Superadmin Provisioning Mechanism
The spec does not specify how the first superadmin account is created (no existing admin can bootstrap it).
**Assumed resolution:** Provide a `bin/console app:create-superadmin` command. Record in DECISIONS.md.
answer: create through command line. at production with a fixed, but hard to get random password.

## OQ-002: "Unrecognized Device" Definition for Login Notifications
The spec says "email user on new login from unrecognized IP or device" but does not define what constitutes a recognized device.
**Assumed resolution:** A device fingerprint is SHA-256(ip + user_agent). A login is "recognized" if the same fingerprint has been seen before in LoginHistory for that user. Record in DECISIONS.md.

answer: configurable. IP only, browser cookie only or both

## OQ-003: Audit Log Retention Cleanup Trigger
The spec says the retention period is configurable but does not specify how old entries are pruned (scheduled command, middleware, manual).
**Assumed resolution:** A console command `app:audit-log:prune` performs cleanup. Operators schedule it via cron. Record in DECISIONS.md.

answer: configurable by default 90 days, configurable in that config page

## OQ-004: Admin Password Reset Flow
The spec describes a self-service password reset for users. It is unclear whether admins also have a self-service reset or are only managed by superadmins.
**Assumed resolution:** Admins do not have a self-service password reset form. A superadmin can reset an admin's password via the admin panel. A superadmin cannot be locked out — provisioning is done via console only.

answer: admins also self serve pw reset

## OQ-005: Session–User Association with PdoSessionHandler
When listing active sessions, the sessions table stores serialized PHP session data. Associating a session row to a specific user requires deserializing or tagging sessions at write time.
**Assumed resolution:** On login success, store a `user_sessions` cross-reference table (session_id, user_id, user_type, ip, user_agent, created_at, last_active_at). This table is the source for "view active sessions" and "logout everywhere". Sessions table remains Symfony-managed; the cross-reference is managed by a LoginSuccessListener and RequestListener.

answer : Yes
reminder: admin and user is completely separated entities so these tables are separate

## OQ-006: Separate Domain Testing (ADMIN_DOMAIN / APP_DOMAIN)
The spec says admin and user interfaces can optionally be served from separate domains via .env config. It is unclear whether tests should cover domain routing.
**Assumed resolution:** Domain routing is infrastructure-level config, not tested in the application test suite. Document in README. Caddy config handles the routing.

answer: the test suite will see what domains is in .env for tesitng.

**Resolved (FEATURE-091, 2026-06-21):** Separate-domain mode is now wired (firewall `host:`
matchers + unified `SESSION_COOKIE_DOMAIN` cookie knob, default off; see ADR-019). Domain
routing IS exercised by the application test suite — SeparateDomainModeTest reads the domains
from env and overrides them to prove both single-domain (no regression) and separate-domain
firewall/cookie behavior. Proxy still owns real cross-host routing.

## OQ-007: Should the pending impersonation request and the impersonation banners follow ADR-063's binding rule?
Found by the FEATURE-154 reviewer sweep (2026-10-01); neither waives 2FA, so neither blocked that fix.
1. `_impersonation_request` (`ImpersonationAuthenticator`) is a one-shot payload that signs the session in as a user
   with no credentials. When it is consumed, nothing re-checks that the admin-realm principal still matches its
   `adminEmail` or still holds ROLE_ADMIN. The window is narrow (admin logout and `AdminSessionRequestListener`
   invalidate the whole session), but it is a session attribute that grants access without being bound to the current
   principal.
2. `templates/base.html.twig` shows the impersonation banners when `_impersonating_as` / `_impersonating_admin_as` are
   merely present. After signing in as someone else in the same session, the banner wrongly says "Impersonating: B"
   while the session is C (C is now correctly challenged; this is display only).
**Assumed resolution:** none yet. Proposal: verify the admin token matches `adminEmail` (and ROLE_ADMIN) before
`ImpersonationAuthenticator` authenticates, and show each banner only when its flag equals the signed-in identifier.

## OQ (2026-10-11): make `trusted_proxies` env-driven so it survives installer updates
`config/packages/framework.yaml` has `trusted_proxies` commented out (README checklist item 2); behind a proxy an operator edits that tracked file.
`bin/rbx-install.sh update` replaces tracked files from the release tarball, so the edit is lost on every update (all clients then look like the
proxy IP: lockouts, whitelists and the Secure cookie flag misbehave). The installer now says so and carries only untracked files. Decision needed:
read `TRUSTED_PROXIES` from the environment (`.env.local` is carried), as the commented line already hints. Not changed here (an app/security-config change).

## OQ-008: fvstexpress-admin must trust this app as a proxy for the driver login rate limit (ADR-074)
The admin API limits logins to 20 per 15 minutes per client IP and logs the IP. Every driver's login reaches it from
this app's server, so unless the admin app trusts this server (`TRUSTED_PROXIES` there) and reads `X-Forwarded-For`,
the whole fleet shares one limit and the logs show one IP. **Assumed resolution:** this app always sends
`X-Forwarded-For`; configuring the admin app's trusted proxies is a deployment step listed in the README. Not changed
in the admin repo here.

## OQ-009: Should user-realm 2FA enforcement "off" still challenge users who enrolled? (audit #2, 2026-10-30)
For admins, `off` no longer skips the challenge of an admin who enabled TOTP (ADR-078). The user realm's spec (AC4,
`TwoFactorEnforcementTest::testOffEnforcementSkipsChallengeEvenWhenEnabled`) says `off` skips it even for enrolled
users, so it was left as specified. Since only a superadmin can change the setting now, the risk is lower; product
owner to decide whether users should match admins.
