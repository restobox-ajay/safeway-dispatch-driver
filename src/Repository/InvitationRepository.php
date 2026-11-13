<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Invitation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class InvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invitation::class);
    }

    public function findByTokenHash(string $tokenHash): ?Invitation
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    /**
     * Atomically claim an unused invitation by stamping used_at only when it is still
     * NULL. Returns true iff this call is the one that consumed it (exactly one row
     * affected). A concurrent registration racing on the same invite affects zero rows
     * and therefore gets false, so the invite can never be consumed twice
     * (FEATURE-119 / review C27).
     */
    public function claim(int $id): bool
    {
        $conn = $this->getEntityManager()->getConnection();

        $affected = $conn->executeStatement(
            'UPDATE invitations SET used_at = :now WHERE id = :id AND used_at IS NULL',
            [
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'id'  => $id,
            ],
        );

        return $affected === 1;
    }

    /**
     * Delete invitations that are terminal (expired OR used) AND whose terminal moment
     * (usedAt ?? expiresAt) is older than the retention grace cutoff. Used by app:prune (FEATURE-147 /
     * ADR-048). Invitations are NOT logs — provenance lives in the audit log (admin.user_invite rows);
     * this only reclaims exhausted invite rows past their retention window. Returns rows removed.
     */
    public function deleteExpiredOrUsed(\DateTimeImmutable $now, \DateTimeImmutable $graceCutoff): int
    {
        return (int) $this->getEntityManager()
            ->createQuery(
                'DELETE FROM App\Entity\Invitation i '
                . 'WHERE (i.expiresAt < :now OR i.usedAt IS NOT NULL) '
                . 'AND COALESCE(i.usedAt, i.expiresAt) < :graceCutoff'
            )
            ->setParameter('now', $now)
            ->setParameter('graceCutoff', $graceCutoff)
            ->execute();
    }

    /** Dry-run mirror of {@see deleteExpiredOrUsed()} for app:prune (FEATURE-147). Mutates nothing. */
    public function countExpiredOrUsed(\DateTimeImmutable $now, \DateTimeImmutable $graceCutoff): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('(i.expiresAt < :now OR i.usedAt IS NOT NULL)')
            ->andWhere('COALESCE(i.usedAt, i.expiresAt) < :graceCutoff')
            ->setParameter('now', $now)
            ->setParameter('graceCutoff', $graceCutoff)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findPaginated(int $page, int $limit): array
    {
        return $this->createQueryBuilder('i')
            ->orderBy('i.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
