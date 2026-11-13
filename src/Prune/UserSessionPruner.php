<?php

declare(strict_types=1);

namespace App\Prune;

use App\Repository\UserSessionRepository;

/**
 * Prunes dead user_sessions cross-reference rows — those whose underlying PdoSessionHandler session is
 * no longer live (expired/GC'd/orphaned), keyed on the `sessions` table lifetime, independent of
 * explicit logout (ADR-020). Supersedes part of app:maintenance:prune (ADR-020 → ADR-048).
 */
final readonly class UserSessionPruner implements PrunerInterface
{
    public function __construct(private UserSessionRepository $userSessions) {}

    public function name(): string
    {
        return 'user_sessions';
    }

    public function count(\DateTimeImmutable $now): int
    {
        return $this->userSessions->countExpired($now->getTimestamp());
    }

    public function prune(\DateTimeImmutable $now): int
    {
        return $this->userSessions->deleteExpired($now->getTimestamp());
    }
}
