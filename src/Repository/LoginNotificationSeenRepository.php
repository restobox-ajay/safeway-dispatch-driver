<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LoginNotificationSeen;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LoginNotificationSeen>
 *
 * FEATURE-107 (review C14): the recognition memory for login notifications. hasSeen() answers
 * "have we already alerted this user about this device?" without ever reading login_history, so the
 * notification decision no longer depends on the login-history-write ordering.
 */
class LoginNotificationSeenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LoginNotificationSeen::class);
    }

    public function hasSeen(int $userId, string $marker): bool
    {
        return $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.userId = :userId')
            ->andWhere('s.marker = :marker')
            ->setParameter('userId', $userId)
            ->setParameter('marker', $marker)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Record that this account has now been notified about this device. Idempotent: a concurrent login that
     * already recorded the same (user_id, marker) is absorbed by the UNIQUE index via INSERT OR IGNORE (SQLite,
     * fine on 3.26). One DBAL statement on purpose (issue #33): catching a failed flush() is not safe, because
     * Doctrine closes the EntityManager on any flush failure and the rest of the login request then cannot
     * write; a flush() here would also commit whatever else happened to be pending in the unit of work.
     */
    public function markSeen(int $userId, string $marker): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT OR IGNORE INTO login_notification_seen (user_id, marker, created_at) VALUES (:owner, :marker, :createdAt)',
            ['owner' => $userId, 'marker' => $marker, 'createdAt' => new \DateTimeImmutable()],
            ['owner' => ParameterType::INTEGER, 'marker' => ParameterType::STRING, 'createdAt' => Types::DATETIME_IMMUTABLE],
        );
    }
}
