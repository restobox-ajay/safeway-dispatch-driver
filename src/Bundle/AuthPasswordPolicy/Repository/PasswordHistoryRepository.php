<?php

declare(strict_types=1);

namespace App\Bundle\AuthPasswordPolicy\Repository;

use App\Bundle\AuthPasswordPolicy\Entity\PasswordHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PasswordHistory>
 */
class PasswordHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswordHistory::class);
    }

    /** @return PasswordHistory[] */
    public function findRecentByUserId(int $userId, int $count): array
    {
        return $this->createQueryBuilder('ph')
            ->where('ph.userId = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('ph.createdAt', 'DESC')
            ->setMaxResults($count)
            ->getQuery()
            ->getResult();
    }

    public function pruneOldEntries(int $userId, int $keepCount): void
    {
        $conn = $this->getEntityManager()->getConnection();

        $keepIds = $conn->fetchFirstColumn(
            'SELECT id FROM password_history WHERE user_id = ? ORDER BY created_at DESC LIMIT ?',
            [$userId, $keepCount]
        );

        if ($keepIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
        $conn->executeStatement(
            "DELETE FROM password_history WHERE user_id = ? AND id NOT IN ({$placeholders})",
            array_merge([$userId], $keepIds)
        );
    }
}
