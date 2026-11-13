<?php

declare(strict_types=1);

namespace App\Prune;

use App\Repository\InvitationRepository;
use App\Service\ConfigService;

/**
 * Prunes terminal invitations — (expired OR used) AND whose terminal moment (usedAt ?? expiresAt) is
 * older than `invitation.retention_days` (default 30). Closes C11 residue (FEATURE-147 / ADR-048):
 * invitations are NOT logs — if history is wanted it belongs in the audit log (admin.user_invite rows
 * already record provenance), so an exhausted invite is reclaimed after its retention window. A still
 * usable invite (unused AND unexpired) is never pruned regardless of age.
 */
final readonly class InvitationPruner implements PrunerInterface
{
    public function __construct(
        private InvitationRepository $invitations,
        private ConfigService $config,
    ) {}

    public function name(): string
    {
        return 'invitations';
    }

    public function count(\DateTimeImmutable $now): int
    {
        return $this->invitations->countExpiredOrUsed($now, $this->graceCutoff($now));
    }

    public function prune(\DateTimeImmutable $now): int
    {
        return $this->invitations->deleteExpiredOrUsed($now, $this->graceCutoff($now));
    }

    private function graceCutoff(\DateTimeImmutable $now): \DateTimeImmutable
    {
        $days = $this->config->getInt('invitation.retention_days', 30);

        return $now->modify("-{$days} days");
    }
}
