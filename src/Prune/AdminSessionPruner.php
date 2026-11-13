<?php

declare(strict_types=1);

namespace App\Prune;

use App\Repository\AdminSessionRepository;

/**
 * Prunes dead admin_sessions cross-reference rows — the admin-side mirror of {@see UserSessionPruner}
 * (FEATURE-148 / ADR-049). Rows whose underlying PdoSessionHandler session is no longer live
 * (expired/GC'd/orphaned) are removed, keyed on the `sessions` table lifetime, independent of explicit
 * logout and of the soft-delete teardown. Without this, admin_sessions ghosts (deleted admins, closed
 * browsers) never age out — unlike user_sessions, which FEATURE-147 already gave a pruner.
 */
final readonly class AdminSessionPruner implements PrunerInterface
{
    public function __construct(private AdminSessionRepository $adminSessions) {}

    public function name(): string
    {
        return 'admin_sessions';
    }

    public function count(\DateTimeImmutable $now): int
    {
        return $this->adminSessions->countExpired($now->getTimestamp());
    }

    public function prune(\DateTimeImmutable $now): int
    {
        return $this->adminSessions->deleteExpired($now->getTimestamp());
    }
}
