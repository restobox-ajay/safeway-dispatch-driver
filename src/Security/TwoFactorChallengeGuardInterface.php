<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * Stable core port for "does this session still owe a TOTP challenge for the given user".
 *
 * Injected by {@see \App\Controller\AccountController} so a pre-2FA session cannot rotate a password
 * on a route the 2FA challenge listener deliberately skips. Core defaults it to
 * {@see NullTwoFactorGuard} (never pending); auth-2fa-bundle's TwoFactorGuard implements it and the
 * bundle compiler pass aliases this interface to it (FEATURE-143 / ADR-043).
 */
interface TwoFactorChallengeGuardInterface
{
    public function isChallengePending(Request $request, User $user): bool;
}
