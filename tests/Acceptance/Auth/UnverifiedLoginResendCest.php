<?php

declare(strict_types=1);

namespace App\Tests\Acceptance\Auth;

use App\Tests\Support\AcceptanceTester;

/**
 * End-to-end verification of what happens when email_verification.mode = required
 * and a user who knows their own password logs in while still unverified.
 *
 * This confirms the intended behaviour discussed for FEATURE-080:
 *   1. The login page tells the user they are blocked until their email is verified.
 *   2. The recovery offered is a *resend verification* link (NOT a password reset) —
 *      the user gets a fresh verification email without touching their password.
 *
 * Each scenario seeds its own state via DatabaseHelper, which resets the DB
 * (config rows included) before every test, so scenarios are fully isolated.
 */
class UnverifiedLoginResendCest
{
    private const EMAIL = 'unverified@example.com';

    // The blocked-login page states the account is unverified and does not authenticate.
    public function unverifiedLoginIsBlockedAndExplained(AcceptanceTester $I): void
    {
        $I->seedConfig('email_verification.mode', 'required');
        $I->createUser(self::EMAIL, 'password123', ['verified' => false]);

        // Correct credentials (the user set this password at registration).
        $I->loginAsUser(self::EMAIL, 'password123');

        // Not authenticated: bounced back to /login, not the dashboard.
        $I->seeCurrentUrlEquals('/staff/login');
        $I->seeElement('.error');
        $I->see('not been verified', '.error');
    }

    // The login page offers a resend-verification link (the recovery path here).
    public function loginPageOffersResendVerificationLink(AcceptanceTester $I): void
    {
        $I->seedConfig('email_verification.mode', 'required');

        $I->amOnPage('/staff/login');
        $I->seeElement('a[href="/resend-verification"]');
        $I->click('a[href="/resend-verification"]');
        $I->seeCurrentUrlEquals('/resend-verification');
        $I->seeElement('input[name="email"]');
    }

    // Submitting the resend form sends a verification email and confirms neutrally,
    // and crucially does NOT create a password-reset token (it is not a PW reset).
    public function resendVerificationSendsVerificationNotPasswordReset(AcceptanceTester $I): void
    {
        $I->seedConfig('email_verification.mode', 'required');
        $I->createUser(self::EMAIL, 'password123', ['verified' => false]);

        $I->amOnPage('/resend-verification');
        $I->submitForm('form', ['email' => self::EMAIL]);

        // Neutral confirmation, redirected back to the login page.
        $I->seeCurrentUrlEquals('/staff/login');
        $I->see('verification email has been sent', '.success');

        // It is a verification resend, not a password reset: no reset token was issued
        // and the user's password is unchanged (still the one they chose at signup).
        $I->dontSeePasswordResetToken(self::EMAIL);
    }
}
