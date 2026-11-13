<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * FEATURE-103 (AXIS-2 / review C8): the byte-identical reset-token repository methods shared between
 * {@see PasswordResetTokenRepository} and {@see AdminPasswordResetTokenRepository}. Both operate on a
 * table with the same shape ({@see \App\Entity\PasswordResetTokenColumns}); the only per-realm
 * difference was the entity FQCN in the DELETE DQL, resolved here via getClassName().
 *
 * Consumed only by ServiceEntityRepository subclasses (which provide createQueryBuilder(),
 * getEntityManager() and getClassName()). Pure dumb data — no shared abstract service body, matching
 * the C8 rule that only realm-agnostic mechanics may be collapsed.
 */
trait PasswordResetTokenRepositoryTrait
{
    /**
     * Mark every still-unused reset token for the given email as used, except
     * the one with $exceptId (the token currently being consumed). Ensures a
     * completed reset invalidates any sibling tokens from earlier requests.
     */
    public function invalidateOtherUnusedTokens(string $email, int $exceptId): void
    {
        $this->createQueryBuilder('t')
            ->update()
            ->set('t.usedAt', ':now')
            ->where('t.email = :email')
            ->andWhere('t.id != :exceptId')
            ->andWhere('t.usedAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('email', $email)
            ->setParameter('exceptId', $exceptId)
            ->getQuery()
            ->execute();
    }

    /**
     * Delete ephemeral tokens that can never be used again — expired OR already used.
     * Used by app:prune (ADR-048, was app:maintenance:prune/ADR-020). Returns the number of rows removed.
     */
    public function deleteExpiredOrUsed(\DateTimeImmutable $now): int
    {
        return (int) $this->getEntityManager()
            ->createQuery('DELETE FROM ' . $this->getClassName() . ' t WHERE t.expiresAt < :now OR t.usedAt IS NOT NULL')
            ->setParameter('now', $now)
            ->execute();
    }

    /** Dry-run mirror of {@see deleteExpiredOrUsed()} for app:prune (FEATURE-147). Mutates nothing. */
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
