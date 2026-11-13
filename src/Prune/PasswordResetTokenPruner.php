<?php

declare(strict_types=1);

namespace App\Prune;

use App\Repository\PasswordResetTokenRepository;

/**
 * Prunes expired-or-used user password_reset_tokens (ephemeral security artifacts, ADR-020).
 * Supersedes part of app:maintenance:prune (ADR-020 → ADR-048).
 */
final readonly class PasswordResetTokenPruner implements PrunerInterface
{
    public function __construct(private PasswordResetTokenRepository $tokens) {}

    public function name(): string
    {
        return 'password_reset_tokens';
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
