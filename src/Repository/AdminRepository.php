<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Admin;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Admin>
 */
class AdminRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Admin::class);
    }

    public function findByEmail(string $email): ?Admin
    {
        return $this->findOneBy(['email' => $email]);
    }

    /**
     * Active admins holding ROLE_SUPER_ADMIN. Roles are a JSON column, so we filter in PHP
     * (the admin table is small) rather than rely on brittle JSON string matching.
     *
     * @return Admin[]
     */
    public function findActiveSuperAdmins(): array
    {
        return array_values(array_filter(
            $this->findBy(['status' => 'active']),
            static fn (Admin $a): bool => in_array('ROLE_SUPER_ADMIN', $a->getRoles(), true)
        ));
    }

    public function countActiveSuperAdmins(): int
    {
        return count($this->findActiveSuperAdmins());
    }
}
