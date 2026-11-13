<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Default {@see MagicLinkTokenMaintainerInterface} used when the optional auth-magic-link-bundle is
 * NOT registered (FEATURE-140). With no magic-link feature installed there is no magic_link_tokens
 * table: invalidation is a no-op. This keeps {@see \App\Security\RecoveryTokenInvalidator} working with
 * the bundle absent, without a hard dependency on the bundle repository. When the bundle IS registered
 * its compiler pass overrides this alias with the real repository.
 *
 * (FEATURE-147 / ADR-048: pruneExpiredOrUsed() dropped from the port — pruning is now the bundle's
 * MagicLinkTokenPruner, which simply is not present when the bundle is absent.)
 */
final class NullMagicLinkTokenMaintainer implements MagicLinkTokenMaintainerInterface
{
    public function invalidateUnusedForEmail(string $email): void
    {
        // No magic_link_tokens table when the bundle is absent — nothing to invalidate.
    }
}
