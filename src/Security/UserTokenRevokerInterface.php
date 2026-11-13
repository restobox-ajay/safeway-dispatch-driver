<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Core-side PORT for the ONLY thing the always-present admin/user-management surface needs from the
 * optional Personal Access Token feature (FEATURE-138, C36 Option A): counting a user's active
 * tokens (for the admin user list) and revoking them (on deactivate / admin "revoke all tokens").
 *
 * The PAT implementation lives in the optional `auth-pat-bundle`
 * ({@see \App\Bundle\AuthPat\Repository\PersonalAccessTokenRepository}). Core depends on THIS
 * interface, never on the bundle class, so the app compiles and runs with the bundle NOT registered.
 * When the bundle is absent the app binds {@see NullUserTokenRevoker} (no tokens exist, so counts are
 * 0 and revoke is a no-op); when it is registered the bundle's compiler pass aliases this interface to
 * the real repository.
 */
interface UserTokenRevokerInterface
{
    /** Revoke all of a user's active tokens. Returns the number revoked. */
    public function revokeAllByUserId(int $userId): int;

    /**
     * Active-token counts keyed by user id (missing users implicitly 0), for the admin user list.
     *
     * @param int[] $userIds
     *
     * @return array<int, int>
     */
    public function countActiveByUserIds(array $userIds): array;
}
