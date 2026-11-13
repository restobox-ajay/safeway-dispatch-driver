<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AdminLoginNotificationSeen;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdminLoginNotificationSeen>
 *
 * FEATURE-108: the recognition memory for ADMIN login notifications — the admin-side mirror of
 * {@see LoginNotificationSeenRepository}. hasSeen() answers "have we already alerted this admin about
 * this device?" without ever reading login-history or the audit log. Deliberately NOT a shared
 * abstract/interface across realms (review C8 / ADR-003): each realm keeps its own explicit store.
 */
class AdminLoginNotificationSeenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminLoginNotificationSeen::class);
    }

    public function hasSeen(int $adminId, string $marker): bool
    {
        return $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.adminId = :adminId')
            ->andWhere('s.marker = :marker')
            ->setParameter('adminId', $adminId)
            ->setParameter('marker', $marker)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Record that this account has now been notified about this device. Idempotent: a concurrent login that
     * already recorded the same (admin_id, marker) is absorbed by the UNIQUE index via INSERT OR IGNORE (SQLite,
     * fine on 3.26). One DBAL statement on purpose (issue #33): catching a failed flush() is not safe, because
     * Doctrine closes the EntityManager on any flush failure and the rest of the login request then cannot
     * write; a flush() here would also commit whatever else happened to be pending in the unit of work.
     */
    public function markSeen(int $adminId, string $marker): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT OR IGNORE INTO admin_login_notification_seen (admin_id, marker, created_at) VALUES (:owner, :marker, :createdAt)',
            ['owner' => $adminId, 'marker' => $marker, 'createdAt' => new \DateTimeImmutable()],
            ['owner' => ParameterType::INTEGER, 'marker' => ParameterType::STRING, 'createdAt' => Types::DATETIME_IMMUTABLE],
        );
    }
}
