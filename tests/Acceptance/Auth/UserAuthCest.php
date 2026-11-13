<?php

declare(strict_types=1);

namespace App\Tests\Acceptance\Auth;

use App\Tests\Support\AcceptanceTester;

/**
 * End-to-end coverage of core user authentication over real HTTP:
 * login, failed login, logout, self-registration, and remember-me.
 *
 * Each scenario seeds its own user via DatabaseHelper, which also resets the DB
 * before every test, so scenarios are fully isolated (FEATURE-065 AC6).
 */
class UserAuthCest
{
    // FEATURE-065 AC1: valid credentials -> 200 dashboard, session cookie set.
    public function validCredentialsReachDashboardAndSetSessionCookie(AcceptanceTester $I): void
    {
        $I->createUser('alice@example.com', 'password123');

        $I->loginAsUser('alice@example.com', 'password123');

        $I->seeCurrentUrlEquals('/dashboard');
        $I->see('Dashboard', 'h1');

        // A server-side session was established for the authenticated request.
        $I->seeCookie('PHPSESSID');
    }

    // FEATURE-065 AC2: invalid credentials -> login page with error, no session.
    public function invalidCredentialsShowErrorAndDoNotAuthenticate(AcceptanceTester $I): void
    {
        $I->createUser('bob@example.com', 'password123');

        $I->loginAsUser('bob@example.com', 'wrong-password');

        $I->seeCurrentUrlEquals('/staff/login');
        $I->seeElement('.error');

        // Confirm no session was established.
        $I->amOnPage('/dashboard');
        $I->seeCurrentUrlEquals('/staff/login');
    }

    // FEATURE-065 AC3: logout -> session invalidated, redirect to /login.
    public function logoutInvalidatesSession(AcceptanceTester $I): void
    {
        $I->createUser('carol@example.com', 'password123');
        $I->loginAsUser('carol@example.com', 'password123');
        $I->seeCurrentUrlEquals('/dashboard');

        $I->amOnPage('/logout');
        $I->seeCurrentUrlEquals('/staff/login');

        // The protected route is no longer reachable.
        $I->amOnPage('/dashboard');
        $I->seeCurrentUrlEquals('/staff/login');
    }

    public function selfRegistrationCreatesUsableAccount(AcceptanceTester $I): void
    {
        $I->amOnPage('/register');
        $I->seeElement('input[name="email"]');

        $I->submitForm('form', [
            'email' => 'dave@example.com',
            'name' => 'Dave Example',
            'password' => 'password123',
        ]);

        // Open-mode registration redirects to the login page.
        $I->seeCurrentUrlEquals('/staff/login');

        // The new account can log in.
        $I->loginAsUser('dave@example.com', 'password123');
        $I->seeCurrentUrlEquals('/dashboard');
        $I->see('Dave Example');
    }

    // FEATURE-065 AC4: remember-me checked -> persistent cookie issued.
    public function rememberMeIssuesPersistentCookie(AcceptanceTester $I): void
    {
        $I->createUser('erin@example.com', 'password123');

        $I->amOnPage('/staff/login');
        $I->submitForm('form', [
            'email' => 'erin@example.com',
            'password' => 'password123',
            '_remember_me' => '1',
        ]);

        $I->seeCurrentUrlEquals('/dashboard');
        $I->seeCookie('REMEMBERME');
    }

    // FEATURE-065 AC5: remember-me cookie re-authenticates after the session is cleared.
    public function rememberMeCookieReauthenticatesAfterSessionCleared(AcceptanceTester $I): void
    {
        $I->createUser('frank@example.com', 'password123');

        $I->amOnPage('/staff/login');
        $I->submitForm('form', [
            'email' => 'frank@example.com',
            'password' => 'password123',
            '_remember_me' => '1',
        ]);
        $I->seeCurrentUrlEquals('/dashboard');

        // A persistent cookie must exist before we test re-auth without a session.
        $I->seeCookie('REMEMBERME');

        // Simulate session expiry: drop the server session cookie, keeping only
        // the persistent REMEMBERME cookie.
        $I->resetCookie('PHPSESSID');
        $I->dontSeeCookie('PHPSESSID');

        // With no session, the REMEMBERME cookie alone must re-authenticate.
        $I->amOnPage('/dashboard');
        $I->seeCurrentUrlEquals('/dashboard');
        $I->see('Dashboard', 'h1');
    }
}
