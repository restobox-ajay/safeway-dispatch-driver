<?php

declare(strict_types=1);

namespace App\Tests\Acceptance\Auth;

use App\Tests\Support\AcceptanceTester;

/**
 * End-to-end coverage of the passwordless magic-link login flow over real HTTP
 * (FEATURE-075): requesting a link (with anti-enumeration confirmation), clicking a
 * valid link to authenticate and land on the dashboard, and rejection of expired and
 * already-used links with a clear error.
 *
 * Each scenario seeds its own state via DatabaseHelper, which resets the DB before every
 * test, so scenarios are fully isolated. Tokens are seeded through createMagicLinkToken so
 * the test holds the plaintext value that goes in the /magic-link/verify?token=... link —
 * the only place the production flow ever exposes it is the emailed link. No production
 * code is changed; this feature adds the Cest plus seed/assert helpers on DatabaseHelper.
 */
class MagicLinkCest
{
    // AC1: the request page renders an email input.
    public function requestPageRendersEmailInput(AcceptanceTester $I): void
    {
        $I->amOnPage('/magic-link');

        $I->seeResponseCodeIs(200);
        $I->seeElement('input[name="email"]');
        $I->see('Magic Link Login');
    }

    // AC2: submitting a known (active) email shows the neutral confirmation and writes a token.
    public function submittingKnownEmailShowsConfirmation(AcceptanceTester $I): void
    {
        $I->createUser('member@example.com', 'password123');

        $I->amOnPage('/magic-link');
        $I->submitForm('form', ['email' => 'member@example.com']);

        $I->seeCurrentUrlEquals('/magic-link/check');
        $I->see('we have sent a magic login link');
        $I->seeExactlyOneMagicLinkToken('member@example.com');
    }

    // AC3: a valid magic link authenticates the user and redirects to the dashboard.
    public function validMagicLinkLogsUserIn(AcceptanceTester $I): void
    {
        $I->createUser('login-me@example.com', 'password123');
        $token = $I->createMagicLinkToken('login-me@example.com');

        $I->amOnPage('/magic-link/verify?token=' . $token);
        $I->seeCurrentUrlEquals('/dashboard');

        // The session is really established: the protected page stays accessible.
        $I->amOnPage('/dashboard');
        $I->seeResponseCodeIs(200);

        // The link is single-use: it was marked consumed.
        $I->seeMagicLinkTokenUsed($token);
    }

    // AC4: an expired magic link is rejected with a clear error and grants no access.
    public function expiredMagicLinkShowsClearError(AcceptanceTester $I): void
    {
        $I->createUser('stale@example.com', 'password123');
        $token = $I->createMagicLinkToken('stale@example.com', -60);

        $I->amOnPage('/magic-link/verify?token=' . $token);

        // Failure redirects back to the magic-link page with the error rendered.
        $I->seeCurrentUrlEquals('/magic-link');
        $I->see('expired', '.error');

        // No session was established.
        $I->amOnPage('/dashboard');
        $I->seeCurrentUrlEquals('/staff/login');
    }

    // AC5: an already-used magic link is rejected with a clear error and grants no access.
    public function usedMagicLinkShowsClearError(AcceptanceTester $I): void
    {
        $I->createUser('once@example.com', 'password123');
        $token = $I->createMagicLinkToken('once@example.com', 15, true);

        $I->amOnPage('/magic-link/verify?token=' . $token);

        $I->seeCurrentUrlEquals('/magic-link');
        $I->see('already been used', '.error');

        $I->amOnPage('/dashboard');
        $I->seeCurrentUrlEquals('/staff/login');
    }
}
