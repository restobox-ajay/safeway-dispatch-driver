<?php

declare(strict_types=1);

namespace App\Bundle\AuthMagicLink\Prune;

use App\Bundle\AuthMagicLink\Repository\MagicLinkTokenRepository;
use App\Prune\PrunerInterface;

/**
 * Prunes expired-or-used magic_link_tokens. Owned by auth-magic-link-bundle (FEATURE-147 / ADR-048):
 * it is autoconfigured (hence carries the core `auth.pruner` tag from PrunerInterface) ONLY when the
 * bundle is registered, so it appears in app:prune exclusively when magic-link is installed — modularity
 * with no services.php or compiler-pass edits. Injects the bundle repository directly (the core
 * MagicLinkTokenMaintainerInterface port dropped pruneExpiredOrUsed in the ADR-048 port shrink).
 */
final readonly class MagicLinkTokenPruner implements PrunerInterface
{
    public function __construct(private MagicLinkTokenRepository $tokens) {}

    public function name(): string
    {
        return 'magic_link_tokens';
    }

    public function count(\DateTimeImmutable $now): int
    {
        return $this->tokens->countExpiredOrUsed($now);
    }

    public function prune(\DateTimeImmutable $now): int
    {
        return $this->tokens->pruneExpiredOrUsed($now);
    }
}
