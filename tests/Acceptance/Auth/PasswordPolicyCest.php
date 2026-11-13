<?php

declare(strict_types=1);

namespace App\Tests\Acceptance\Auth;

use App\Tests\Support\AcceptanceTester;

/**
 * End-to-end coverage of the configurable password policy over real HTTP
 * (FEATURE-076): registration rejecting weak passwords (minimum length and each
 * required character class), the expired-password forced-change redirect on
 * login, reuse prevention, and a compliant new password letting the user proceed
 * normally.
 *
 * Each scenario seeds its own policy config and fixtures via DatabaseHelper,
 * which resets the DB (config rows included) before every test, so scenarios are
 * fully isolated. Policy rules are off by default after a reset (all thresholds
 * 0/false), so a scenario only exercises the rule it explicitly enables.
 */
class PasswordPolicyCest
{
    // AC1: a password below the configured minimum length is rejected on
    // registration with a validation error that names the rule, and no account
    // is created.
    public function registrationRejectsPasswordBelowMinLength(AcceptanceTester $I): void
    {
        $I->seedConfig('password_policy.min_length', '12');

        $I->amOnPage('/register');
        $I->submitForm('form', [
            'email'    => 'tooshort@example.com',
            'name'     => 'Too Short',
            'password' => 'short',
        ]);

        $I->seeCurrentUrlEquals('/register');
        $I->seeElement('.error');
        $I->see('at least 12');
        $I->dontSeeUserWithEmail('tooshort@example.com');
    }

    // AC2: each required character class (uppercase / number / symbol) produces
    // its own specific error when violated. Rules are toggled one at a time so a
    // failure can only be attributed to the single enabled rule.
    public function registrationNamesEachMissingCharacterClass(AcceptanceTester $I): void
    {
        // Uppercase required, password has none.
        $I->seedConfig('password_policy.require_uppercase', '1');
        $I->amOnPage('/register');
        $I->submitForm('form', [
            'email'    => 'noupper@example.com',
            'name'     => 'No Upper',
            'password' => 'alllower1!',
        ]);
        $I->seeCurrentUrlEquals('/register');
        $I->seeElement('.error');
        $I->see('uppercase');
        $I->dontSeeUserWithEmail('noupper@example.com');

        // Number required, password has none (uppercase rule now off).
        $I->seedConfig('password_policy.require_uppercase', '0');
        $I->seedConfig('password_policy.require_number', '1');
        $I->amOnPage('/register');
        $I->submitForm('form', [
            'email'    => 'nonumber@example.com',
            'name'     => 'No Number',
            'password' => 'NoNumberHere!',
        ]);
        $I->seeCurrentUrlEquals('/register');
        $I->seeElement('.error');
        $I->see('number');
        $I->dontSeeUserWithEmail('nonumber@example.com');

        // Symbol required, password has none (number rule now off).
        $I->seedConfig('password_policy.require_number', '0');
        $I->seedConfig('password_policy.require_symbol', '1');
        $I->amOnPage('/register');
        $I->submitForm('form', [
            'email'    => 'nosymbol@example.com',
            'name'     => 'No Symbol',
            'password' => 'NoSymbol123',
        ]);
        $I->seeCurrentUrlEquals('/register');
        $I->seeElement('.error');
        $I->see('symbol');
        $I->dontSeeUserWithEmail('nosymbol@example.com');
    }

    // AC3: a user whose password is older than the configured expiry is
    // intercepted on login and redirected to the forced-change page.
    public function expiredPasswordForcesChangeOnLogin(AcceptanceTester $I): void
    {
        $userId = $I->createUser('expired@example.com', 'CorrectPass1!');
        $I->seedConfig('password_policy.expiry_days', '30');
        $I->setUserPasswordChangedAt($userId, '-60 days');

        $I->loginAsUser('expired@example.com', 'CorrectPass1!');

        // phpBrowser follows the login redirect to /dashboard, where the expiry
        // listener bounces the user to the forced-change page.
        $I->seeCurrentUrlEquals('/account/change-expired-password');
        $I->see('Password Expired');
    }

    // AC4: setting a new password that matches a recent history entry is rejected
    // with an error, and the reset is not applied (the token stays unconsumed).
    public function reusingRecentPasswordIsRejected(AcceptanceTester $I): void
    {
        $userId = $I->createUser('reuser@example.com', 'OldPass1!');
        $I->seedConfig('password_policy.reuse_count', '3');
        $I->seedPasswordHistory($userId, 'OldPass1!');

        $token = $I->createPasswordResetToken('reuser@example.com');

        $I->amOnPage('/reset-password/' . $token);
        $I->submitForm('form', ['password' => 'OldPass1!']);

        // Stays on the reset URL and shows the specific reuse error; the reset
        // template hides the form once an error is set, so the proof the change
        // was blocked is the error message plus the token remaining unconsumed.
        $I->seeCurrentUrlEquals('/reset-password/' . $token);
        $I->see('reuse', '.error');
        $I->seePasswordResetTokenNotUsed($token);
    }

    // AC5: a compliant new password completes the forced change, and the user can
    // then log in normally with it.
    public function compliantNewPasswordLetsUserProceed(AcceptanceTester $I): void
    {
        $userId = $I->createUser('renew@example.com', 'CorrectPass1!');
        $I->seedConfig('password_policy.expiry_days', '30');
        $I->seedConfig('password_policy.min_length', '8');
        $I->setUserPasswordChangedAt($userId, '-60 days');

        // Login lands on the forced-change page (password expired).
        $I->loginAsUser('renew@example.com', 'CorrectPass1!');
        $I->seeCurrentUrlEquals('/account/change-expired-password');

        // A compliant new password is accepted. The session stays live (the token's user is
        // mutated in-request), so the user lands on the dashboard rather than the login form.
        $I->submitForm('form', ['current_password' => 'CorrectPass1!', 'password' => 'BrandNew2Pass!']);
        $I->seeCurrentUrlEquals('/dashboard');

        // Log out, then confirm the new password works end-to-end with no expiry redirect.
        $I->amOnPage('/logout');
        $I->loginAsUser('renew@example.com', 'BrandNew2Pass!');
        $I->seeCurrentUrlEquals('/dashboard');
    }
}
