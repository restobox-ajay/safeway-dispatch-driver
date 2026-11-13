<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;

/**
 * Null-object default for {@see UserTwoFactorManagerInterface}: with auth-2fa-bundle absent there is no
 * user 2FA, so nothing is ever enabled and there is nothing to disable. auth-2fa-bundle's compiler pass
 * re-aliases the port to the real TwoFactorSettingsRepository when registered (FEATURE-143 / ADR-043).
 */
final class NullUserTwoFactorManager implements UserTwoFactorManagerInterface
{
    public function isEnabled(User $user): bool
    {
        return false;
    }

    public function disable(User $user): void
    {
        // No-op: no user 2FA exists when the bundle is absent.
    }
}
