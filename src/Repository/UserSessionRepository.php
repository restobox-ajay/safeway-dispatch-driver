<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\UserSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

class UserSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserSession::class);
    }

    /** @return UserSession[] */
    public function findByUserId(int $userId): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.userId = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('s.lastActiveAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findBySessionId(string $sessionId): ?UserSession
    {
        return $this->findOneBy(['sessionId' => $sessionId]);
    }

    /**
     * Bump last_active_at for a single session via a SCOPED DBAL UPDATE (review C10 / FEATURE-106).
     * The per-request session-activity touch must NOT go through $em->flush(), which would commit
     * the whole unit of work (collateral commit of any unrelated dirty entity — AC4). A one-row
     * UPDATE keyed on session_id writes exactly that column and nothing else.
     */
    public function touchLastActive(string $sessionId, \DateTimeImmutable $now): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE user_sessions SET last_active_at = :now WHERE session_id = :sid',
            ['now' => $now, 'sid' => $sessionId],
            ['now' => Types::DATETIME_IMMUTABLE, 'sid' => ParameterType::STRING],
        );
    }

    public function deleteAllByUserId(int $userId): void
    {
        $this->createQueryBuilder('s')
            ->delete()
            ->where('s.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();
    }

    /**
     * Delete cross-reference rows whose underlying PdoSessionHandler session is no longer
     * live — either garbage-collected/expired or otherwise gone. The authoritative session
     * lifetime lives per-row in the `sessions` table (sess_lifetime is the ABSOLUTE expiry timestamp PdoSessionHandler writes, time() + ttl — issue #36), so a
     * user_sessions row is "expired" when its session_id has no live `sessions` row. This is
     * independent of explicit logout (logout removes both rows) and clears the phantom rows
     * that would otherwise linger in the "Active Sessions" view. Used by app:prune via UserSessionPruner
     * (ADR-048, was app:maintenance:prune/ADR-020). `sessions` is a DBAL-only table (not an entity),
     * so this uses raw SQL.
     *
     * @param int $now current UNIX timestamp
     */
    public function deleteExpired(int $now): int
    {
        // Bind $now as an INTEGER: SQLite orders storage classes (INTEGER < TEXT), so a
        // string-bound parameter would make the numeric `sess_lifetime`
        // expression always compare as less-than and wrongly prune every live session.
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM user_sessions WHERE session_id NOT IN '
            . '(SELECT sess_id FROM sessions WHERE sess_lifetime >= :now)',
            ['now' => $now],
            ['now' => \Doctrine\DBAL\ParameterType::INTEGER]
        );
    }

    /**
     * Dry-run mirror of {@see deleteExpired()} for app:prune (FEATURE-147). Mutates nothing. Binds
     * $now as INTEGER for the same SQLite storage-class-ordering reason documented on deleteExpired().
     *
     * @param int $now current UNIX timestamp
     */
    public function countExpired(int $now): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM user_sessions WHERE session_id NOT IN '
            . '(SELECT sess_id FROM sessions WHERE sess_lifetime >= :now)',
            ['now' => $now],
            ['now' => \Doctrine\DBAL\ParameterType::INTEGER]
        );
    }
}
