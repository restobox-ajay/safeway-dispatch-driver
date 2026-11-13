<?php

declare(strict_types=1);

namespace App\Prune;

use App\Repository\AdminPasswordResetTokenRepository;

/**
 * Prunes expired-or-used admin_password_reset_tokens (ephemeral security artifacts, ADR-020).
 * Supersedes part of app:maintenance:prune (ADR-020 → ADR-048).
 */
final readonly class AdminPasswordResetTokenPruner implements PrunerInterface
{
    public function __construct(private AdminPasswordResetTokenRepository $tokens) {}

    public function name(): string
    {
        return 'admin_password_reset_tokens';
    }

    public function count(\DateTimeImmutable $now): int
    {
        return $this->tokens->countExpiredOrUsed($now);
    }

    public function prune(\DateTimeImmutable $now): int
    {
        return $this->tokens->deleteExpiredOrUsed($now);
    }
}
