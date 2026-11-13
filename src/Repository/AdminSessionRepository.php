<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AdminSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdminSession>
 *
 * FEATURE-123: reads/terminates an admin's own active sessions — the admin-side mirror of
 * {@see UserSessionRepository}. Deliberately NOT a shared abstract/interface across realms
 * (review C8 / ADR-003): each realm keeps its own explicit store.
 */
class AdminSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminSession::class);
    }

    /** @return AdminSession[] */
    public function findByAdminId(int $adminId): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.adminId = :adminId')
            ->setParameter('adminId', $adminId)
            ->orderBy('s.lastActiveAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findBySessionId(string $sessionId): ?AdminSession
    {
        return $this->findOneBy(['sessionId' => $sessionId]);
    }

    /**
     * Scoped one-row UPDATE of last_active_at (review C10 / FEATURE-106) — the admin-side mirror of
     * {@see UserSessionRepository::touchLastActive()}. Avoids the whole-unit-of-work $em->flush()
     * that would collaterally commit any unrelated dirty entity (AC4).
     */
    public function touchLastActive(string $sessionId, \DateTimeImmutable $now): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE admin_sessions SET last_active_at = :now WHERE session_id = :sid',
            ['now' => $now, 'sid' => $sessionId],
            ['now' => Types::DATETIME_IMMUTABLE, 'sid' => ParameterType::STRING],
        );
    }

    public function deleteAllByAdminId(int $adminId): void
    {
        $this->createQueryBuilder('s')
            ->delete()
            ->where('s.adminId = :adminId')
            ->setParameter('adminId', $adminId)
            ->getQuery()
            ->execute();
    }

    /**
     * Admin-side mirror of {@see UserSessionRepository::deleteExpired()} (FEATURE-148 / ADR-049).
     * Delete cross-reference rows whose underlying PdoSessionHandler session is no longer live —
     * garbage-collected/expired or otherwise gone. The authoritative session lifetime lives per-row
     * in the DBAL-only `sessions` table (sess_lifetime = absolute expiry, time() + ttl — issue #36), so an admin_sessions row is
     * "expired" when its session_id has no live `sessions` row. Independent of explicit logout (which
     * removes the row itself) and of the soft-delete teardown (which removes by admin_id): this ages
     * out ghosts left by admins who simply closed the browser. Used by app:prune via AdminSessionPruner.
     *
     * @param int $now current UNIX timestamp
     */
    public function deleteExpired(int $now): int
    {
        // Bind $now as an INTEGER: SQLite orders storage classes (INTEGER < TEXT), so a string-bound
        // parameter would make the numeric `sess_lifetime` column always compare as
        // less-than and wrongly prune every live session (see UserSessionRepository::deleteExpired).
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM admin_sessions WHERE session_id NOT IN '
            . '(SELECT sess_id FROM sessions WHERE sess_lifetime >= :now)',
            ['now' => $now],
            ['now' => ParameterType::INTEGER]
        );
    }

    /**
     * Dry-run mirror of {@see deleteExpired()} for app:prune (FEATURE-148). Mutates nothing. Binds
     * $now as INTEGER for the same SQLite storage-class-ordering reason documented on deleteExpired().
     *
     * @param int $now current UNIX timestamp
     */
    public function countExpired(int $now): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM admin_sessions WHERE session_id NOT IN '
            . '(SELECT sess_id FROM sessions WHERE sess_lifetime >= :now)',
            ['now' => $now],
            ['now' => ParameterType::INTEGER]
        );
    }
}
