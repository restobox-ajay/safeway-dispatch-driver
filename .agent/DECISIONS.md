# Architecture & Product Decisions

## ADR-001: Test Database — SQLite
**Tripwire:** rationale (test-DB environment/config choice; no behavioural claim to pin).
**Decision:** Use SQLite for all test environments (unit, functional, E2E).
**Rationale:** Per spec. Fast, zero-config, no server required.
**Impact:** .env.test sets `DATABASE_URL=sqlite:///%kernel.project_dir%/var/test.db`. Migrations run before test suite via `doctrine:migrations:migrate --env=test`.

## ADR-002: Session Storage — PdoSessionHandler
**Tripwire:** verifiable. Verified by `tests/Functional/Session/PdoSessionHandlerTest.php`.
**Decision:** All sessions (user and admin) are stored in the database via Symfony's `PdoSessionHandler`.
**Rationale:** Per spec. Required for "view active sessions" and "logout everywhere" to work.
**Impact:** A `sessions` table is created. Session handler is configured as a service in `services.yaml`.

## ADR-003: Separate Firewalls
**Tripwire:** verifiable. Verified by `tests/Functional/Security/AdminApiBoundaryTest.php`, `tests/Functional/Security/CrossRealmLockoutTest.php`.
**Decision:** Two completely independent Symfony Security firewalls: `user` (covers everything except `/admin/*`) and `admin` (covers `/admin/*`). Each has its own provider, authenticator, session namespace, and login page.
**Rationale:** Per spec: "fully separate tables, entities, firewalls, and login pages for admin and user."
**Impact:** `security.yaml` defines two firewalls. No shared base class between `User` and `Admin` entities.

## ADR-004: Superadmin Provisioning via Console Command
**Tripwire:** verifiable. Verified by `tests/Functional/Command/CreateSuperAdminCommandTest.php`.
**Decision:** The first superadmin is provisioned via `bin/console app:create-superadmin --email=X --password=Y`.
**Rationale:** No admin exists initially; there is no bootstrapping UI. A console command is the minimal, auditable mechanism.
**Impact:** `CreateSuperAdminCommand` must be implemented in core. Documented in README.

## ADR-005: Device Recognition for Login Notifications
**Tripwire:** verifiable. Verified by `tests/Functional/Security/LoginNotificationRecognitionTest.php`.
**Decision:** Recognition mode is configurable via admin config with three options: `ip_only` (match on IP address), `cookie_only` (match on a persistent browser cookie set at login), or `both` (must match both IP and cookie). A login is "unrecognized" if it does not match the configured criteria for that user's history.
**Rationale:** OQ-002. Different deployments have different threat models — IP-only is simpler, cookie-based survives IP changes, both is strictest.
**Impact:** Admin config gains a `login_notifications.recognition_mode` setting. `LoginHistory` stores both `ip` and a `device_cookie` token. `LoginNotificationListener` applies the configured mode when comparing.

## ADR-006: User Session Cross-Reference Table
**Tripwire:** verifiable. Verified by `tests/Functional/Account/SessionManagementTest.php`.
**Decision:** Introduce a `user_sessions` table (session_id, user_id, user_type enum(user|admin), ip, user_agent, created_at, last_active_at) managed by application listeners, separate from the `sessions` (PdoSessionHandler) table.
**Rationale:** OQ-005. The PdoSessionHandler sessions table stores opaque serialized data; joining it to a user is not practical without a cross-reference.
**Impact:** `UserSession` entity + migration. `LoginSuccessListener` inserts a row. `KernelRequestListener` updates `last_active_at`. Logout removes the row. "Logout everywhere" deletes all rows for the user and invalidates the session IDs.
**Amended by ADR-034 (2026-07-06):** the `user_type enum(user|admin)` discriminator was never used (only `user_type='user'` rows were ever written) and was **removed** — admin sessions now live in a dedicated `admin_sessions` table. See ADR-034.

## ADR-007: Config Storage — Key-Value Table
**Tripwire:** verifiable. Verified by `tests/Unit/Service/ConfigServiceTest.php`, `tests/Functional/Admin/AdminConfigTest.php`.
**Decision:** All runtime-configurable values are stored as rows in a `config` table (id, key VARCHAR UNIQUE, value TEXT). A `ConfigService` provides typed getters with defaults. Bundles define their own key namespaces (e.g., `2fa.enforcement_level`).
**Rationale:** Per spec: "All configurable values are stored in the database and editable via the admin config UI."
**Impact:** `Config` entity + migration + `ConfigService`. Admin `/admin/config` renders all registered `ConfigPageProviderInterface` sub-pages.

## ADR-008: Audit Log Retention via Console Command
**Tripwire:** verifiable. Verified by `tests/Functional/Command/PruneCommandTest.php`.
**Decision:** Old audit log entries are pruned by `bin/console app:audit-log:prune`. Operators schedule it via cron.
**Rationale:** OQ-003. A command is explicit, auditable, and avoids hidden side-effects during web requests.
**Impact:** `AuditLogPruneCommand` reads retention days from `ConfigService` and deletes old `AuditLog` rows.
**Superseded by ADR-048 (2026-07-09):** the standalone `app:audit-log:prune` command was replaced by the
unified `app:prune` harness (its `audit_log` pruner reads the same `audit_log.retention_days`). The
retention-via-scheduled-command decision stands; only the command surface changed. Tripwire repointed to
the consolidated `PruneCommandTest` (the old `AuditLogPruneCommandTest` was deleted with its scenarios
carried over).

