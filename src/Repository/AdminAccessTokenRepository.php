<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AdminAccessToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdminAccessToken>
 */
class AdminAccessTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminAccessToken::class);
    }

    public function findByTokenHash(string $hash): ?AdminAccessToken
    {
        return $this->findOneBy(['tokenHash' => $hash]);
    }

    /** @return AdminAccessToken[] */
    public function findActiveByAdminId(int $adminId): array
    {
        $now = new \DateTimeImmutable();

        return $this->createQueryBuilder('t')
            ->where('t.adminId = :adminId')
            ->andWhere('t.revokedAt IS NULL')
            ->andWhere('t.expiresAt IS NULL OR t.expiresAt > :now')
            ->setParameter('adminId', $adminId)
            ->setParameter('now', $now)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every token of one admin, newest first — active, expired and revoked alike, so the token page shows the
     * full picture (a revoked token stays listed as revoked rather than vanishing).
     *
     * @return AdminAccessToken[]
     */
    public function findAllByAdminId(int $adminId): array
    {
        return $this->findBy(['adminId' => $adminId], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    /** @return AdminAccessToken[] every token of every admin, newest first (CLI listing) */
    public function findAllNewestFirst(): array
    {
        return $this->findBy([], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }
}
