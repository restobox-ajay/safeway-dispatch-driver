<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Default {@see UserTokenRevokerInterface} used when the optional `auth-pat-bundle` is NOT registered
 * (FEATURE-138). With no PAT feature installed, a user has no tokens: counts are 0 and revoking is a
 * no-op. This is what keeps the admin user list and the deactivate/soft-delete paths working with the
 * bundle absent — without a hard dependency on the bundle repository. When the bundle IS registered
 * its compiler pass overrides the interface alias with the real repository.
 */
final class NullUserTokenRevoker implements UserTokenRevokerInterface
{
    public function revokeAllByUserId(int $userId): int
    {
        return 0;
    }

    public function countActiveByUserIds(array $userIds): array
    {
        return [];
    }
}
