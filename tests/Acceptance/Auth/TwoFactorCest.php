<?php

declare(strict_types=1);

namespace App\Tests\Acceptance\Auth;

use App\Tests\Support\AcceptanceTester;

/**
 * FEATURE-073 — E2E Cest: 2FA Flows (setup, challenge, trusted device, trusted IP).
 *
 * Seven phpBrowser scenarios over live HTTP, one per acceptance criterion: rendering the
 * TOTP setup QR code, confirming setup with a valid code, the post-login TOTP challenge,
 * an invalid code being rejected (and access still gated), trusting a device to skip the
 * challenge on the next login, a trusted IP bypassing the challenge, and an admin resetting
 * a user's 2FA (forcing setup again under required enforcement).
 *
 * The 2FA subsystem already has green functional coverage (TwoFactorAuthTest,
 * TwoFactorEnforcementTest, AdminReset2faTest); this feature adds dedicated end-to-end
 * depth. No production code changes — only seed/codegen/assertion helpers on
 * DatabaseHelper (the acceptance actor has no Asserts module, and TOTP codes must come
 * from the same TotpService the server verifies against). Codes are generated in-process
 * and submitted over HTTP on the same host; the verifier's ±1-period window absorbs any
 * clock boundary between codegen and submit.
 */
final class TwoFactorCest
{
    public function _before(AcceptanceTester $I): void
    {
        $I->resetDatabase();
    }

    /**
     * Reset after each scenario too: several scenarios seed global config rows
     * (2fa.enforcement, 2fa.trusted_ips). Leaving 2fa.enforcement=required behind
     * would break the PHPUnit suite on the next verify-fast run (every login would
     * redirect to /account/2fa/setup), so this suite must not leave residue.
     */
    public function _after(AcceptanceTester $I): void
    {
        $I->resetDatabase();
    }

    // AC1: the 2FA setup page renders a QR code and the TOTP secret.
    public function setupPageRendersQrCode(AcceptanceTester $I): void
    {
        $I->createUser('setup@example.com', 'password123');
        $I->loginAsUser('setup@example.com', 'password123');
        $I->seeCurrentUrlEquals('/dashboard');

        $I->amOnPage('/account/2fa/setup');
        $I->seeResponseCodeIs(200);
        $I->seeElement('img.qr-code');
        $I->seeElement('.totp-secret');
    }

    // AC2: entering a valid TOTP code confirms and enables 2FA.
    public function validCodeConfirmsSetup(AcceptanceTester $I): void
    {
        $userId = $I->createUser('confirm@example.com', 'password123');
        $I->loginAsUser('confirm@example.com', 'password123');

        $I->amOnPage('/account/2fa/setup');
        // The displayed secret is the one the server stored in the session for this setup.
        $secret = $I->grabTextFrom('.totp-secret');
        $code = $I->generateTotpCode($secret);

        $I->submitForm('form', ['_code' => $code]);

        // Success redirects to account settings and persists the enabled flag.
        $I->seeCurrentUrlEquals('/account/settings');
        $I->see('Two-factor authentication has been enabled.');
        $I->seeUserTotpEnabled($userId, true);
    }

    // AC3: logging in with 2FA enabled lands on the TOTP challenge page.
    public function nextLoginTriggersChallenge(AcceptanceTester $I): void
    {
        $secret = $I->generateTotpSecret();
        $I->createUserWith2fa('challenge@example.com', 'password123', $secret);

        // phpBrowser follows /login -> /dashboard -> /2fa/challenge automatically.
        $I->loginAsUser('challenge@example.com', 'password123');

        $I->seeCurrentUrlEquals('/2fa/challenge');
        $I->see('Two-Factor Authentication');
        $I->seeElement('input[name="_code"]');
    }

    // AC4: an invalid TOTP code shows an error and leaves the user gated (not authenticated).
    public function invalidCodeShowsErrorAndBlocks(AcceptanceTester $I): void
    {
        $secret = $I->generateTotpSecret();
        $I->createUserWith2fa('wrong@example.com', 'password123', $secret);

        $I->loginAsUser('wrong@example.com', 'password123');
        $I->seeCurrentUrlEquals('/2fa/challenge');

        $I->submitForm('form', ['_code' => '000000']);

        // Re-renders the challenge with an error rather than authenticating.
        $I->seeResponseCodeIs(200);
        $I->seeCurrentUrlEquals('/2fa/challenge');
        $I->see('Invalid authentication code. Please try again.', '.error');

        // The dashboard is still gated behind the challenge.
        $I->amOnPage('/dashboard');
        $I->seeCurrentUrlEquals('/2fa/challenge');
    }

    // AC5: trusting the device skips the challenge on the next login.
    public function trustDeviceSkipsChallengeOnNextLogin(AcceptanceTester $I): void
    {
        $secret = $I->generateTotpSecret();
        $I->createUserWith2fa('trust@example.com', 'password123', $secret);

        $I->loginAsUser('trust@example.com', 'password123');
        $I->seeCurrentUrlEquals('/2fa/challenge');

        // Pass the challenge and trust this device.
        $I->submitForm('form', [
            '_code' => $I->generateTotpCode($secret),
            '_trust_device' => '1',
        ]);
        $I->seeCurrentUrlEquals('/dashboard');

        // Log out, then log back in: the TRUSTED_DEVICE cookie skips the challenge.
        $I->amOnPage('/logout');
        $I->loginAsUser('trust@example.com', 'password123');
        $I->seeCurrentUrlEquals('/dashboard');
    }

    // AC6: a trusted IP bypasses the challenge entirely.
    public function trustedIpSkipsChallenge(AcceptanceTester $I): void
    {
        // The acceptance server is reached over 127.0.0.1, so whitelisting it bypasses 2FA.
        $I->seedConfig('2fa.trusted_ips', '127.0.0.1');

        $secret = $I->generateTotpSecret();
        $I->createUserWith2fa('trustedip@example.com', 'password123', $secret);

        $I->loginAsUser('trustedip@example.com', 'password123');

        // No challenge: login lands directly on the dashboard.
        $I->seeCurrentUrlEquals('/dashboard');
    }

    // AC7: an admin resetting a user's 2FA forces the user to set it up again.
    public function adminResets2faForcesUserSetupAgain(AcceptanceTester $I): void
    {
        $secret = $I->generateTotpSecret();
        $userId = $I->createUserWith2fa('victim@example.com', 'password123', $secret);
        $I->createAdmin('admin@example.com', 'adminpass');

        $I->loginAsAdmin('admin@example.com', 'adminpass');
        $I->amOnPage('/admin/users');
        $I->click('.reset-2fa-btn');

        $I->seeCurrentUrlEquals('/admin/users');
        $I->see('2FA has been reset for victim@example.com.');
        $I->seeUserTotpEnabled($userId, false);

        // With 2FA now cleared and enforcement required, the user is forced back to setup.
        $I->amOnPage('/admin/logout');
        $I->seedConfig('2fa.enforcement', 'required');
        $I->loginAsUser('victim@example.com', 'password123');
        $I->seeCurrentUrlEquals('/account/2fa/setup');
    }
}
