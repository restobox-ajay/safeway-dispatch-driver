<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Admin;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Blocks deactivated admin accounts on the `admin` firewall — both at login (checkPreAuth)
 * and on every subsequent request (checkPostAuth), so a superadmin deactivating an admin
 * takes effect immediately. Mirrors the user-side status enforcement in UserChecker.
 */
final class AdminChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof Admin && !$user->isActive()) {
            throw new DisabledException();
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
        if ($user instanceof Admin && !$user->isActive()) {
            throw new DisabledException();
        }
    }
}
