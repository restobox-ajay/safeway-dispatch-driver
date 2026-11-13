<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;

/**
 * Stable core port for the account-lockout state that CORE (non-feature) code needs to read and clear.
 * The lockout state used to live as a `locked_until` column on the `user` table; it now lives in the
 * auth-security-bundle satellite `account_lockouts` (FEATURE-144 / ADR-044). Consumers that must NOT
 * depend on the bundle read/clear it through this interface:
 *  - {@see \App\Security\UserChecker} enforces the lock on login;
 *  - {@see \App\Controller\AdminUserController} and {@see \App\Controller\Api\AdminApiUserController}
 *    let an admin manually UNLOCK an account, and the admin user list renders a "Locked" indicator /
 *    "Unlock" button;
 *  - the PAT bundle's TokenAuthenticator rejects a locked user's token.
 *
 * Core defaults it to {@see NullAccountLockManager} (nothing is ever locked when auth-security-bundle is
 * absent). The bundle's {@see \App\Bundle\AuthSecurity\Repository\AccountLockoutRepository} implements it
 * and the bundle compiler pass aliases this interface to it.
 */
interface AccountLockManagerInterface
{
    /**
     * The moment the user's lockout ends, or null when the user has no active lockout.
     */
    public function lockedUntil(User $user): ?\DateTimeImmutable;

    /**
     * True when the user is currently locked (a lockout exists and has not yet expired).
     */
    public function isLocked(User $user): bool;

    /**
     * Clear the user's lockout and persist. A no-op when the user is not locked.
     */
    public function unlock(User $user): void;
}
