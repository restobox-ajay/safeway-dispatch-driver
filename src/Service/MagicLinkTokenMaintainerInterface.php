<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Core port for the single magic-link-token maintenance operation that always-present core code must
 * be able to invoke without a hard dependency on the optional auth-magic-link-bundle (FEATURE-140):
 *
 *   - {@see \App\Security\RecoveryTokenInvalidator} kills a user's outstanding magic links when the
 *     account is deactivated/soft-deleted (FEATURE-102 / ADR-020) via invalidateUnusedForEmail().
 *
 * With the bundle absent this is bound to {@see NullMagicLinkTokenMaintainer} (no-op — honest: no
 * magic-link feature means no magic_link_tokens table). When the bundle IS registered its compiler pass
 * aliases this interface to the bundle's MagicLinkTokenRepository.
 *
 * NOTE (FEATURE-147 / ADR-048 port shrink): pruneExpiredOrUsed() was REMOVED from this port. Its only
 * core consumer was the deleted app:maintenance:prune command; pruning is now owned by the bundle's
 * MagicLinkTokenPruner (auth.pruner), which injects the bundle repository directly. The repository still
 * exposes pruneExpiredOrUsed() for that pruner.
 */
interface MagicLinkTokenMaintainerInterface
{
    /**
     * Mark every still-unused magic-link token for the given email as used, so an outstanding link is
     * dead the instant the owning account is disabled. Idempotent when nothing is unused.
     */
    public function invalidateUnusedForEmail(string $email): void;
}
