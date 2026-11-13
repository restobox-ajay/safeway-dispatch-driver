<?php

declare(strict_types=1);

namespace App\Bundle\Auth2fa\Repository;

use App\Bundle\Auth2fa\Entity\TwoFactorSettings;
use App\Entity\User;
use App\Security\UserTwoFactorManagerInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository for the user 2FA satellite (FEATURE-143 / ADR-043). Besides the ORM lookups it IS the
 * bundle's implementation of the core {@see UserTwoFactorManagerInterface} port, so core admin/user
 * management code (which must not depend on the bundle) can read/clear a user's 2FA through the stable
 * interface. The bundle compiler pass aliases that interface to this class.
 *
 * @extends ServiceEntityRepository<TwoFactorSettings>
 */
class TwoFactorSettingsRepository extends ServiceEntityRepository implements UserTwoFactorManagerInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TwoFactorSettings::class);
    }

    public function findForUser(User $user): ?TwoFactorSettings
    {
        return $this->findOneBy(['user' => $user]);
    }

    /**
     * The user's settings row, creating (but not yet flushing) a fresh one bound to the user when none
     * exists. Callers mutate the returned entity and flush.
     */
    public function getOrCreate(User $user): TwoFactorSettings
    {
        $settings = $this->findForUser($user);
        if ($settings === null) {
            $settings = new TwoFactorSettings($user);
            $this->getEntityManager()->persist($settings);
        }

        return $settings;
    }

    public function isEnabled(User $user): bool
    {
        return $this->findForUser($user)?->isTotpEnabled() ?? false;
    }

    public function getSecret(User $user): ?string
    {
        $settings = $this->findForUser($user);

        return $settings !== null && $settings->isTotpEnabled() ? $settings->getTotpSecret() : null;
    }

    public function getLastCounter(User $user): ?int
    {
        return $this->findForUser($user)?->getLastTotpCounter();
    }

    /**
     * Enable (or re-enrol) 2FA for the user with the given secret, seeding the replay floor with the
     * counter the enabling code matched. Persists + flushes.
     */
    public function enable(User $user, string $secret, ?int $counter = null): void
    {
        $settings = $this->getOrCreate($user);
        $settings->setTotpSecret($secret);
        $settings->setIsTotpEnabled(true);
        $settings->setLastTotpCounter($counter);
        $this->getEntityManager()->flush();
    }

    /**
     * Record a newly consumed replay counter after a successful challenge. Persists + flushes.
     */
    public function recordCounter(User $user, int $counter): void
    {
        $settings = $this->getOrCreate($user);
        $settings->setLastTotpCounter($counter);
        $this->getEntityManager()->flush();
    }

    public function disable(User $user): void
    {
        $settings = $this->findForUser($user);
        if ($settings === null) {
            return;
        }

        $settings->setTotpSecret(null);
        $settings->setIsTotpEnabled(false);
        $settings->setLastTotpCounter(null);
        $this->getEntityManager()->flush();
    }
}
