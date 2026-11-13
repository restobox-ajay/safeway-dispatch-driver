<?php

declare(strict_types=1);

namespace App\Bundle\AuthSecurity\Repository;

use App\Bundle\AuthSecurity\Entity\AccountLockout;
use App\Entity\User;
use App\Security\AccountLockManagerInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository for the account-lockout satellite (FEATURE-144 / ADR-044). Besides the ORM lookups it IS the
 * bundle's implementation of the core {@see AccountLockManagerInterface} port, so core code (UserChecker,
 * the admin user list + unlock actions, the PAT bundle's TokenAuthenticator) — none of which may depend on
 * this bundle — can read and clear a user's lockout through the stable interface. The bundle compiler pass
 * aliases that interface to this class.
 *
 * The lockout WRITE (on repeated failed logins) is performed directly against the table by
 * {@see \App\Bundle\AuthSecurity\Security\LoginRateLimitListener} via DBAL, keyed by email, mirroring how
 * that listener already records login_attempts — so it never needs to hydrate a User mid-authentication.
 *
 * @extends ServiceEntityRepository<AccountLockout>
 */
class AccountLockoutRepository extends ServiceEntityRepository implements AccountLockManagerInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountLockout::class);
    }

    public function findForUser(User $user): ?AccountLockout
    {
        return $this->findOneBy(['user' => $user]);
    }

    public function lockedUntil(User $user): ?\DateTimeImmutable
    {
        $lockout = $this->findForUser($user);
        if ($lockout === null) {
            return null;
        }

        // An expired lockout is not a lockout: treat it as unlocked (the row is harmless and will be
        // cleared on the next admin unlock or overwritten by a fresh lockout).
        return $lockout->getLockedUntil() > new \DateTimeImmutable() ? $lockout->getLockedUntil() : null;
    }

    public function isLocked(User $user): bool
    {
        return $this->lockedUntil($user) !== null;
    }

    public function unlock(User $user): void
    {
        $lockout = $this->findForUser($user);
        if ($lockout === null) {
            return;
        }

        $em = $this->getEntityManager();
        $em->remove($lockout);
        $em->flush();
    }
}
