<?php

declare(strict_types=1);

namespace App\Tests\Acceptance\Auth;

use App\Tests\Support\AcceptanceTester;

/**
 * FEATURE-077 — E2E Cest: IP Whitelist.
 *
 * Three phpBrowser scenarios over live HTTP, one per acceptance criterion: a login from a
 * non-whitelisted IP rejected with a clear error, a login from a whitelisted IP proceeding
 * normally, and a per-user override admitting a user the global whitelist would block.
 *
 * The IP-whitelist subsystem already has green functional coverage (IpWhitelistTest); this
 * adds dedicated end-to-end depth. No production code changes — only one seed helper on
 * DatabaseHelper (setUserAllowedIps), since the per-user allowed_ips override is the one
 * IP-whitelist input not reachable through seedConfig. The live acceptance server makes
 * every request originate from 127.0.0.1, so a whitelist of 192.168.1.100 blocks the test
 * client and a whitelist of 127.0.0.1 admits it — the same technique IpWhitelistTest uses.
 */
final class IpWhitelistCest
{
    public function _before(AcceptanceTester $I): void
    {
        $I->resetDatabase();
    }

    /**
     * Reset after each scenario too: every scenario seeds the global ip_whitelist.user_ips
     * config row. Leaving it behind would change login behaviour for the next test run, so
     * this suite must not leave residue. Mirrors SecurityBundleCest / TwoFactorCest.
     */
    public function _after(AcceptanceTester $I): void
    {
        $I->resetDatabase();
    }

    // AC1: a login from a non-whitelisted IP is rejected with a clear error.
    public function nonWhitelistedIpRejectsLoginWithClearError(AcceptanceTester $I): void
    {
        // Global user whitelist excludes 127.0.0.1 (the only IP the live server can see).
        $I->seedConfig('ip_whitelist.user_ips', '192.168.1.100');
        $I->createUser('blocked@example.com', 'password123');

        $I->loginAsUser('blocked@example.com', 'password123');
        $I->seeCurrentUrlEquals('/staff/login');
        $I->see('not allowed', '.error');

        // The rejected login established no session.
        $I->amOnPage('/dashboard');
        $I->seeCurrentUrlEquals('/staff/login');
    }

    // AC2: a login from a whitelisted IP succeeds.
    public function whitelistedIpAllowsLogin(AcceptanceTester $I): void
    {
        // Whitelist the live client's IP.
        $I->seedConfig('ip_whitelist.user_ips', '127.0.0.1');
        $I->createUser('allowed@example.com', 'password123');

        $I->loginAsUser('allowed@example.com', 'password123');
        $I->seeCurrentUrlEquals('/dashboard');
    }

    // AC3: a per-user IP override is respected over the global whitelist.
    public function perUserOverrideAllowsLoginGlobalStillBlocks(AcceptanceTester $I): void
    {
        // Global whitelist blocks 127.0.0.1, so by default every user is rejected.
        $I->seedConfig('ip_whitelist.user_ips', '192.168.1.100');

        // One user gets a per-user override admitting 127.0.0.1; another has none.
        $overriddenId = $I->createUser('override@example.com', 'password123');
        $I->setUserAllowedIps($overriddenId, '127.0.0.1');
        $I->createUser('plain@example.com', 'password123');

        // The overridden user logs in despite the global block.
        $I->loginAsUser('override@example.com', 'password123');
        $I->seeCurrentUrlEquals('/dashboard');

        // End that session, then the user without an override is still blocked by the
        // global whitelist — proving it is the override, not a disabled global, that let
        // the first user through.
        $I->amOnPage('/logout');
        $I->loginAsUser('plain@example.com', 'password123');
        $I->seeCurrentUrlEquals('/staff/login');
        $I->see('not allowed', '.error');
    }
}
