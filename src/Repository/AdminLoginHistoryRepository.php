<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AdminLoginHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdminLoginHistory>
 *
 * FEATURE-109: reads an admin's own recent logins — the admin-side mirror of
 * {@see LoginHistoryRepository::findRecentByUserId}. Deliberately NOT a shared abstract/interface
 * across realms (review C8 / ADR-003): each realm keeps its own explicit store.
 */
class AdminLoginHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminLoginHistory::class);
    }

    /** @return AdminLoginHistory[] */
    public function findRecentByAdminId(int $adminId, int $limit = 20): array
    {
        return $this->createQueryBuilder('lh')
            ->where('lh.adminId = :adminId')
            ->setParameter('adminId', $adminId)
            ->orderBy('lh.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
