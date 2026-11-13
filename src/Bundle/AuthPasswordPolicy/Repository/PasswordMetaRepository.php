<?php

declare(strict_types=1);

namespace App\Bundle\AuthPasswordPolicy\Repository;

use App\Bundle\AuthPasswordPolicy\Entity\PasswordMeta;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository for the password-meta satellite (FEATURE-145 / ADR-045). Reads and upserts the user's
 * `password_changed_at`, which drives password expiry. Core reaches this only through the
 * {@see \App\Security\PasswordPolicyManagerInterface} port (via the bundle's PasswordPolicyManager), never
 * directly.
 *
 * @extends ServiceEntityRepository<PasswordMeta>
 */
class PasswordMetaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswordMeta::class);
    }

    public function findForUser(User $user): ?PasswordMeta
    {
        return $this->findOneBy(['user' => $user]);
    }

    public function getPasswordChangedAt(User $user): ?\DateTimeImmutable
    {
        return $this->findForUser($user)?->getPasswordChangedAt();
    }

    /**
     * Stamp the user's password-changed time to now, creating the meta row on first change. Persists +
     * flushes. Requires a flushed (id-bearing) user.
     */
    public function recordChange(User $user): void
    {
        $meta = $this->findForUser($user);
        if ($meta === null) {
            $meta = new PasswordMeta($user, new \DateTimeImmutable());
            $this->getEntityManager()->persist($meta);
        } else {
            $meta->setPasswordChangedAt(new \DateTimeImmutable());
        }

        $this->getEntityManager()->flush();
    }
}
