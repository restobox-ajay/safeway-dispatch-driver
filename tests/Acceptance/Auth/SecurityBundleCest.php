<?php

declare(strict_types=1);

namespace App\Tests\Acceptance\Auth;

use App\Tests\Support\AcceptanceTester;

/**
 * FEATURE-074 — E2E Cest: Security Bundle (rate limiting, lockout, admin unlock).
 *
 * Five phpBrowser scenarios over live HTTP, one per acceptance criterion: exceeding the
 * per-IP rate limit, failed attempts locking the account with a remaining-time message,
 * a locked account staying blocked even with the correct password, the admin user list
 * showing a locked indicator, and an admin unlock letting the user log in immediately.
 *
 * The rate-limit / lockout subsystem already has green functional coverage (RateLimitTest,
 * AccountLockoutTest); this adds dedicated end-to-end depth. No production code changes —
 * only seed/assert helpers on DatabaseHelper (the acceptance actor has no Asserts module,
 * and there is no other way to seed/inspect a user's locked_until). Rate limit and lockout
 * are configured per scenario via the DB-backed config the server reads at request time.
 */
final class SecurityBundleCest
{
    public function _before(AcceptanceTester $I): void
    {
        $I->resetDatabase();
    }

    /**
     * Reset after each scenario too: every scenario seeds global config rows
     * (rate_limit.*, lockout.*). Leaving them behind would change login behaviour for
     * the next test run, so this suite must not leave residue. Mirrors TwoFactorCest.
     */
    public function _after(AcceptanceTester $I): void
    {
        $I->resetDatabase();
    }

    // AC1: exceeding the per-IP rate limit shows the "too many attempts" lockout response.
    public function exceedingRateLimitShowsLockoutResponse(AcceptanceTester $I): void
    {
        // Rate limiting on at 3/window; account lockout left disabled so only the rate
        // limiter can trigger here.
        $I->seedConfig('rate_limit.max_attempts', '3');
        $I->seedConfig('rate_limit.window_seconds', '300');

        $I->createUser('rate@example.com', 'password123');

        // 3 failures are recorded (count 0->1->2->3), each allowed through.
        for ($i = 0; $i < 3; $i++) {
            $this->failedLogin($I, 'rate@example.com');
            $I->seeCurrentUrlEquals('/staff/login');
        }

        // 4th attempt: count 3 >= 3 -> blocked before credentials are even checked.
        $this->failedLogin($I, 'rate@example.com');
        $I->seeCurrentUrlEquals('/staff/login');
        $I->see('Too many login attempts', '.error');
    }

    // AC2: X failed login attempts lock the account; the next attempt shows remaining time.
    public function failedAttemptsLockAccountWithRemainingTime(AcceptanceTester $I): void
    {
        // Lockout on at 3 failures / 15 minutes; rate limiting left disabled so it does
        // not pre-empt the lockout count.
        $I->seedConfig('lockout.max_attempts', '3');
        $I->seedConfig('lockout.duration_minutes', '15');

        $userId = $I->createUser('lockcount@example.com', 'password123');

        // 3 failed logins: the 3rd pushes the failure count to the threshold and writes
        // locked_until.
        for ($i = 0; $i < 3; $i++) {
            $this->failedLogin($I, 'lockcount@example.com');
        }
        $I->seeUserLocked($userId);

        // The next attempt is rejected by the lockout check with the remaining time.
        $this->failedLogin($I, 'lockcount@example.com');
        $I->seeCurrentUrlEquals('/staff/login');
        $I->see('locked', '.error');
        $I->see('minute', '.error');
    }

    // AC3: a locked account stays blocked even when the correct password is supplied.
    public function lockedAccountBlocksValidPassword(AcceptanceTester $I): void
    {
        $userId = $I->createUser('stilllocked@example.com', 'password123');
        $I->lockUserAccount($userId, 30);

        // Correct credentials, but the account is locked: blocked at the door.
        $I->loginAsUser('stilllocked@example.com', 'password123');
        $I->seeCurrentUrlEquals('/staff/login');
        $I->see('locked', '.error');

        // No session was established by the blocked login.
        $I->amOnPage('/dashboard');
        $I->seeCurrentUrlEquals('/staff/login');
    }

    // AC4: the admin user list flags a locked account with a visible indicator.
    public function lockedIndicatorShownInAdminList(AcceptanceTester $I): void
    {
        $userId = $I->createUser('flagged@example.com', 'password123');
        $I->lockUserAccount($userId);
        $I->createAdmin('admin@example.com', 'adminpass');

        $I->loginAsAdmin('admin@example.com', 'adminpass');
        $I->amOnPage('/admin/users');

        $I->seeElement('.locked-indicator');
        $I->see('Locked');
    }

    // AC5: an admin unlock clears the lock and the user can log in immediately.
    public function adminUnlockLetsUserLogInImmediately(AcceptanceTester $I): void
    {
        $userId = $I->createUser('unlockme@example.com', 'password123');
        $I->lockUserAccount($userId);
        $I->createAdmin('admin@example.com', 'adminpass');

        $I->loginAsAdmin('admin@example.com', 'adminpass');
        $I->amOnPage('/admin/users');

        // The unlock form renders only while the account is locked; submitting it sends
        // the per-id CSRF token rendered in the row.
        $I->submitForm('form[action="/admin/users/' . $userId . '/unlock"]', []);
        $I->seeCurrentUrlEquals('/admin/users');
        $I->see('Account unlocked for unlockme@example.com.');
        $I->seeUserNotLocked($userId);

        // End the admin session, then the previously-locked user logs in successfully.
        $I->amOnPage('/admin/logout');
        $I->loginAsUser('unlockme@example.com', 'password123');
        $I->seeCurrentUrlEquals('/dashboard');
    }

    /**
     * Submit the login form with a deliberately wrong password, recording one failed
     * attempt. phpBrowser follows the post-submit redirect back to /login automatically.
     */
    private function failedLogin(AcceptanceTester $I, string $email): void
    {
        $I->amOnPage('/staff/login');
        $I->submitForm('form', [
            'email' => $email,
            'password' => 'wrong-password',
        ]);
    }
}
