<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AdminPasswordResetToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AdminPasswordResetTokenRepository extends ServiceEntityRepository
{
    use PasswordResetTokenRepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminPasswordResetToken::class);
    }
}
