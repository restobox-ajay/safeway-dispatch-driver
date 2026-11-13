<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Bundle\Auth2fa\Repository\TwoFactorSettingsRepository;
use App\Entity\User;

/**
 * Test helper for the user 2FA satellite (FEATURE-143 / ADR-043). Since the user's TOTP state no longer
 * lives on the User entity, tests seed / inspect it through the bundle's TwoFactorSettingsRepository
 * (the two_factor_settings table) instead of via now-removed User accessors. Used by the WebTestCase
 * 2FA suites, which all have access to the test container via self::getContainer().
 */
trait TwoFactorTestTrait
{
    private function enableTwoFactor(User $user, string $secret, ?int $counter = null): void
    {
        static::getContainer()->get(TwoFactorSettingsRepository::class)->enable($user, $secret, $counter);
    }

    private function isTwoFactorEnabled(User $user): bool
    {
        return static::getContainer()->get(TwoFactorSettingsRepository::class)->isEnabled($user);
    }

    /** The stored secret regardless of the enabled flag (null once 2FA is disabled/reset). */
    private function twoFactorSecret(User $user): ?string
    {
        return static::getContainer()->get(TwoFactorSettingsRepository::class)->findForUser($user)?->getTotpSecret();
    }
}
