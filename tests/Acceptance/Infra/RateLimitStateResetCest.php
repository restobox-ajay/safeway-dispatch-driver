<?php

declare(strict_types=1);

namespace App\Tests\Acceptance\Infra;

use App\Tests\Support\AcceptanceTester;

/**
 * FEATURE-129 (review C40): the per-test database reset must clear the DBAL-only rate-limit
 * stores (login_attempts, endpoint_rate_limits), not just the ORM-mapped tables.
 *
 * Those tables carry a schema_asset_filter (config/packages/doctrine.yaml), which used to hide
 * them from DatabaseHelper::resetDatabase()'s table enumeration, so failed-login rows survived
 * every reset and accumulated across suite runs until the login rate limiter tripped — the
 * cross-run acceptance flake (a later scenario redirected to /login with "Too many login
 * attempts" instead of proceeding).
 *
 * The two scenarios run in order within this Cest: the first seeds rows into both stores, the
 * second (after a fresh _before reset) asserts they are gone. On the pre-fix reset the second
 * scenario sees the leaked rows and fails.
 */
class RateLimitStateResetCest
{
    public function rateLimitStoresCanBeSeeded(AcceptanceTester $I): void
    {
        $I->seedLoginAttempt('203.0.113.7', 'leak@example.com', 'user');
        $I->seedEndpointRateLimit('register', '203.0.113.7');

        $I->seeRateLimitRowCount('login_attempts', 1);
        $I->seeRateLimitRowCount('endpoint_rate_limits', 1);
    }

    public function rateLimitStoresAreResetBetweenTests(AcceptanceTester $I): void
    {
        // The previous scenario populated both stores; the per-test reset must have cleared them.
        $I->seeRateLimitRowCount('login_attempts', 0);
        $I->seeRateLimitRowCount('endpoint_rate_limits', 0);
    }
}
