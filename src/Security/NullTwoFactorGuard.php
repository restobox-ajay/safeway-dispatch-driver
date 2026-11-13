<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * Null-object default for {@see TwoFactorChallengeGuardInterface}: with auth-2fa-bundle absent there is
 * no user 2FA at all, so no challenge is ever pending. auth-2fa-bundle's compiler pass re-aliases the
 * port to the real TwoFactorGuard when the bundle is registered (FEATURE-143 / ADR-043).
 */
final class NullTwoFactorGuard implements TwoFactorChallengeGuardInterface
{
    public function isChallengePending(Request $request, User $user): bool
    {
        return false;
    }
}
