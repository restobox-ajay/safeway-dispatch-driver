# SCRATCHPAD — FEATURE-148: admin session teardown on soft-delete/deactivate + admin_sessions pruner

## Selected feature

FEATURE-148 (priority **should**). The ONLY remaining `passes:false` feature. Deps FEATURE-123 +
FEATURE-147 both `passes:true`. Mirrors the user realm's teardown+pruner in the admin realm.

## Problem (review residue, Ken 2026-07-09)

`AdminAdminManagementController::delete()`/`edit()` flip an admin to `status='inactive'` (soft delete,
ADR-020) but never touch the `admin_sessions` bookkeeping table. Rows are removed only on explicit
logout (which a deauthenticated admin never performs), and — unlike `user_sessions`, which FEATURE-147
gave a pruner — `admin_sessions` has NO pruner, so ghost "active sessions" (deleted admins, closed
browsers) never age out. Bookkeeping-only TODAY: deleted admin genuinely loses access via
`Admin::isEqualTo` (status compare → next-request deauth), `AdminChecker` (no re-login),
`AdminTokenAuthenticator` (API tokens rejected). CRITICAL PRECONDITION: the "cosmetic only" verdict
holds ONLY because the admin firewall has NO remember_me. Any future admin remember-me MUST add a
`sessionsInvalidatedAt`-equivalent on `Admin`, fold it into the cookie HMAC, and bump it in this
teardown — else review finding C3 (revoked access survives via remember-me) reopens in the admin realm.

## Acceptance criteria → design map

1. Soft-delete flows tear down sessions. `AdminAdminManagementController::delete()` (always→inactive)
   and `::edit()` (only when `$status==='inactive'`) call
   `AdminSessionRepository::deleteAllByAdminId($admin->getId())` in the same flow as the status flip.
   Repo method already exists.
2. Functional test (AdminManagementTest): a logged-in target admin (real login → admin_sessions row)
   is soft-deleted by a superadmin (fresh cookie jar so their live session survives); assert the
   target's admin_sessions rows == 0 (explicit row-deletion assert, NOT just isEqualTo deauth), then
   restore the target's cookies and GET /admin/dashboard → 302 to /admin/login (request listener
   invalidates on the missing row).
3. Core `src/Prune/AdminSessionPruner.php` (implements PrunerInterface, autoconfigured→tagged
   auth.pruner, name 'admin_sessions'), mirrors UserSessionPruner. `AdminSessionRepository` gains
   `deleteExpired(int now)`/`countExpired(int now)` with the SAME predicate as UserSessionRepository
   (row's session_id absent from live `sessions`; $now bound INTEGER for SQLite storage-class order).
4. `app:prune` output includes 'admin_sessions' (falls out free via the tag). Functional
   PruneCommandTest gains admin_sessions coverage: live survives, expired+orphan pruned, idempotent
   2nd run 0; also assert 'admin_sessions: 0' in the per-table-counts test.
5. ADR-049 records the teardown + pruner AND the admin remember-me precondition; Tripwire verifiable
   citing AdminManagementTest + PruneCommandTest. Add a forward-pointer amendment note on ADR-034.
6. verify-fast green; nothing skipped/weakened/deleted.

## Files
- EDIT src/Repository/AdminSessionRepository.php  (+deleteExpired/+countExpired, mirror user repo)
- NEW  src/Prune/AdminSessionPruner.php            (mirror UserSessionPruner)
- EDIT src/Controller/AdminAdminManagementController.php (inject AdminSessionRepository into edit+delete)
- EDIT tests/Functional/Admin/AdminManagementTest.php   (teardown+bounce test, admin_sessions cleanup)
- EDIT tests/Functional/Command/PruneCommandTest.php     (admin_sessions seed/prune coverage + cleanup)
- EDIT .agent/DECISIONS.md                               (ADR-049 + ADR-034 amendment pointer)

## Notes / gotchas
- Pruner autoconfigures ONLY from being a class in src/Prune implementing PrunerInterface (same as
  UserSessionPruner) — no services.php/compiler edits.
- To switch client from target→super without logging target out (logout would delete the row and
  defeat the test): preserve target's cookies, `clear()` the jar (fresh browser), login super, delete,
  then restore target's cookies. Do NOT GET /admin/logout on the target.
- $now bound as INTEGER in deleteExpired/countExpired (SQLite orders INTEGER < TEXT).

## Status — IN PROGRESS (do NOT set passes=true, do NOT append claude-progress, do NOT commit; reviewer next)
