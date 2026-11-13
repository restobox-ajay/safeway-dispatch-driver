<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;

/**
 * Stable core port for the user-2FA state that CORE (non-feature) code needs to read and clear:
 *  - {@see \App\Controller\AdminUserController} and {@see \App\Controller\Api\AdminApiUserController}
 *    let an admin RESET a user's 2FA (a user-management action, not the 2FA feature itself);
 *  - {@see \App\Controller\AccountController} reads the enabled flag for the settings page.
 *
 * Core defaults it to {@see NullUserTwoFactorManager} (no 2FA when the bundle is absent). auth-2fa-bundle's
 * {@see \App\Bundle\Auth2fa\Repository\TwoFactorSettingsRepository} implements it and the bundle compiler
 * pass aliases this interface to it (FEATURE-143 / ADR-043).
 */
interface UserTwoFactorManagerInterface
{
    public function isEnabled(User $user): bool;

    /**
     * Clear the user's 2FA enrolment (secret + enabled flag + replay counter) and persist. A no-op when
     * the user has no 2FA.
     */
    public function disable(User $user): void;
}
