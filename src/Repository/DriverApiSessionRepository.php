<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DriverApiSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DriverApiSession>
 */
class DriverApiSessionRepository extends ServiceEntityRepository
{
    /** The admin API's refresh token lives 30 days; a row unused that long can never call the API again. */
    public const IDLE_DAYS = 30;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DriverApiSession::class);
    }

    public function findOneByKeyHash(string $keyHash): ?DriverApiSession
    {
        return $this->findOneBy(['keyHash' => $keyHash]);
    }

    /** The row as stored right now (another request may have refreshed since this one loaded it). */
    public function reload(DriverApiSession $session): DriverApiSession
    {
        $this->getEntityManager()->refresh($session);

        return $session;
    }

    public function countIdle(\DateTimeImmutable $now): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.lastUsedAt < :cutoff')
            ->setParameter('cutoff', $now->modify(sprintf('-%d days', self::IDLE_DAYS)), Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function deleteIdle(\DateTimeImmutable $now): int
    {
        return (int) $this->createQueryBuilder('s')
            ->delete()
            ->where('s.lastUsedAt < :cutoff')
            ->setParameter('cutoff', $now->modify(sprintf('-%d days', self::IDLE_DAYS)), Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->execute();
    }
}
