<?php

declare(strict_types=1);

namespace App\Tests\Acceptance\Auth;

use App\Tests\Support\AcceptanceTester;

/**
 * End-to-end coverage of the self-service password reset flow over real HTTP
 * (FEATURE-067): forgot-password request with anti-enumeration, the reset link
 * showing a new-password form, setting a new password (and the token being
 * invalidated afterwards), and rejection of expired / already-used tokens.
 *
 * Each scenario seeds its own state via DatabaseHelper, which resets the DB
 * (config rows included) before every test, so scenarios are fully isolated.
 * Tokens are seeded through createPasswordResetToken so the test holds the
 * plaintext value that goes in the /reset-password/{token} link — the only
 * place the production flow ever exposes it is the emailed link.
 */
class PasswordResetCest
{
    // AC1: known email -> neutral confirmation shown and a reset token is created.
    public function knownEmailShowsConfirmationAndCreatesToken(AcceptanceTester $I): void
    {
        $I->createUser('member@example.com', 'password123');

        $I->amOnPage('/forgot-password');
        $I->submitForm('form', ['email' => 'member@example.com']);

        $I->seeCurrentUrlEquals('/forgot-password/check');
        $I->see('we have sent a password reset link');
        $I->seeExactlyOnePasswordResetToken('member@example.com');
    }

    // AC2: unknown email -> SAME confirmation, and no token is created (no enumeration).
    public function unknownEmailShowsSameConfirmationAndCreatesNoToken(AcceptanceTester $I): void
    {
        $I->amOnPage('/forgot-password');
        $I->submitForm('form', ['email' => 'nobody@example.com']);

        $I->seeCurrentUrlEquals('/forgot-password/check');
        $I->see('we have sent a password reset link');
        $I->dontSeePasswordResetToken('nobody@example.com');
    }

    // AC3: a valid reset link shows the new-password form.
    public function validResetLinkShowsNewPasswordForm(AcceptanceTester $I): void
    {
        $I->createUser('reset-me@example.com', 'password123');
        $token = $I->createPasswordResetToken('reset-me@example.com');

        $I->amOnPage('/reset-password/' . $token);

        $I->seeResponseCodeIs(200);
        $I->seeElement('input[name="password"]');
        $I->dontSeeElement('.error');
    }

    // AC4: a valid new password updates the account and invalidates the token.
    public function validNewPasswordUpdatesPasswordAndInvalidatesToken(AcceptanceTester $I): void
    {
        $I->createUser('change-me@example.com', 'oldpassword123');
        $token = $I->createPasswordResetToken('change-me@example.com');

        $I->amOnPage('/reset-password/' . $token);
        $I->submitForm('form', ['password' => 'newpassword456']);

        // Success redirects to the login page with a confirmation flash.
        $I->seeCurrentUrlEquals('/staff/login');
        $I->see('reset', '.success');

        // The password really changed: the new password logs in end-to-end.
        $I->loginAsUser('change-me@example.com', 'newpassword456');
        $I->seeCurrentUrlEquals('/dashboard');

        // The token is invalidated: it is marked used and the link no longer works.
        $I->seePasswordResetTokenUsed($token);
        $I->amOnPage('/reset-password/' . $token);
        $I->see('already been used', '.error');
        $I->dontSeeElement('input[name="password"]');
    }

    // AC5: an expired token is rejected with a clear error.
    public function expiredTokenShowsClearError(AcceptanceTester $I): void
    {
        $I->createUser('stale-reset@example.com', 'password123');
        $token = $I->createPasswordResetToken('stale-reset@example.com', -60);

        $I->amOnPage('/reset-password/' . $token);

        $I->see('expired', '.error');
        $I->dontSeeElement('input[name="password"]');
    }

    // AC6: an already-used token is rejected with a clear error.
    public function usedTokenShowsClearError(AcceptanceTester $I): void
    {
        $I->createUser('used-reset@example.com', 'password123');
        $token = $I->createPasswordResetToken('used-reset@example.com', 60, true);

        $I->amOnPage('/reset-password/' . $token);

        $I->see('already been used', '.error');
        $I->dontSeeElement('input[name="password"]');
    }
}
