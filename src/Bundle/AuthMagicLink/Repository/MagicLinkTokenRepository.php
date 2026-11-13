<?php

declare(strict_types=1);

namespace App\Bundle\AuthMagicLink\Repository;

use App\Bundle\AuthMagicLink\Entity\MagicLinkToken;
use App\Service\MagicLinkTokenMaintainerInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MagicLinkToken>
 *
 * Implements the core {@see MagicLinkTokenMaintainerInterface} port so the always-present
 * RecoveryTokenInvalidator (deactivation kill) can operate on magic-link tokens WITHOUT a hard
 * dependency on this optional bundle. The bundle's compiler pass aliases that interface to this
 * repository (FEATURE-140); with the bundle absent core uses App\Service\NullMagicLinkTokenMaintainer
 * instead. Ephemeral pruning (pruneExpiredOrUsed/countExpiredOrUsed) is NOT part of that port anymore
 * (ADR-048 port shrink) — the bundle's MagicLinkTokenPruner (auth.pruner) injects this repo directly.
 */
class MagicLinkTokenRepository extends ServiceEntityRepository implements MagicLinkTokenMaintainerInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MagicLinkToken::class);
    }

    /**
     * Atomically consume one token: mark it used only if it is still unused and unexpired, in a single
     * conditional UPDATE, so of two concurrent verifications exactly one wins (issue #13). Returns whether
     * this call consumed it.
     */
    public function consume(string $tokenHash, \DateTimeImmutable $now): bool
    {
        $affected = $this->getEntityManager()
            ->createQuery(
                'UPDATE '.MagicLinkToken::class.' t SET t.usedAt = :now'
                .' WHERE t.tokenHash = :hash AND t.usedAt IS NULL AND t.expiresAt > :now'
            )
            ->setParameter('now', $now)
            ->setParameter('hash', $tokenHash)
            ->execute();

        return $affected === 1;
    }

    /**
     * Mark every still-unused magic-link token for the given email as used (FEATURE-102 / ADR-020).
     * Bulk DQL UPDATE, exact-email match, idempotent when nothing is unused.
     */
    public function invalidateUnusedForEmail(string $email): void
    {
        $this->getEntityManager()
            ->createQuery(
                'UPDATE '.MagicLinkToken::class.' t SET t.usedAt = :now WHERE t.email = :email AND t.usedAt IS NULL'
            )
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('email', $email)
            ->execute();
    }

    /**
     * Delete ephemeral magic-link tokens that can never be used again — expired OR already used.
     * Used by the bundle's MagicLinkTokenPruner (app:prune / FEATURE-147). Returns rows removed.
     * NOTE: no longer part of the core MagicLinkTokenMaintainerInterface port (ADR-048 port shrink) —
     * the bundle pruner injects this repository directly.
     */
    public function pruneExpiredOrUsed(\DateTimeImmutable $now): int
    {
        return (int) $this->getEntityManager()
            ->createQuery(
                'DELETE FROM '.MagicLinkToken::class.' t WHERE t.expiresAt < :now OR t.usedAt IS NOT NULL'
            )
            ->setParameter('now', $now)
            ->execute();
    }

    /** Dry-run mirror of {@see pruneExpiredOrUsed()} for app:prune (FEATURE-147). Mutates nothing. */
    public function countExpiredOrUsed(\DateTimeImmutable $now): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.expiresAt < :now OR t.usedAt IS NOT NULL')
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
