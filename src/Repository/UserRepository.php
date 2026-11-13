<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => $email]);
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findPaginated(int $page, int $limit): array
    {
        return $this->createQueryBuilder('u')
            ->orderBy('u.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count users matching the given filters (FEATURE-117).
     *
     * @param array{email?: string, status?: string} $filters
     */
    public function countFiltered(array $filters): int
    {
        $qb = $this->createQueryBuilder('u')->select('COUNT(u.id)');
        $this->applyFilters($qb, $filters);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Page through users matching the given filters, newest first (FEATURE-117).
     *
     * @param array{email?: string, status?: string} $filters
     *
     * @return list<User>
     */
    public function findFilteredPaginated(array $filters, int $page, int $limit): array
    {
        $qb = $this->createQueryBuilder('u')
            ->orderBy('u.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);
        $this->applyFilters($qb, $filters);

        return $qb->getQuery()->getResult();
    }

    /**
     * @param array{email?: string, status?: string} $filters
     */
    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        if (isset($filters['email']) && $filters['email'] !== '') {
            $qb->andWhere('LOWER(u.email) LIKE :email')
                ->setParameter('email', '%' . strtolower($filters['email']) . '%');
        }

        if (isset($filters['status']) && $filters['status'] !== '') {
            $qb->andWhere('u.status = :status')
                ->setParameter('status', $filters['status']);
        }
    }
}
