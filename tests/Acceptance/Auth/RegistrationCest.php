<?php

declare(strict_types=1);

namespace App\Tests\Acceptance\Auth;

use App\Tests\Support\AcceptanceTester;

/**
 * End-to-end coverage of registration and email-verification flows over real HTTP
 * (FEATURE-066): open registration, duplicate-email rejection, invitation-only
 * blocking, invite-token pre-fill, verification-link confirmation, and expired-token
 * rejection.
 *
 * Each scenario seeds its own state via DatabaseHelper, which also resets the DB
 * (config rows included) before every test, so scenarios are fully isolated.
 */
class RegistrationCest
{
    // AC1: open mode -> register form accessible, valid submission creates account.
    public function openModeAllowsRegistrationAndCreatesAccount(AcceptanceTester $I): void
    {
        // Default mode after a DB reset is "open".
        $I->amOnPage('/register');
        $I->seeElement('input[name="email"]');

        $I->submitForm('form', [
            'email'    => 'newcomer@example.com',
            'name'     => 'New Comer',
            'password' => 'password123',
        ]);

        // Open-mode registration redirects to the login page.
        $I->seeCurrentUrlEquals('/staff/login');
        $I->seeExactlyOneUserWithEmail('newcomer@example.com');

        // The new account is usable end-to-end.
        $I->loginAsUser('newcomer@example.com', 'password123');
        $I->seeCurrentUrlEquals('/dashboard');
    }

    // AC2: duplicate email -> validation error shown, no second account created.
    public function duplicateEmailShowsValidationError(AcceptanceTester $I): void
    {
        $I->createUser('taken@example.com', 'password123');

        $I->amOnPage('/register');
        $I->submitForm('form', [
            'email'    => 'taken@example.com',
            'name'     => 'Impostor',
            'password' => 'password123',
        ]);

        // Stays on the registration page with a clear error; no duplicate is created.
        $I->seeCurrentUrlEquals('/register');
        $I->see('already registered');
        $I->seeExactlyOneUserWithEmail('taken@example.com');
    }

    // AC3: invitation-only mode -> /register without a token returns 403.
    public function invitationOnlyModeBlocksRegistrationWithoutToken(AcceptanceTester $I): void
    {
        $I->seedConfig('registration.mode', 'invitation-only');

        $I->amOnPage('/register');

        $I->seeResponseCodeIs(403);
        $I->see('invitation only');
    }

    // AC4: valid invite token -> registration form pre-filled with the invited email.
    public function validInviteTokenShowsPrefilledRegistrationForm(AcceptanceTester $I): void
    {
        $I->seedConfig('registration.mode', 'invitation-only');
        $token = $I->createInvitation('invitee@example.com');

        $I->amOnPage('/register?token=' . $token);

        $I->seeResponseCodeIs(200);
        $I->seeInField('email', 'invitee@example.com');
    }

    // AC5: clicking a valid verification link marks the account verified.
    public function verificationLinkMarksAccountVerified(AcceptanceTester $I): void
    {
        $I->seedConfig('email_verification.mode', 'required');
        $userId = $I->createUser('verify-me@example.com', 'password123', ['verified' => false]);

        // While unverified and mode=required, login is blocked.
        $I->loginAsUser('verify-me@example.com', 'password123');
        $I->seeCurrentUrlEquals('/staff/login');
        $I->seeElement('.error');

        // Visiting the signed verification link confirms the address.
        $I->amOnPage($I->generateVerificationPath($userId, 'verify-me@example.com'));
        $I->seeCurrentUrlEquals('/staff/login');
        $I->see('verified', '.success');
        $I->seeUserVerified($userId);

        // The account can now log in.
        $I->loginAsUser('verify-me@example.com', 'password123');
        $I->seeCurrentUrlEquals('/dashboard');
    }

    // AC6: an expired verification token is rejected with a clear error.
    public function expiredVerificationTokenShowsClearError(AcceptanceTester $I): void
    {
        $I->seedConfig('email_verification.mode', 'required');
        $userId = $I->createUser('stale@example.com', 'password123', ['verified' => false]);

        $I->amOnPage($I->generateExpiredVerificationPath($userId, 'stale@example.com'));

        $I->seeCurrentUrlEquals('/staff/login');
        $I->see('expired', '.error');

        // The account remains unverified.
        $I->dontSeeUserVerified($userId);
    }
}