## ADR-009: Admin Password Reset
**Tripwire:** verifiable. Verified by `tests/Functional/Admin/AdminPasswordResetTest.php`.
**Decision:** Admins have a self-service password reset form at `/admin/forgot-password`, identical in behaviour to the user flow. Superadmins can also reset any admin's password via the admin panel. The console `app:create-superadmin` remains the recovery path for a locked-out superadmin.
**Rationale:** OQ-004. Admins are people too — locking them out of self-service creates unnecessary support burden.
**Status: implemented (2026-07-05).** `AdminPasswordResetController` provides `/admin/forgot-password`, `/admin/forgot-password/check`, and `/admin/reset-password/{token}` (all `PUBLIC_ACCESS`), mirroring the hardened user flow: rate-limited above the CSRF check, anti-enumeration (always the same confirmation), single-use 1-hour tokens stored only as SHA-256 hashes in a **separate** `admin_password_reset_tokens` table (so a user and admin sharing an email can't cross-consume tokens), and sibling-token invalidation on success. The password change invalidates a live admin session via `Admin::isEqualTo`. Superadmins trigger a reset email from `/admin/superadmin/admins/{id}/reset-password` (shared `AdminPasswordResetService`). Correction to the original note: `app:create-superadmin` refuses an existing email, so the console path only *provisions a new* superadmin — it cannot reset an existing one.

## ADR-019: Separate-Domain Mode Wiring — Host-Regex Match-All Default + Unified Cookie Knob (FEATURE-091)
**Tripwire:** verifiable. Verified by `tests/Functional/Security/SeparateDomainModeTest.php`.
**Decision:** Separate-domain mode is wired via firewall `host:` matchers
(`%env(ADMIN_DOMAIN)%` on admin_api + admin, `%env(APP_DOMAIN)%` on user) and a single
`SESSION_COOKIE_DOMAIN` env var driving both the framework session `cookie_domain` and the
REMEMBERME cookie Domain. All three vars default to empty.
**Rationale:** An empty firewall `host` value compiles to a match-all host regex, so the
default (unset) env preserves exact single-domain behavior — zero regression, opt-in only.
A single cookie-domain knob keeps session and remember-me cookies consistently scoped
(empty -> host-scoped, the desired isolation). Cross-host routing to the wrong surface is
owned by the proxy (Caddy/nginx) per the spec; firewall host-matching is defense-in-depth.
**Impact:** With both domains set, a request on a host matching neither firewall is
unfirewalled by design (proxy owns host routing). `.env.acceptance` sets domains to
`127.0.0.1` (the live-server host) so matchers match in acceptance. Extends ADR-011.

## ADR-011: Separate Domain Testing
**Tripwire:** verifiable. Verified by `tests/Functional/Security/SeparateDomainModeTest.php`.
**Decision:** The test suite reads `ADMIN_DOMAIN` and `APP_DOMAIN` from `.env.test`. Tests use whatever domains are configured there rather than hardcoding `localhost` variants.
**Rationale:** OQ-006. Makes the test suite portable across environments without code changes.
**Impact:** `.env.test` defines both domain vars. Codeception base URL and functional test client host are derived from them.

## ADR-010: Bundle Directory Layout
**Tripwire:** verifiable. Verified by `tests/Functional/Bundle/AuthPatBundleModularityTest.php`; repo `src/Bundle/AuthPat`.
**Decision:** Each bundle lives under `src/Bundle/<BundleName>/` as a Symfony bundle class with its own `DependencyInjection/`, `Controller/`, `Entity/`, and `Resources/` subdirectories. Bundles are registered in `config/bundles.php`. *(Amended 2026-07-09: bundle tests live centrally under `tests/Functional/Bundle/` — one `*ModularityTest.php` per bundle — not in a per-bundle `Tests/` subdirectory, which was never created.)*
**Rationale:** Keeps bundles portable and consistent with Symfony conventions. Allows cherry-picking which bundles to enable per project.

**Status: realized for the FIRST bundle — `auth-pat-bundle` (FEATURE-138, 2026-07-07), the C36
Option A proof-of-concept.** Concrete extraction pattern the remaining 7 bundles reuse:
- **Layout.** `src/Bundle/AuthPat/` (namespace `App\Bundle\AuthPat`, autoloaded by the existing
  `App\ => src/` PSR-4 map). `AuthPatBundle` (extends Symfony `Bundle`), `DependencyInjection/AuthPatExtension`
  (loads `Resources/config/services.php`), `DependencyInjection/Compiler/RegisterAuthPatServicesPass`,
  the moved `Entity/PersonalAccessToken`, `Repository/PersonalAccessTokenRepository`,
  `Security/TokenAuthenticator`, `Controller/ApiController` + `Controller/AccountTokenController`
  + `Controller/AdminApiTokenController` (the admin-API `DELETE /admin-api/users/{id}/tokens` revoke
  endpoint, moved out of core in FEATURE-139 so it too is registration-gated — see the AC5
  reconciliation there), `Resources/config/{services,routes}.php`, and `Migrations/`.
- **Services are bundle-scoped.** `config/services.yaml` now EXCLUDES `../src/Bundle/` from the app
  `App\` autoregistration, so a bundle's classes become services ONLY via its own `services.php`, i.e.
  ONLY when registered. That is what makes "services absent when uninstalled" real.
- **Routing is registration-gated.** `App\Kernel::configureRoutes` (MicroKernelTrait override)
  replicates the default config loading, then imports `@AuthPatBundle/Resources/config/routes.php`
  ONLY when `isset($this->bundles['AuthPatBundle'])`. `config/routes.yaml` imports only
  `App\Controller`, so bundle controllers are never auto-discovered.
- **Firewall stays in one file (Symfony forbids splitting `security.firewalls`).** The `api` firewall
  stays in `config/packages/security.yaml` but references the stable alias `app.api_authenticator`,
  which core defaults to `App\Security\NullApiAuthenticator` (`supports()`=false → /api falls through
  to routing and 404s with the bundle absent). The bundle's compiler pass re-aliases it to the real
  `TokenAuthenticator`. Prepending a firewall from a bundle extension is NOT possible.
  *Amendment (issue #27):* "falls through" must not mean "runs anonymously": an APPLICATION-level /api
  route would. `access_control` therefore carries `{ path: ^/api, roles: ROLE_USER }`, so every /api
  request is blocked whichever authenticator is wired (Ken: the requirement is that requests are blocked;
  401 is acceptable). Bundle-owned /api routes still 404 when the bundle is absent. Verified by
  `AuthPatBundleModularityTest::testAnAppLevelApiRouteIsBlockedWithoutTheBundle`.
- **Core→bundle coupling via a port + null-object.** The always-present admin/user-management surface
  (admin user list token counts, deactivate/revoke-all-tokens) depends on
  `App\Security\UserTokenRevokerInterface`, defaulting to `NullUserTokenRevoker`; the bundle's compiler
  pass upgrades the alias to the repository. Both alias upgrades are a compiler pass (not a bundle
  `services.php` alias) because `config/services.yaml` loads AFTER bundle extensions and would clobber
  a bundle-level alias.
- **Migration ownership.** `AuthPatExtension::prepend()` adds the bundle `Migrations/` dir under
  namespace `App\Bundle\AuthPat\Migrations` (dir case must match PSR-4). The bundle migration is a
  guarded create (plain early-return if the table exists — NOT `skipIf`, which would leave the version
  perpetually pending and break `MigrationIdempotencyTest`). The legacy app create
  (`Version20260530270000`) was made idempotent the same way so the two never collide regardless of
  run order. Doctrine runs migrations in ascending version order, and the legacy app migration has
  the earlier version, so it still creates the table first on both existing and fresh histories; the
  bundle migration is a no-op today and becomes the sole creator only once the legacy app migration
  is retired. The mechanism (bundle-owned `migrations_path` + guarded reversible create) is what the
  AC establishes; full creation-ownership transfer is a follow-up. Doctrine excludes DBAL-only tables
  via `schema_filter` (ADR-026) — unaffected.
- **404-when-uninstalled proof.** `tests/Functional/Bundle/AuthPatBundleModularityTest` boots a
  `NoAuthPatKernel` (filters the bundle out of `registerBundles()`, distinct cache/build dir) and
  asserts the PAT routes are absent from the router, the bundle services are absent from the container,
  and GET `/api/ping` returns 404 (not 401/403) — real modularity, not a feature flag. The asserted
  route set includes the admin-API `app_api_admin_users_revoke_tokens` (FEATURE-139), whose absence
  is the honest form of FEATURE-062 AC5 (the `/admin-api` firewall's `access_control` makes an HTTP
  probe 401 for any path, so route-collection absence — not a status code — is the proof there).
**Impact:** New optional bundles follow this template: bundle dir + extension + `services.php` +
registration-gated `routes.php` import in `Kernel::configureRoutes` + (if they touch security) a
stable core alias defaulting to a null-object and a compiler-pass upgrade + a `Migrations/` dir +
a modularity test. The remaining 7 bundles (magic-link, security, password-policy, ip-whitelist, 2fa,
impersonate, webhook) are queued only after this lands green. The `AccessTokenColumns` trait stays in
core `App\Entity` because `AdminAccessToken` also uses it (ADR-003/C8) — the bundle entity imports it.

## ADR-013: Rate Limiting Storage — DBAL Table over Symfony RateLimiterFactory
**Tripwire:** verifiable. Verified by `tests/Functional/Security/RateLimitTest.php`.
**Decision:** Login rate limiting (FEATURE-035) uses a custom `login_attempts` DBAL table
(ip, email, attempted_at) rather than Symfony's built-in `RateLimiterFactory`.
**Rationale:** Two reasons. First, the test suite uses SQLite (ADR-001), and the
`RateLimiterFactory` Redis/Memcached backends are unavailable in that environment;
the DBAL backend is supported but its window semantics differ from a sliding-window
counted via `COUNT(*)`. Second, rate limit parameters (`max_attempts`, `window_seconds`)
must be runtime-configurable from admin config (FEATURE-014); `RateLimiterFactory` limits
are defined in `config/packages/rate_limiter.yaml` at compile time, not from the DB.
A custom DBAL table gives full control over both storage and query semantics with zero
additional infrastructure.
**Impact:** `login_attempts` table created via migration. Rows pruned to a 1-day rolling
window on each failure insertion. Per-IP and per-account (per-email) dimensions are both
tracked in the same table.

## ADR-014: Impersonation via Custom Session Handoff (Not Symfony switch_user)
**Tripwire:** verifiable. Verified by `tests/Functional/Security/ImpersonateTest.php`.
**Decision:** FEATURE-051 uses a custom session-handoff mechanism instead of Symfony Security's built-in `switch_user`.
**Rationale:** Symfony's `switch_user` works within a single firewall. This project uses two completely separate firewalls (`admin` and `user`), so an admin authenticated on the admin firewall cannot use `switch_user` on the user firewall. The custom approach: admin POSTs to an admin-firewall endpoint (CSRF-protected, ROLE_ADMIN guard) which writes `_impersonation_request` to the shared PHP session, then redirects to `/impersonate/start`; a custom `ImpersonationAuthenticator` on the user firewall reads and validates this payload, authenticates as the target user, and sets `_impersonating_as` in the session. Both firewalls share the same underlying PHP session (different keys: `_security_admin` vs `_security_user`). This achieves the same functional result as `switch_user` (including LoginSuccessEvent, UserSession row insertion, and proper session state) without requiring the admin to be on the user firewall first.
**Impact:** `ImpersonationAuthenticator` registered as custom authenticator on user firewall. Admin exit is via POST `/impersonate/exit` which clears `_security_user` and `_impersonating_as`, then redirects to `/admin/dashboard` where `_security_admin` re-authenticates the admin.

## ADR-015: E2E Acceptance Coverage Split — Harness in 064, Per-Group Depth in 065..079
**Tripwire:** rationale (E2E coverage-split planning decision; owns no test of its own).
**Decision:** FEATURE-064 delivers the reusable Codeception/phpBrowser acceptance harness (Acceptance.suite.yml, PhpBuiltinServerExtension live server, DatabaseHelper reset+seeding, AcceptanceTester login helpers, verify-fast integration) plus two baseline Cests that prove the harness end-to-end over real HTTP: UserAuthCest (core user auth) and AdminFlowsCest (core admin flows). The per-group E2E depth — email verification, password reset, session management, 2FA, security/lockout, magic link, impersonation, IP whitelist, and Admin API — is owned by FEATURE-065..079, which already enumerate those flows criterion-by-criterion.
**Rationale:** The original FEATURE-064 acceptance_criteria bundled the entire E2E epic (one "Cest covers X" line per feature group) into a single feature. Keeping all of those as 064 criteria would mean either an unreviewably large diff or marking 064 complete with unmet criteria (the anti-cheat "fake success" pattern). Splitting per group keeps diffs small and reviewable and gives each user-facing flow its own dedicated, independently-verifiable feature.
**Impact:** FEATURE-064's acceptance_criteria were trimmed to the harness + baseline user-auth + baseline admin scope actually delivered (criterion 4 narrowed to login/logout/failed/register/remember-me; the per-group "Cest covers X" lines removed). Those removed flows are not lost — FEATURE-065..079 (all depends_on FEATURE-064) carry them. A `scope_note` on FEATURE-064 records this split inline. This ADR supersedes the SCRATCHPAD-only record of the decision.

## ADR-016: Fail-Closed Rate-Limit Default + Shared Endpoint Limiter (extends ADR-013)
**Tripwire:** verifiable. Verified by `tests/Functional/Security/EndpointRateLimitTest.php`.
**Decision:** The code-level default for `rate_limit.max_attempts` is a non-zero constant
(`DEFAULT_MAX_ATTEMPTS = 10`, window 300s), shared between `LoginRateLimitListener` and a new
`EndpointRateLimiter` service. A configured `0` remains a valid *explicit* admin choice meaning
"disabled" — `ConfigService` returns the default only when the key is **absent**, so an
unconfigured deployment is throttled (fail-closed) while an admin can still opt out with an
explicit `0`. The four non-form auth POSTs (forgot-password, magic-link, resend-verification,
2FA challenge) are throttled by `EndpointRateLimiter`, a reusable DBAL sliding-window limiter
over a new `endpoint_rate_limits` table that reuses the same `rate_limit.*` config keys.
**Rationale:** H4 (security review). The previous `0` default was fail-open: a deployment that
never configured rate limiting had no throttling at all, and only the form-login path was covered,
leaving email-flood / code-brute-force endpoints unprotected.
**Impact:** `=== 0` guards become `<= 0`; the throttle check sits ABOVE CSRF in each controller so a
missing token cannot bypass it; pre-auth flows key on IP, the 2FA challenge keys on user id (the
account is the actor post-auth). Same DBAL-over-RateLimiterFactory reasoning as ADR-013.

## ADR-017: Session Cookie Hardening — `cookie_secure: auto` + Trusted-Proxy Stance
**Tripwire:** verifiable. Verified by `tests/Functional/Session/SessionCookieHardeningTest.php`.
**Decision:** Pin the session cookie flags explicitly in `framework.yaml` rather
than inheriting framework defaults: `cookie_secure: auto`, `cookie_httponly: true`,
`cookie_samesite: lax`. `cookie_secure` is `auto` (not `true`) because the stack
also serves `http://auth.localhost`; `auto` emits the `Secure` flag on HTTPS
requests only, keeping local/dev over http working while production over HTTPS is
secured.
**Rationale:** Security review M4. Relying on framework defaults means a framework
upgrade or accidental override could silently weaken the flags. Pinning them makes
the security posture explicit and testable.
**Trusted proxies:** `cookie_secure: auto` derives HTTPS-ness from the request.
Behind a TLS-terminating proxy the app must trust `X-Forwarded-Proto` via
`framework.trusted_proxies` + `trusted_headers`, otherwise the request looks like
plain HTTP and the `Secure` flag is never emitted. Guidance is documented inline
in `framework.yaml`; the actual proxy CIDR is deployment-specific and left to the
operator (commented `trusted_proxies` line provided as a template).
**Impact:** `config/packages/framework.yaml` pins the three flags; the compiled
values are asserted via the `session.storage.options` parameter in
`SessionCookieHardeningTest`.

## ADR-018: TOTP Secret Encryption at Rest + Key Management (M1) — REVERSED
**Tripwire:** rationale (REVERSED decision kept as a historical record; the code it describes was removed).
**Status: REVERSED (2026-06-21).** This was implemented and then reverted. The
threat model it defends against — a database leak whose blast radius excludes the
application's secrets — does not exist for this deployment: a single host running
**SQLite** (`var/data.db` sits on the same disk as `.env`), no cloud/S3, no separate
DB-snapshot pipeline. The DB and the encryption key are always in the same backup,
the same `tar`, the same host. At-rest encryption with a locally-stored key buys
nothing here, while it cost a custom DBAL type, a data migration, `Kernel::boot`
wiring, and a non-deterministic test (~8% flake). `User.totpSecret` is back to a
plain column. If this is ever deployed on a networked DB (PostgreSQL) with DB
backups handled separately from the host secrets, revisit this decision. The
original (now-reverted) design follows for the record.

---

**Decision:** `User.totpSecret` is encrypted at rest with AES-256-GCM via a custom
Doctrine DBAL type (`encrypted_string`), so the entity API (`get/setTotpSecret`)
keeps operating on the plaintext base32 seed while the database column holds
ciphertext. Conversion happens at the SQL boundary (`convertToDatabaseValue` /
`convertToPHPValue`), so the UnitOfWork change-tracks plaintext-to-plaintext and
the seed is only ever decrypted in memory for verify/QR.
**Key management:** the encryption key is derived (HKDF-SHA256, dedicated `info`
string) from `TOTP_ENCRYPTION_KEY` if supplied, else from `APP_SECRET`
(`kernel.secret`). Both live **outside** the database and are supplied via the
gitignored environment (per FEATURE-082). HKDF separates the derived key from the
other uses of `APP_SECRET` (CSRF, remember-me HMAC, signed verify-email URLs).
Production can rotate the seed-encryption key independently by setting
`TOTP_ENCRYPTION_KEY` (re-encryption of existing rows would then be a follow-up
data migration).
**Ciphertext envelope:** `v1:base64(iv[12]|tag[16]|ciphertext)`. The version
prefix lets the type distinguish encrypted values from legacy/not-yet-migrated
plaintext (and from test fixtures that raw-INSERT plaintext) and skip decryption
for those, so hydration never throws mid-migration.
**Migration:** `Version20260621120000` encrypts existing non-null plaintext
`totp_secret` rows in place; it is re-runnable (skips already-enveloped rows) and
reversible (`down()` decrypts). No physical column change: production and tests
both run SQLite (ADR-001), which ignores `VARCHAR` length, so the ~90-char
ciphertext fits the existing column.
**Wiring:** the DBAL type is registered under `doctrine.dbal.types`; its Encryptor
is injected via a static setter in `Kernel::boot()` (runs in web, CLI, migrations
and tests alike, since DBAL types are built outside the container).
**Impact:** `src/Security/Encryptor.php`, `src/Doctrine/EncryptedStringType.php`,
`Kernel::boot()`, `config/packages/doctrine.yaml`, `config/services.yaml`,
`User::$totpSecret` mapping, and the data migration.

## ADR-020: User Deletion is Soft-Delete (status=inactive) + Prune Ephemeral Tokens/Sessions
**Tripwire:** verifiable. Verified by `tests/Functional/Security/SoftDeleteTest.php`, `tests/Functional/Command/PruneCommandTest.php`.
**Decision:** There is **no right-to-erasure** requirement. Login history, audit log, and all
user-linked *historical* data are **retained indefinitely** for replayability — PII lingering
(IPs, UAs) is explicitly accepted, not a concern. A user is **never hard-deleted**; "deleting" a
user is a **soft delete** = set `status = inactive` (disabled, cannot authenticate). No separate
`archived` state and no `archived_at` — `active` / `inactive` is sufficient.
**Separately**, genuinely **ephemeral** rows *must* be flushed on a schedule (they are security
artifacts, not history): expired **or used** `password_reset_tokens` + `admin_password_reset_tokens`,
expired/used `magic_link_tokens`, and expired `user_sessions` (today removed only on explicit
logout — dead rows accumulate forever and show as phantom "Active Sessions").
**Rationale:** The product wants complete replayable history. Physically removing the `users` row
(`$em->remove()`) severs it and — no DB foreign keys anywhere (all satellite tables key on a scalar
`user_id`/`email`, verified across `src/Entity/`) — strands child rows against an id that no longer
resolves. Soft-delete-via-status keeps everything intact while making the account inert. Ephemeral
auth tokens/sessions are the exception: they carry no historical value and unbounded growth is an
availability/hygiene problem, so they get a prune command like the audit log (ADR-008).
**Impact / gap:**
- **Soft-delete DONE (FEATURE-110, 2026-07-06):** `AdminUserController::delete`,
  `Api/AdminApiUserController::delete`, and `AdminAdminManagementController::delete` now set
  `status='inactive'` + `flush()` instead of `$em->remove()` — the row and its satellite history
  survive. Login is already gated on status by `UserChecker`/`AdminChecker` (DisabledException on
  `!isActive()`), so a soft-deleted user/admin cannot authenticate. Reuses the existing
  active/inactive field; no new state, no `archived_at`, no migration.
- **Ephemeral-table prune DONE (FEATURE-111, 2026-07-06):** `app:maintenance:prune`
  (`MaintenancePruneCommand`) mirrors `AuditLogPruneCommand` and deletes expired-or-used
  `password_reset_tokens` + `admin_password_reset_tokens` + `magic_link_tokens` (via
  `deleteExpiredOrUsed` on each repo — `MagicLinkToken` gained a repositoryClass), plus dead
  `user_sessions` (via `UserSessionRepository::deleteExpired`, keyed on the PdoSessionHandler
  `sessions` table's per-row lifetime, independent of explicit logout). Reports per-table counts,
  idempotent, never touches login_history/password_history/audit_log. Operators cron it (ADR-008
  pattern). No schema change, no new config key.
- **Reframes review finding C11:** "orphan rows on delete" is resolved by *not deleting* —
  retention is intentional and PII-lingering is accepted (C7 moot). What survives of C11 is only the
  ephemeral-table prune above.
**Status: decided (2026-07-06); soft-delete implemented (FEATURE-110, 2026-07-06); ephemeral-table prune command implemented (FEATURE-111, 2026-07-06).**
**Superseded by ADR-048 (2026-07-09) for the prune surface:** the `app:maintenance:prune` command was
folded into the unified `app:prune` harness (password_reset_tokens / admin_password_reset_tokens /
user_sessions / magic_link_tokens pruners, same predicates). The retention policy here is unchanged;
Tripwire repointed to `PruneCommandTest` (the old `MaintenancePruneCommandTest` was deleted with its
scenarios carried over).

## ADR-028: Trusted-Device Cookie Bound to a Per-User Binding Token (totpSecret + status) (FEATURE-132 / review C16)
**Tripwire:** verifiable. Verified by `tests/Functional/Security/TrustedDeviceReplayTest.php`, `tests/Unit/Security/TrustedDeviceManagerTest.php`.
**Decision (Ken, 2026-07-06):** The `TRUSTED_DEVICE` 2FA-bypass cookie is made invalidatable via
the **lighter alternative** from the feature description (NOT the server-side trusted-devices
table, so AC4 — per-device view/revoke — does not apply). `TrustedDeviceManager` folds a per-user
**binding token** into the cookie HMAC: `bindingToken(User) = sha256((totpSecret ?? '') . '|' .
status)`. The token is NOT stored in the cookie; `isDeviceTrusted()` recomputes it from the user's
CURRENT state, so a cookie minted under an old state no longer matches once that state changes and
stops skipping the 2FA challenge. `generateCookie()` and `isDeviceTrusted()` now take the `User`
(was a bare `int $userId`); both call sites (`TwoFactorGuard`, `TwoFactorController::challenge`)
already hold the authenticated user.
**Rationale:** Review finding C16. The cookie was bound only to `userId:expires` under an HMAC of
`kernel.secret`, with no server-side record, so after an admin reset a user's 2FA and the user
re-enrolled (spec action), a previously issued cookie kept skipping the challenge until its own
expiry. Binding to `totpSecret` invalidates on any 2FA reset/disable/re-enroll (the seed rotates or
clears — AC1/AC2); binding to `status` invalidates on deactivation/soft-delete (`status='inactive'`
across every delete/deactivate path, AC3, consistent with FEATURE-102). The lighter path invalidates
ALL of a user's devices at once (accepted); targeted per-device revocation and a manage-devices UI
would need the server-side table and are out of scope. Password-reset as a trigger (an optional item
in the description's lighter option) is not in the ACs and is out of scope. Coordinates with
FEATURE-112 — the Secure/HttpOnly/SameSite=Strict attributes are unchanged.
**Impact:** `src/Security/TrustedDeviceManager.php` (binding token + widened signatures),
`src/Security/TwoFactorGuard.php`, `src/Controller/TwoFactorController.php`. No schema change, no new
config key. Behaviour-preserving within a session for an active user with a stable secret (stable
binding ⇒ the cookie keeps working), so the existing FEATURE-112/trusted-device tests stay green.
**Status: decided + implemented (2026-07-06).**

## ADR-012: Cookie-Based Device Recognition Deferred
**Tripwire:** rationale (deferral/scope note for cookie-based recognition modes; nothing built to test).
**Decision:** `cookie_only` and `both` recognition modes (per ADR-005) are deferred. FEATURE-027 implements only `fingerprint` (SHA-256 of IP + User-Agent, the default) and `ip_only` modes.
**Rationale:** Cookie-based recognition requires a persistent `device_cookie` token stored in `LoginHistory`, a cookie-set listener that fires on every login, and cookie-validation middleware on every request. This is a materially larger change than FEATURE-027 warrants.
**Impact:** `login_notifications.recognition_mode` accepts `fingerprint` (default) or `ip_only` in the admin config. Cookie-based modes (`cookie_only`, `both`) can be added in a future feature that introduces the `device_cookie` column and supporting infrastructure.

## ADR-021: Login Throttling/Lockout is Realm-Scoped; Admins are Not Hard-Lockable (FEATURE-100 / review C5)
**Tripwire:** verifiable. Verified by `tests/Functional/Security/CrossRealmLockoutTest.php`.
**Decision:** `LoginRateLimitListener` now records every failed form login with a `realm`
column (`user` | `admin`, derived from the request path — `^/admin` is the admin firewall's
own pattern) on the shared `login_attempts` table, and scopes all per-IP and per-account
counts by realm. The hard account lockout (`UPDATE "user" SET locked_until`) runs **only for
the user realm**; a failed admin-firewall login never touches the `user` table.
**Rationale:** Previously any `FormLoginAuthenticator` failure — including admin logins — could
`UPDATE "user" SET locked_until WHERE email = ?`, so a failed *admin* login for an email that
also belonged to a *User* locked that user out (cross-realm DoS, violating ADR-003 firewall
isolation). Admin has no `locked_until` column and no `AdminChecker` enforcement, so admins
were never actually lockable via that path anyway. Admins remain intentionally **not
hard-lockable**: they are console-provisioned (OQ-001), few in number, and account-keyed
lockout is itself a username-targeted DoS vector (see `maybeApplyLockout`'s comment). Admin
logins are still **rate-limited** (throttled), now realm-scoped so they cannot throttle a user.
**Impact:** New migration adds `login_attempts.realm` (default `'user'` for legacy rows) plus
realm-prefixed indexes. No new config keys. If admin hard-lockout is ever wanted, it needs its
own `Admin.locked_until` column + `AdminChecker` enforcement — deliberately out of scope here.
**Status: decided + implemented (2026-07-06).**

## ADR-022: Invitation Resend Guard is Used-ness Only; Registration Binds to Invited Email (FEATURE-101 / review C6)
**Tripwire:** verifiable. Verified by `tests/Functional/Admin/InvitationTest.php`, `tests/Functional/Api/AdminApiInvitationTest.php`.
**Decision:** Invitation **resend** (web `AdminInvitationController` and API
`AdminApiInvitationController`) rejects **only when the invitation `isUsed()`** — a consumed
invite has already created an account and regenerating it (which nulls `usedAt`) would allow a
second account in invitation-only mode. **Expiry does NOT block resend**: rotating a
valid-but-leaked invite link is a supported operation, and renewing an expired invite is the
primary resend use case. Both surfaces now apply this single identical guard (used → reject:
409 Conflict on the API, `error` flash + redirect on the web). Separately, **invitation-only
registration binds the created account's email to the invitation's email** — `RegistrationController`
rejects (form re-render with an `email` error, invite not consumed, no user created) when the
POSTed email does not case-insensitively equal `invitation->getEmail()`.
**Rationale:** Review finding C6. The web resend had no guard at all and the API guarded only
expiry, so a **used-and-expired** invite slipped the API's `!isExpired()` gate, got regenerated,
and could be reused. The web/API also **drifted**: the web resend contract (FEATURE-071 AC5)
rotates a **non-expired** invite and expects success, while the API returned 409 for the same —
contradictory. AC2 mandates a single reconciled guard; the used-ness guard is the actual security
fix, and expiry-as-a-resend-blocker was the anomaly (a poor UX that can't rotate a leaked live
link). Registration previously captured the invited email for display only and never compared it,
letting any invite-link holder register an arbitrary email.
**Impact:** Removes the API resend `!isExpired() → 409` branch (the prior API-only
`testResendNonExpiredInvitationReturns409` is reconciled to
`testResendNonExpiredUnusedInvitationRotatesToken`, asserting 200 + token rotation). Adds a
used-ness guard to both surfaces and an email-binding check in registration. No schema change.
**Status: decided + implemented (2026-07-06).**

## ADR-023: Email + Webhook Delivery via Symfony Messenger (Doctrine async transport) (FEATURE-104 / review C9)
**Tripwire:** verifiable. Verified by `tests/Functional/Messenger/AsyncDeliveryTest.php`.
**Decision:** Both notification **email** and **webhook** delivery move off the login/request path
onto **Symfony Messenger** with a **Doctrine async transport** (`messenger_messages` in the same DB).
(1) `Symfony\Component\Mailer\Messenger\SendEmailMessage` is routed to the `async` transport, so the
Mailer enqueues instead of sending inline — **no listener code change**. (2) Webhook dispatch is
wrapped in `App\Message\SendWebhookMessage` + `App\MessageHandler\SendWebhookMessageHandler`; the
app-facing `WebhookDispatcherInterface` is bound in prod/dev to a new `MessengerWebhookDispatcher`
whose `dispatch()` only `$bus->dispatch(new SendWebhookMessage(...))`, so all four existing call
sites (WebhookListener, LoginRateLimitListener, RegistrationController, PasswordResetController)
enqueue unchanged and the request never makes the outbound HTTP call itself. The handler runs the
**existing** `HttpWebhookDispatcher` logic (SSRF guard + `WebhookDelivery` logging) on the worker.
(3) `HttpWebhookDispatcher` is refactored from a self-retrying dispatcher into a **single-attempt
sender** `send(url,payload): WebhookDelivery`; its internal retry loop and the no-op `backoff()` are
**removed**. The handler throws on a non-delivered result, so retry/backoff is now Messenger's
`retry_strategy` (max_retries 3, delay 1000ms, multiplier 2); exhausted messages land in the
`failed` failure transport (dead-letter, inspectable/retryable).
**Rationale:** Review finding C9. `LoginNotificationListener` sent SMTP synchronously and
`HttpWebhookDispatcher` made blocking `file_get_contents` calls with a no-op backoff **inside**
`LoginSuccessEvent`/`LoginFailureEvent`, so a down webhook or SMTP outage hung/500'd **every login**,
and a password-spray forced outbound HTTP + a `WebhookDelivery` persist per failed attempt. Doctrine
transport chosen for consistency with ADR-001/ADR-013 (SQLite, single host, no Redis) and because
`messenger_messages` in the same DB gives transactional-outbox atomicity for free.
**Does NOT fix SSRF** (separate finding C19/FEATURE-105 — the DNS-rebind pin still belongs in the
handler); async only makes the call blind.
**Worker requirement:** delivery only happens if a `messenger:consume async` worker runs — documented
in README ("Background worker"). Without it, email/webhooks silently queue.
**Test wiring:** `when@test` routes `SendEmailMessage` → `sync://` (so `MailerAssertionsTrait`, which
counts *sent* not *queued* emails, keeps working) while the `async` transport is `in-memory://` so a
dispatched `SendWebhookMessage` is inspectable; `WebhookDispatcherInterface` stays bound to
`InMemoryWebhookDispatcher` in test/acceptance. Acceptance routes both to `sync://` (no worker on the
live server; mailer DSN is `null://`).
**Legacy note:** the admin `webhook.max_retry_attempts` config field is now a **no-op** (retry count
is Messenger's compile-time `retry_strategy`, not a DB value). The field is retained so FEATURE-057's
`WebhookConfigPageTest` is untouched; rewiring/removing it is deliberately out of this feature's scope.
**Impact:** NEW `src/Message/SendWebhookMessage.php`, `src/MessageHandler/SendWebhookMessageHandler.php`,
`src/Service/MessengerWebhookDispatcher.php`, `migrations/Version20260706120000.php`
(messenger_messages), `config/packages/messenger.yaml`, `config/packages/acceptance/messenger.yaml`;
`HttpWebhookDispatcher` refactored (drop loop + backoff, drop unused ConfigService dep); interface
binding flipped to MessengerWebhookDispatcher in `config/services.yaml`. Adds `symfony/messenger` +
`symfony/doctrine-messenger`.
**Status: decided + implemented (2026-07-06).**

## ADR-024: User Roles are Fixed to ROLE_USER; the Admin Edit Contract Rejects Unknown role/status (FEATURE-115 / review C22)
**Tripwire:** verifiable. Verified by `tests/Unit/Service/UserFieldValidatorTest.php`, `tests/Functional/Api/AdminApiUserValidationTest.php`.
**Decision:** A `User` account's role set is **fixed to `ROLE_USER`** — the single source of
truth is `User::ALLOWED_ROLES = ['ROLE_USER']`, enforced by the `setRoles()` allowlist (a User
carrying an admin role would collapse the separate-firewall boundary, ADR-003). The admin
user-write paths (web `AdminUserController::new`/`edit`, API `AdminApiUserController::create`/`update`)
therefore **do not implement a role-change**: they **validate** a submitted `role` (reject
anything not in `ALLOWED_ROLES` with a validation error) but never mutate roles, and the previous
`setRoles([])` dead calls are removed. A submitted `status` is likewise **rejected** when it is
not `AccountStatus::isValid()` — no more silent coercion to `active` (web) or silent drop-to-200
(API). The shared contract lives in one pure service, `App\Service\UserFieldValidator`
(`validateRole`/`validateStatus`), injected by both surfaces so they cannot drift.
**Rationale:** Review finding C22. The API `update` only acted on a *valid* role/status, so an
invalid value returned **200 OK with nothing changed** — a success response for an edit that never
happened. And a *valid* role triggered `setRoles([])`, which — because the allowed set is the
singleton `['ROLE_USER']` — could never change anything: validated-then-discarded dead code
(mirrored as silent coercion in the web new/edit paths). AC2 offered "implement the role edit OR
remove it + document roles as fixed"; since the allowed set is a singleton, an implementation would
be a permanent no-op, so **fixed roles + explicit rejection of unknown values** is the honest,
auditable contract.
**Impact:** NEW `src/Service/UserFieldValidator.php`; `AdminUserController` (new/edit) and
`Api/AdminApiUserController` (create/update) inject it, reject unknown role/status, and drop all
`setRoles([])` calls. No schema change, no new config key. Behaviour-preserving for valid inputs:
`$roles` defaults to `[]` and `getRoles()` always appends `ROLE_USER`, so stored + serialized roles
are unchanged; the web create/edit templates only ever offer `ROLE_USER`/`active`/`inactive`.
If a project ever needs additional user-tier roles, add them to `User::ALLOWED_ROLES` and the same
validator + write paths pick them up with no further change.
**Status: decided + implemented (2026-07-06).**

## ADR-025: Admin Impersonation Runs AdminChecker; Admin.isEqualTo Compares Roles (FEATURE-133 / review C23)
**Tripwire:** verifiable. Verified by `tests/Functional/Security/AdminImpersonationAuthzTest.php`, `tests/Unit/Entity/AdminTest.php`.
**Decision:** Two halves of the admin-impersonation / live-session authorization gap are closed
without introducing a full admin authenticator (that larger refactor is deferred):
(a) `AdminAdminManagementController::impersonateStart` now runs the target admin through the admin
firewall's own `user_checker` — `AdminChecker::checkPreAuth($targetAdmin)` — **before** forging the
`PostAuthenticationToken`. On any `AuthenticationException` (e.g. `DisabledException` for an inactive
admin) it fails **closed**: `error` flash + redirect to `app_admin_superadmin_admins`, with **no**
token set, no `_impersonating_admin_*` session markers, and no audit row. This makes admin
impersonation apply the same status gate a real admin login applies, matching the user-side path
(ADR-014, which goes through `ImpersonationAuthenticator` + `UserChecker`).
(b) `Admin::isEqualTo` now compares a **normalized (sorted) roles** list alongside the existing
password + status comparison. Because `Admin` implements `EquatableInterface`, Symfony's
`ContextListener::hasUserChanged` relies **solely** on `isEqualTo` and skips its own role-name
comparison, so omitting roles meant a **demoted** superadmin (ROLE_SUPER_ADMIN → ROLE_ADMIN) kept
the elevated role in a live session until re-auth. With roles in `isEqualTo`, a role change makes
`hasUserChanged` true, so the stale token is deauthenticated on the very next request.
**Rationale:** Review finding C23. Hand-forging the impersonation token bypassed `AdminChecker`
(privilege bypass — an inactive admin was impersonable), and role-blind `isEqualTo` let a role
demotion linger. A proper admin authenticator mirroring the user side is the ideal, but gating on
the same `AdminChecker` first is the minimal, auditable fix that satisfies the "same checks as a
real login" AC with a small, reviewable diff.
**Impact:** `AdminAdminManagementController::impersonateStart` injects `App\Security\AdminChecker`
and gates on `checkPreAuth`; `Admin::isEqualTo` adds the sorted-roles comparison. No schema change,
no new config key. Behaviour-preserving for unchanged roles (a normally-authenticated or actively
impersonated admin refreshes to identical roles, so `isEqualTo` stays true); the active-target
impersonation success path is unaffected. Routing admin impersonation through a dedicated
authenticator (rather than a hand-forged token) remains a future improvement.
**Status: decided + implemented (2026-07-06).**

## ADR-026: DBAL-only Tables Are Excluded via schema_filter; Unique Constraints Are Named Indexes (FEATURE-121 / review C29)
**Tripwire:** verifiable. Verified by `tests/Functional/Schema/SchemaFilterTest.php`, `tests/Functional/Schema/SchemaSyncTest.php`.
**Decision:** ORM schema management (`schema:validate`, `migrations:diff`) is kept authoritative and
in-sync via two rules:
(a) Tables that are **not** ORM-mapped because a bundle/DBAL owns them — `login_attempts`,
`endpoint_rate_limits`, `sessions` (PdoSessionHandler, ADR-002), and `messenger_messages`
(ADR-023) — are excluded from ORM schema management via a
`dbal.schema_filter` negative-lookahead regex in `config/packages/doctrine.yaml`. Without this,
a `migrations:diff` would emit `DROP TABLE` for them and one careless accepted diff would destroy
the rate-limiting store. *(Amended 2026-07-09: `doctrine_migration_versions` is deliberately NOT in
the `schema_filter` — filtering it broke `migrations:migrate` idempotency, so it is left visible; the
code comment in `config/packages/doctrine.yaml` explains. The Tripwire test can't catch this prose
drift since it only checks that the Tripwire line names a passing test.)*
(b) Column uniqueness that must be visible to DBAL introspection is expressed as a **named**
`UNIQ_xxx` index, never an inline `CONSTRAINT ... UNIQUE` (which SQLite stores as an invisible
`sqlite_autoindex_*` that DBAL 4.4 does not introspect, causing perpetual phantom ADD-index diffs).
Migration `Version20260706135549` recreates the 7 token/config tables so `token_hash`/`config_key`
uniqueness is a named index; `UserSession.session_id` uses a named `UNIQ_user_sessions_session_id`.
Entities must also declare every non-unique migration index (`#[ORM\Index]`) so metadata matches the DB.
**Rationale:** Review finding C29. `doctrine:schema:validate` was failing, masking real drift and
making `migrations:diff` unusable/dangerous. A green `schema:validate` is the guardrail that keeps
future migrations trustworthy.
**Impact:** New unique columns on ORM-mapped tables must ship as named indexes; new bundle/DBAL-owned
tables must be added to the `schema_filter` regex. Table recreations that touch uniqueness must
preserve data via `INSERT ... SELECT` and provide a faithful reversible `down()`.
**Status: decided + implemented (2026-07-06).**

## ADR-027: Webhook SSRF Guard — Hard-Coded Blocklist, Resolve-Check-Pin, Default-ON Switch (FEATURE-105 / review C19)
**Tripwire:** verifiable. Verified by `tests/Unit/Security/SsrfGuardTest.php`, `tests/Functional/Security/WebhookSsrfSwitchTest.php`.
**Decision (Ken, 2026-07-06):** Outbound webhook delivery is gated by a hardened SSRF guard,
`App\Security\SsrfGuard`, replacing the old `filter_var(NO_PRIV_RANGE|NO_RES_RANGE)` check.
(a) **In-code (not admin-editable) blocklist** for BOTH IPv4 and IPv6: v4 = 0/8, 10/8, 100.64/10,
127/8, 169.254/16, 172.16/12, 192.168/16, 224/4, 240/4; v6 = ::1, ::, fe80::/10, fc00::/7, ff00::/8.
IPv6 **embedded-IPv4** forms are unwrapped and the embedded v4 re-checked against the v4 list:
IPv4-mapped `::ffff:0:0/96`, 6to4 `2002::/16`, NAT64 `64:ff9b::/96`, Teredo `2001::/32` (last 32
bits XOR 0xffffffff). A webhook target must also be a **public FQDN** — single-label hosts
(localhost, db, intranet) and internal TLDs (`.local`, `.internal`, `.localhost`, `.home.arpa`,
`.lan`, `.corp`) are rejected.
(b) **Config switch** `webhook.block_internal_targets` (bool on the Webhook admin config page)
gates the block; it **defaults ON** (`ConfigService::getBool(key, true)` → fail-closed when
unconfigured). An operator can set it OFF to allow internal delivery. **Non-http(s) schemes**
(file://, php://, gopher:// …) are rejected **UNCONDITIONALLY** — that is a local-read/smuggling
attack, not an "internal target", so the switch never re-opens it.
(c) **TOCTOU fix:** on each delivery the host is resolved to ALL A+AAAA records ONCE; delivery is
refused if ANY resolved address is blocked; the connection is then **pinned** to the validated IP
via cURL `CURLOPT_RESOLVE` (no second resolution at connect) with the Host header / TLS SNI kept as
the original hostname. `HttpWebhookDispatcher::sendHttp` was switched from `file_get_contents` to
cURL for this. **Redirects are refused** (`CURLOPT_FOLLOWLOCATION=false`) so a 3xx to a fresh
unchecked host cannot re-open the hole.
**Rationale:** Review finding C19. The prior guard had a DNS-rebind TOCTOU (resolve-for-check then
`file_get_contents` re-resolves at connect), no IPv6 coverage, and no operator control. Single-host
/ no-cloud deployment reduces the metadata-theft payoff, but default-ON still protects localhost/LAN
services here and keeps forks safe on cloud/multi-tenant.
**Impact:** NEW `src/Security/SsrfGuard.php` + `src/Security/SsrfInspection.php`;
`HttpWebhookDispatcher` injects `?SsrfGuard` (nullable so the `ControlledHttpWebhookDispatcher` test
double still constructs with just the EM) and pins via cURL; `WebhookConfigPage` gains the bool
switch field; `HttpWebhookDispatcher::isAllowedUrl` now delegates to `SsrfGuard::isAllowedUrl`
(ON-posture). No schema change, no migration. The guard applies whether delivery is sync or via the
Messenger handler (ADR-023).
**Status: decided + implemented (2026-07-06).**

## ADR-029: Pin trusted_hosts from the App's Real Hosts + Fail-Fast Host Boot Guard + Safe-Local 2FA Redirect (FEATURE-131 / review C17) — AMENDS ADR-019
**Tripwire:** verifiable. Verified by `tests/Functional/Security/TrustedHostTest.php`, `tests/Functional/BootGuardTest.php`, `tests/Unit/Security/SafeRedirectTest.php`.
**Decision (Ken, 2026-07-06):** Close the host-header-trust root cause behind review finding C17.
(a) `framework.trusted_hosts` is pinned to the app's real hosts, derived from **all three** of the
existing deploy-time env vars: the `DEFAULT_URI` host (`^%env(key:host:url:DEFAULT_URI)%$`),
`ADMIN_DOMAIN` (`^%env(ADMIN_DOMAIN)%$`) and `APP_DOMAIN` (`^%env(APP_DOMAIN)%$`). Symfony wraps each
as `{pattern}i` and applies them via `kernel.trusted_hosts` at preBoot (`Request::setTrustedHosts`),
so a request whose `Host` matches none is rejected with **400** (`SuspiciousOperationException`)
before any controller runs. Ken accepts this three-var duplication as cheap, low-churn config (static
env, not drift-prone logic).
(b) **AMENDS ADR-019:** `ADMIN_DOMAIN` and `APP_DOMAIN` are now **REQUIRED** (no longer
optional-empty). A **fail-fast boot guard** in `src/Kernel.php::boot()` refuses to start — in web AND
CLI — unless `DEFAULT_URI` is a valid absolute URL (scheme + host) and both domain vars are non-empty,
throwing a `\RuntimeException` with a clear message. This eliminates the empty-entry footgun: an empty
value would compile to a match-all trusted-hosts regex and silently disable the guard. Single-domain
deployments set `ADMIN_DOMAIN = APP_DOMAIN = ` the single host; `.env` now sets both to `localhost`
so dev boots (operators override in `.env.local`, e.g. `auth.localhost`); `.env.test` (localhost) and
`.env.acceptance` (127.0.0.1) already set them, and both inherit `DEFAULT_URI=http://localhost` from
`.env`.
(c) The post-2FA redirect is made **same-origin by construction**: `TwoFactorChallengeListener` stores
`$request->getRequestUri()` (a LOCAL path+query, no host) instead of the absolute `getUri()`, and
`TwoFactorController::challenge` re-validates it through the new pure helper
`App\Security\SafeRedirect::localPathOr($target, '/dashboard')` — which accepts only a path-absolute,
same-origin target (single leading `/`, not `//`, not `/\`/`\`, no CR/LF) and otherwise falls back to
`/dashboard`. So a spoofed Host or a crafted request URI cannot turn the redirect off-origin.
**Rationale:** Review finding C17. With `trusted_hosts` unset the app trusted any client `Host`
header. The visible symptom was the absolute post-2FA redirect; the more serious sibling was
password-reset / verify-email links generated with `ABSOLUTE_URL` from the request Host during the
synchronous send (`router.default_uri` only applies in non-HTTP/worker contexts), enabling
password-reset link poisoning → account takeover. Pinning `trusted_hosts` is the direct fix (a spoofed
Host is rejected at 400 before any link is generated); the boot guard guarantees the pin is never
silently empty; the safe-local redirect is defence-in-depth on the specific C17 symptom.
**Impact:** `config/packages/framework.yaml` (trusted_hosts), `src/Kernel.php` (boot guard),
`.env` (dev domains set + REQUIRED note), NEW `src/Security/SafeRedirect.php`,
`src/EventListener/TwoFactorChallengeListener.php`, `src/Controller/TwoFactorController.php`. No schema
change, no migration, no new config key (reuses DEFAULT_URI/ADMIN_DOMAIN/APP_DOMAIN). Behaviour-
preserving on the trusted host: existing WebTestCase functional tests run over `localhost` (a trusted
host) and the post-2FA redirect target is `/dashboard` either way, so prior 2FA tests stay green.
**Status: decided + implemented (2026-07-06).**

## ADR-030: Check-then-insert Races Handled at the DB Layer — Catch Unique Violations, Atomic Cap/Claim (FEATURE-119 / review C27)
**Tripwire:** verifiable. Verified by `tests/Functional/Registration/RegistrationRaceTest.php`, `tests/Functional/Account/PersonalAccessTokenRaceTest.php`, `tests/Functional/Registration/InvitationRaceTest.php`, `tests/Functional/Admin/UserEmailChangeRaceTest.php`.
**Decision (Ken, 2026-07-06):** The friendly application-level pre-checks (`findByEmail`,
`countActiveByUserId`, `isUsed()`) are kept for the common, non-racy path, but correctness under
concurrency is delegated to the database, not to the pre-check:
(a) **Email uniqueness** — the `user.email UNIQUE` index is the source of truth. All three create
paths (`RegistrationController`, `AdminUserController::new`, `Api/AdminApiUserController::create`)
wrap `persist()+flush()` in `try/catch (UniqueConstraintViolationException)` and, on catch, return
the SAME clean validation error (200 + `.error` on web, 422 on API) — never a 500. The same holds for an
email CHANGE (issue #59): `UserAccountAdminService::update()`, shared by the web edit form and
`PATCH /admin-api/users/{id}`, catches the violation around its transaction and returns that error.
(b) **PAT per-user cap** — `AccountController::createToken` enforces the cap atomically via
`em->wrapInTransaction`: persist+flush the token, then re-count active tokens INSIDE the transaction;
if the fresh count `> max`, throw `TokenLimitExceededException` so the transaction rolls back (token
not created). On SQLite (ADR-001) the whole-DB write lock serialises the two writers, so the second
transaction's re-count observes the first's committed row and rolls back — the cap cannot be exceeded.
(c) **Invitation single-use** — a new `InvitationRepository::claim(int $id): bool` runs the conditional
DBAL UPDATE `SET used_at=:now WHERE id=:id AND used_at IS NULL` and returns `affected === 1`.
`RegistrationController` replaces `$invitation->markUsed()` with `claim()` executed INSIDE the same
`wrapInTransaction` that persists the user; a false return means a concurrent registration already
consumed the invite → throw `InvitationAlreadyUsedException` → rollback → reject (no user, invite not
re-consumed). The UPDATE takes the write lock, so a racing claim of the same row affects zero rows.
**Rationale:** Review finding C27. A check-then-insert that trusts the pre-check yields a 500 (email),
an exceeded cap (PAT), or a double-consumed invite (invitation) under concurrency. Pushing the
invariant to a DB constraint / conditional write / in-transaction re-count makes each flow correct
regardless of interleaving, with no new infrastructure.
**Impact:** NEW `src/Exception/TokenLimitExceededException.php`,
`src/Exception/InvitationAlreadyUsedException.php`; `InvitationRepository::claim`;
`AccountController::createToken`, `RegistrationController::register`, `AdminUserController::new`,
`Api/AdminApiUserController::create`. No schema change (the constraints already exist), no new config
key. Behaviour-preserving on the non-racy path: the pre-checks still short-circuit with the same
messages, so existing tests stay green.
**Status: decided + implemented (2026-07-06).**

## ADR-031: Login-notification recognition lives in a dedicated marker store, not login_history (FEATURE-107 / review C14)
**Tripwire:** verifiable. Verified by `tests/Functional/Security/LoginNotificationRecognitionTest.php`, `tests/Unit/Security/LoginFingerprintTest.php`.
**Decision (Ken, 2026-07-06):** The "have we already alerted this user about this device?" question
is answered by a NEW single-purpose store — `login_notification_seen` (user_id + a per-mode
`marker`, UNIQUE) — never by reading `login_history`. Concretely:
(1) **Shared fingerprint helper** — NEW `src/Security/LoginFingerprint.php` defines the fingerprint
formula (`hash('sha256', ip.ua)`) and the request-context extraction (ip default `0.0.0.0`, UA
`substr(...,0,512)`) in exactly ONE place; both `LoginHistoryListener` and `LoginNotificationListener`
call it, so the formula can no longer drift into a "new device on every login" bug.
(2) **Decoupled recognition** — `LoginNotificationListener` computes `marker = mode === 'ip_only' ? ip
: fingerprint`, notifies iff `LoginNotificationSeenRepository::hasSeen(userId, marker)` is false, then
records the marker. Because recognition never reads `login_history`, the decision no longer depends on
whether `LoginHistoryListener` has already run — the load-bearing listener-priority coupling is gone
and `recognition_mode` is honoured by construction.
(2b) **Visibility-only audit row** — when an alert is sent, a dedicated `audit_log` row
(action `login_notification_sent`, actorType `user`, outcome `success`) is written for the trail. It
is NOT read back for recognition; keeping the marker store and the audit row as two single-purpose
records means audit retention/pruning can never cause a spurious re-alert (and avoids a full scan of
the unindexed audit_log per login — C30).
(3) **Seed on deploy** — the migration seeds `login_notification_seen` from existing `login_history`
(DISTINCT user_id+fingerprint AND user_id+ip, covering both modes) so users are not alerted about
devices they have long used the first time this ships.
**Rationale:** Review finding C14. One value — the login_history fingerprint — was overloaded for two
jobs (history record AND recognition memory), coupling two listeners via run-order; reordering them
would silently stop all alerts with no failing test. Splitting recognition into its own store removes
the coupling entirely and lets the listeners run in any order.
**Impact:** NEW `src/Security/LoginFingerprint.php`, `src/Entity/LoginNotificationSeen.php`,
`src/Repository/LoginNotificationSeenRepository.php`, `migrations/Version20260706160000.php`;
EDIT `src/EventListener/LoginNotificationListener.php` (marker store + audit row + helper),
`src/EventListener/LoginHistoryListener.php` (helper), `src/Repository/LoginHistoryRepository.php`
(removed the now-dead history-recognition readers). New table + new audit action; no new config key
(reuses `login_notifications.enabled` / `.recognition_mode`).
**Status: decided + implemented (2026-07-06).**

## ADR-032: Admin login notifications mirror the user side in a separate admin_login_notification_seen table (FEATURE-108)
**Tripwire:** verifiable. Verified by `tests/Functional/Security/AdminLoginNotificationTest.php`.
**Decision (Ken, 2026-07-06):** The original SPEC frames login notifications as a USER feature (both
`LoginNotificationListener` and `LoginHistoryListener` bail on non-`User`), so admins get no login
alerts. Admins should ALSO receive "new login from an unrecognized device" emails. Build the admin-side
**mirror** of the user login-notification flow (FEATURE-107 / ADR-031), following ADR-003 realm isolation
and the explicit-separation preference:
(1) **Separate listener** — NEW `src/EventListener/AdminLoginNotificationListener.php`
(`LoginSuccessEvent`, priority 10, `InteractiveFirewallTrait` + `instanceof Admin`) reacts to interactive
admin logins only — never stateless admin-api (PAT) auth (FEATURE-097). It computes the fingerprint via
the SHARED `LoginFingerprint` helper (one formula across both realms), emails the admin on an
unrecognized device (reusing the existing `security/email/login_notification_email.html.twig` with an
admin-specific subject), then records the marker.
(2) **Dedicated separate table** — NEW `admin_login_notification_seen` (owner column `admin_id` + per-mode
`marker`, UNIQUE(admin_id, marker)) via `AdminLoginNotificationSeen` +
`AdminLoginNotificationSeenRepository`. A SEPARATE table — NOT a shared login_notification_seen with a
`user_type` discriminator — mirroring the admin_* token-table pairs.
(3) **Dumb-data trait (C8)** — the realm-agnostic columns (id, marker, created_at + accessors) are shared
verbatim between the user and admin entities via NEW trait `App\Entity\LoginNotificationSeenColumns`;
only the owner-id column (user_id vs admin_id) is realm-specific. Share-vs-duplicate rule honoured: the
ONLY cross-realm sharing is the pure `LoginFingerprint` helper, the string-param `AuditLogger` sink, and
this dumb-data column trait — NO abstract base listener, NO cross-realm store interface; each listener
writes its own table inline. The admin listener does NOT reuse the User-typed `LoginNotificationChecker`;
it inlines its config check.
(4) **Explicit admin config keys (not reused)** — NEW `login_notifications.admin_enabled` (bool, default
ON) and `login_notifications.admin_recognition_mode` (enum fingerprint|ip_only, default fingerprint) on
`GeneralConfigPage`, kept explicitly separate from the user keys so the two realms tune independently.
Admin has no per-account notification toggle (unlike `User.loginNotificationsEnabled`), so enablement is
the global admin key only.
(5) **Visibility-only audit row** — a `login_notification_sent` audit row with actorType `'admin'` is
written when an alert is sent; recognition is NEVER read from the audit log (stays the marker store), so
retention/pruning can't cause a spurious re-alert.
(6) **Deploy grace (documented)** — unlike the user table (seeded from pre-existing `login_history`),
there is NO prior admin device data anywhere: admins were never tracked before this feature (admin login
history is FEATURE-109; `user_sessions` only ever stores `user_type='user'`). So the migration
deliberately does NOT seed; the first login per admin device after deploy establishes the baseline (one
alert), exactly like a user's genuinely-first login, and only NEW devices thereafter alert. This is the
documented-grace branch of the feature's AC.
**Rationale:** Enhancement beyond the SPEC, decided by Ken. Explicit realm separation (separate table,
separate config keys, separate listener) over a shared discriminated table matches ADR-003 and the
project's auditability preference; the only shared code is genuinely realm-agnostic (fingerprint, audit
sink, dumb-data columns), per review finding C8.
**Impact:** NEW `src/Entity/AdminLoginNotificationSeen.php`, `src/Entity/LoginNotificationSeenColumns.php`,
`src/Repository/AdminLoginNotificationSeenRepository.php`, `src/EventListener/AdminLoginNotificationListener.php`,
`migrations/Version20260706170000.php`; EDIT `src/Entity/LoginNotificationSeen.php` (use the trait — no
schema change), `src/Config/GeneralConfigPage.php` (2 admin keys). Admin login-HISTORY parity (the "view
your recent logins" page) is OUT OF SCOPE here — that is FEATURE-109 — because the decoupled design
recognizes devices via the marker table, not login_history.
**Status: decided + implemented (2026-07-06).**

## ADR-033: Admin login history mirrors the user side in a separate admin_login_history table (FEATURE-109)
**Tripwire:** verifiable. Verified by `tests/Functional/Security/AdminLoginHistoryTest.php`.
**Decision (Ken, 2026-07-06):** The original SPEC frames Login History as a USER feature ("User can view
their own recent logins (IP, device, timestamp)"); `LoginHistoryListener` bails on non-`User`, so admin
logins are never recorded and admins have no recent-logins view. Admins should ALSO get login history.
Build the admin-side **mirror** of the user login-history flow (FEATURE-107 helper), following ADR-003
realm isolation and the explicit-separation preference — the sibling of the admin login-notification
mirror (FEATURE-108 / ADR-032):
(1) **Separate listener** — NEW `src/EventListener/AdminLoginHistoryListener.php` (`LoginSuccessEvent`,
`InteractiveFirewallTrait` + `instanceof Admin`) records each interactive admin login (ip, user_agent,
fingerprint via the SHARED `LoginFingerprint` helper, created_at) into a dedicated table. Only interactive,
session-backed logins are recorded — never stateless admin-api (PAT) auth (FEATURE-097).
(2) **Dedicated separate table** — NEW `admin_login_history` (owner column `admin_id`) via
`AdminLoginHistory` + `AdminLoginHistoryRepository`. A SEPARATE table — NOT a shared login_history with a
`user_type` discriminator — mirroring the admin_* token/notification-seen table pairs.
(3) **Dumb-data trait (C8)** — the realm-agnostic columns (id, ip, user_agent, fingerprint, created_at +
accessors) are shared verbatim between the user and admin history entities via NEW trait
`App\Entity\LoginHistoryColumns`; only the owner-id column (user_id vs admin_id) is realm-specific.
`LoginHistory` is refactored to use the trait — no schema change. Share-vs-duplicate rule honoured: the
ONLY cross-realm sharing is the pure `LoginFingerprint` helper and this dumb-data column trait — NO
abstract base listener, NO cross-realm store interface; each listener writes its own table inline.
(4) **Self-service view** — NEW `src/Controller/AdminAccountController.php` (`#[IsGranted('ROLE_ADMIN')]`)
exposes `/admin/login-history` (`app_admin_login_history`), rendering ONLY the logged-in admin's own rows
(`AdminLoginHistoryRepository::findRecentByAdminId(getUser()->getId())`), so an admin can never see another
admin's history. A "Login History" link is added to `_admin_nav.html.twig`.
(5) **Deploy grace (documented)** — there is NO prior admin device data anywhere (admins were never tracked;
`user_sessions` only ever stores `user_type='user'`). So the migration deliberately does NOT seed; the table
starts empty and the first login per admin after deploy establishes the history.
**Rationale:** Enhancement beyond the SPEC, decided by Ken. Explicit realm separation (separate table,
separate listener, separate controller) over a shared discriminated table matches ADR-003 and the project's
auditability preference; the only shared code is genuinely realm-agnostic (fingerprint helper + dumb-data
columns), per review finding C8.
**Impact:** NEW `src/Entity/AdminLoginHistory.php`, `src/Entity/LoginHistoryColumns.php`,
`src/Repository/AdminLoginHistoryRepository.php`, `src/EventListener/AdminLoginHistoryListener.php`,
`src/Controller/AdminAccountController.php`, `templates/admin/account/login_history.html.twig`,
`migrations/Version20260706180000.php`; EDIT `src/Entity/LoginHistory.php` (use the trait — no schema
change), `templates/layout/_admin_nav.html.twig` (nav link). No new config key. The admin-notification
mirror (FEATURE-108) is the sibling; admin login-notification recognition uses its own marker table, so this
history table is view-only and is never read for recognition.
**Status: decided + implemented (2026-07-06).**

## ADR-034: Admin sessions live in a dedicated admin_sessions table; user_sessions.user_type discriminator removed (FEATURE-123 / review C31)
**Tripwire:** verifiable. Verified by `tests/Functional/Security/AdminSessionManagementTest.php`.
**Decision (Ken, 2026-07-06):** ADR-006 introduced `user_sessions.user_type enum(user|admin)` for "both
User and Admin have independent session tracking", but `UserSessionListener` hardcoded `'user'` and every
session listener early-returned for `Admin`, so `user_type='admin'` was **dead schema** and admins had no
active-sessions view and no logout-everywhere. Take the **parity** branch of the feature (not the "just
delete the enum" branch), following ADR-003 realm isolation + the explicit-separation preference — the
sibling of the admin login-notification (ADR-032) and login-history (ADR-033) mirrors:
(1) **Dedicated separate table** — NEW `admin_sessions` (owner column `admin_id`, `UNIQ_admin_sessions_session_id`
+ `IDX_admin_sessions_admin_id`) via `AdminSession` + `AdminSessionRepository`. A SEPARATE table keyed on
`admin_id` — NOT a shared `user_sessions` leaning on a `user_type` discriminator.
(2) **Separate listeners** — `AdminSessionListener` (`LoginSuccessEvent`/`LogoutEvent`, `InteractiveFirewallTrait`
+ `instanceof Admin`) inserts on login / removes on logout; `AdminSessionRequestListener` (kernel.request -10)
bumps `last_active_at` and performs terminated-session logout, mirroring the user request listener.
(3) **Self-service parity** — `AdminAccountController` gains GET `/admin/sessions`, POST `/admin/sessions/terminate-all`,
POST `/admin/sessions/{id}/terminate` (strictly scoped to `getUser()->getId()`, per-session ownership enforced
with 404 = no IDOR, both POSTs CSRF-protected) + `admin/account/sessions.html.twig` + nav link. Admin firewall
has NO remember_me, so logout-everywhere just deletes the rows + redirects to `/admin/login`; the request
listener invalidates the current session on the next request (same mechanism as the user side) — no
`sessionsInvalidatedAt` needed.
(4) **Discriminator removed (AC3)** — because admin rows now live in their own table, `user_sessions.user_type`
is provably single-valued and is a dead discriminator. Migration `Version20260706190000` DROPs the column
(reversible `down()`) and drops it from the `UserSession` entity mapping; `UserSessionRepository`
(`findByUserId`/`deleteAllByUserId`), the `AccountController` call sites, and `UserSessionListener` drop the
`$userType` param/arg. The `PrincipalType` enum is RETAINED — it still validates `audit_log.actor_type`
(docblock's `user_sessions.user_type` reference removed).
(5) **Stateless PAT excluded (AC4)** — `AdminSessionListener` gates on `isInteractiveFirewall` (excludes
`admin_api`) and `AdminSessionRequestListener` skips `^/admin-api`, so PAT auth never records a session.
(6) **Impersonation** — `ImpersonationController::exitImpersonation` re-establishes the admin's `admin_sessions`
row on the post-migration session id (user impersonation migrates the session id, orphaning the admin row),
so the request listener does not log the admin out on return to `/admin/dashboard`.
**Rationale:** Review finding C31. A discriminator column that is only ever written with one value is dead
schema that makes the DB lie about its own contract; the honest fix given Ken's parity goal is real admin
session records in their own table + removal of the discriminator. Share-vs-duplicate (C8) honoured: the ONLY
cross-realm sharing stays the pure `LoginFingerprint` helper + `InteractiveFirewallTrait`; `AdminSession` is
its own explicit entity, no shared base/interface, no discriminator.
**Impact:** NEW `src/Entity/AdminSession.php`, `src/Repository/AdminSessionRepository.php`,
`src/EventListener/AdminSessionListener.php`, `src/EventListener/AdminSessionRequestListener.php`,
`templates/admin/account/sessions.html.twig`, `migrations/Version20260706190000.php`,
`tests/Functional/Security/AdminSessionManagementTest.php`; EDIT `src/Entity/UserSession.php`,
`src/EventListener/UserSessionListener.php`, `src/Repository/UserSessionRepository.php`,
`src/Controller/AccountController.php`, `src/Controller/AdminAccountController.php`, `src/Enum/PrincipalType.php`,
`templates/layout/_admin_nav.html.twig`, `src/Controller/ImpersonationController.php`, and the schema-drop test
reconciliations. The bare `$em->flush()` in `AdminSessionRequestListener` mirrors `UserSessionRequestListener`
verbatim — the flush-footgun cleanup stays deferred to FEATURE-106 (parity preserved). No new config key.
**Status: decided + implemented (2026-07-06).**
**Extended by ADR-049 (2026-07-09):** admin soft-delete/deactivate now tears down `admin_sessions`
(previously left dangling as ghost "active sessions"), and a core `AdminSessionPruner` ages out dead
rows — closing the two gaps this ADR left relative to the user realm. See ADR-049, which also records
the admin remember-me precondition the "no `sessionsInvalidatedAt` needed" claim above depends on.

## ADR-035: Two Audit Write Modes (independent durable log() vs atomic logDeferred()) + Scoped Throttled Session Touch (FEATURE-106 / review C10)
**Tripwire:** verifiable. Verified by `tests/Functional/AuditLog/AuditFlushFootgunTest.php`, `tests/Unit/Service/AuditLoggerTest.php`.
**Decision (Ken, 2026-07-06):** `AuditLogger::log()` and both session request listeners previously
called a bare `$em->flush()`, committing the WHOLE unit of work — (A) any entity dirtied earlier in
the request was silently committed by an unrelated logging/session write; (B) the audit row committed
immediately and independently, so a 'success' audit could outlive a business change that later
failed/rolled back. Split the audit sink into two explicit modes:
(a) **`log()` = independent write** via a direct DBAL `Connection::insert('audit_log', ...)` that
NEVER touches the UnitOfWork (no collateral flush of unrelated dirty entities, AC4). This is the mode
for login/security/logout/explicit-failure events, which must be recorded regardless of a rolled-back
business change. The enum guard is preserved (`assertValid()` on `PrincipalType`/`AuditOutcome`).
*(Amended 2026-07-09: the insert uses the SAME DBAL connection as the EntityManager, not a dedicated
one — so "survives a surrounding rollback" is NOT a guarantee and is not test-pinned. It is durable in
practice only because no current caller wraps `log()` in an open transaction, each flushing its own
business change first; enforcing true rollback-survival would require a dedicated autocommit connection.)*
(b) **`logDeferred()` = persist-only (no flush)**. The CALLER owns a single transaction boundary
(`$em->wrapInTransaction`) so the audit row and the business change commit atomically — both or neither
(AC1/AC2). The enum guard is preserved via the entity setters (`setActorType`/`setOutcome`).
**Admin CRUD is made atomic:** web `AdminUserController::{new,edit,delete}`,
`AdminAdminManagementController::{create,edit,delete}`, and API
`Api/AdminApiUserController::{create,update,delete}` wrap the entity mutation + `logDeferred()` in
`wrapInTransaction`, dropping the prior standalone `$em->flush()`; the create paths'
`UniqueConstraintViolationException` catch now wraps the transaction call, so a rollback leaves no user
AND no audit row. Non-CRUD admin actions (activate/deactivate/unlock/reset-2fa/password-reset/
revoke-tokens/impersonate/force-logout) keep the independent `log()` — AC2 scopes to create/update/delete
and every such caller already flushes its own business change before its `log()`, so moving `log()` off
the UoW drops no business commit.
**Session listeners (AC5):** `UserSessionRequestListener` and `AdminSessionRequestListener` replace the
whole-UoW `$em->flush()` with a scoped one-row DBAL UPDATE via new
`UserSessionRepository`/`AdminSessionRepository::touchLastActive(sessionId, now)`, **throttled** to at
most once / `LAST_ACTIVE_THROTTLE_SECONDS` (60s). Both listeners STILL call `findBySessionId()` every
request (null ⇒ invalidate + clear token — terminated-session logout unaffected); the `EntityManager`
dependency is removed from both. This closes the flush-footgun cleanup deferred by ADR-034.
**Rationale:** Review finding C10. A bare `flush()` from a logging/session write is a collateral-commit
and a non-atomic-audit footgun; logging merely "after" the business commit would still leave a
crash-after-commit gap. Splitting into an atomic mode (success-of-a-change) and an independent durable
mode (login/security/failure) makes each contract explicit. The throttled scoped session UPDATE avoids a
per-request whole-UoW write, important on SQLite's single-writer whole-DB lock (ADR-001/ADR-013).
**Impact:** EDIT `src/Service/AuditLogger.php` (adds `logDeferred()`, `log()` → DBAL insert), the three
admin controllers, `src/EventListener/{UserSessionRequestListener,AdminSessionRequestListener}.php`,
`src/Repository/{UserSessionRepository,AdminSessionRepository}.php` (add `touchLastActive`); reconcile
`tests/Unit/Service/AuditLoggerTest.php` to the new persist-without-flush / independent-insert contract;
NEW `tests/Functional/AuditLog/AuditFlushFootgunTest.php`. No schema change, no migration, no new config
key. DBAL insert column names/types match the AuditLog mapping (`actor_type`, `created_at` as
DATETIME_IMMUTABLE).
**Status: decided + implemented (2026-07-06).**

## ADR-036: Per-Role 2FA Enforcement (strictest-wins, global fallback) + Admin TOTP as a Realm-Isolated Mirror (FEATURE-126 / review C37)
**Tripwire:** verifiable. Verified by `tests/Unit/Security/TwoFactorEnforcementResolverTest.php`, `tests/Functional/Security/AdminTwoFactorTest.php`.
**Decision (Ken, 2026-07-06):** Close the two remaining actionable C37 spec deviations.
(a) **Per-role enforcement (backward-compatible):** NEW `src/Security/TwoFactorEnforcementResolver.php`
`resolveForRoles(array $roles): string` reads `2fa.enforcement.role.<ROLE>` per role
(`inherit|off|optional|required`; `inherit`/absent = not configured). If ANY role is configured, the
effective level is the **strictest** across the user's roles (rank `off < optional < required`); if
none is configured it falls back to the legacy global `2fa.enforcement` key (default `optional`). A
superadmin holds `[ROLE_SUPER_ADMIN, ROLE_ADMIN]`, so the strictest of the two per-role settings wins
(the most-privileged account is forced when either role requires it). `TwoFactorChallengeListener` and
`TwoFactorGuard` are rewired to resolve by `$user->getRoles()`. A plain user has no per-role key, so the
global-key fallback preserves existing user-2FA behaviour exactly — existing tests stay green.
(b) **Admin TOTP as a realm-isolated mirror (ADR-003):** the Admin realm previously had NO second factor.
Add TOTP to Admin by **mirroring** the user 2FA flow, sharing only pure helpers/traits per the
share-vs-duplicate rule and Ken's explicit-separation preference — NO shared identity base class, NO
cross-realm store interface. The realm-agnostic TOTP columns (`totpSecret`, `isTotpEnabled`,
`lastTotpCounter` + accessors) live in NEW dumb-data trait `src/Entity/TotpColumns.php`, used verbatim by
BOTH `User` and `Admin` (mirrors the existing `LoginHistoryColumns` / `LoginNotificationSeenColumns`
traits); `User` is refactored to `use TotpColumns` behaviour-preservingly. `migrations/Version20260706200000.php`
ADDs the three columns to the `admin` table (`totp_secret VARCHAR(64)`, `is_totp_enabled BOOLEAN NOT NULL
DEFAULT 0`, `last_totp_counter INTEGER`), reversible DROP. The pure `TotpService` is reused verbatim. NEW
`src/Controller/AdminTwoFactorController.php` (`/admin/2fa/setup`, `/disable`, `/challenge`) mirrors the
user controller with admin-scoped session keys (`_admin_2fa_temp_secret`/`_verified`/`_target_url`) and
`admin_2fa_*` CSRF ids; the challenge is rate-limited via `EndpointRateLimiter` keyed by admin id. NEW
`src/EventListener/AdminTwoFactorChallengeListener.php` (`kernel.request`, priority -20) intercepts
`^/admin` (skips `/admin-api`, login/logout/forgot/reset, and active admin→admin impersonation),
redirects enrolled admins with a pending challenge to `app_admin_2fa_challenge` and `required`-but-
unenrolled admins to `app_admin_2fa_setup`. NO trusted-device cookie on the admin side (kept minimal,
out of scope). A superadmin resets an admin's 2FA via `AdminAdminManagementController::resetTwoFactor`
(`/admin/superadmin/admins/{id}/reset-2fa`), audited `admin.admin_reset_2fa`.
**Security (prior BLOCKER, fixed):** the listener exempts ONLY `/admin/2fa/challenge`, NOT the whole
`/admin/2fa` prefix — an authenticated-but-unverified enrolled admin hitting `/admin/2fa/disable` (or
`/setup`) falls through to the enrolled-challenge branch and is redirected BEFORE the disable controller
runs, so a stolen password alone cannot clear the second factor. The `required`-but-unenrolled branch
guards against a setup→setup loop so enrolment stays reachable while disable stays gated.
**Rationale:** Review finding C37. The spec calls for off/optional/required *per role* (not one global
switch) and "superadmin can reset any admin or user's 2FA", yet Admin had no TOTP fields — the most
privileged accounts had no second factor. Mirroring the user flow with shared pure helpers keeps the
realms isolated while avoiding a divergent second implementation. (The other two C37 items are handled
elsewhere: Webhook→Messenger = FEATURE-104 / ADR-023; the `/admin-api` path is an intentional documented
decision, not a deviation.)
**Impact:** NEW `src/Security/TwoFactorEnforcementResolver.php`, `src/Entity/TotpColumns.php`,
`src/EventListener/AdminTwoFactorChallengeListener.php`, `src/Controller/AdminTwoFactorController.php`,
`templates/admin/2fa/{setup,challenge}.html.twig`, `migrations/Version20260706200000.php`;
EDIT `src/Entity/User.php` + `src/Entity/Admin.php` (use trait), `src/Config/TwoFactorConfigPage.php`
(three per-role enum fields alongside the legacy global fallback),
`src/EventListener/TwoFactorChallengeListener.php`, `src/Security/TwoFactorGuard.php`,
`src/Controller/AdminAdminManagementController.php`, `templates/admin/superadmin/admins.html.twig`,
`templates/layout/_admin_nav.html.twig`. Schema change is additive (three admin columns);
`doctrine:schema:validate` green (trait mapping + migration match).
**Status: decided + implemented (2026-07-06).**

## ADR-037: Cross-realm collapse is dumb-data traits only; AXIS-2 delivered first as a standalone slice (FEATURE-103 / review C8)
**Tripwire:** verifiable. Verified by `tests/Unit/Entity/AccessTokenColumnsTest.php`, `tests/Unit/Entity/PasswordResetTokenColumnsTest.php`.
**Decision (Ken, 2026-07-06):** FEATURE-103 has two axes and is delivered in reviewable slices.
This ADR records the **AXIS-2** slice (cross-realm dumb-data collapse) as implemented; the AXIS-1
service extraction is deferred to a follow-up slice.
**AXIS 2 — collapse ONLY realm-agnostic dumb data, never behaviour, across the user/admin realms
(ADR-003 isolation preserved):**
(1) NEW `src/Entity/PasswordResetTokenColumns.php` trait carries the identical fields + constructor +
lifecycle (`getCreatedAt`, `markUsed`, `isExpired/isUsed/isValid`) shared verbatim by
`PasswordResetToken` and `AdminPasswordResetToken`; each entity keeps only its class-level ORM attrs
(Entity+repo, Table, per-realm email Index). This **resolves review finding C32**: the admin token
previously carried `declare(strict_types=1)` but LACKED `getCreatedAt()`, while the user token had
`getCreatedAt()` but LACKED strict_types — both are now strict and both expose `getCreatedAt()`.
(2) NEW `src/Entity/AccessTokenColumns.php` trait carries the identical fields/lifecycle shared by
`PersonalAccessToken` and `AdminAccessToken`; the **owner-id column stays realm-specific** (`userId` +
`IDX_PAT_USER_ID` vs `adminId` + `IDX_AAT_ADMIN_ID`), so each entity keeps its own owner column, owner
getter, index, and constructor (which assigns the owner id + createdAt).
(3) NEW `src/Repository/PasswordResetTokenRepositoryTrait.php` holds the byte-identical
`invalidateOtherUnusedTokens` + `deleteExpiredOrUsed` methods (the DELETE DQL uses `$this->getClassName()`
so one body serves both repos); both repos `use` it and drop the inline copies.
(4) The "duplicated ALLOWED_STATUSES constant (single source)" clause was **already satisfied** by the
existing `App\Enum\AccountStatus` enum (single source of truth for both realms) — no change needed.
**Deliberately NOT done (AC6 negative constraint honoured):** no shared abstract service body across
realms, no shared User/Admin identity base class or trait. The ONLY cross-realm sharing added is pure
dumb-data traits + a repository-mechanics trait — the same rule already applied by
`LoginNotificationSeenColumns`/`TotpColumns`/`LoginHistoryColumns`.
**Rationale:** Review finding C8. The mappings are moved **verbatim** (same columns, lengths, unique
flag, nullability), so the Doctrine schema is byte-for-byte unchanged — `doctrine:schema:validate`
stays green (ADR-026), no migration. Behaviour-preserving: the full PHPUnit suite passes except the
pre-existing, unrelated OQ-007 ledger failure (FEATURE-106 stale citation).
**AXIS 1 (same-model web/API collapse) — DELIVERED across follow-up slices:** `InvitationService`
(AC2, one used-ness guard both surfaces — ADR-022), the user-side `PasswordResetService` (AC3, three
inlined copies collapsed), and finally NEW `src/Service/UserAccountAdminService.php` +
`src/Service/UserWriteResult.php` (AC1 — the last slice, 2026-07-06): the single site for admin user
create/update/delete/deactivate-with-teardown. Web `AdminUserController` (new/edit/delete) and API
`Api/AdminApiUserController` (create/update/delete/deactivate) are now thin callers that build an input
array, invoke the service, and map `UserWriteResult` to their realm response. Actor identity passed in
as `(actorEmail, actorIp)` so the service is realm-agnostic and testable. This makes the C4/C6/C13/C22
fixes single-site and structurally hard to reintroduce. Behaviour-preserving except the AC1 fold-in:
admin-created users now get `setPasswordChangedAt(now)` + a `passwordHistory` seed, matching
`RegistrationController`. AC6 negative constraint still honoured — a pure web<->API collapse within the
user realm, no shared abstract service body, no cross-realm sharing.
**Impact:** NEW `src/Entity/PasswordResetTokenColumns.php`, `src/Entity/AccessTokenColumns.php`,
`src/Repository/PasswordResetTokenRepositoryTrait.php` (AXIS-2); NEW `src/Service/UserAccountAdminService.php`,
`src/Service/UserWriteResult.php` (AXIS-1/AC1) + the earlier `InvitationService` / `PasswordResetService`;
EDIT the four token entities + two reset-token repositories (traits) and the two admin user controllers
(thin callers); NEW unit tests `tests/Unit/Entity/PasswordResetTokenColumnsTest.php`,
`tests/Unit/Entity/AccessTokenColumnsTest.php`, `tests/Unit/Service/UserAccountAdminServiceTest.php`.
No schema change, no migration, no new config key.
**Status: AXIS-2 decided + implemented (2026-07-06); AXIS-1 (AC1/AC2/AC3) decided + implemented
(2026-07-06). FEATURE-103 complete — all seven ACs met, verify-fast fully green.**

## ADR-039: ADR-to-reality reconciliation + machine-checked ADR tripwire (FEATURE-139)
**Tripwire:** verifiable. Verified by `tests/Functional/Meta/AdrTripwireTest.php`, `tests/Functional/Bundle/AuthPatBundleModularityTest.php`; repo `bin/adr-tripwire.php`, `src/Meta/AdrTripwire.php`.
**Decision (2026-07-07):** Two-part close-out of the C36 "did the ADR ledger tell the truth?" check.
**(1) Reconcile the modularity fudge.** FEATURE-062 AC5 ("endpoints are only registered when their
respective bundle is installed") had been marked passes:true with evidence that literally said the
opposite — *"all bundles always present in this monorepo; route existence satisfies AC5; conditional
route registration would be over-engineering."* Now that a REAL bundle exists (auth-pat, FEATURE-138),
the PAT admin-API endpoint `DELETE /admin-api/users/{id}/tokens` was moved out of core
`AdminApiUserController` into `App\Bundle\AuthPat\Controller\AdminApiTokenController`, so it is
registered ONLY when the bundle is installed. `AuthPatBundleModularityTest` now asserts
`app_api_admin_users_revoke_tokens` is absent from the router when the bundle is not registered — the
genuine 404/route-absent form of AC5. Route-collection absence (not an HTTP status) is the signal
because the `^/admin-api` firewall's `access_control` returns 401 for ANY path, present or not. The
other two FEATURE-062 endpoints (unlock=security, 2fa=2fa) stay in core and remain honestly
"monorepo-present" until those bundles are extracted; FEATURE-062 AC5 + its evidence were rewritten to
say exactly that. **Audit (AC2):** the auth-*-bundle features (029–057) were reviewed — only
FEATURE-062 AC5 makes an installability/modularity claim; every other bundle-feature AC is behavioural,
so none needed an uninstall test. PAT is the only real bundle today and is the only feature carrying a
real uninstall test.
**(2) ADR tripwire.** Every ADR in this file now carries a `**Tripwire:**` line tagging it `rationale`
(prose-only: ADR-001/012/015/018) or `verifiable` (names ≥1 test that goes red if the claim breaks).
`src/Meta/AdrTripwire.php` parses this file and flags any ADR that is untagged, verifiable-but-names-no-
test, or names a test/repo path that does not exist — parsing ONLY the Tripwire line (never the prose
Impact lists) so it is deterministic. `bin/adr-tripwire.php` prints those flags and **always exits 0**
(advisory, non-gating — surfaced by `bin/verify-fast.sh`). The teeth are the TEST
`tests/Functional/Meta/AdrTripwireTest.php`, which asserts the parser finds zero flags on the real
ledger; a second test feeds a broken ledger and asserts the expected flags appear, proving the tripwire
is not a no-op. Per the AC, enforcement lives in ADRs-carrying-tests + a mechanical check, NOT in a
"smarter reconciliation prompt" (the same soft judge that rubber-stamped AC5 in the first place).
**Rationale:** FEATURE-139. A ledger that can mark an installability AC true while its own evidence
admits the endpoint is always present is a ledger that lies; the fix is (a) make the one real claim
real, and (b) give every future verifiable ADR a named test plus a mechanical tripwire so silent drift
(a deleted/renamed cited test, an ADR asserting a path that no longer exists) is surfaced.
**Impact:** NEW `src/Bundle/AuthPat/Controller/AdminApiTokenController.php`, `src/Meta/AdrTripwire.php`,
`bin/adr-tripwire.php`, `tests/Functional/Meta/AdrTripwireTest.php`; EDIT
`src/Controller/Api/AdminApiUserController.php` (drop `revokeTokens` + unused port import),
`tests/Functional/Bundle/AuthPatBundleModularityTest.php` (assert the admin-API route), `config/services.yaml`
(exclude `../src/Meta/`), `bin/verify-fast.sh` (advisory tripwire step), this file (38 Tripwire tags +
ADR-010 amendment), and FEATURE-062's AC5 + evidence in `.agent/feature_list.json`. No schema change,
no migration, no new config key. Behaviour-preserving with the bundle installed: the revoke endpoint
keeps the same route/name/response.
**Status: decided + implemented (2026-07-07).**

## ADR-038: The verify gate resets var/test.db to a pristine, migrated state before EACH suite (FEATURE-137)
**Tripwire:** verifiable. Verified by `tests/Functional/Meta/MigrationIdempotencyTest.php`.
**Decision (2026-07-07, C36 prerequisite):** `bin/verify-fast.sh` owns a `reset_test_db()` step that
removes `var/test.db` (+ `-wal`/`-shm` side files, WAL is on) and runs `doctrine:migrations:migrate`
on the now-empty file, called BEFORE the PHPUnit(unit+functional) suite AND again BEFORE the
Codeception acceptance suite. Each suite therefore starts from an identical, fully-migrated, zero-row
schema regardless of what a prior suite or a prior full run left behind.
**Rationale:** All three gate steps share one SQLite file. The acceptance `DatabaseHelper` resets
per-test (`_before`) but never after the suite, so global `config` rows written by the LAST acceptance
test survived; PHPUnit functional tests only clean the specific config keys they own, so a leftover
global config row silently changed global behaviour (reproduced: 5 leftover rows -> 7 err + 28 fail).
The pre-fix green was incidental to acceptance-test ordering + PHPUnit-before-acceptance sequencing —
fragile against any reordering, mid-suite crash, or manual acceptance run. Reset-before-each-suite
makes repeatability STRUCTURAL, not order-dependent.
**Verifiable claim / linked test (per FEATURE-139 tripwire discipline):** the migration step is
idempotent on a populated test.db — pinned by `tests/Functional/Meta/MigrationIdempotencyTest.php`
(migrate on an already-migrated DB asserts exit 0 + "Already at the latest version"). This ADR goes
red if that no-op guarantee breaks.
**Scope:** infra/test-lifecycle only — touches test-only `var/test.db` (ADR-001), never a dev/prod DB
or live service; no test skipped/weakened/deleted. This clean-per-suite lifecycle is the trustworthy
benchmark that FEATURE-138's bundle-carried guarded migrations extend.
**Status: decided + implemented (2026-07-07). FEATURE-137 complete — verify-fast green & repeatable.**

## ADR-040: auth-magic-link-bundle — second bundle extraction (own table); 4 core→feature couplings decoupled via stable aliases + null-objects + a marker interface (FEATURE-140)
**Tripwire:** verifiable. Verified by `tests/Functional/Bundle/AuthMagicLinkBundleModularityTest.php`; repo `src/Bundle/AuthMagicLink`.
**Amended by ADR-048 (2026-07-09) — port shrink:** `MagicLinkTokenMaintainerInterface` dropped
`pruneExpiredOrUsed()` (its only core consumer was the deleted `app:maintenance:prune`). The port now
carries just `invalidateUnusedForEmail()`; magic-link pruning is the bundle's own `MagicLinkTokenPruner`
(`auth.pruner`), which injects the bundle repository directly. `NullMagicLinkTokenMaintainer` shrank to
match. See ADR-048.
**Decision (2026-07-07, C36 phase-2 first own-table bundle):** The passwordless magic-link feature is
extracted into an OPTIONAL Symfony bundle `src/Bundle/AuthMagicLink/` (namespace
`App\Bundle\AuthMagicLink`), reusing the auth-pat-bundle template verbatim (ADR-010 / FEATURE-138):
Bundle class + `AuthMagicLinkExtension` (loads `Resources/config/services.php`; prepends the bundle
Entity ORM mapping + a bundle-owned `Migrations/` path) + `RegisterAuthMagicLinkServicesPass` +
registration-gated `Resources/config/routes.php` import in `Kernel::configureRoutes` (gated on
`isset($this->bundles['AuthMagicLinkBundle'])`) + a guarded create-if-not-exists migration
(`Version20260707130000`, no-op today because the legacy app migration has the earlier version and
still creates `magic_link_tokens` first; recreates the ADR-026 named unique index
`UNIQ_99E6B427B3BC57DA` shape if it ever becomes the sole creator). The moved units are the
`MagicLinkToken` entity, its repository, the `MagicLinkAuthenticator` (user firewall), the
`/magic-link*` controller, and the `MagicLinkConfigPage` (slug `magic-link`, registers via the
`auth.config_page`-tagged `ConfigPageProviderInterface`). Templates stay in core `templates/security/`.
**Four core→feature couplings, each decoupled so core compiles and runs with the bundle absent:**
(1) **User-firewall authenticator** — `security.yaml` references the stable alias
`app.magic_link_authenticator`, defaulting in core to `App\Security\NullMagicLinkAuthenticator`
(`supports()`=false → `/magic-link*` falls through to routing and 404s, the routes being bundle-owned);
the bundle's compiler pass re-aliases it to the real authenticator. Same mechanism as PAT's `api`
firewall (firewalls must all live in one file, so the authenticator can't be bundle-conditional).
(2)+(3) **RecoveryTokenInvalidator (deactivation kill) and MaintenancePruneCommand (ephemeral prune)**
— both reach magic-link tokens through ONE core port `App\Service\MagicLinkTokenMaintainerInterface`
(`invalidateUnusedForEmail()` + `pruneExpiredOrUsed()`), defaulting to
`App\Service\NullMagicLinkTokenMaintainer` (no-op / 0). The bundle repository implements the port; the
compiler pass aliases the interface to it. Without the port, the invalidator's DQL against the unmapped
entity would fatal, and the command would fail to autowire a missing repository, when the bundle is
absent.
(4) **IpWhitelistListener** — replaced its `instanceof MagicLinkAuthenticator` (a concrete bundle
class) with a core marker `App\Security\IpWhitelistedAuthenticatorInterface` that the bundle
authenticator implements, so the whitelist still gates magic-link logins (review C20 / FEATURE-113)
without core referencing the bundle; with the bundle absent no class carries the marker.
**Rationale:** FEATURE-140 (C36). The PAT proof-of-concept established "services/routes absent when
uninstalled"; magic-link is the first OWN-TABLE bundle to also prove "admin /config sub-page absent
when uninstalled" (closes review C12 for this bundle) and to exercise the port+null-object pattern for
BOTH an event-time coupling (recovery invalidation) and a maintenance-command coupling. A single
maintainer port (not two) keeps the surface minimal.
**Impact:** NEW `src/Bundle/AuthMagicLink/**`, `src/Security/NullMagicLinkAuthenticator.php`,
`src/Security/IpWhitelistedAuthenticatorInterface.php`,
`src/Service/MagicLinkTokenMaintainerInterface.php`, `src/Service/NullMagicLinkTokenMaintainer.php`;
EDIT `RecoveryTokenInvalidator`, `MaintenancePruneCommand`, `IpWhitelistListener`,
`config/services.yaml` (2 default aliases), `config/packages/security.yaml` (firewall alias),
`config/bundles.php`, `src/Kernel.php`; the 5 moved core files deleted. No schema change (the table is
pre-existing; the bundle migration is a guarded no-op), no new config key. Real modularity proven by
`AuthMagicLinkBundleModularityTest` (a `NoAuthMagicLinkKernel` asserts routes null / services absent /
config sub-page provider absent / GET /magic-link → 404). Extends ADR-010; the remaining phase-2
bundles (webhook, impersonate, and the FEATURE-143 satellite-table trio) reuse this exact template.
**Status: decided + implemented (2026-07-07).**

## ADR-041: auth-impersonate-bundle — third bundle extraction (NO new table); single authenticator-alias decoupling + admin-trigger-stays-in-core + Twig-global button guard (FEATURE-142)
**Tripwire:** verifiable. Verified by `tests/Functional/Bundle/AuthImpersonationBundleModularityTest.php`; repo `src/Bundle/AuthImpersonation`.
**Decision (2026-07-07, C36 phase-2):** The impersonation feature is extracted into an OPTIONAL
Symfony bundle `src/Bundle/AuthImpersonation/` (namespace `App\Bundle\AuthImpersonation`), reusing the
auth-magic-link-bundle template (ADR-040 / FEATURE-140). Unlike magic-link there is **NO new table**:
impersonation is entirely PHP-session-key based (`_impersonation_request`, `_impersonating_as/_by`,
`_impersonating_admin_as/_by`), so ADR-014's custom session-handoff is preserved verbatim and the
bundle has no Entity/Repository/Migration and no doctrine/migrations prepend. The moved units are the
`ImpersonationAuthenticator` (user firewall), the `/impersonate/*` controller (start + user-exit +
admin-exit), and the `ImpersonateConfigPage` (slug `impersonate`, registers via the
`auth.config_page`-tagged `ConfigPageProviderInterface`). Bundle class + `AuthImpersonationExtension`
(loads `Resources/config/services.php`; prepends ONLY a Twig global) +
`RegisterAuthImpersonationServicesPass` + registration-gated `Resources/config/routes.php` import in
`Kernel::configureRoutes` (gated on `isset($this->bundles['AuthImpersonationBundle'])`).
**ONE core→feature coupling, decoupled so core compiles and runs with the bundle absent:**
The user-firewall authenticator — `security.yaml` references the stable alias
`app.impersonation_authenticator`, defaulting in core to `App\Security\NullImpersonationAuthenticator`
(`supports()`=false → `/impersonate/*` falls through to routing and 404s, the routes being
bundle-owned); the bundle's compiler pass re-aliases it to the real authenticator. Same mechanism as
PAT's `api` and magic-link's authenticator (firewalls must all live in one file, so the authenticator
can't be bundle-conditional). No RecoveryTokenInvalidator / MaintenancePrune / IpWhitelist ports are
needed (no table, and impersonation is intentionally EXEMPT from the IP whitelist — IpWhitelistListener
only mentions it in a comment, no instanceof), so the decoupling surface is a single alias — smaller
than magic-link's four.
**Admin-side "start" triggers stay in core (deliberate):** `AdminUserController::impersonateStart`
(`app_admin_users_impersonate_start`) and `AdminAdminManagementController::impersonateStart`
(`app_admin_superadmin_impersonate`) are single actions embedded in shared core admin controllers whose
other actions are core CRUD. They are pure TRIGGERS — they write the session-key contract the four core
listeners already read, then redirect — so splitting single methods out of shared controllers
(replicating route prefixes + the AdminChecker/token-forge logic) is a larger, riskier diff for no AC
benefit, and keeping their routes in core means the always-rendered admin templates never throw on
`path()` generation regardless of bundle state. To keep removal clean, the bundle extension prepends a
Twig global `impersonation_available: true`, and the two admin templates guard their impersonate buttons
on `{% if impersonation_available is defined and impersonation_available %}` (strict_variables is on →
the `is defined` test is required); bundle absent ⇒ global undefined ⇒ buttons hidden. base.html.twig's
exit banners are already guarded by the session keys (only set when the bundle acted), so they need no
change.
**Rationale:** FEATURE-142 (C36). Third bundle after PAT (services/routes-absent) and magic-link
(first own-table + config-sub-page-absent). This is the first NO-TABLE extraction and the first whose
UI trigger lives in an always-rendered CORE template rather than a bundle-owned page — solved with a
bundle-provided Twig global instead of moving shared-controller methods. Closes review C12 for this
bundle.
**Impact:** NEW `src/Bundle/AuthImpersonation/**`, `src/Security/NullImpersonationAuthenticator.php`;
EDIT `config/services.yaml` (default alias), `config/packages/security.yaml` (firewall alias),
`config/bundles.php`, `src/Kernel.php` (route gate), `templates/admin/users/list.html.twig` +
`templates/admin/superadmin/admins.html.twig` (button guards); the 3 moved core files deleted
(`src/Controller/ImpersonationController.php`, `src/Security/ImpersonationAuthenticator.php`,
`src/Config/ImpersonateConfigPage.php`). No schema change, no migration, no new config key. Real
modularity proven by `AuthImpersonationBundleModularityTest` (a `NoAuthImpersonationKernel` asserts
routes null / authenticator + config-page services absent / getBySlug('impersonate') null / GET
/impersonate/start → 404). Extends ADR-010/ADR-040; the FEATURE-143 satellite-table trio is next.
**Status: decided + implemented (2026-07-07).**

## ADR-042: auth-webhook-bundle — fourth bundle extraction (own table webhook_delivery); single dispatcher-port coupling decoupled via null-object + alias-target-guarded compiler pass; NO routes (config-only) (FEATURE-141)
**Tripwire:** verifiable. Verified by `tests/Functional/Bundle/AuthWebhookBundleModularityTest.php`; repo `src/Bundle/AuthWebhook`.
**Decision (2026-07-07, C36 phase-2):** The outbound auth-event webhook feature is extracted into an
OPTIONAL Symfony bundle `src/Bundle/AuthWebhook/` (namespace `App\Bundle\AuthWebhook`), reusing the
auth-magic-link-bundle own-table template (ADR-040 / FEATURE-140). The bundle OWNS the `webhook_delivery`
table (Entity + Repository + guarded migration `Version20260707140000`, mirroring magic-link's guarded
create-if-not-exists over the legacy `migrations/Version20260531000000`) and moves the whole delivery
mechanism into itself: the async Messenger path (`Message/SendWebhookMessage` +
`MessageHandler/SendWebhookMessageHandler` + `Service/HttpWebhookDispatcher` single-attempt cURL sender,
ADR-023), the `MessengerWebhookDispatcher` prod adapter, the `Security/SsrfGuard` + `SsrfInspection`
(ADR-027, webhook-only), the login `EventListener/WebhookListener`, and the `Config/WebhookConfigPage`
(slug `webhook`, registers via the `auth.config_page`-tagged `ConfigPageProviderInterface`). Bundle class
+ `AuthWebhookExtension` (loads `Resources/config/services.php`; prepends the doctrine ORM mapping for the
Entity dir + the migrations path) + `RegisterAuthWebhookServicesPass`.
**ONE core→feature coupling, decoupled so core compiles and runs with the bundle absent:** the
dispatcher port `App\Service\WebhookDispatcherInterface` is injected by core auth flows
(`RegistrationController`, `PasswordResetController`) plus `LoginRateLimitListener`. *(Amended 2026-07-09:
`LoginRateLimitListener` is NOT in core — ADR-044 moved it into auth-security-bundle the same day; the
wiring is unaffected because any injector resolves the same port alias wherever it lives.)* The port
interface stays in core; core defaults it to a NEW `App\Service\NullWebhookDispatcher` (dispatch() no-op
→ no webhooks fire when uninstalled). The bundle's compiler pass upgrades that alias to the real
`MessengerWebhookDispatcher` — but ONLY when its current target is the Null default, so the test/acceptance
envs that explicitly bind the port to the inspectable `InMemoryWebhookDispatcher` are left untouched
(checking the alias TARGET, not the env name, avoids hardcoding env strings). `InMemoryWebhookDispatcher`
+ `NullWebhookDispatcher` stay in CORE so the `when@test` / acceptance bindings and the
`NoAuthWebhookKernel` never reference a missing bundle service. `InteractiveFirewallTrait` stays in core
(shared by non-webhook listeners); the bundle `WebhookListener` cross-namespace `use`s it.
**NO routes (deliberate):** webhooks have no controller — their admin surface is the generic
`AdminConfigController` `/admin/config` page, which renders every `auth.config_page` provider. So the
bundle needs no `Resources/config/routes.php` and no `Kernel::configureRoutes` gate (unlike magic-link /
impersonate). Modularity is proven at the service + config-page level rather than by a 404.
**Rationale:** FEATURE-141 (C36). Fourth bundle after PAT, magic-link and impersonate. First whose ONLY
coupling is a plain service port (no firewall authenticator / no security.yaml edit) and first with no
bundle-owned routes. AC3 for this bundle requires "no webhooks fire / services absent / config sub-page
gone" when unregistered — NOT that the `webhook_delivery` table vanish (the null dispatcher already
guarantees no writes), so the legacy app migration is kept and the bundle migration is guarded. Closes
review C12 for this bundle.
**Impact:** NEW `src/Bundle/AuthWebhook/**`, `src/Service/NullWebhookDispatcher.php`; EDIT
`config/services.yaml` (default alias Null), `config/packages/messenger.yaml` (route the moved
`App\Bundle\AuthWebhook\Message\SendWebhookMessage`), `config/bundles.php`; the 10 moved core files
deleted (`src/Entity/WebhookDelivery.php`, `src/Repository/WebhookDeliveryRepository.php`,
`src/Service/HttpWebhookDispatcher.php`, `src/Service/MessengerWebhookDispatcher.php`,
`src/Message/SendWebhookMessage.php`, `src/MessageHandler/SendWebhookMessageHandler.php`,
`src/EventListener/WebhookListener.php`, `src/Config/WebhookConfigPage.php`, `src/Security/SsrfGuard.php`,
`src/Security/SsrfInspection.php`). Existing webhook tests keep their behaviour; only their `use`
statements point at the bundle namespaces. Real modularity proven by
`AuthWebhookBundleModularityTest` (a `NoAuthWebhookKernel` asserts the webhook services +
WebhookConfigPage absent and getBySlug('webhook') present only when registered). Extends
ADR-010/ADR-040/ADR-041; the FEATURE-143 satellite-table trio is next.
**Status: decided + implemented (2026-07-07).**

## ADR-043: auth-2fa-bundle — FIRST satellite-table extraction; user TOTP moves off `user` into a bundle-owned `two_factor_settings` table (user_id FK); TotpService/EnforcementResolver stay in core (shared with Admin realm); two core→feature couplings decoupled via new ports + null-objects (FEATURE-143)
**Tripwire:** verifiable. Verified by `tests/Functional/Bundle/Auth2faBundleModularityTest.php`; repo `src/Bundle/Auth2fa`.
**Decision (2026-07-07, C36 phase-2):** The USER 2FA feature is extracted into an OPTIONAL Symfony
bundle `src/Bundle/Auth2fa/` (namespace `App\Bundle\Auth2fa`) and PROVES the satellite-table pattern
(the template FEATURE-144/145/146 reuse). Ken's SATELLITE-TABLE RULE (2026-07-07): the bundle must NOT
keep its data as columns on core `user`. It owns a NEW `two_factor_settings` table with a `user_id` FK;
the bundle migration copies the existing `totp_secret` / `is_totp_enabled` / `last_totp_counter` column
values across and DROPS those three columns from `user`. The association is UNIDIRECTIONAL
(`TwoFactorSettings` -> `User`, a unique-join-column OneToOne, onDelete CASCADE); core `User` loses
`getTotpSecret/isTotpEnabled/getLastTotpCounter` and never references the bundle. MOVED into the bundle:
`Controller/TwoFactorController` (user /account/2fa/setup + /account/2fa/disable + /2fa/challenge),
`EventListener/TwoFactorChallengeListener` (user), `Security/TwoFactorGuard`,
`Security/TrustedDeviceManager`, `Config/TwoFactorConfigPage` (slug `2fa`), plus the NEW
`Entity/TwoFactorSettings` + `Repository/TwoFactorSettingsRepository` and the migration
`Version20260707160000`. Bundle class + `Auth2faExtension` (loads services.php; prepends the doctrine
ORM mapping for the Entity dir, the migrations path, and the Twig global `two_factor_available=true`) +
`RegisterAuth2faServicesPass`.
**What STAYS in core (deliberate deviation from the feature *description*, which said "TotpService
moves"):** `TotpService` (pure TOTP codec/QR, zero entity coupling) and `TwoFactorEnforcementResolver`
(reads config only) are SHARED with the Admin realm's 2FA (`AdminTwoFactorController`,
`AdminTwoFactorChallengeListener`), which is NOT part of this bundle. Moving them would break admin 2FA
when the user bundle is uninstalled, and the AC does not require moving them. So they stay in core, as
does the `TotpColumns` trait — now used ONLY by `Admin` (its docblock updated to say so). The AC contract
(satellite table + unidirectional assoc + User loses accessors + TwoFactorConfigPage moves + modularity
test + existing tests pass + verify green) is fully met.
**Two core→feature couplings, decoupled via new stable ports + null-objects so core compiles/runs with
the bundle absent:**
  1. `AccountController::changeExpiredPassword` needs the 2FA challenge guard (a pre-2FA session must not
     rotate a password on a listener-skipped route). NEW core port
     `App\Security\TwoFactorChallengeGuardInterface { isChallengePending(Request,User):bool }`, default
     `App\Security\NullTwoFactorGuard` (returns false → never blocks when uninstalled). Bundle's
     `TwoFactorGuard` implements it; the compiler pass aliases the interface to it.
  2. `AdminUserController` + `Api\AdminApiUserController` RESET a *user's* 2FA (core admin/API features
     that manage users, not the 2FA feature itself). NEW core port
     `App\Security\UserTwoFactorManagerInterface { isEnabled(User):bool; disable(User):void }`, default
     `App\Security\NullUserTwoFactorManager` (false / no-op). Bundle's `TwoFactorSettingsRepository`
     implements it; the compiler pass aliases the interface to it. `AccountController::settings` also uses
     `isEnabled()` to drive the settings-page 2FA status.
**Always-rendered core templates** guard their user-2FA UI so nothing points at a bundle route when it is
absent: `_user_nav` + `account/settings` gate their 2FA block on the Twig global `two_factor_available`
(is-defined test, mirrors impersonation ADR-041) and read a `two_factor_enabled` flag passed from the
manager port; `admin/users/list` replaces `user.isTotpEnabled` with `user.id in two_factor_enabled_ids`
(the controller builds that id set via the manager port — empty when the bundle is absent, so the Reset
2FA button hides).
**TrustedDeviceManager** no longer reads `User::getTotpSecret()` (gone); its methods take the secret
explicitly (`generateCookie(User,?string,int,bool)`, `isDeviceTrusted(Request,User,?string)`,
`bindingToken(?string,string)`), callers resolve it from the satellite repo. Binding semantics
(FEATURE-132) unchanged.
**Migration:** `Version20260707160000` (bundle-owned, runs only when registered) CREATEs
`two_factor_settings`, `INSERT ... SELECT`s existing enrolments from `user`, and DROPs the three user
columns. Its DDL was generated from `doctrine:schema:update --dump-sql` so `SchemaSyncTest`
(`getUpdateSchemaList`) stays green (exact FK/index names). `down()` recreates the user columns, copies
back, and drops the table. With the bundle absent the migration never runs, so `two_factor_settings`
never exists and the user columns remain (unused, unmapped) — "table/logic gone" holds.
**Rationale:** FEATURE-143 (C36). FIRST satellite-table proof; the own-table bundles (PAT/magic-link/
webhook) added a NEW table but here core data is physically relocated off `user`, which is why the two
new core ports + the Twig-global/id-set template guards are needed. Closes review C12 for this bundle.
**Impact:** NEW `src/Bundle/Auth2fa/**`, `src/Security/{TwoFactorChallengeGuardInterface,
NullTwoFactorGuard,UserTwoFactorManagerInterface,NullUserTwoFactorManager}.php`; EDIT
`config/services.yaml` (two null-object default bindings), `config/bundles.php` (register),
`src/Kernel.php` (route import), `src/Entity/User.php` (drop `use TotpColumns`),
`src/Entity/TotpColumns.php` (docblock: Admin-only), `AccountController`, `AdminUserController`,
`Api\AdminApiUserController`, three templates; DELETE the 5 moved core files
(`src/Controller/TwoFactorController.php`, `src/EventListener/TwoFactorChallengeListener.php`,
`src/Security/TwoFactorGuard.php`, `src/Security/TrustedDeviceManager.php`,
`src/Config/TwoFactorConfigPage.php`); test `use`/accessor updates in `DatabaseHelper` + the 2FA
functional/unit suite; NEW `tests/Functional/Bundle/Auth2faBundleModularityTest.php` (+ `NoAuth2faKernel`).
Real modularity proven by that test. Extends ADR-010/040/041/042; unblocks FEATURE-144/145/146.
**Status: decided + implemented (2026-07-07).**

## ADR-044: auth-security-bundle — SECOND satellite-table extraction; user `locked_until` moves off `user` into a bundle-owned `account_lockouts` table (user_id FK); login rate-limit + endpoint rate-limiter move in; two core→feature couplings decoupled via new ports + null-objects (FEATURE-144)
**Tripwire:** verifiable. Verified by `tests/Functional/Bundle/AuthSecurityBundleModularityTest.php`; repo `src/Bundle/AuthSecurity`.
**Decision (2026-07-07, C36 phase-2):** Login rate-limiting + account lockout are extracted into an
OPTIONAL Symfony bundle `src/Bundle/AuthSecurity/` (namespace `App\Bundle\AuthSecurity`), reusing the
FEATURE-143 satellite template (ADR-043). Per Ken's SATELLITE-TABLE RULE, the hard-lockout state must NOT
stay a column on core `user`: the bundle owns a NEW `account_lockouts` table with a `user_id` FK; the
bundle migration copies existing `locked_until` values across and DROPS the column from `user`. The
association is UNIDIRECTIONAL (`AccountLockout` -> `User`, unique-join-column OneToOne, onDelete CASCADE);
core `User` loses `getLockedUntil/setLockedUntil/isLocked` and never references the bundle. A lockout row
exists only while an account is locked; an admin unlock DELETEs it; an expired row is treated as unlocked.
**MOVED into the bundle:** `Security/LoginRateLimitListener` (login throttle + lockout write, now a DBAL
upsert into `account_lockouts` keyed by email→user_id), `Security/EndpointRateLimiter`,
`Security/TooManyLoginAttemptsException`, `Config/SecurityConfigPage` (slug `security`), plus the NEW
`Entity/AccountLockout` + `Repository/AccountLockoutRepository` and the migrations.
**Two core→feature couplings decoupled via NEW core ports + null-objects** (the ADR-010/043 pattern):
(1) `App\Security\AccountLockManagerInterface { lockedUntil(User):?DateTimeImmutable; isLocked(User):bool;
unlock(User):void }`, default `NullAccountLockManager` (never locked / no-op). Consumed by core
`UserChecker` (login enforcement), `AdminUserController` (list "Locked" indicator + unlock) +
`Api\AdminApiUserController` (unlock), and the PAT bundle's `TokenAuthenticator` (was `User::isLocked()`).
(2) `App\Security\EndpointRateLimiterInterface { tooManyAttempts(action,key):bool }`, default
`NullEndpointRateLimiter` (never throttles). Consumed by 5 core controllers + the Auth2fa/AuthMagicLink
bundle controllers. The bundle's `RegisterAuthSecurityServicesPass` re-aliases both ports to the real
services (compiler pass, not a services.php alias, because config/services.yaml loads after bundle
extensions). The admin `list.html.twig` gates the "Locked"/"Unlock" affordances on a controller-built
`locked_user_ids` set (empty when the bundle is absent), mirroring `two_factor_enabled_ids` (ADR-043).
**DBAL stores `login_attempts` / `endpoint_rate_limits`:** these stay DBAL-only (raw SQL in the moved
services) and remain in the core doctrine `schema_filter` (a global connection setting, ADR-026). The
bundle takes ownership via GUARDED create-if-not-exists migrations (ADR-010 AuthPat pattern) — no-ops today
because the legacy migrations create the tables first. The guard probes `sqlite_master` directly, NOT
`createSchemaManager()->tablesExist()`, because the `schema_filter` hides these two tables from ORM
introspection so `tablesExist()` would always report them absent (SQLite-only per ADR-001).
**Migration ordering:** the bundle migrations live under namespace `SecurityBundleMigrations` (not
`App\Bundle\AuthSecurity\Migrations`) so they sort AFTER the legacy `DoctrineMigrations\...` ones (Doctrine
orders across paths by FQ class name; 'S' > 'D'), letting the relocate migration DROP `user.locked_until`
only after `user` exists — the same trick ADR-043 uses (`TwoFactorMigrations`). Registered only via
`AuthSecurityExtension::prepend()`, so they never run on a bundle-less deploy.
**Impact:** NEW `src/Bundle/AuthSecurity/**` (Bundle + Extension[doctrine mapping + migrations path] +
RegisterAuthSecurityServicesPass + Entity/AccountLockout + Repository/AccountLockoutRepository +
moved LoginRateLimitListener/EndpointRateLimiter/TooManyLoginAttemptsException/SecurityConfigPage +
Migrations Version20260707170000[relocate] / 170100[login_attempts guarded] / 170200[endpoint guarded]);
NEW core ports `src/Security/{AccountLockManagerInterface,NullAccountLockManager,
EndpointRateLimiterInterface,NullEndpointRateLimiter}`; core edits `config/{services.yaml[2 null bindings],
bundles.php}`, `User.php`(drop lockedUntil), `UserChecker.php`, `AdminUserController.php`,
`Api/AdminApiUserController.php`, `Bundle/AuthPat/Security/TokenAuthenticator.php`, 7 controllers'
EndpointRateLimiter→interface typehint, `templates/admin/users/list.html.twig`; 4 moved core files deleted;
test updates (DatabaseHelper + AccountLockoutTest + AdminUnlockTest + AdminApiBundleEndpointsTest +
CrossRealmLockoutTest); NEW `tests/Functional/Bundle/AuthSecurityBundleModularityTest.php`
(+ `NoAuthSecurityKernel`). No new config key (reuses rate_limit.*/lockout.*). Extends
ADR-010/040/041/042/043; the second of the four satellite bundles. Unblocks nothing further (145/146
depend on FEATURE-143, already met).
**Status: decided + implemented (2026-07-07).**

## ADR-045: auth-password-policy-bundle Extraction — password_meta Satellite + PasswordPolicyManager Façade Port (FEATURE-145) — Extends ADR-043/044
**Tripwire:** verifiable. Verified by `tests/Functional/Bundle/AuthPasswordPolicyBundleModularityTest.php`,
`tests/Functional/Security/PasswordExpiryTest.php`, `tests/Functional/Security/ExpiredPasswordChangeTest.php`,
`tests/Functional/Security/PasswordReuseTest.php`, `tests/Functional/Admin/PasswordPolicyConfigPageTest.php`,
`tests/Unit/Service/PasswordPolicyServiceTest.php`.
**Decision (2026-07-07):** The password-policy feature (strength rules, expiry, reuse-prevention) becomes an
OPTIONAL Symfony bundle `src/Bundle/AuthPasswordPolicy/` (namespace `App\Bundle\AuthPasswordPolicy`) — the
THIRD satellite-table extraction, reusing the ADR-044 template (no bundle routes).
**Satellite table:** the `password_changed_at` timestamp moves off core `user` into a NEW bundle-owned
`password_meta` table (`#[OneToOne] User`, unidirectional, unique `user_id` FK, onDelete CASCADE,
`password_changed_at DATETIME NOT NULL`). Migration `Version20260707180000` (namespace
`PasswordPolicyBundleMigrations` so it sorts AFTER the legacy `DoctrineMigrations\...` ones — Doctrine orders
across paths by FQ class name, 'P' > 'D') CREATEs the table (DDL from `doctrine:schema:update --dump-sql` so
SchemaSyncTest stays green), INSERT..SELECTs currently-set change dates off `user`, then DROPs the column;
reversible `down()`. `password_history` (already its own table) has its ENTITY + repository moved into the
bundle (namespace change only; table unchanged), with a GUARDED create-if-not-exists migration
`Version20260707180100` (sqlite_master probe, no-op today since the legacy `Version20260530250000` still
creates it — sole creator once legacy is retired).
**Core→feature coupling decoupled via ONE façade port + null-object:**
`App\Security\PasswordPolicyManagerInterface { validate(string):array; checkReuse(User,string):?string;
recordPasswordChange(User,hashedPassword):void; isExpired(User):bool }`, default
`NullPasswordPolicyManager` ([]/null/no-op/false). The bundle's `Security\PasswordPolicyManager` implements
it, delegating to the moved `Service\PasswordPolicyService` (validate), `Service\PasswordHistoryService`
(reuse), `Security\PasswordExpiryChecker` (expiry — now reads the `password_meta` satellite via
`Repository\PasswordMetaRepository`, not `User`), and the meta repo (`recordPasswordChange` = stamp
changed-at + store history hash, replacing the old pre-flush `setPasswordChangedAt(now)` + post-flush
`storeHash()` pair). `RegisterAuthPasswordPolicyServicesPass` re-aliases the port to the real manager
(compiler pass, not a services.php alias, because config/services.yaml loads after bundle extensions).
The `EventListener\PasswordExpiryListener` moves into the bundle unchanged (autoconfigured kernel.request
listener; redirects to the CORE route `app_account_change_expired_password`). `Config\PasswordPolicyConfigPage`
(slug `password-policy`) moves in and registers via the `auth.config_page` tag.
**Consumers rewired concrete-service → port:** core `AccountController` (change / forced-change password),
`RegistrationController`, `PasswordResetController`, `AdminPasswordResetController`,
`AdminAdminManagementController` (validate only), and `Service\UserAccountAdminService`. Core `User` drops
`passwordChangedAt` + its get/set accessors and never references the bundle.
**Rationale:** C36 Option A modularity — the whole feature (validation, expiry listener, reuse history, its
config sub-page, and its data) must vanish cleanly when the bundle is unregistered, with core falling back to
a no-enforcement null-object. Same satellite/port/migration-ordering mechanics as ADR-043/044.
**Impact:** NEW `src/Bundle/AuthPasswordPolicy/**` (Bundle + Extension[doctrine mapping + migrations path] +
RegisterAuthPasswordPolicyServicesPass + Entity/PasswordMeta + Entity/PasswordHistory + Repository/
PasswordMetaRepository + Repository/PasswordHistoryRepository + Service/PasswordPolicyService + Service/
PasswordHistoryService + Security/PasswordExpiryChecker + Security/PasswordPolicyManager + EventListener/
PasswordExpiryListener + Config/PasswordPolicyConfigPage + Migrations 180000[relocate] / 180100[password_history
guarded]); NEW core port `src/Security/{PasswordPolicyManagerInterface,NullPasswordPolicyManager}`; core edits
`config/{services.yaml[1 null binding],bundles.php}`, `Entity/User.php`(drop passwordChangedAt), 5 controllers
+ `UserAccountAdminService.php` (typehint → port); 7 moved core files deleted; test updates (DatabaseHelper +
PasswordExpiryTest + ExpiredPasswordChangeTest + PasswordPolicyServiceTest[namespace] +
UserAccountAdminServiceTest[port]); NEW `tests/Functional/Bundle/AuthPasswordPolicyBundleModularityTest.php`
(+ `NoAuthPasswordPolicyKernel`). No new config key (reuses password_policy.*). The third of the four
satellite bundles; only FEATURE-146 (ip-whitelist) remains.
**Status: decided + implemented (2026-07-07).**

## ADR-046: auth-ip-whitelist-bundle Extraction — user_ip_whitelist Satellite + IpWhitelistManager Façade Port (FEATURE-146) — Extends ADR-043/044/045
**Tripwire:** verifiable. Verified by `tests/Functional/Bundle/AuthIpWhitelistBundleModularityTest.php`,
`tests/Functional/Security/IpWhitelistTest.php`, `tests/Functional/Security/IpWhitelistCidrTest.php`,
`tests/Functional/Security/MagicLinkIpWhitelistTest.php`, `tests/Functional/Admin/IpWhitelistConfigPageTest.php`,
`tests/Functional/Admin/AdminUserEditTest.php`, `tests/Unit/Service/UserAccountAdminServiceTest.php`.
**Decision (2026-07-07):** The login IP-whitelist feature becomes an OPTIONAL Symfony bundle
`src/Bundle/AuthIpWhitelist/` (namespace `App\Bundle\AuthIpWhitelist`) — the FOURTH and FINAL satellite-table
extraction, reusing the ADR-045 template (no bundle routes).
**Satellite table:** the per-user `allowed_ips` override moves off core `user` into a NEW bundle-owned
`user_ip_whitelist` table (`#[OneToOne] User`, unidirectional, unique `user_id` FK, onDelete CASCADE,
`allowed_ips TEXT NOT NULL`; a row exists iff the user has a non-blank override). Migration
`Version20260707190000` (namespace `IpWhitelistBundleMigrations` so it sorts AFTER the legacy
`DoctrineMigrations\...` ones — Doctrine orders across paths by FQ class name, 'I' > 'D') CREATEs the table
(DDL from `doctrine:schema:update --dump-sql` so SchemaSyncTest stays green), INSERT..SELECTs currently-set
overrides off `user`, then DROPs the column; reversible `down()`.
**Port:** ONE stable core port `App\Security\IpWhitelistManagerInterface` (`getAllowedIps(User): ?string`,
`setAllowedIps(User, ?string): void` — blank normalises to null = clear/DELETE the satellite row), defaulted
by `config/services.yaml` to `App\Security\NullIpWhitelistManager` (null / no-op); the bundle's compiler pass
`RegisterAuthIpWhitelistServicesPass` re-aliases it to the bundle `IpWhitelistManager` (delegates to
`UserIpWhitelistRepository`) when registered.
**What moves in:** `EventListener/IpWhitelistListener` (login enforcement; now reads globals from the core
`config` store via ConfigService + the per-user override from `UserIpWhitelistRepository`, no longer from a
User accessor), `Config/IpWhitelistConfigPage` (the /admin/config sub-page, slug 'ip-whitelist').
**What stays in core:** the `App\Security\IpWhitelistedAuthenticatorInterface` marker (MagicLinkAuthenticator
implements it — keeps core decoupled from the magic-link bundle) and the GLOBAL whitelist config keys
`ip_whitelist.{user,admin}_ips` (rows in the core `config` key-value store), which are only *enforced by* and
*edited through* this bundle. NO routes → NO Kernel::configureRoutes change.
**Rationale:** C36 Option A modularity — the whole feature (listener, config sub-page, and its per-user data)
must vanish cleanly when the bundle is unregistered, leaving NO IP restriction on login at all (the listener
is gone), with core falling back to a no-op null-object. Same satellite/port/migration-ordering mechanics as
ADR-043/044/045.
**Impact:** NEW `src/Bundle/AuthIpWhitelist/**` (Bundle + Extension[doctrine mapping + migrations path] +
RegisterAuthIpWhitelistServicesPass + Entity/UserIpWhitelist + Repository/UserIpWhitelistRepository +
Security/IpWhitelistManager + EventListener/IpWhitelistListener + Config/IpWhitelistConfigPage +
Migrations/Version20260707190000[relocate]); NEW core port
`src/Security/{IpWhitelistManagerInterface,NullIpWhitelistManager}`; core edits
`config/{services.yaml[1 null binding],bundles.php}`, `Entity/User.php`(drop allowedIps + get/set),
`Controller/AdminUserController.php`(edit → port), `Service/UserAccountAdminService.php`(port write),
`templates/admin/users/edit.html.twig`(read `allowed_ips` var, not `user.allowedIps`); 2 moved core files
deleted (`src/EventListener/IpWhitelistListener.php`, `src/Config/IpWhitelistConfigPage.php`); test updates
(DatabaseHelper.setUserAllowedIps → satellite + IpWhitelistTest + MagicLinkIpWhitelistTest +
AdminUserEditTest[port] + UserAccountAdminServiceTest[port]); NEW
`tests/Functional/Bundle/AuthIpWhitelistBundleModularityTest.php` (+ `NoAuthIpWhitelistKernel`). No new config
key (reuses ip_whitelist.*). The fourth and FINAL satellite bundle — completes the C36 Option A extraction set.
**Status: decided + implemented (2026-07-07).**

## ADR-047: Admin REST API on a dedicated `/admin-api` firewall with admin-issued tokens (FEATURE-081 / review H2, C37 residue)
**Tripwire:** verifiable. Verified by `tests/Functional/Security/AdminApiBoundaryTest.php`.
**Decision:** The admin REST API is served under the `/admin-api/` prefix on a dedicated, **stateless** Symfony
firewall (`admin_api`) using the `app_admins` provider and admin-issued Bearer tokens stored in
`admin_access_tokens` (authenticated by `AdminTokenAuthenticator`). It is NOT the user `api` firewall and does
NOT accept user PATs. This supersedes the spec's original `/api/admin/*` table, which authenticated a `User`
carrying `ROLE_ADMIN` via the PAT bundle's `TokenAuthenticator` on the `api` firewall.
**Rationale:** Security review H2. The original design collapsed the user/admin boundary — a `User` with a
DB-injected `ROLE_ADMIN` and a valid user PAT could reach admin operations. Complete user/admin isolation
(ADR-003) must extend to the API: only `Admin` entities may reach the admin surface. Re-homing onto
`^/admin-api` with the `app_admins` provider means a user token can never authenticate there (401) regardless
of any role a `User` row holds; `ROLE_ADMIN` was removed as something a `User` can hold, and role policy is
centralized per entity.
**Impact:** `config/packages/security.yaml` gains the stateless `admin_api` firewall (declared BEFORE `admin`,
since `^/admin` also matches `/admin-api`); `AdminTokenAuthenticator` + `AdminAccessToken`
entity/repository/migration (`admin_access_tokens`); the admin-API controllers live under `/admin-api/` (core
`AdminApiUserController`/`AdminApiInvitationController`/`AdminApiAuditLogController`, plus the three
bundle-owned endpoints). `docs/SPEC.md`'s API route table is corrected from `/api/admin/*` to `/admin-api/*`
with an auth note. Regression: a `User` with injected `ROLE_ADMIN` + a valid user PAT gets **401** on
`/admin-api` (`AdminApiBoundaryTest`). This ADR records a decision previously captured only in the FEATURE-081
ledger entry / commit `2030c94` (review C37 residue — the `/admin-api` decision had no dedicated ADR).
**Status: decided + implemented.**

## ADR-048: Unified Prune Harness — `app:prune` + tagged `auth.pruner` contributors (FEATURE-147) — SUPERSEDES the commands of ADR-008/ADR-020; AMENDS ADR-040 (port shrink)
**Tripwire:** verifiable. Verified by `tests/Functional/Command/PruneCommandTest.php`, `tests/Unit/Command/PruneCommandTest.php`.
**Decision (Ken, 2026-07-09):** The two bare prune commands (`app:audit-log:prune` ADR-008,
`app:maintenance:prune` ADR-020) are replaced by ONE `app:prune` harness. Each prunable domain is a
`App\Prune\PrunerInterface` (`name()`/`count()`/`prune()`) carrying the `auth.pruner` autoconfigure tag;
the harness (`App\Command\PruneCommand`) collects them via `#[AutowireIterator('auth.pruner')]` — the exact
`ConfigPageProviderInterface` + `ConfigPageRegistry` extension pattern. Each pruner OWNS its domain
predicate and reads its OWN retention via `ConfigService`; the harness owns only the infrastructure:
deterministic ordering (sorted by `name()`), `--dry-run` (calls `count()` only, never `prune()`, never
audit-logs), `--only=<name>` (repeatable; unknown name errors listing available names, exit 1), duplicate-name
guard (throws), per-pruner `try/catch \Throwable` error isolation (a throwing pruner is reported and the
rest still run; overall exit FAILURE if any failed), and — on a real run that deleted rows OR hit a failure
— exactly ONE `AuditLogger::log()` row (`maintenance.prune`, actorType `admin`, actor `app:prune`, per-table
detail; idle and dry runs write none).
**Modularity falls out free.** Both AuthMagicLink and AuthWebhook glob-load their namespace with
autoconfigure, so a bundle pruner is tagged `auth.pruner` (hence part of `app:prune`) ONLY when the bundle
is registered — no `services.php` or compiler-pass edits. Proven by the two bundles' modularity tests
(`NoAuth*Kernel` — pruner service absent when unregistered).
**C11 residue closed — retention decisions (Ken):**
- **invitations:** prune when (expired OR used) AND `COALESCE(usedAt, expiresAt)` older than
  `invitation.retention_days` (default 30). Invitations are NOT logs — if history is wanted it belongs in
  the audit log (`admin.user_invite` rows already record provenance). A still-usable invite (unused AND
  unexpired) is never pruned regardless of age.
- **webhook_delivery:** prune `attemptedAt` older than `webhook.delivery_retention_days` (default 30), all
  statuses. (Entity has `attemptedAt`, no `createdAt`.)
- **messenger `failed` queue:** deliberately operator-owned (`messenger:failed:remove`), documented in
  README, NOT wrapped in a pruner (test/acceptance use in-memory transports — zero gain).
- **sessions (the PdoSessionHandler table itself, issue #30):** core `App\Prune\SessionPruner` (name
  `sessions`) deletes rows with `sess_lifetime < now` (absolute expiry, INTEGER-bound) — the handler's own GC
  predicate. PHP's probabilistic session GC is php.ini-dependent (Debian/Ubuntu ship gc_probability = 0), so
  it is not relied on; app:prune is the guarantee.
**Port shrink (amends ADR-040):** `MagicLinkTokenMaintainerInterface` dropped `pruneExpiredOrUsed()` (only
consumer was the deleted `app:maintenance:prune`); it keeps `invalidateUnusedForEmail()` (used by
`RecoveryTokenInvalidator`). The bundle repository keeps `pruneExpiredOrUsed()`/`countExpiredOrUsed()` for
its own pruner. `NullMagicLinkTokenMaintainer` shrank to match.
**Rejected (with revisit triggers):** batching/locking in the pruner interface and a `symfony/lock`
dependency — rejected for this single-host SQLite deployment (ADR-001/013); revisit on multi-host deploy or
tables large enough that one DELETE statement holds an unacceptable write lock (batching stays a
pruner-internal escape hatch). A thin BC-preserving delegate keeping the two old command names — rejected;
Ken chose delete-now (one command), migrating tests + docs + ADR tripwire citations in the same change.
**Anti-cheat:** the two legacy functional tests were DELETED only after every scenario was carried into
`PruneCommandTest` (docblock maps old→new): audit-log old/recent + count + 90d default; token expired/used/valid;
dead/live/orphan sessions; never-touch-login_history; per-table counts; idempotency. New: dry-run intactness;
invitation grace; webhook cutoff; audit-row semantics; `--only`.
**Impact:** NEW `src/Prune/` (interface + 5 core pruners), `src/Command/PruneCommand.php`,
`src/Bundle/AuthMagicLink/Prune/MagicLinkTokenPruner.php`, `src/Bundle/AuthWebhook/Prune/WebhookDeliveryPruner.php`;
`count*` dry-run mirrors on 6 repositories; `invitation.retention_days` (GeneralConfigPage) +
`webhook.delivery_retention_days` (WebhookConfigPage); DELETE `AuditLogPruneCommand`/`MaintenancePruneCommand`
+ their tests. No schema change, no migration. ADR-008/020 Tripwire citations repointed and ADR-040 amended in
this same change so `bin/adr-tripwire.php` stays flag-free.
**Status: decided + implemented (2026-07-09).**

## ADR-049: Admin Session Teardown on Soft-Delete/Deactivate + Core admin_sessions Pruner — Records the Admin Remember-Me Precondition (FEATURE-148) — EXTENDS ADR-034
**Tripwire:** verifiable. Verified by `tests/Functional/Admin/AdminManagementTest.php`, `tests/Functional/Command/PruneCommandTest.php`.
**Decision (Ken, 2026-07-09):** Bring the admin realm to parity with the user realm's revoke-time
session hygiene (ADR-020 / FEATURE-110/111 on the user side), closing two gaps ADR-034 left.
(a) **Teardown on status→inactive.** `AdminAdminManagementController::delete()` (always soft-deletes to
`inactive`) and `::edit()` (only when the submitted `status` is `inactive`) now call
`AdminSessionRepository::deleteAllByAdminId($admin->getId())` in the SAME flow as the status flip,
alongside the existing recovery-token invalidation (FEATURE-102). Previously the status flipped but the
`admin_sessions` bookkeeping table was untouched, so a deleted/deactivated admin's rows lingered
forever as ghost "active sessions" in `/admin/sessions` (rows are otherwise removed only on explicit
logout, which a deauthenticated admin never performs). With the row gone, `AdminSessionRequestListener`
(kernel.request −10) invalidates the session and bounces the admin to `/admin/login` on its next request
— the same mechanism as the user side and as admin terminate-all.
(b) **Core `AdminSessionPruner`** (`src/Prune/AdminSessionPruner.php`, implements `PrunerInterface`,
autoconfigured→tagged `auth.pruner`, name `admin_sessions`) mirrors `UserSessionPruner`:
`AdminSessionRepository` gains `deleteExpired(int $now)` / `countExpired(int $now)` with the IDENTICAL
predicate to `UserSessionRepository` — a row is prunable when its `session_id` has no live row in the
DBAL-only `sessions` table (`sess_time + sess_lifetime > :now`, `$now` bound INTEGER for SQLite
storage-class ordering).
**CORRECTION (issue #36, 2026-10-01):** that predicate was wrong. `PdoSessionHandler` stores `sess_lifetime` as
an ABSOLUTE expiry (`time() + ttl`, see ADR-051), so `sess_time + sess_lifetime` is about `2*now + ttl` and was
true for every row still present — nothing was pruned until PHP's own session GC deleted the `sessions` row
(which never happens where `gc_probability = 0`). Both repositories now use `sess_lifetime >= :now` (the handler itself treats a session as live until `sess_lifetime < now`); the prune
tests seed rows the way the handler writes them. This ages out ghosts left by admins who simply closed the browser, which the
teardown (keyed on `admin_id`) does not cover. It falls into `app:prune` for free via the tag (no
services.php / compiler-pass edits — the FEATURE-147/ADR-048 modularity property).
**CRITICAL PRECONDITION (admin remember-me).** Today's "cosmetic-only" verdict on the pre-teardown ghost
rows — the deleted admin genuinely loses access via `Admin::isEqualTo` (status compare → next-request
deauth), `AdminChecker` (no re-login), and `AdminTokenAuthenticator` (API tokens rejected) — holds ONLY
because the admin firewall has **NO remember_me**. The user realm kills remember-me on
delete/deactivate/terminate-all by bumping `User::sessionsInvalidatedAt`, folded into the cookie HMAC
(`ConfigAwareRememberMeHandler`); `Admin` has no such field. **Any future admin remember-me feature MUST
add the equivalent marker on `Admin`, bind it into the remember-me cookie HMAC, and bump it in this
teardown** — otherwise review finding C3 (revoked access surviving via a remember-me cookie) reopens in
the admin realm. This is recorded here so the tripwire discipline carries the precondition forward.
**Rationale:** Review residue behind ADR-034. The admin realm gained an active-sessions view + a
terminate-all in FEATURE-123 but never wired the admin-management delete/deactivate paths into that
bookkeeping, and — unlike `user_sessions` (FEATURE-147) — `admin_sessions` had no pruner, so ghosts
never aged out. The minimal, auditable fix is to mirror the two user-side mechanisms exactly.
**Impact:** EDIT `src/Repository/AdminSessionRepository.php` (+`deleteExpired`/`countExpired`),
`src/Controller/AdminAdminManagementController.php` (inject `AdminSessionRepository` into `edit`+`delete`);
NEW `src/Prune/AdminSessionPruner.php`; test coverage in
`tests/Functional/Admin/AdminManagementTest.php` (teardown + bounce-to-login, explicit row-deletion
assert) and `tests/Functional/Command/PruneCommandTest.php` (live survives, expired+orphan pruned,
idempotent, `admin_sessions` in per-table output). No schema change, no migration, no new config key
(reuses the PdoSessionHandler `sessions` lifetime, like the user_sessions pruner).
**Status: decided + implemented (2026-07-09).**

## ADR-050: ROLE_TECH_SUPPORT — Hidden Maintainer Tier (superadmin powers via role_hierarchy, invisible to non-tech-support admins) (FEATURE-149)
**Tripwire:** verifiable. Verified by `tests/Functional/Admin/TechSupportVisibilityTest.php`, `tests/Unit/Security/TechSupportVisibilityTest.php`, `tests/Functional/Command/CreateTechSupportCommandTest.php`, `tests/Unit/Security/TwoFactorEnforcementResolverTest.php`, `tests/Functional/Security/AdminTwoFactorTest.php`.
**Decision (Ken, 2026-07-09):** Add a third admin role, `ROLE_TECH_SUPPORT`, for the product's
maintainers. It is "superadmin plus invisibility": `role_hierarchy` grants it `ROLE_SUPER_ADMIN`
(hence `ROLE_ADMIN`), so every `IsGranted` check passes — but accounts holding it are HIDDEN from
every admin-management surface for non-tech-support viewers, superadmins included. Motivation:
clients get annoyed seeing maintainer names on their staff list. Tech-support admins see everyone,
each other included.
(1) **One visibility rule, one class** — NEW `App\Security\TechSupportVisibility`
(`isTechSupport`/`canSee`/`filterVisible`): a viewer sees a target iff the viewer is tech-support
OR the target is not. `AdminAdminManagementController` filters its list through it and gates every
per-id action (edit/delete/reset-password/reset-2fa/impersonate) via `findVisibleAdminOr404()` —
a hidden target throws the IDENTICAL 404 as a nonexistent id, so tech-support accounts are not
enumerable by probing ids.
(2) **Role assignment is viewer-scoped, silently** — `assignableRoles()` offers
`ROLE_TECH_SUPPORT` only to tech-support viewers; for anyone else a submitted
`ROLE_TECH_SUPPORT` falls back to `ROLE_ADMIN` exactly like any unknown string (an explicit
validation error would reveal the role exists). The create/edit templates render the option only
under `is_granted('ROLE_TECH_SUPPORT')`.
(3) **Provisioning (Ken):** console + TS-only UI. NEW `app:create-tech-support` mirrors
`app:create-superadmin` (ADR-004) — a SEPARATE command, not a flag, so the client-facing
command's `--help` never advertises the maintainer tier. Existing tech-support admins can create
more via the admin UI.
(4) **Anti-lockout guard ignores tech-support (Ken):** `countActiveSuperAdmins()` counts stored
`ROLE_SUPER_ADMIN` only (tech-support admins store `['ROLE_TECH_SUPPORT']`), so the "last active
superadmin" guard keeps protecting the last VISIBLE client superadmin even when hidden
maintainers exist — clients never end up depending on invisible staff for access to their own
system. Deleting/demoting tech-support accounts is unguarded (console recovery exists).
(5) **Deliberately NOT hidden (Ken):** audit-log rows keep real tech-support actor emails on both
audit surfaces — the trail stays honest for everyone; invisibility is a staff-list concern, not
an accountability exemption. The admins-list impersonate button is hidden for tech-support rows
(same treatment as superadmin rows).
(6) **Mandatory 2FA (Ken, 2026-07-09):** ROLE_TECH_SUPPORT ALWAYS requires 2FA — it is too
privileged to leave optional. Enforced in CODE, not config: `TwoFactorEnforcementResolver`
(ADR-036) carries `MANDATORY_2FA_ROLES = ['ROLE_TECH_SUPPORT']` and short-circuits to `required`
for any principal holding such a role, un-overridable by any `2fa.enforcement*` key. A config
FIELD was rejected precisely because it would render the role's name on the client-visible admin
config page and defeat the tier's invisibility. Because the resolver is the single chokepoint for
all three enforcement callers (the core admin `AdminTwoFactorChallengeListener`, the Auth2fa
bundle's user listener, and its `TwoFactorGuard`), the floor applies everywhere at once: a
tech-support admin with no TOTP is redirected to `/admin/2fa/setup` and cannot reach the panel
until enrolled.
**Impact:** EDIT `src/Entity/Admin.php` (ALLOWED_ROLES + docblock),
`config/packages/security.yaml` (role_hierarchy), `src/Controller/AdminAdminManagementController.php`
(visibility ctor + viewer()/findVisibleAdminOr404()/assignableRoles(), list filter, five per-id
gates, `setRoles([$role])`), `templates/admin/superadmin/{admins,admin_new,admin_edit}.html.twig`,
`src/Security/TwoFactorEnforcementResolver.php` (mandatory-2FA floor, item 6);
NEW `src/Security/TechSupportVisibility.php`, `src/Command/CreateTechSupportCommand.php`, and the five
tests in the Tripwire line. No schema change (roles is the existing JSON column), no migration,
no new config key.
**Status: decided + implemented (2026-07-09).**

## ADR-051: Admin "Remember Me" is an extended SESSION, not a remember_me bearer cookie; session lifetime becomes DB-configurable
**Tripwire:** verifiable. Verified by `tests/Unit/Session/SessionTtlResolverTest.php`,
`tests/Unit/Session/ConfigAwarePdoSessionHandlerTest.php`,
`tests/Functional/Security/AdminRememberMeTest.php`,
`tests/Acceptance/Auth/AdminRememberMeCest.php`.
**Decision (Ken, 2026-09-03):** The admin login form gains a "Remember me" checkbox that (1) prefills
the admin's email on a later visit and (2) keeps them signed in for 21 days, pushed back on every hit
of activity. It is implemented by EXTENDING THE SESSION, **not** by enabling Symfony's `remember_me`
on the admin firewall.

Why not `remember_me`: ADR-049's CRITICAL PRECONDITION records that an admin remember-me bearer cookie
would reopen review finding **C3** (revoked access surviving via a cookie) unless an
`Admin::sessionsInvalidatedAt` marker were added and bound into the cookie HMAC — because a bearer
cookie outlives the revocation paths. A session has no such problem: "terminate all sessions",
soft-delete and deactivate all delete the `admin_sessions` row, and `AdminSessionRequestListener`
deauthenticates on the very next request. So the session route is **strictly safer**, needs no new
`Admin` column and no migration, and the ADR-049 precondition remains satisfied (the admin firewall
still has NO remember_me). `AdminRememberMeTest::testTerminatedSessionStillWinsOverRememberMe` pins
this.

It also resolves the 2FA question for free: `_admin_2fa_verified` is session-scoped, so a remembered
admin is not re-challenged for TOTP inside the window — no admin trusted-device mechanism (the user
realm's `TRUSTED_DEVICE` cookie) had to be mirrored.

**Session lifetime is now DB-configurable** (`GeneralConfigPage`), replacing PHP's 24-minute
`session.gc_maxlifetime` default, which was far too aggressive for an admin panel (2FA is
session-scoped, so it forced a TOTP re-entry every 24 idle minutes):
  - `session.idle_lifetime_minutes` (default **180** = 3h) — baseline sliding idle window, all realms.
  - `session.remember_me_lifetime_days` (default **21**) — used instead for a session flagged by the
    checkbox.

Symfony has no per-firewall session config, but `PdoSessionHandler` accepts a `ttl` **closure**
evaluated on every write and timestamp refresh. `App\Session\ConfigAwarePdoSessionHandler` (a subclass,
because a closure cannot be expressed in YAML args) supplies one backed by
`App\Session\SessionTtlResolver`, which memoises per request and never throws (a config/DB failure
falls back to the baseline rather than breaking session writes). Because the handler re-stamps
`sess_lifetime` on EVERY request, the window slides on activity for free — no refresh listener.

`framework.session.cookie_lifetime` is raised to 21 days as a browser-side hint only; the server TTL
is authoritative. It must stay >= the largest server window, so raising
`session.remember_me_lifetime_days` past 21 days requires raising it too (noted inline in
`framework.yaml`). A session whose server TTL is shorter simply presents an already-expired cookie —
harmless.

The email prefill is a separate, **opt-in** concern: `last_username` is session-scoped and cannot
survive an expired session, so ticking the box also writes a long-lived HttpOnly `ADMIN_LAST_EMAIL`
cookie (email only — never a credential), which `AdminSecurityController` uses as the fallback for
`last_username` and to re-tick the box. Not ticking the box stores nothing; explicit logout clears it
(an explicit "forget me on this device"), while mere session expiry does not — which is exactly the
"come back tomorrow" case the prefill exists for.
**Rationale:** Ken's ask ("admin/login should have a remember me: prefills email, 60 → 21 days since
last activity"), plus his follow-up that the 24-minute default is "too onerous (it's like a bank
website)" and that the DB should drive the values.
**Impact:** NEW `src/Session/SessionTtlResolver.php`, `src/Session/ConfigAwarePdoSessionHandler.php`,
`src/EventListener/AdminRememberMeListener.php`; EDIT `config/packages/framework.yaml` (+ the
acceptance override), `config/services.yaml` / `services_dev.yaml` / `services_acceptance.yaml`
(the last two declare no `_defaults`, so `$ttlResolver` is wired explicitly there),
`src/Config/GeneralConfigPage.php` (two keys), `src/Controller/AdminSecurityController.php`,
`templates/admin/security/login.html.twig`, `tests/Support/AcceptanceTester.php`. No schema change,
no migration.
**Status: decided + implemented (2026-09-03).**

## ADR-053: The phpLiteAdmin console is gated by a revocable token, not the Symfony session; phpliteadmin.php leaves the docroot
**Tripwire:** verifiable. Verified by `tests/Unit/Security/ConsoleCookieTest.php`,
`tests/Functional/Security/DbConsoleGatewayTest.php`, `tests/Functional/Admin/AdminDatabaseConsoleTest.php`.
**Decision (Ken, 2026-09-17):** Port the working design from `axcelmediacorp/wholesale-b2b-core`.

The previous gateway had never worked. It read the Symfony session by hand — expecting FILE sessions at
`var/cache/{env}/sessions` while this app stores them in the `sessions` DB table, and looking for
`_security_main` when no `main` firewall exists — so `$isAdmin` was always false and `/db-admin.php`
always redirected. Broken, but broken safely; one careless "fix" away from fail-open, with
`phpliteadmin.php` sitting in the docroot behind nothing but its own in-file guard and `$password = ''`.

Two structural changes:
1. **`phpliteadmin.php` moved to `tools/`**, outside the docroot, so it cannot be requested directly
   whatever its in-file guard does. Its hardcoded `$databases` path is replaced by the `APP_DB_PATH`
   the gateway passes in — a hardcoded path silently opens (or CREATES) the wrong database.
2. **The credential is an opaque 256-bit token**, stored server-side only as a SHA-256 hash in
   `db_console_session`. Having a row is what makes it expirable and revocable; a self-contained
   signed cookie would be the same value forever.

The gateway serves a request only if all seven hold: console armed (`db_console.enabled_until` in the
future, checked FIRST and unconditionally — the kill-switch); IP not locked out; IP on
`DB_CONSOLE_ALLOWED_IPS` when set; token hashes to a row; row not expired; request IP matches the mint
IP; and the owning admin still exists, is active, and still holds ROLE_TECH_SUPPORT. That last check is
the only place a revoked admin is caught, because the gateway runs outside the kernel. Everything is
wrapped in one `catch (\Throwable) => deny`, so it fails closed by construction.

Gated on ROLE_TECH_SUPPORT (ADR-050) rather than ROLE_ADMIN: raw database access is a maintainer
power, and that tier's mandatory-2FA floor means the console is reachable only behind a second factor.

**Two real bugs were found by testing, not by reading.** The gateway must read its env from `$_ENV`,
`$_SERVER` AND `getenv()`, seeding the superglobals from `getenv()` BEFORE `Dotenv::bootEnv()`: PHP's
default `variables_order` is `GPCS` (no `E`) so real env vars never reach `$_ENV`, the built-in server
puts only request variables in `$_SERVER`, and because Dotenv refuses to override only what it can
SEE, booting first let `.env` quietly beat the actual environment. Reading one source, or seeding
after boot, makes the gateway open a different database than the app — or deny everyone.
**Rationale:** Ken asked for the wholesale-b2b-core fix applied here, and for the tests to poke every
check rather than a token few.
**Impact:** NEW `src/Security/ConsoleCookie.php`, `src/Entity/DbConsoleSession.php`,
`src/Repository/DbConsoleSessionRepository.php`, `src/Controller/AdminDatabaseConsoleController.php`,
`templates/admin/db_console/index.html.twig`, `migrations/Version20260917100000.php`;
REWRITTEN `public/db-admin.php`; MOVED `public/phpliteadmin.php` -> `tools/phpliteadmin.php`;
EDIT `config/packages/doctrine.yaml` (schema_filter for the DBAL-only `db_console_throttle`).
**Status: decided + implemented (2026-09-17).**

## ADR-054: Squash the 38 incremental core migrations into one; retire the "legacy app migration" guards in the migration-ownership bundles (Ken, 2026-09-27)

**Tripwire:** rationale (a migrations/history change, not new application behavior — no dedicated
test verifies it directly; guarded transitively by every existing test that depends on the schema
being correct — `SchemaSyncTest`, `MigrationIdempotencyTest`, `SchemaFilterTest`, and every
bundle `*ModularityTest` — all of which stayed green across this change).

**Decision:** No production instance predates this project (Ken: "we have no prod instances where we
have to run anything before this point"), so the 38 `DoctrineMigrations\...` files under `migrations/`
(2026-05-30 → 2026-09-17), including their intermediate SQLite table-rebuild dances and columns that
were later relocated into bundle-owned satellite tables, collapse into a single
`migrations/Version20260927120000.php` reflecting only the FINAL schema. Verified by actually running
the full 38+10-bundle-migration chain against a scratch DB, diffing its resulting `sqlite_master`
schema byte-for-byte (modulo whitespace) against the squashed chain's — not reconstructed by reading
the history.

Six tables that a "guarded create-if-not-exists" bundle migration only ever no-op'd against (because
the earlier core migration always ran first and won the race): `personal_access_tokens`
(auth-pat-bundle), `magic_link_tokens` (auth-magic-link-bundle), `login_attempts` /
`endpoint_rate_limits` (auth-security-bundle), `password_history` (auth-password-policy-bundle) — each
of these bundles' own comments already anticipated this exact moment ("It becomes the sole creator only
once the legacy app migration is retired"). Their guards are removed; they are now the sole,
unconditional, unguarded creators, and the squashed core migration no longer creates any of them. A
disabled bundle now means the table genuinely never exists — the honest end-state the
migration-ownership pattern (ADR-010) always intended, and consistent with every affected feature's own
acceptance criteria (FEATURE-138, FEATURE-140, FEATURE-144, FEATURE-145), none of which require the
table to outlive its bundle.

Four satellite-relocation migrations (`two_factor_settings`/ADR-043, `account_lockouts`/ADR-044,
`password_meta`/ADR-045, `user_ip_whitelist`/ADR-046) drop their INSERT-then-ALTER-TABLE-DROP-COLUMN
dance entirely: core `user` is now created (in the squashed migration) without the
totp/locked_until/password_changed_at/allowed_ips columns those satellites used to relocate off of it,
so there is nothing left to copy or drop. Each satellite migration is now a plain `CREATE TABLE`, and
its `down()` a plain `DROP TABLE` (no more re-adding the column and copying state back).

**One deliberate exception:** `webhook_delivery` stays core-created (squashed migration keeps a
create-if-not-exists guard specifically for it), because auth-webhook-bundle's own AC3 requires the
table to persist even when that bundle is uninstalled (see the ADR-042/FEATURE-141 rationale above) —
unlike the six tables above, this was never a "becomes sole creator once retired" situation.
`AuthWebhook\Migrations\Version20260707140000` is untouched.

**Rationale:** Ken's call — squashing incremental migration history is safe and desirable specifically
because there is no production data anyone needs to migrate up through; every subsequent verify-fast
run rebuilds `var/test.db` from scratch anyway (`bin/verify-fast.sh`'s `reset_test_db`), so the squash
changes only which files produce that same schema, not the schema itself.
**Impact:** DELETED the 38 files `migrations/Version20260530092902.php` →
`migrations/Version20260917100000.php`; NEW `migrations/Version20260927120000.php` (squashed core
schema, `webhook_delivery` guard kept); EDIT `src/Bundle/AuthPat/Migrations/Version20260707120000.php`,
`src/Bundle/AuthMagicLink/Migrations/Version20260707130000.php`,
`src/Bundle/AuthSecurity/Migrations/Version20260707170000.php` (satellite, simplified),
`src/Bundle/AuthSecurity/Migrations/Version20260707170100.php`,
`src/Bundle/AuthSecurity/Migrations/Version20260707170200.php`,
`src/Bundle/AuthPasswordPolicy/Migrations/Version20260707180000.php` (satellite, simplified),
`src/Bundle/AuthPasswordPolicy/Migrations/Version20260707180100.php`,
`src/Bundle/Auth2fa/Migrations/Version20260707160000.php` (satellite, simplified),
`src/Bundle/AuthIpWhitelist/Migrations/Version20260707190000.php` (satellite, simplified); UNCHANGED
`src/Bundle/AuthWebhook/Migrations/Version20260707140000.php`; EDIT `docs/SPEC.md` (auth-pat-bundle
description). `bin/verify-fast.sh` stays green (807 PHPUnit / 2564 assertions; 106 acceptance / 373).
**Status: decided + implemented (2026-09-27).**

## ADR-055: CLI discoverability of the tech-support tier — AMENDS ADR-050 item 3 (Ken, 2026-09-28)

**Tripwire:** rationale (a `--help`/description wording change with no new assertable behavior;
the underlying access-control tests from ADR-050 are unaffected and unchanged).

**Decision:** ADR-050 item 3 kept `app:create-tech-support` a separate command from
`app:create-superadmin` specifically "so the client-facing command's `--help` never advertises the
maintainer tier." In practice this only cost deployment time (whoever bootstraps a new instance has
to already know the separate command exists) without buying any real secrecy — the command was never
`hidden: true`, so it was always listed in plain `bin/console list`. Ken: obscuring a *deployment*
command is not a security control, just friction.

Reversed the CLI-discoverability part only: `app:create-superadmin --help` now names
`app:create-tech-support` and explains the intended order — bootstrap a brand-new instance with
`app:create-tech-support` FIRST (ROLE_TECH_SUPPORT has full control via `role_hierarchy`: superadmin
powers plus more, and can create every other admin, including superadmins, from the UI afterward);
`app:create-superadmin` is for additional client-facing superadmins once that maintainer account
exists. `app:create-tech-support`'s own description/help states this directly instead of calling
itself a "hidden maintainer" account.

**What did NOT change:** every other part of ADR-050 stands — tech-support accounts are still hidden
from other admins' staff-list views (item 1, the actual client-facing privacy property), role
assignment is still viewer-scoped (item 2), the anti-lockout guard still ignores tech-support (item
4), audit-log rows still show real tech-support actor emails (item 5), and 2FA is still mandatory for
the role (item 6). Only the CLI's own self-description changed.

**Impact:** EDIT `src/Command/CreateSuperAdminCommand.php` (added `help:` on `#[AsCommand]`),
`src/Command/CreateTechSupportCommand.php` (description + `help:` + class docblock reworded).
**Status: decided + implemented (2026-09-28).**

## ADR-056: Standard SQLite connection baseline (WAL + busy_timeout + foreign_keys=ON) applied to every real connection (Ken, 2026-09-28)

**Tripwire:** verifiable. Verified by `tests/Functional/Doctrine/SqlitePragmaMiddlewareTest.php`.

**Decision:** This project was missing a baseline Ken runs on every SQLite project (dev or prod):

```sql
PRAGMA journal_mode = WAL;
PRAGMA busy_timeout = 5000;
PRAGMA foreign_keys = ON;
PRAGMA locking_mode = NORMAL;
PRAGMA synchronous = FULL;
```

Nothing in the app applied any of these to a real connection — the only place they appeared at all
was `tests/Support/Helper/DatabaseHelper.php`, purely for that helper's own test-seeding/reset logic
(`foreign_keys` toggled OFF/ON around a bulk wipe; `journal_mode`/`busy_timeout` set ad hoc for
acceptance-test seeding against a live server). Two of the five are SQLite's own defaults anyway
(`locking_mode = NORMAL`, `synchronous = FULL`) and so cost nothing to make explicit, but the other
three were real, live gaps:

- **No WAL / busy_timeout on real connections** — every real request ran in SQLite's default
  rollback-journal mode with a 0ms busy timeout, i.e. any write overlap fails immediately rather than
  retrying.
  **CORRECTION (ADR-061):** the "0ms busy timeout" was wrong for this stack — PHP's pdo_sqlite defaults to
  60000 ms (verified: a fresh PDO reports `busy_timeout = 60000`), so the baseline's 5000 actually *tightens*
  it. The rollback-journal and never-enforced-foreign-keys findings stand. This is the exact class of problem `config/services_dev.yaml`'s `lock_mode: 0` workaround
  on the session handler already worked around locally, for the session handler only.
- **`foreign_keys` never ON** — SQLite does not persist or default-enforce `FOREIGN KEY` constraints;
  it must be turned on per-connection. Every satellite table's `ON DELETE CASCADE` (`account_lockouts`,
  `two_factor_settings`, `password_meta`, `user_ip_whitelist`) was therefore inert at runtime — SQLite
  recorded the constraint but never enforced it. Low blast radius today only because the admin UI's
  user "delete" is a soft delete (`status='inactive'`, ADR-020), so the cascade path was rarely
  exercised, but a real gap for anything that ever does a hard delete.

**Implementation:** NEW `App\Doctrine\SqlitePragmaMiddleware`, a `Doctrine\DBAL\Driver\Middleware`
that wraps every connection and runs the five PRAGMAs immediately after connecting — but only when
the underlying native connection is actually a PDO SQLite one (defensive; ADR-001 already makes this
app SQLite-only). DoctrineBundle auto-tags any service implementing that interface as
`doctrine.middleware` (`registerForAutoconfiguration`), and this app's `services.yaml` already
autoconfigures everything under `src/` — so no explicit YAML wiring was needed, and the baseline
applies to EVERY connection/environment (dev, prod, test, acceptance) rather than needing to be
threaded through each one individually.

Verified by more than reading the pragma values back: `SqlitePragmaMiddlewareTest` inserts a `user` +
a dependent `account_lockouts` row, hard-deletes the user, and asserts the lockout row is actually
gone — proving `foreign_keys=ON` is enforced by the engine, not just reported as on. Ran the full
`bin/verify-fast.sh` gate with the middleware active (foreign key enforcement now genuinely on
everywhere, including the 807-test PHPUnit suite and the 106-test acceptance suite): no regression —
nothing in the codebase was relying on FK enforcement being off.

**Impact:** NEW `src/Doctrine/SqlitePragmaMiddleware.php`,
`tests/Functional/Doctrine/SqlitePragmaMiddlewareTest.php`. No config file changes (autoconfiguration
picks it up). `bin/verify-fast.sh` stays green (810 PHPUnit / 2572 assertions; 106 acceptance / 373).
**Status: decided + implemented (2026-09-28).**

## ADR-057: DB Console gets a sidebar link, gated on ROLE_TECH_SUPPORT (Ken, 2026-09-28)

**Tripwire:** verifiable. Verified by `tests/Functional/Navigation/SidebarTest.php`
(`testTechSupportSidebarShowsDbConsoleLink`, and the existing superadmin-sidebar test now also
asserts the link is ABSENT for a plain superadmin).

**Decision:** `/admin/db` (`AdminDatabaseConsoleController`, ADR-053) was already correctly gated
server-side with `#[IsGranted('ROLE_TECH_SUPPORT')]`, but nothing in `templates/layout/_admin_nav.html.twig`
ever linked to it — a tech-support admin had to already know the URL. Added a "Maintainer" nav
section, mirroring the existing "Superadmin" section's `is_granted(...)`-gated pattern, visible only
to `is_granted('ROLE_TECH_SUPPORT')`. `role_hierarchy` makes `ROLE_TECH_SUPPORT` a superset of
`ROLE_SUPER_ADMIN` (ADR-050), not the reverse, so a plain superadmin — even one who could technically
navigate to `/admin/db` by guessing the URL and getting a 403 — still does not see the link.
**Impact:** EDIT `templates/layout/_admin_nav.html.twig`, `tests/Functional/Navigation/SidebarTest.php`.
**Status: decided + implemented (2026-09-28).**

## ADR-058: DATABASE_URL pointed at anything but SQLite now fails loudly at the first connection (Ken, 2026-09-28)

**Tripwire:** verifiable. Verified by `tests/Unit/Doctrine/SqlitePragmaMiddlewareTest.php`
(`testConnectingWithANonSqliteNativeConnectionThrows`).

**Decision:** Ken: engineers working from this boilerplate have repeatedly ended up pointing a
deployment at MySQL. Two changes close that gap:
(1) `.env`'s Flex-managed `doctrine/doctrine-bundle` block carried two commented-out MySQL/MariaDB
`DATABASE_URL` examples (auto-inserted by the original recipe) sitting directly above the real SQLite
line — bait for exactly this mistake, and liable to reappear verbatim on a future
`composer recipes:update`. Removed them and replaced the block's comment with an unmissable banner
stating this app is SQLite-only (ADR-001/ADR-013) and citing the enforcement in (2). The
belt-and-suspenders duplicate `DATABASE_URL` line already living OUTSIDE the Flex markers (so the
last-definition-wins rule protects the effective value even if the managed block regresses) stays,
with its own comment pointing back at the banner.
(2) `App\Doctrine\SqlitePragmaMiddleware` (ADR-056) — already the one place every real connection
passes through — now THROWS a `\LogicException` naming ADR-001/ADR-013 when the native connection
isn't PDO SQLite, instead of silently skipping the pragma baseline. A MySQL/PostgreSQL `DATABASE_URL`
previously would have either failed confusingly deep inside a migration's SQLite-specific DDL, or —
worse — connected without the WAL/foreign_keys baseline and misbehaved subtly. Now it fails at the
very first connection attempt, in dev, prod, test or a console command, with a message that says
exactly what's wrong and where to look.
**Impact:** EDIT `.env` (removed 2 misleading commented lines, strengthened both DATABASE_URL
comments), `src/Doctrine/SqlitePragmaMiddleware.php` (throw instead of silent no-op); NEW
`tests/Unit/Doctrine/SqlitePragmaMiddlewareTest.php` (guard tested directly against a fake
non-SQLite driver, without needing a real MySQL/PostgreSQL server).
**Status: decided + implemented (2026-09-28).**

## ADR-059: Htaccess Lock — a tech-support-only, web-server-level IP-whitelist lock that edits a managed block of `.htaccess` (FEATURE-150)
**Tripwire:** verifiable. Verified by `tests/Functional/Admin/HtaccessLockControllerTest.php`, `tests/Functional/Command/DisableHtaccessLockCommandTest.php`, `tests/Unit/Htaccess/HtaccessLockValidatorTest.php`, `tests/Unit/Htaccess/HtaccessLockRendererTest.php`, `tests/Unit/Htaccess/HtaccessFileTest.php`, `tests/Unit/Htaccess/HtaccessLockSelfTestTest.php`.
**Decision (Ken, 2026-09-30):** Internal back-office instances should not be wide open. Add an admin page
(sidebar: Maintainer → "Htaccess Lock") that enforces a whitelist-only policy at the web-server layer by
editing a marker-delimited block of `public/.htaccess`. Settings: (1) on/off radio; (2) allowed IPs/CIDRs,
one per line, and exempt URL paths (no domain), one per line, reachable from any IP; (3) blocked status code
(403, or a real 404 — "we can be tricky") and an optional error file (blank = empty body). A separate
**self-test** proves the server really enforces it by inserting/removing the server's own IP.
**Why not the existing auth-ip-whitelist-bundle (ADR-046):** that is an application-level check on the
login form only. This runs in the web server, before PHP, so it also covers static files, the admin panel
and the DB-console gateway. Different layer, different risk profile → separate mechanism, not an extension.
**Who (Ken):** `ROLE_TECH_SUPPORT` only — **not even superadmin** (controller `#[IsGranted]`; nav link in the
Maintainer section beside the DB console). Rewriting the server's access rules is a maintainer power and a
client superadmin must not be able to lock the maintainers out. Inherits the mandatory-2FA floor (ADR-050).
**Target (Ken):** the standard environment is LiteSpeed reading `.htaccess`; LiteSpeed implements Apache 2.4
`Require`. Nginx/Caddy ignore `.htaccess` — there the lock would silently do nothing, which is the failure
the self-test exists to expose.
**How — verified on a real Apache 2.4, not assumed:** `<RequireAny>` of `Require ip <ip|cidr>` lines plus
`Require env HTACCESS_LOCK_EXEMPT` (set by `SetEnvIf Request_URI` per exempt path; exact = `^/p/?$`, trailing
`*` = prefix). A plain `Require` denial is always 403, so a **real 404** needs an `ErrorDocument 403` internal
redirect plus `RewriteCond %{ENV:REDIRECT_STATUS} =403` / `RewriteRule ^ - [R=404,L]` (a rewrite without an
`ErrorDocument 403` *path* never fires; `<If>`-scoped ErrorDocuments broke it; `ErrorDocument 403 " "`
text does not redirect). Matrix: 403+file → `ErrorDocument 403 F`; 403+blank → `ErrorDocument 403 " "`;
404+file → 403→F, rewrite, `ErrorDocument 404 F`; 404+blank → 403→a missing path (`/__htaccess_lock__`),
rewrite, `ErrorDocument 404 " "`. ErrorDocument redirects are not re-authorised, so a blocked client is
served the error file. Caveat: in 404 mode the file/blank body also applies to the web server's *own* 404s
while the lock is on (PHP-generated 404s are unaffected) — documented in the UI.
**Safety (the feature's main risk is locking yourself out of the panel that controls it):** (1) **self-lockout
guard** — enabling is refused (422, nothing written) unless the admin's own IP is covered by the whitelist,
and an empty whitelist is refused; (2) **console recovery** `app:htaccess-lock:disable` removes the block
from the shell (the saved whitelist is kept); (3) **only the managed block is touched** — everything else is
preserved byte-for-byte, the block is PREPENDED so it runs before catch-all rules, unbalanced markers are
refused, writes are atomic and serialised under a lock; (4) **no directive injection** — `HtaccessLockValidator`
is the boundary: every line must be a canonical IPv4/IPv6/CIDR (`/0` rejected) or a path from a strict alphabet
(`/*` rejected), status is 403/404 only, the error file must exist under the web root; free text never reaches
the renderer; (5) audit rows carry counts/outcome, never the whitelist.
**Self-test (Ken: "insert and remove its own IP and test if it gets an error"):** writes temporary blocks and
requests the site from the server itself over **loopback** (`CURLOPT_RESOLVE host→127.0.0.1`, so the web server
always sees source 127.0.0.1 regardless of DNS/CDN/NAT): (1) only a reserved address (192.0.2.1) whitelisted →
must be blocked; (2) exempt path → must not be blocked; (3) `127.0.0.1` inserted → must be allowed; (4) the
configured status/file → must use the configured status. It then restores the original file byte-for-byte in
`finally` and verifies it. A server that ignores `.htaccess` (AllowOverride off, proxy in front) fails step 1
loudly instead of failing open silently. Waits after each write so the server re-reads the file (mtime is
second-granular). Real visitors are blocked for a few seconds while it runs — stated on the page.
**Placement:** a core feature, not a bundle — no table (settings live in the `config` store as
`htaccess_lock.*`), no cross-cutting coupling, and it is server-ops tooling rather than an auth feature. The
HTTP prober is an interface (`HtaccessProbeInterface`, `CurlLoopbackProbe`) so tests script a fake server.
**Testing limits (honest):** PHPUnit/Codeception run on PHP's built-in server, which has no `.htaccess`, so the
suite pins the generated text and all logic but cannot prove enforcement. Enforcement was verified by hand
against Apache 2.4 (all four shapes, CIDR, exact+prefix exemption, rewrite coexistence, injection rejected);
it has **not** been run on LiteSpeed — the in-app self-test is the on-host check and should be run once per host.
**Open operational notes:** the web-server user must be able to write `public/.htaccess` (the page reports when
it cannot); behind a CDN/proxy the server sees the proxy's address, and `Require ip` and the lockout guard use
that same address.
**Impact:** NEW `src/Htaccess/**` (Settings, Validator(+Validation), Renderer, HtaccessFile, Manager,
SelfTest(+Report), ProbeInterface, CurlLoopbackProbe), `src/Controller/AdminHtaccessLockController.php`,
`src/Command/DisableHtaccessLockCommand.php`, `templates/admin/htaccess_lock/index.html.twig`, `docs/htaccess-lock.md`,
`tests/Support/ScriptedHtaccessProbe.php` + the six tests in the Tripwire line; EDIT `config/services.yaml`
(`app.htaccess_path`, lock path, settle time; `when@test` points the file at `var/test-htaccess/` and makes the
probe swappable), `templates/layout/_admin_nav.html.twig`, `tests/Functional/Navigation/SidebarTest.php`,
`README.md`. No schema change, no migration.
**Status: decided + implemented (2026-09-30).**

## ADR-060: Hand-written OpenAPI spec + admin "API Docs" page, held to the real API by contract tests (FEATURE-151)
**Tripwire:** verifiable. Verified by `tests/Functional/Api/OpenApiSpecTest.php`, `tests/Functional/Api/OpenApiContractTest.php`, `tests/Functional/Api/ApiDocsPageTest.php`, `tests/Acceptance/Admin/ApiDocsCest.php`.
**Decision (Ken, 2026-09-30):** Document the two token-authenticated REST surfaces (`/admin-api/*` admin tokens,
`/api/*` user PATs — 16 operations) in a hand-written **OpenAPI 3.1** file, `docs/api/openapi.yaml`, shown in a
Swagger UI page in the admin nav ("API Docs", Manage section), with tests that make drift impossible to miss.
Hand-written rather than generated (NelmioApiDocBundle): no dependency weight or per-endpoint annotations, the
spec reads as a document — and the tests, not the generator, are what keep it true.
**Who (Ken):** every admin class that can make API calls — `ROLE_ADMIN` (superadmin and tech-support inherit
it). Any admin role can be issued an admin API token (`app:admin:create-api-token`), so any may need the docs.
(An earlier plan of tech-support-only was revised by Ken mid-build.) The spec is served through the gated route
`/admin/api-docs/openapi.json`, not as a static file, so the API description is not public; `no-store, private`.
**Drift guards (Ken: "a test for every single endpoint … so if it drifts it gets fixed right away"; "a Cest on
# of calls in doc vs # of endpoints"):**
(1) `OpenApiSpecTest` — the spec is a well-formed 3.1 document (unique operationIds, every `$ref` resolves, a 2xx and
a 401 per call); EVERY example in it validates against its own schema; the set of documented `METHOD /path`
equals the set of real `/admin-api` + `/api` routes (nothing undocumented, nothing phantom) and so does the
plain COUNT; path parameters match the route variables; each call documents the auth scheme its firewall uses;
`x-bundle` labels match the bundle that owns the controller.
(2) `OpenApiContractTest` — ONE data-provider case per documented operation, calling the real endpoint over the
real kernel. Every response status must be documented and its JSON body must validate against the documented
schema (`opis/json-schema`, a new **dev** dependency). Response schemas set `additionalProperties: false`, so a
field added/renamed/retyped in code but not in the docs fails. Request bodies sent are the spec's OWN documented
examples (proving they work). Every status the docs list must actually be produced (docs cannot promise a 409 the
app never returns). An operation added to the spec with no scenario fails ("No contract scenario").
(3) `ApiDocsCest` (acceptance, live server) — the docs the server actually serves list exactly as many calls as the
app's real route table (read from the acceptance kernel) has, and the exact same calls.
(4) `ApiDocsPageTest` — the access matrix (plain admin / superadmin / tech-support yes; anonymous and ordinary user
no), served JSON == the file on disk, the vendored assets and boot script.
**Verified by mutation, not assumed:** undocumented leaked field, renamed field, changed status (404→410), docs
promising a never-returned status, docs saying `id` is a string, and a brand-new undocumented route each turn the
suite red with a readable message; the Cest catches the new route ("docs list 16 calls but the app has 17").
**UI:** Swagger UI 5.33.0 **vendored** in `public/vendor/swagger-ui/` (Apache-2.0, unmodified, licence kept) — not
a CDN, so it works on an IP-locked internal instance (ADR-059) and executes no third-party CDN script. The boot
script is an external file reading the spec URL from a data attribute (CSP-friendly). Checked in a real browser:
log in → sidebar "API Docs" → 16 operations render → Authorize with a real token → Execute `listUsers` → live 200.
**Not covered:** fetching the vendored assets in the acceptance suite — that server uses `public/index.php` as its
router script, which fatals on any existing static file (real servers serve `public/` natively); their presence,
size and licence are pinned in PHP instead.
**Impact:** NEW `docs/api/openapi.yaml`, `src/Api/OpenApiSpec.php`, `src/Controller/AdminApiDocsController.php`,
`templates/admin/api_docs/index.html.twig`, `public/vendor/swagger-ui/**`, the four test files above,
`tests/Support/OpenApiValidator.php`, `tests/Support/Helper/ApiDocsHelper.php`; EDIT `config/services.yaml`,
`templates/layout/_admin_nav.html.twig`, `tests/Acceptance.suite.yml`, `tests/Support/AuthenticationTestTrait.php`
(`loginAsEnrolledTechSupport`), `composer.json`/`.lock` (dev: `opis/json-schema`), `CLAUDE.md`, `README.md`.
**Status: decided + implemented (2026-09-30).**

## ADR-061: Session connection = DATABASE_URL with the SQLite baseline, no row lock; target SQLite 3.26.0; migrations run with foreign keys off (FEATURE-152) — CORRECTS the interim session-DSN fix and ADR-056
**Tripwire:** verifiable. Verified by `tests/Functional/Session/PdoSessionHandlerTest.php`, `tests/Unit/Doctrine/SqlitePdoFactoryTest.php`, `tests/Functional/Doctrine/SqliteMigrationForeignKeyGuardTest.php`, `tests/Functional/Doctrine/SqlitePragmaMiddlewareTest.php`.
**Decision (Ken, 2026-09-30):** Ken: the session handler's PDO is a different connection from Doctrine's, another
agent had to fix its settings in a cloned repo, and **production runs SQLite 3.26.0** with the baseline
`journal_mode=WAL, busy_timeout=5000, foreign_keys=ON, locking_mode=NORMAL, synchronous=FULL`. Checking found
four real problems, all fixed here:
(1) **The session PDO got none of the baseline.** `PdoSessionHandler` opens its own `PDO` from a DSN, outside
Doctrine, so `SqlitePragmaMiddleware` (ADR-056) never touched it. NEW `SqliteConnectionBaseline` (the ONE
pragma list) + `SqlitePdoFactory` (builds the session PDO with it); the middleware now reads the same list.
A test compares the live session connection with Doctrine's for all five pragmas and the same file.
(2) **My interim session-DSN fix was wrong.** I derived `var/data_<env>.db` from the environment name, reasoning
PDO needs a different DSN form than Doctrine. False: `PdoSessionHandler` parses `sqlite:///…` natively. The
README tells operators to point `DATABASE_URL` at persistent storage — with the interim fix, sessions would have
gone to `var/data_prod.db` while the migrated data lived elsewhere (logins 500). Now `app.session.dsn` IS
`%env(resolve:DATABASE_URL)%`, so the two cannot diverge; the stale test/acceptance overrides are gone.
(3) **Sessions in the same DB exposes a same-request deadlock under the default LOCK_TRANSACTIONAL.** The session
connection holds `BEGIN IMMEDIATE` for the whole request; Doctrine's `login_history`/`audit_log` writes on the
second connection wait on it until `busy_timeout`, then fail. Reproduced with a real admin login (Chromium):
~8 s then "database is locked". The old comment "production uses LOCK_TRANSACTIONAL and is unaffected" was true
only because the original bug kept prod sessions in a separate stray file. Now ONE handler definition for every
environment with `lock_mode: 0` (LOCK_NONE) — what dev/acceptance already ran; the dev/acceptance overrides are
deleted so production runs the same config the 110 acceptance tests exercise. Cost: two simultaneous requests in
one session are last-writer-wins (no row lock).
(4) **Foreign keys ON makes migrations dangerous.** SQLite rebuilds a table to alter it (copy, DROP, rename); with
enforcement on, `DROP TABLE "user"` is an implicit DELETE that fires every child `ON DELETE CASCADE`. Proven on a
real SQLite **3.26.0** build: rebuilding the parent left `lockouts=0 2fa=0`; with FKs off both survived. NEW
`SqliteMigrationForeignKeyGuard` (Doctrine listener) turns enforcement off before migrations open a transaction,
runs `PRAGMA foreign_key_check` after, restores ON, and **refuses `--all-or-nothing`** (the pragma is a silent
no-op inside a transaction). The test includes the control case proving the hazard.
**Target SQLite 3.26.0 (recorded in CLAUDE.md "Platform Constraints"):** the sandbox has 3.45, so green tests here
do not prove 3.26 compatibility. A real 3.26.0 CLI was built from the amalgamation and fed every statement our
migrations emit: all valid, `integrity_check` ok, `foreign_key_check` clean, the five pragmas return the expected
values, cascade and FK enforcement behave, `ON CONFLICT DO UPDATE`/`BEGIN IMMEDIATE` (used for sessions) work.
Nothing newer than 3.26.0 is used (no `DROP COLUMN`, `RETURNING`, `UPDATE … FROM`, generated columns, `STRICT`,
`->>`, `RIGHT/FULL JOIN`); the squashed migrations (ADR-054) removed the last `DROP COLUMN`s.
**Impact:** NEW `src/Doctrine/{SqliteConnectionBaseline,SqlitePdoFactory,SqliteMigrationForeignKeyGuard}.php`;
EDIT `src/Doctrine/SqlitePragmaMiddleware.php`, `src/Session/ConfigAwarePdoSessionHandler.php` (takes a PDO),
`config/services.yaml`, `config/services_acceptance.yaml`, `config/packages/acceptance/services.yaml`;
DELETED `config/services_dev.yaml` (its only content was the now-universal handler definition); tests as listed
plus `tests/Unit/Session/ConfigAwarePdoSessionHandlerTest.php`; `CLAUDE.md`. **The session-DSN change already merged
to master in PR #5 must not be deployed without this follow-up** (it would deadlock logins on a shared DB).
**Status: decided + implemented (2026-09-30).**

## ADR-062: Htaccess Lock over the admin API, tech-support only, behind ONE gate (FEATURE-153)

**Decision (Ken, 2026-09-30):** Ken: make the Htaccess Lock available over the API too — tech support only —
with list / add / remove whitelisted IP (and "anything else?"), and use the **one-gate principle**: one
method/helper does the actual listing, adding and removing, so validation, audit logging and any hooks happen
once and cannot drift between the web request and the API call.
**One gate.** NEW `App\Htaccess\HtaccessLockGate` is the only way to read or change the lock. Every change is
expressed as "derive the complete new policy from the current one" and funnelled through one private `mutate()`:
authorize (`HtaccessLockActor::mayManage()` — tech support or the console) -> the same `HtaccessLockValidator` the
form always used (so adding ONE IP gets the canonical form, the `/0` refusal, the injection grammar AND the
self-lockout guard against the caller's own IP, exactly like saving the whole form) -> one `commit()` (manager
writes `.htaccess` first, then config) -> ONE audit row -> ONE `HtaccessLockChangedEvent` (the single hook point).
Refusals (validation, conflict, not found, write failure) are audited once as `failure`. `disable()` deliberately
skips validation: there is no input, and it is the lockout-recovery path, which must work even when the stored
policy would no longer pass today's rules (e.g. its error page was deleted). The three callers are now thin
adapters: `AdminHtaccessLockController` (form), NEW `Api\AdminApiHtaccessLockController` (JSON) and
`DisableHtaccessLockCommand` (console, which is now audited as actor `console` — it was not before). The old
`HtaccessLockManager::disable()` was deleted; the validator gained public `normalizeIp()`/`normalizeExemptPath()`.
**Enforced, not just intended:** `HtaccessLockGateArchitectureTest` fails the build if anything outside
`src/Htaccess/` names the manager/file/validator/renderer/self-test/probe, writes an `admin.htaccess_lock_*`
audit action or a `htaccess_lock.` config key, if anything inside the namespace other than the gate saves,
audits or dispatches, or if a fourth adapter appears unlisted. `HtaccessLockAdaptersParityTest` proves the page
and the API leave identical `.htaccess`, config and audit rows, and the identical refusal message.
**API (12 operations, all `/admin-api/htaccess-lock…`, tech support only — `#[IsGranted('ROLE_TECH_SUPPORT')]`,
so a superadmin gets 403 too):** `GET`/`PATCH` the lock; `POST enable`/`disable`; `GET`/`POST`/`DELETE …/ips`
(list / add / remove whitelisted IP); `GET`/`POST`/`DELETE …/exempt-paths`; `GET`/`POST …/self-test`. "Anything
else?": exempt paths, enable/disable, status code and error file (PATCH), and the enforcement self-test with its
last result. CIDR and path values contain slashes, which web servers mangle in path segments, so the two DELETEs
take `?ip=` / `?path=`. `enabled` is deliberately not patchable (explicit enable/disable calls instead, each with
its own audit action). Deliberately NOT added: editing the raw `.htaccess`, reading the file path, and any way to
bypass the lockout guard.
**Docs audience.** Operations and the tag carry `x-audience: tech-support`; `OpenApiSpec::jsonFor()` serves every
other admin the document minus those operations AND every schema/response/parameter only they used, so the docs
never advertise a call the viewer cannot make (a test asserts the word "htaccess" does not occur at all in a plain
admin's or superadmin's copy). `OpenApiSpecTest` ties the marker to the controller's real `#[IsGranted]` in both
directions. The admin-api firewall gained `AdminApiAccessDeniedHandler`: a role that is too low now gets the JSON
`403 {"error":"Access denied."}` like every other API error instead of an HTML page.
**Acceptance:** `HtaccessLockApiCest` (real HTTP, real file in `var/acceptance-htaccess/` — `services_acceptance.yaml`
keeps acceptance away from `public/.htaccess`), and `ApiDocsCest` now checks served-docs == callable endpoints for
tech support, a plain admin and a superadmin separately.
**Impact:** NEW `src/Htaccess/{HtaccessLockGate,HtaccessLockActor,HtaccessLockResult,HtaccessLockOutcome,
HtaccessLockChangedEvent,HtaccessLockForbiddenException,HtaccessLockView}.php`, `src/Controller/Api/
AdminApiHtaccessLockController.php`, `src/Security/AdminApiAccessDeniedHandler.php`; EDIT the web controller,
console command, validator, manager, `OpenApiSpec`, `AdminApiDocsController`, `config/packages/security.yaml`,
`config/services.yaml`, `config/services_acceptance.yaml`, `docs/api/openapi.yaml` (+12 operations),
`docs/htaccess-lock.md`, `CLAUDE.md`.
**Tripwire:** verifiable. Verified by `tests/Functional/Htaccess/HtaccessLockGateTest.php`, `tests/Functional/Htaccess/HtaccessLockAdaptersParityTest.php`, `tests/Unit/Architecture/HtaccessLockGateArchitectureTest.php`, `tests/Functional/Api/OpenApiContractTest.php`, `tests/Functional/Api/OpenApiSpecTest.php`, `tests/Functional/Api/ApiDocsPageTest.php`, `tests/Acceptance/Admin/HtaccessLockApiCest.php`, `tests/Acceptance/Admin/ApiDocsCest.php`.
**Amendment (issue #44) — changes are serialised.** Every change is a read-modify-write of the whole policy, so two
concurrent ones lost updates (a revoked IP silently came back). The gate now runs read → derive → validate → write
(`mutate()`, `disable()`) and the self-test's swap-and-restore inside `HtaccessLockMutex::synchronized()`, an
exclusive flock on its OWN file (`app.htaccess_lock.gate_lock_path`; NOT `HtaccessFile`'s per-write lock, which is
taken inside it — a second flock handle on the same file from one process would wait on itself).
`HtaccessLockManager::settings()` reads the rows straight from the DB (an identity-mapped Config entity would be stale
after waiting for the lock), and `save()` writes the five rows straight to the DB in one DBAL transaction, then refreshes
any of them this process holds as entities — NOT through ConfigService, because Doctrine skips an UPDATE when the value
equals the one it LOADED (a disable racing an enable stored nothing). `HtaccessLockMutex` is a gate internal
(architecture test); the `HtaccessLockChangedEvent` fires while the lock is held, so a listener must never call back
into the gate (it would wait on itself).
**Status: decided + implemented (2026-09-30).**

## ADR-063: Session attributes that waive 2FA (the "passed" marker, the impersonation skips) are bound to the account they were granted for (FEATURE-154)

**Context (Ken, 2026-10-01):** a reported bypass, reproduced on both realms. `_2fa_verified` /
`_admin_2fa_verified` were a bare `true`, tied to no account, and nothing clears them on login (Symfony's default
`migrate` session-fixation strategy keeps session attributes; only disabling 2FA removed the key). Someone signs
in to their own account, passes their own TOTP, then POSTs the login form with a victim's password in the same
session (no logout): the marker survives, so the victim's account opens with no 2FA prompt. Registration is open,
so anyone can get an account to start from. Admin side: a plain admin knowing a superadmin's password got in as
that superadmin without the superadmin's code.
**Decision:** both markers store the id of the account that passed the challenge (set at challenge success and at
enrolment), and are honoured only when they equal the signed-in account's id (`TwoFactorGuard` for users,
`AdminTwoFactorChallengeListener` for admins). A null id is never treated as verified. The reviewer pass found the
same flaw in the impersonation skips: `_impersonating_as` / `_impersonating_admin_as` (the impersonated
account's email) skipped 2FA, and the password-expiry gate, for whoever was signed in. Reproduced on both realms:
a superadmin impersonating a plain admin, then signing in with a tech-support admin's password, reached the
tech-support account without its mandatory TOTP. Those flags are now honoured only when they equal the signed-in
account's identifier (`TwoFactorChallengeListener`, `AdminTwoFactorChallengeListener`, `PasswordExpiryListener`).
Rule: a session attribute that waives a check names the account it waives it for and is compared with the current
principal, never just tested for presence. Chosen over clearing attributes on `LoginSuccessEvent`: binding covers
every way of switching accounts in one session (form login, magic link, any future authenticator) without
touching remember-me or the impersonation exit flows.
**Cost:** sessions that already hold the old `true` marker are challenged once more after deploy.
**Tripwire:** verifiable. Verified by `tests/Functional/Security/TwoFactorAuthTest.php::testPassedChallengeDoesNotCarryOverToAnotherAccountInTheSameSession`, `tests/Functional/Security/TwoFactorAuthTest.php::testImpersonationFlagDoesNotSkipTheChallengeForAnotherUser`, `tests/Functional/Security/AdminTwoFactorTest.php::testPassedChallengeDoesNotCarryOverToAnotherAdminInTheSameSession`, `tests/Functional/Security/AdminTwoFactorTest.php::testImpersonationFlagDoesNotSkipTheChallengeForAnotherAdmin`, `tests/Functional/Security/PasswordExpiryTest.php::testImpersonationFlagDoesNotSkipTheExpiryCheckForAnotherUser` (each fails on the old code) and `tests/Functional/Security/AdminTwoFactorTest.php::testAdminCanEnrolInTwoFactor` (fails if enrolment writes the wrong marker).
**Status: decided + implemented (2026-10-01).**

## ADR-064: Admin API tokens can be listed and revoked (CLI, own page, tech-support page), expire by default, and die with their admin (FEATURE-156, issue #39)

**Context:** issue #39 — admin API tokens could only be created: no expiry option, nothing in `src/` ever revoked
one, and admin deactivation/soft-delete left them in place (only *rejected* while inactive, ADR-049), so
reactivating an account revived every token it was ever issued. The 401 states the API docs advertised for
"revoked" and "expired" were unreachable through the product.
**Decision (Ken, issue comment 2026-10-01):** (1) revoke any token from the command line; (2) a token page per
account where each admin sees and revokes their own; (3) tech support can open every admin's token page and revoke;
(4) since only a hash is stored, show the last 6 characters so people know which one to revoke. Implemented as:
- `AdminApiTokenManager` — the one place that issues (hash + 6-char hint + expiry) and revokes (one / all-of-an-admin)
  admin tokens, and audits `admin.api_token_create` / `admin.api_token_revoke` / `admin.api_token_revoke_all`.
- CLI: `app:admin:list-api-tokens` (never prints a secret), `app:admin:revoke-api-token --id=N | --email=… --all`;
  `app:admin:create-api-token` gains `--expires-in-days` and `--no-expiry`.
- `/admin/api-tokens` (every admin, own tokens only — another admin's token id is a 404) and
  `/admin/superadmin/admins/{id}/api-tokens` (+ revoke, revoke-all), **tech support only**: superadmins get 403. Reading
  and killing other accounts' API credentials is a maintainer power, like the other Maintainer tools (ADR-059).
- `token_hint` column (nullable; tokens issued earlier show "(not recorded)"). 6 hex chars of a 64-char random
  token give an attacker nothing useful.
- Deactivate (edit → inactive) and soft-delete now call `revokeAllFor()`: tokens are revoked, not merely rejected,
  so reactivation brings none back.
**Existing data:** the migration also revokes, once, every unrevoked token of an admin that is ALREADY inactive
(deactivated or soft-deleted before this release) — otherwise reactivating such an account after deploy would
revive its tokens, the very bug fixed here (found by the reviewer pass).
**Defaults chosen (the issue asked a human for the lifetime):** new tokens expire after **365 days**; `--no-expiry`
remains possible from the shell but must be asked for. Existing tokens keep their (null) expiry — nothing is
silently broken on deploy; operators should run `app:admin:list-api-tokens` and revoke what they do not recognise.
**Deliberately not done:** issuing tokens from the web UI (stays on the shell, as before); revoking admin tokens on a
password reset — user PATs are not revoked on reset either, and an API token is a separate credential a reset does
not prove compromised (deactivation, tech-support revoke-all and the CLI cover the compromise case); the user-side
PAT page is unchanged (it already lists and revokes; PATs have no hint column yet).
**Tripwire:** verifiable. Verified by `tests/Functional/Admin/AdminApiTokenPagesTest.php`, `tests/Functional/Command/AdminApiTokenCommandsTest.php`, `tests/Functional/Migrations/AdminTokenHintMigrationTest.php`.
**Status: decided + implemented (2026-10-01).**

## ADR-065: Impersonation markers are written only once the impersonation has succeeded (FEATURE-157, issue #16)

**Context:** issue #16's main finding (the bare 2FA "passed" marker) was fixed by ADR-063. Its residue:
`ImpersonationAuthenticator::authenticate()` wrote `_impersonating_as` / `_impersonating_by` BEFORE the user checker
ran, and `onAuthenticationFailure()` never removed them. Impersonating a locked, inactive or unverified user therefore
failed but left a marker naming that user. ADR-063 honours the marker only for the matching account, so when that
very user later signed in with their own password in the same browser session, their 2FA challenge (and the
password-expiry gate) was skipped. Reproduced: an enrolled, locked target; impersonation refused; the lock lifted; the
target's own login went straight to /dashboard with no challenge.
**Decision:** `authenticate()` only consumes the one-shot request and hands the admin email to
`onAuthenticationSuccess()` in a request attribute; the markers are written there, i.e. only for an impersonation that
actually happened. A refused attempt leaves the session untouched (an impersonation already in progress keeps its
markers).
**Not changed (still OQ-007):** binding the banners to the signed-in identifier (it would also hide the user
banner on admin pages during an impersonation — a UX change) and re-checking the admin behind `_impersonation_request`.
**Tripwire:** verifiable. Verified by `tests/Functional/Security/TwoFactorAuthTest.php::testFailedImpersonationLeavesNoMarkerThatSkipsTheTargetsChallengeLater` (fails on the old code at both the banner and the 2FA step).
**Status: decided + implemented (2026-10-01).**

## ADR-066: app:create-tech-support and app:admin:set-role documented in README/core.md; console provisioning gets a role-change counterpart (FEATURE-189)

**Context:** Ken deployed from an older checkout that predated `app:create-tech-support` (FEATURE-149) and had no
way, short of editing the `admin` table by hand, to grant an existing admin `ROLE_TECH_SUPPORT`. Two gaps: (1)
`app:create-tech-support` existed but was undocumented in README/`docs/core.md` — ADR-050 kept it out of
client-facing docs on the premise that clients read them; (2) there was no console command at all to change an
*existing* admin's role (only new-account provisioning commands existed; a role change required the admin-panel
edit screen).
**Decision (Ken):** ADR-050's "keep it out of client-facing docs" premise is wrong for README/`docs/core.md` —
clients run the shipped application, they do not read its README or source. Documenting `app:create-tech-support`
there costs nothing: it only reaches people with repository and shell access, who already have the Admin entity
source showing `ROLE_TECH_SUPPORT` exists. Both files now list it in their command reference. ADR-050's actual
behavioural guarantees are untouched by this: admin-management surfaces (list/edit/delete/reset/impersonate) and
the live UI still hide tech-support accounts from non-tech-support admins exactly as before; only the static docs
changed.
**New command:** `app:admin:set-role --email=… --role=…` changes an existing admin's role from the shell, validated
against `Admin::ALLOWED_ROLES`, and enforces the same anti-lockout guard as
`AdminAdminManagementController::edit()` (refuses to strip `ROLE_SUPER_ADMIN` from the last active superadmin).
It does not audit-log the change, matching `app:create-superadmin` / `app:create-tech-support`, which are likewise
unaudited — console access is already a higher trust boundary than the web UI.
**Deliberately not changed:** the `--help` text of `app:admin:set-role` does list all three roles by name (it has
to, for the command to be usable); this is a narrower exposure than the README (shell access only) and is accepted
for the same reason — anyone with console access already has the source.
**Tripwire:** verifiable. Verified by `tests/Functional/Command/SetAdminRoleCommandTest.php` (promote to
tech-support, unknown role rejected, unknown email rejected, missing options rejected, last-active-superadmin
demotion refused, demotion allowed with a second superadmin present, an inactive superadmin never blocks the
guard).
**Status: decided + implemented (2026-10-11).**

## ADR-067: Automatic releases via semantic-release on push to `master`; the tarball is built on the minimum PHP
**Tripwire:** verifiable. Verified by `tests/Unit/Release/ReleaseScriptsTest.php` (tarball keeps runtime files, drops dev ones; scripts parse). Config in `.releaserc.json`, `.github/workflows/release.yml`, `bin/build-release.sh`.
**Decision (user request, mirrors the reference repo's setup):** every push to `master` runs semantic-release over Conventional Commits (`fix:` patch, `feat:` minor, `feat!:`/`BREAKING CHANGE:` major, `chore:`/`docs:` none; PR titles too, since squash-merges become the commit). exec writes `VERSION` and runs `bin/build-release.sh <version>`; git commits `CHANGELOG.md` + `VERSION` as `chore(release): X.Y.Z [skip ci]`; github attaches `dist/*.tar.gz` and `.sha256`. The workflow skips `[skip ci]` commits, uses `fetch-depth: 0`, and builds on PHP 8.2 (`composer.json`'s minimum) so the bundled `vendor/` runs on newer hosts. Never tag by hand; with no prior tag the first release is `v1.0.0`.
**Tarball:** tracked files minus dev/CI paths, plus `composer install --no-dev --no-scripts --optimize-autoloader`, one top-level folder `symfony-auth-boilerplate-X.Y.Z/`, plus a sha256 file; no package manager is needed on the target. App-specific: `docs/api/openapi.yaml` is kept (the admin API Docs page reads it at runtime, ADR-060) while the rest of `docs/` is dropped; the build fails if `vendor/autoload_runtime.php` is missing (the symfony/runtime plugin did not run).
**Status: decided + implemented (2026-10-11).**

## ADR-068: Install and update with one bash script (`bin/rbx-install.sh`) plus the release tarball
**Tripwire:** verifiable. Verified by `tests/installer/e2e.sh` (fresh install, update, no-op, checksum mismatch against a fake `$RBX_API_BASE`).
**Decision:** `bin/rbx-install.sh [install|update|rollback|auto] [--dir --domain --php --admin-email --yes --no-cron]` needs only bash, curl, tar, sha256sum and PHP >= 8.2 with pdo_sqlite and SQLite >= 3.26 (no git, Node, Composer or root). It reads `/releases/latest`, downloads the tarball and checksum **by asset id**, and verifies the checksum before extracting. Install merges into the folder, writes `.env.local` (mode 600; random `APP_SECRET`, `DEFAULT_URI`/`ADMIN_DOMAIN`/`APP_DOMAIN` from `--domain`), seeds `public/.htaccess` once from `config/deploy/public.htaccess`, then finalizes: `dotenv:dump prod`, env check, `assets:install`, migrations, cache clear/warmup. It optionally creates the first tech-support admin and adds the cron jobs (Messenger worker every minute, nightly `app:prune`, replacing only this folder's own lines), and prints the manual leftovers (Document Root, HTTPS, `MAILER_DSN`, trusted proxies). `dotenv:dump` is registered in `config/services.yaml` because the app does not load it by default. The env check and health check use built-in console commands (`lint:container`, `doctrine:migrations:up-to-date`) plus a SQLite integrity check instead of new app commands.
**Status: decided + implemented (2026-10-11).**

## ADR-069: Updates rehearse on a DB copy, run under maintenance mode, and roll back code and DB on any failure
**Tripwire:** verifiable. Verified by `tests/installer/e2e.sh`, `tests/Unit/Maintenance/MaintenanceGateTest.php`.
**Decision:** `update` takes a lock, restores any interrupted swap, exits when current, checks disk space and DB integrity, verifies the platform (`vendor/composer/platform_check.php`), then **rehearses** the new release on a copy of the DB while the site stays live (a failing migration changes nothing). Only then: maintenance mode (`var/maintenance.json` -> `public/index.php` answers 503 before the Kernel boots, `App\Maintenance\MaintenanceGate`, self-expires after 30 minutes, `$RBX_ALLOW_IP` keeps listed IPs through), back up the DB, swap folders (carrying `.env.local`, `.env.*.local`, `public/.htaccess` and the secrets key, then `var/`), finalize, health-check. Any failure restores the old code and the pre-update DB, and so does an abort (SIGINT/SIGTERM/SIGHUP or a script error) while maintenance mode is on; the cron worker skips runs while `var/maintenance.json` exists. `auto` treats a leftover `<dir>.old-*` as an interrupted update to recover, never a fresh install. The old code stays as `<dir>.previous`; the last 3 `pre-*` DB backups are kept. `rollback` restores `.previous` and the pre-update backup after confirmation. Only the default SQLite file under `var/` is supported; any other `DATABASE_URL` makes the updater refuse (a non-SQLite or relocated DB would need its own copy/backup/restore helpers, and rehearsal would be skipped in favour of backup + auto-rollback).
**Status: decided + implemented (2026-10-11).**

## ADR-070: DB copies and backups work on SQLite 3.26.0 (no `VACUUM INTO`)
**Tripwire:** verifiable. Verified by `tests/installer/e2e.sh` (the canary row survives update, failed-migration rollback and manual rollback).
**Decision:** `VACUUM INTO` needs 3.27 and production runs 3.26.0 (CLAUDE.md "Platform Constraints"), so `copy_db` takes the write lock (`BEGIN IMMEDIATE`; readers continue under WAL), copies the database file and its `-wal`, and rolls back; no commit or checkpoint can run meanwhile, so the pair is consistent, and opening the copy replays the WAL. Everything else the script runs is plain `PRAGMA quick_check` / `foreign_key_check`. The installer's PDO one-liners are maintenance tools, not app connections, so the connection baseline (ADR-056/061) is not applied beyond `busy_timeout`.
**Status: decided + implemented (2026-10-11).**

## ADR-071: The installer's baked GitHub token is committed on purpose (empty until the owner supplies it)
**Tripwire:** verifiable. Verified by `tests/Unit/Release/ReleaseScriptsTest.php::testBakedTokenIsEmptyOrAFineGrainedToken`.
**Decision (user):** `BAKED_TOKEN` in `bin/rbx-install.sh` may hold a read-only, single-repo fine-grained PAT (`github_pat_...`), written by `bin/bake-installer.sh` and shipped in every release so a fresh server installs with no setup. Accepted risk, not a leak. It stays empty until the owner provides that token; the reference repo's token is for another repo and was not copied. Token lookup order: `$RBX_GITHUB_TOKEN`, `RBX_UPDATE_TOKEN` in `<dir>/.env.local`, then the baked value. It becomes a real finding only if the token gains more than read access to this repo; no other token is ever committed (`bake-installer.sh` and the test reject non-fine-grained tokens).
**Status: decided (2026-10-11); token pending from the owner.**

## ADR-072: A weekly workflow prunes release assets, never releases or tags
**Tripwire:** rationale (a housekeeping job; `bin/prune-release-assets.sh` was exercised by hand against a fake releases API: newest 10 kept, drafts skipped, pagination followed, dry run deletes nothing).
**Decision:** `.github/workflows/prune-release-assets.yml` runs weekly and on `workflow_dispatch`. It deletes the tarball/checksum assets of all but the newest 10 releases; the releases and tags stay. Scheduled runs delete; manual runs are a dry run unless `dry_run` is unticked. Older versions can no longer be installed from their release, which is fine: install/update only ever use `/releases/latest`.
**Status: decided + implemented (2026-10-11).**

## ADR-073: One gate for changing an existing admin's password; "change my password" and tech-support "set password" added (FEATURE-191)

**Context:** an admin's password could only change through the emailed reset link, which `AdminPasswordResetController`
carried out inline (validate, hash, consume the token, kill sibling tokens, audit, revoke the DB console — issue #48).
There was no "change my password" for a logged-in admin, and tech support could not set another admin's password at
all (a mailbox that no longer works left the account unrecoverable short of editing the database). Adding two more
paths by copying that block would have given three places to keep in step — which is how a revocation gets forgotten.
**Decision (Ken):** (1) every admin gets "Change My Password" (`/admin/password`); (2) **tech support only** can set
another admin's password from the admins list (`/admin/superadmin/admins/{id}/password`) — superadmins do NOT get
this and keep the emailed link; (3) all three ways are ONE method: `App\AdminPassword\AdminPasswordGate::change()`
does the permission check, validation, the write, the audit row and the revocations. Controllers only collect input and
map the result. Sending a reset link changes nothing, so it stays in `AdminPasswordResetService`; account CREATION
(the create-* commands, the admin "new" form) is not a change and stays where it is.
**How the gate authorises each source:** reset link = a valid, unused, unexpired token for an active account (the same
`resetLinkProblem()` check the GET form uses); own = the actor is the target AND the current password verifies (a wrong
one is audited as a failure); support = the actor holds `ROLE_TECH_SUPPORT` (checked in the gate itself, not only by the
route's `#[IsGranted]`, and audited as a failure when refused), the target is active, and the target is NOT the actor —
a tech-support admin changing their own password must go through "own" so the current-password proof cannot be skipped.
**What every successful change does (one transaction):** write the hash; consume the link when one was used; retire every
other pending reset link; delete every `admin_sessions` row of the target (so a live session ends and none lingers as a
ghost); close any open DB console (issue #48); write the audit row (`admin.password_reset` / `admin.password_change` /
`admin.password_set`, the last with the target's email as context and the real tech-support actor). After "change my
password" the person is sent to login: the gate closed their session too.
**Changed behaviour:** a completed emailed reset now also deletes the admin's `admin_sessions` rows (it previously only
relied on `Admin::isEqualTo` refusing the live session) and retires pending links through `RecoveryTokenInvalidator`.
**Deliberately not done:** revoking the admin's API tokens (ADR-064 keeps a reset from doing that; a password is not an API
credential); password history/reuse checks for admins (`PasswordPolicyManagerInterface` reuse/history is `User`-only); an
email notice to the admin when tech support sets their password (the audit row records it; a notice is a possible
follow-up); a console command (the reset link, "set password" and the DB cover recovery; say if you want one). The
"change my password" form is throttled per account (`admin_change_password`) above CSRF because it verifies a password.
**Tripwire:** verifiable. Verified by `tests/Functional/AdminPassword/AdminPasswordGateTest.php`,
`tests/Functional/Admin/AdminPasswordChangeTest.php`, `tests/Functional/Admin/AdminPasswordResetTest.php` and
`tests/Unit/Architecture/AdminPasswordGateArchitectureTest.php` (mutation-checked: removing the tech-support check, or
the console revoke, fails the gate tests; a hand-rolled `->setPassword()` in an adapter fails the architecture test).
**Status: decided + implemented (2026-10-12).**

## ADR-074: Driver web app is a backend-for-frontend over the fvstexpress-admin driver API (FEATURE-192/193)
**Tripwire:** verifiable. Verified by `tests/Functional/Driver/DriverApiClientTest.php`, `tests/Functional/Driver/DriverWebAppTest.php`.
**Context:** this project is the browser version of the FVST Express driver Android app. Drivers, orders, PINs and
tokens all live in fvstexpress-admin, which already serves the Android app through `/driver-api/v1`.
**Decision:** a separate `driver` firewall on `/driver` with its own PIN authenticator. The PIN is checked by the admin
API (`POST /auth/login`); the returned access/refresh tokens are kept in the server-side session and never reach the
browser. Every page and action calls the admin API through one client (`DriverApiClient`). Drivers are NOT boilerplate
users; the boilerplate's users/admins remain for staff running this app. This app stores no driver or order data.
**Rationale:** one source of truth with the Android app, same PINs and lockouts, no second account to maintain
(chosen by the product owner over boilerplate accounts per driver).
**Impact:** env `DRIVER_API_BASE_URL`, `DRIVER_API_HOST_HEADER`, `DISPATCH_PHONE`. Calls forward the driver's IP in
`X-Forwarded-For`; the admin app must trust this server as a proxy (OQ-008) or every driver shares one login rate limit.

## ADR-075: Offline taps, photos and GPS pings are queued in the browser (FEATURE-203)
**Tripwire:** verifiable. Verified by `tests/Functional/Driver/DriverWebAppTest.php` (tap keys passed through once; offline answered as `503 {offline: true}` for the browser queue).
**Decision:** without signal the phone cannot reach this server either, so the queue lives in the browser (IndexedDB)
and is replayed through this app's `/driver` actions. Each tap's `Idempotency-Key` and `client_time` are fixed when the
driver taps and passed through unchanged, so the admin API applies a replay once. Failed GPS pings are sent to the
admin `POST /presence/history`. A service worker (scope `/driver/`) serves the last copy of a page when offline.
**Impact:** browsers stop GPS when the screen is off; tracking only runs while the app is open (docs/DRIVER_APP.md §4).

## ADR-076: /login is the driver login; staff sign in at /staff/login (FEATURE-193)
**Tripwire:** verifiable. Verified by `tests/Functional/Driver/DriverWebAppTest.php` (login fields, old address 301, staff login) and `tests/Functional/Navigation/RootRedirectTest.php`.
**Decision (product owner, 2026-10-16):** this app is the driver app, so its front door `/login` (and `/`) is the driver
login with the mockup's fields (phone number or employee card #, PIN). The `driver` firewall pattern is
`^/(?:driver|login$)`; the boilerplate user login route `app_login` moved to `/staff/login` (route name unchanged, so
every redirect follows). `/driver/login` 301s to `/login`. The device-id cookie path is now `/`.
**Impact:** the service worker (scope `/driver/`) does not control `/login`; the login page clears cached pages through
the registration instead. Tests that used `/login` for staff now use `/staff/login`.

## ADR-077: The driver app's GPS tester is off unless the server turns it on
**Tripwire:** verifiable. Verified by `tests/Functional/Driver/DriverWebAppTest.php` (no tester markup without the flag; with `DRIVER_GPS_TESTER=1` a "GPS Test" item after Connectivity and its panel).
**Context:** the product owner asked for a hidden menu function to send fake location pings for easy testing.
**Decision:** the tester is rendered only when `DRIVER_GPS_TESTER` is true (unset = false); with it on, the menu item
is shown straight away (product owner dropped the 5-tap unlock, 2026-10-28). A client-side unlock alone would let any
real driver fake their location in production, so the server flag is the only gate.
**Impact:** set `DRIVER_GPS_TESTER=1` only on test servers. While a fake location is on, real GPS fixes are ignored
until "Use real GPS". A zone dropdown under GPS Test lists the admin's delivery zones (admin's testing-only
`GET /driver-api/v1/test/zones`, via `/driver/test/zones`, 404 without the flag) and reports a zone's centre;
both endpoints are self-contained files so they can be deleted later (product owner, 2026-10-28).

## ADR-078: Only a superadmin changes settings; an enrolled admin is always asked for the 2FA code (audit #2, 2026-10-30)
**Tripwire:** verifiable. Verified by `tests/Functional/Admin/AdminConfigTest.php` (plain admin: read-only page, 403 on save) and `tests/Functional/Security/AdminTwoFactorTest.php::testAnEnrolledAdminIsChallengedEvenWithEnforcementOff`.
**Context:** every `/admin/config` page governs security (2FA enforcement per role, admin IP list, sessions,
registration, retention, webhooks) and any ROLE_ADMIN could save them, so one compromised plain admin could set
`2fa.enforcement.role.ROLE_SUPER_ADMIN=off` (enrolled superadmins were then never challenged) or IP-lock superadmins out.
**Decision:** saving a config page requires ROLE_SUPER_ADMIN; plain admins see the values read-only. For admins,
enforcement `off` only means "nobody is forced to enrol": an admin with TOTP enabled is always challenged.
**Impact:** the user realm keeps its specified behaviour (`off` skips the challenge even for enrolled users, AC4) — see OQ-009.

## ADR-079: Driver offline updates belong to a driver and are never silently dropped (audit #3/#4/#7, 2026-10-30)
**Tripwire:** verifiable. Verified by `tests/js/app.test.mjs` (run by `tests/Unit/Driver/DriverJsTest.php`) and `DriverWebAppTest::testSignedOutApiCallsAnswerJson401SoQueuedTapsAreKept`.
**Decision:** signed-out `/driver/api/*` answers JSON 401 `SESSION_EXPIRED` (never a redirect, which fetch() read as
success). Each saved tap/photo/ping carries the driver id and is only sent with that driver's session. A saved update
Dispatch refuses (4xx, or 5 failed tries) is kept in an IndexedDB `parked` store, shown in the banner and reported via
`app-issues`, instead of being deleted. Logout warns while updates are unsent. JS rules are tested with Node's built-in
runner (no npm packages); the service worker's VERSION is a hash of its cached files (`ServiceWorkerVersionTest`).
