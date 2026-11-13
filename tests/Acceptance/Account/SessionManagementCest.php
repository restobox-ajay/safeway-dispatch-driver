<?php

declare(strict_types=1);

namespace App\Tests\Acceptance\Account;

use App\Tests\Support\AcceptanceTester;

/**
 * End-to-end coverage of the session management page over real HTTP (FEATURE-068):
 * viewing the current session, terminating another device's session, "logout
 * everywhere", and the inactive-user login block.
 *
 * AC2 and AC3 need a genuine second session. PhpBrowser implements Codeception's
 * MultiSession interface, so a "friend" keeps its own cookie jar: when the friend
 * logs in as the same user it gets a distinct PHPSESSID and therefore a distinct
 * UserSession row, letting us prove a terminated session ID really stops
 * authenticating rather than poking the database directly.
 *
 * Each scenario seeds its own state via DatabaseHelper, which resets every table
 * (config rows included) before each test, so scenarios are fully isolated.
 */
class SessionManagementCest
{
    // AC1: /account/sessions shows IP, user-agent, and last-active for the current session.
    public function sessionsPageShowsCurrentSessionDetails(AcceptanceTester $I): void
    {
        $I->createUser('viewer@example.com', 'password123');
        $I->loginAsUser('viewer@example.com', 'password123');
        $I->seeCurrentUrlEquals('/dashboard');

        $I->amOnPage('/account/sessions');

        $I->seeResponseCodeIs(200);
        $I->see('Active Sessions', 'h1');

        // The login that just happened recorded a UserSession row for this session.
        $I->seeElement('.session-ip');
        $I->seeElement('.session-ua');
        $I->seeElement('.session-last-active');

        // The live server runs on the loopback interface, so the recorded client IP is local.
        $I->see('127.0.0.1', '.session-ip');
    }

    // AC2: terminating another session deauthenticates that session ID, not the current one.
    public function terminatingOtherSessionDeauthenticatesIt(AcceptanceTester $I): void
    {
        $I->createUser('multi@example.com', 'password123');

        // Current device (session S1).
        $I->loginAsUser('multi@example.com', 'password123');
        $I->seeCurrentUrlEquals('/dashboard');

        // Second device (session S2) — a separate cookie jar logging in as the same user.
        $secondDevice = $I->haveFriend('secondDevice');
        $secondDevice->does(function (AcceptanceTester $I): void {
            $I->loginAsUser('multi@example.com', 'password123');
            $I->seeCurrentUrlEquals('/dashboard');
        });

        // The current device sees both sessions; only the other one has a Terminate button
        // (its own row is marked "(Current session)").
        $I->amOnPage('/account/sessions');
        $I->click('Terminate');
        $I->seeCurrentUrlEquals('/account/sessions');

        // The terminated second device can no longer reach a protected route.
        $secondDevice->does(function (AcceptanceTester $I): void {
            $I->amOnPage('/dashboard');
            $I->seeCurrentUrlEquals('/staff/login');
        });

        // The current device is unaffected — its own session still authenticates.
        $I->amOnPage('/dashboard');
        $I->seeCurrentUrlEquals('/dashboard');
    }

    // AC3: "logout everywhere" invalidates every session ID for the user.
    public function logoutEverywhereInvalidatesAllSessions(AcceptanceTester $I): void
    {
        $I->createUser('everywhere@example.com', 'password123');

        $I->loginAsUser('everywhere@example.com', 'password123');
        $I->seeCurrentUrlEquals('/dashboard');

        $secondDevice = $I->haveFriend('secondDevice');
        $secondDevice->does(function (AcceptanceTester $I): void {
            $I->loginAsUser('everywhere@example.com', 'password123');
            $I->seeCurrentUrlEquals('/dashboard');
        });

        // Logout everywhere deletes all of the user's session rows and returns to /login.
        $I->amOnPage('/account/sessions');
        $I->click('Logout Everywhere');
        $I->seeCurrentUrlEquals('/staff/login');

        // The current device's session is now invalid.
        $I->amOnPage('/dashboard');
        $I->seeCurrentUrlEquals('/staff/login');

        // The second device's session is invalid too.
        $secondDevice->does(function (AcceptanceTester $I): void {
            $I->amOnPage('/dashboard');
            $I->seeCurrentUrlEquals('/staff/login');
        });
    }

    // AC4: an inactive user is blocked at login and shown a clear error.
    public function inactiveUserBlockedAtLoginWithError(AcceptanceTester $I): void
    {
        $I->createUser('inactive@example.com', 'password123', ['status' => 'inactive']);

        $I->loginAsUser('inactive@example.com', 'password123');

        $I->seeCurrentUrlEquals('/staff/login');
        $I->seeElement('.error');

        // The block is real: no session was established.
        $I->amOnPage('/dashboard');
        $I->seeCurrentUrlEquals('/staff/login');
    }
}
