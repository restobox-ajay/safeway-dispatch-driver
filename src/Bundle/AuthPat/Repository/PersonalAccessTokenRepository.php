<?php

declare(strict_types=1);

namespace App\Bundle\AuthPat\Repository;

use App\Bundle\AuthPat\Entity\PersonalAccessToken;
use App\Security\UserTokenRevokerInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PersonalAccessToken>
 *
 * Implements the core {@see UserTokenRevokerInterface} port so the always-present admin/user-management
 * surface can count/revoke a user's tokens without a hard dependency on this bundle (FEATURE-138).
 */
class PersonalAccessTokenRepository extends ServiceEntityRepository implements UserTokenRevokerInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PersonalAccessToken::class);
    }

    /** @return PersonalAccessToken[] */
    public function findActiveByUserId(int $userId): array
    {
        $now = new \DateTimeImmutable();

        return $this->createQueryBuilder('pat')
            ->where('pat.userId = :userId')
            ->andWhere('pat.revokedAt IS NULL')
            ->andWhere('pat.expiresAt IS NULL OR pat.expiresAt > :now')
            ->setParameter('userId', $userId)
            ->setParameter('now', $now)
            ->orderBy('pat.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countActiveByUserId(int $userId): int
    {
        $now = new \DateTimeImmutable();

        return (int) $this->createQueryBuilder('pat')
            ->select('COUNT(pat.id)')
            ->where('pat.userId = :userId')
            ->andWhere('pat.revokedAt IS NULL')
            ->andWhere('pat.expiresAt IS NULL OR pat.expiresAt > :now')
            ->setParameter('userId', $userId)
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findByTokenHash(string $hash): ?PersonalAccessToken
    {
        return $this->findOneBy(['tokenHash' => $hash]);
    }

    public function revokeAllByUserId(int $userId): int
    {
        return (int) $this->getEntityManager()
            ->createQuery(
                'UPDATE App\Bundle\AuthPat\Entity\PersonalAccessToken pat
                 SET pat.revokedAt = :now
                 WHERE pat.userId = :userId AND pat.revokedAt IS NULL'
            )
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('userId', $userId)
            ->execute();
    }

    /** @param int[] $userIds @return array<int,int> */
    public function countActiveByUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $now  = new \DateTimeImmutable();
        $rows = $this->getEntityManager()
            ->createQuery(
                'SELECT pat.userId AS uid, COUNT(pat.id) AS cnt
                 FROM App\Bundle\AuthPat\Entity\PersonalAccessToken pat
                 WHERE pat.userId IN (:userIds)
                   AND pat.revokedAt IS NULL
                   AND (pat.expiresAt IS NULL OR pat.expiresAt > :now)
                 GROUP BY pat.userId'
            )
            ->setParameter('userIds', $userIds)
            ->setParameter('now', $now)
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['uid']] = (int) $row['cnt'];
        }

        return $counts;
    }
}
