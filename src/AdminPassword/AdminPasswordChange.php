<?php

declare(strict_types=1);

namespace App\AdminPassword;

use App\Entity\Admin;

/**
 * A request to change an admin's password. Built only through the named constructors, one per
 * {@see PasswordChangeSource}, so each carries exactly the proof the gate needs to authorise it.
 */
final class AdminPasswordChange
{
    private function __construct(
        public readonly PasswordChangeSource $source,
        public readonly string $newPassword,
        public readonly ?string $confirmation,
        public readonly string $ip,
        public readonly ?Admin $actor = null,
        public readonly ?Admin $target = null,
        public readonly ?string $currentPassword = null,
        public readonly ?string $resetToken = null,
    ) {
    }

    public static function viaResetLink(string $resetToken, string $newPassword, string $ip): self
    {
        return new self(PasswordChangeSource::ResetLink, $newPassword, null, $ip, resetToken: $resetToken);
    }

    public static function own(Admin $admin, string $currentPassword, string $newPassword, string $confirmation, string $ip): self
    {
        return new self(PasswordChangeSource::Own, $newPassword, $confirmation, $ip, actor: $admin, target: $admin, currentPassword: $currentPassword);
    }

    public static function bySupport(Admin $actor, Admin $target, string $newPassword, string $confirmation, string $ip): self
    {
        return new self(PasswordChangeSource::Support, $newPassword, $confirmation, $ip, actor: $actor, target: $target);
    }
}
