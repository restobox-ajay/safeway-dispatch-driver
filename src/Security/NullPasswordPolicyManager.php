<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;

/**
 * Null-object default for {@see PasswordPolicyManagerInterface}: with auth-password-policy-bundle absent
 * there is no password policy at all — nothing is validated, no reuse is checked, no change is recorded
 * (there is no satellite table to write) and no password ever expires. auth-password-policy-bundle's
 * compiler pass re-aliases the port to the real PasswordPolicyManager when registered (FEATURE-145 / ADR-045).
 */
final class NullPasswordPolicyManager implements PasswordPolicyManagerInterface
{
    public function validate(string $password): array
    {
        return [];
    }

    public function checkReuse(User $user, string $newPlaintextPassword): ?string
    {
        return null;
    }

    public function recordPasswordChange(User $user, string $hashedPassword): void
    {
        // No-op: no password-meta / password-history table exists when the bundle is absent.
    }

    public function isExpired(User $user): bool
    {
        return false;
    }
}
