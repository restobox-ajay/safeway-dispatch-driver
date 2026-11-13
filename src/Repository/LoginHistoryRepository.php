<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LoginHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LoginHistory>
 */
class LoginHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LoginHistory::class);
    }

    // FEATURE-107 (review C14): the login-notification "known device" recognition was moved OUT of
    // login_history into the dedicated login_notification_seen store (LoginNotificationSeenRepository),
    // decoupling recognition from this table's write ordering. The former
    // hasPreviousLoginWithIp()/hasPreviousLoginWithFingerprint() readers were removed with it —
    // recognition must NOT be inferred from login_history again.

    /** @return LoginHistory[] */
    public function findRecentByUserId(int $userId, int $limit = 20): array
    {
        return $this->createQueryBuilder('lh')
            ->where('lh.userId = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('lh.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
