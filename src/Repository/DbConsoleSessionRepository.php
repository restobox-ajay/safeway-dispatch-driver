<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DbConsoleSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DbConsoleSession>
 */
class DbConsoleSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DbConsoleSession::class);
    }

    /**
     * Revoke every open console for one admin (issue #48): on panel logout, "logout everywhere", a completed
     * password reset, deactivation and soft-delete — a console must not outlive its admin's access.
     */
    public function deleteAllByAdminId(int $adminId): int
    {
        return (int) $this->createQueryBuilder('s')
            ->delete()
            ->where('s.adminId = :adminId')
            ->setParameter('adminId', $adminId)
            ->getQuery()
            ->execute();
    }

    /** Revoke every open console of every admin — the "Disable now" kill-switch (issue #48). */
    public function deleteAll(): int
    {
        return (int) $this->createQueryBuilder('s')->delete()->getQuery()->execute();
    }

    /** Sweep lapsed rows. The gateway also does this opportunistically; this is the scheduled path. */
    public function deleteExpired(\DateTimeImmutable $now): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM db_console_session WHERE expires_at <= :now',
            ['now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }

    /** Dry-run mirror of {@see deleteExpired()} for app:prune. */
    public function countExpired(\DateTimeImmutable $now): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM db_console_session WHERE expires_at <= :now',
            ['now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }
}
