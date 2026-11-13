<?php

declare(strict_types=1);

namespace App\Tests\Acceptance\Auth;

use App\Tests\Support\AcceptanceTester;

/**
 * E2E Cest: an admin deactivating OR deleting a user tears down that user's ALREADY-LIVE browser
 * session — not merely blocks the next login.
 *
 * Regression guard for the 2026-07-09 review follow-up: UserAccountAdminService::delete() set
 * status='inactive' and killed recovery tokens but — unlike deactivate() — did NOT drop the user's
 * user_sessions rows or stamp sessionsInvalidatedAt, so a soft-deleted user's already-open browser tab
 * kept working until logout/expiry (delete was strictly weaker than deactivate). Both callers now route
 * through the shared tearDownLiveAccess(); these two scenarios prove BOTH actually invoke it end to end.
 *
 * Mechanism under test — and a correction to a prior version of this docblock: tearDownLiveAccess()
 * deletes the user's user_sessions rows AND flips status='inactive' in the same call, so the observed
 * /login redirect below is produced by TWO independent mechanisms firing together, not one:
 *   1. UserSessionRequestListener finds no user_sessions row for the session id and invalidates it.
 *   2. Symfony's ContextListener sees the reloaded user's status no longer equals the token's cached
 *      user (User::isEqualTo() compares password + status) and drops the token on its own.
 * Because both fire on the same request, this Cest cannot tell which one actually produced the
 * redirect — a status flip alone (no session-row deletion) would ALSO bounce the browser here, via
 * ContextListener/isEqualTo alone, with zero session rows deleted. That isolation is done in
 * SessionInvalidationMechanismTest (tests/Functional/Security/), which triggers each mechanism alone
 * via raw SQL, plus proves a plain edit (including an attempted role change, a no-op per ADR-024)
 * triggers neither. Each scenario here also asserts the user_sessions row count directly (not just the
 * browser redirect), and confirms the live session works (/dashboard renders) before the admin action so
 * the eventual /login redirect is known to follow the teardown rather than a pre-existing lost cookie.
 *
 * The PAT-revocation and remember-me halves of tearDownLiveAccess() aren't covered by this Cest — they
 * are covered functionally by DeactivationCutsAccessTest.
 *
 * deactivate() is reachable only via the admin JSON API (POST /admin-api/users/{id}/deactivate), driven
 * here with a Bearer admin token (ApiHelper — stateless, does not touch the user's session cookie).
 * delete() is a web admin-panel action, driven by a second cookie-jarred browser (haveFriend) so the
 * admin login does not clobber the user's session.
 */
final class SessionTeardownCest
{
    public function _before(AcceptanceTester $I): void
    {
        $I->resetDatabase();
    }

    public function _after(AcceptanceTester $I): void
    {
        $I->resetDatabase();
    }

    // deactivate() (admin JSON API) tears down the user's live session.
    public function deactivateTearsDownLiveUserSession(AcceptanceTester $I): void
    {
        $userId  = $I->createUser('live-deact@example.com', 'password123');
        $adminId = $I->createAdmin('admin@example.com', 'adminpass');
        $adminToken = $I->createAdminAccessToken($adminId);

        // The user logs in: a real session + user_sessions row now exist, and /dashboard renders.
        $I->loginAsUser('live-deact@example.com', 'password123');
        $I->amOnPage('/dashboard');
        $I->seeCurrentUrlEquals('/dashboard');

        // The admin deactivates the user out-of-band via the stateless JSON API (Bearer token; no
        // Set-Cookie, so the user's session cookie in the shared jar is untouched).
        $I->sendApiRequest('POST', '/admin-api/users/' . $userId . '/deactivate', null, $adminToken);
        $I->seeApiResponseCodeIs(200);

        // tearDownLiveAccess() actually deleted the session row, not just flipped status.
        $I->seeUserSessionCountForUser($userId, 0);

        // The user's already-open session is dead: the next request is bounced to /login.
        $I->amOnPage('/dashboard');
        $I->seeCurrentUrlEquals('/staff/login');
    }

    // delete() (soft-delete via the admin web panel) tears down the user's live session too.
    public function deleteTearsDownLiveUserSession(AcceptanceTester $I): void
    {
        $userId = $I->createUser('live-del@example.com', 'password123');
        $I->createAdmin('admin@example.com', 'adminpass');

        // The user logs in: live session established, /dashboard renders.
        $I->loginAsUser('live-del@example.com', 'password123');
        $I->amOnPage('/dashboard');
        $I->seeCurrentUrlEquals('/dashboard');

        // A separate cookie-jarred browser: the admin soft-deletes the user from the panel (its own
        // session, so it does not clobber the user's).
        $admin = $I->haveFriend('admin');
        $admin->does(function (AcceptanceTester $I) use ($userId): void {
            $I->loginAsAdmin('admin@example.com', 'adminpass');
            $I->amOnPage('/admin/users');
            $I->submitForm('form[action="/admin/users/' . $userId . '/delete"]', []);
            $I->seeCurrentUrlEquals('/admin/users');
        });

        // tearDownLiveAccess() actually deleted the session row, not just flipped status.
        $I->seeUserSessionCountForUser($userId, 0);

        // The soft-deleted user's already-open session is dead: bounced to /login.
        $I->amOnPage('/dashboard');
        $I->seeCurrentUrlEquals('/staff/login');
    }
}
