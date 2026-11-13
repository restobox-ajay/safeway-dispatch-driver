<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;

/**
 * Null-object default for {@see AccountLockManagerInterface}: with auth-security-bundle absent there is no
 * lockout store, so no account is ever locked and there is nothing to unlock. The bundle's compiler pass
 * re-aliases the port to the real AccountLockoutRepository when registered (FEATURE-144 / ADR-044).
 */
final class NullAccountLockManager implements AccountLockManagerInterface
{
    public function lockedUntil(User $user): ?\DateTimeImmutable
    {
        return null;
    }

    public function isLocked(User $user): bool
    {
        return false;
    }

    public function unlock(User $user): void
    {
        // No-op: no lockout exists when the bundle is absent.
    }
}
