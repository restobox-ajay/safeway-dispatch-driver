<?php

declare(strict_types=1);

namespace App\Tests\Acceptance\Auth;

use App\Tests\Support\AcceptanceTester;

/**
 * FEATURE-078 — E2E Cest: Impersonation.
 *
 * Four phpBrowser scenarios over live HTTP: an admin starting impersonation and the
 * user-firewall session switching to the target, the impersonation banner appearing on
 * every page, exiting impersonation restoring the admin session, and the start/exit
 * actions surfacing in the admin audit log. (The "regular admin cannot impersonate an
 * admin-role user -> 403" scenario was removed: users can no longer hold ROLE_ADMIN —
 * FEATURE-081/ADR-024 — so that is now an impossible state; admin-impersonates-admin
 * authorization is covered by FEATURE-052/133.)
 *
 * The impersonation subsystem already has green functional coverage (ImpersonateTest,
 * ImpersonationAuditTest, AdminImpersonateAdminTest); this feature adds dedicated
 * end-to-end depth. No production code changes and no new DatabaseHelper helpers — every
 * assertion is reachable through the live UI (the redirect chain, the base-template
 * banner, and the /admin/audit-log page) using existing actor methods. The CSRF-protected
 * impersonate/exit forms render their hidden tokens into the page, and phpBrowser carries
 * the session cookie, so click() submits a valid token (same pattern proven by
 * TwoFactorCest's CSRF-protected button clicks).
 */
final class ImpersonationCest
{
    public function _before(AcceptanceTester $I): void
    {
        $I->resetDatabase();
    }

    /**
     * Reset after each scenario too, so the admins/users/audit_log rows seeded here do
     * not leak into the PHPUnit suite on a subsequent verify-fast run. Mirrors
     * TwoFactorCest / IpWhitelistCest.
     */
    public function _after(AcceptanceTester $I): void
    {
        $I->resetDatabase();
    }

    // AC1: admin clicks Impersonate -> the user-firewall session switches to the target user.
    public function startImpersonationSwitchesToTargetUser(AcceptanceTester $I): void
    {
        $I->createAdmin('admin@example.com', 'adminpass');
        $I->createUser('target@example.com', 'password123');

        $I->loginAsAdmin('admin@example.com', 'adminpass');
        $I->amOnPage('/admin/users');
        $I->click('.impersonate-btn');

        // phpBrowser follows impersonate-start -> /impersonate/start -> /dashboard.
        $I->seeCurrentUrlEquals('/dashboard');
        // The banner names the target, proving the user firewall now authenticates as them.
        $I->see('target@example.com', '.impersonation-banner');
    }

    // AC2: the impersonation banner is visible on every page while impersonating.
    public function bannerVisibleOnEveryPage(AcceptanceTester $I): void
    {
        $I->createAdmin('admin@example.com', 'adminpass');
        $I->createUser('target@example.com', 'password123');

        $I->loginAsAdmin('admin@example.com', 'adminpass');
        $I->amOnPage('/admin/users');
        $I->click('.impersonate-btn');
        $I->seeCurrentUrlEquals('/dashboard');

        // Two distinct authenticated pages both carry the banner.
        $I->amOnPage('/dashboard');
        $I->seeElement('.impersonation-banner');
        $I->amOnPage('/account/settings');
        $I->seeElement('.impersonation-banner');
    }

    // AC3: exiting impersonation restores the admin session.
    public function exitImpersonationRestoresAdminSession(AcceptanceTester $I): void
    {
        $I->createAdmin('admin@example.com', 'adminpass');
        $I->createUser('target@example.com', 'password123');

        $I->loginAsAdmin('admin@example.com', 'adminpass');
        $I->amOnPage('/admin/users');
        $I->click('.impersonate-btn');
        $I->seeCurrentUrlEquals('/dashboard');

        // Submit the banner's exit form; phpBrowser follows the redirect to the admin dashboard.
        $I->click('.exit-impersonation-btn');
        $I->seeCurrentUrlEquals('/admin/dashboard');
        $I->dontSeeElement('.impersonation-banner');

        // The admin-only user list is reachable again -> the admin session is restored.
        $I->amOnPage('/admin/users');
        $I->seeResponseCodeIs(200);

        // ...and the browser is no longer signed in as the impersonated user (issue #64).
        $I->amOnPage('/dashboard');
        $I->seeCurrentUrlEquals('/staff/login');
    }


    // AC5: impersonation start and exit both appear in the admin audit log.
    public function startAndExitAppearInAuditLog(AcceptanceTester $I): void
    {
        $I->createAdmin('admin@example.com', 'adminpass');
        $I->createUser('target@example.com', 'password123');

        $I->loginAsAdmin('admin@example.com', 'adminpass');
        $I->amOnPage('/admin/users');
        $I->click('.impersonate-btn');
        $I->seeCurrentUrlEquals('/dashboard');

        // Exit returns to the admin firewall, which restores access to the audit log.
        $I->click('.exit-impersonation-btn');
        $I->seeCurrentUrlEquals('/admin/dashboard');

        $I->amOnPage('/admin/audit-log');
        $I->seeResponseCodeIs(200);
        $I->see('admin.impersonate_start');
        $I->see('admin.impersonate_exit');
    }
}
