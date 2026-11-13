<?php

declare(strict_types=1);

namespace App\Bundle\AuthIpWhitelist\Repository;

use App\Bundle\AuthIpWhitelist\Entity\UserIpWhitelist;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository for the IP-whitelist satellite (FEATURE-146 / ADR-046). Reads and upserts the user's per-user
 * `allowed_ips` override. Core reaches this only through the {@see \App\Security\IpWhitelistManagerInterface}
 * port (via the bundle's IpWhitelistManager); the bundle's moved IpWhitelistListener reads it directly during
 * login.
 *
 * @extends ServiceEntityRepository<UserIpWhitelist>
 */
class UserIpWhitelistRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserIpWhitelist::class);
    }

    public function findForUser(User $user): ?UserIpWhitelist
    {
        return $this->findOneBy(['user' => $user]);
    }

    public function getAllowedIps(User $user): ?string
    {
        return $this->findForUser($user)?->getAllowedIps();
    }

    /**
     * Set (or clear) the user's per-user allowed-IPs override. A blank/null value removes the satellite row
     * (no override), otherwise the row is created or updated. Persists + flushes. Requires a flushed
     * (id-bearing) user.
     */
    public function setAllowedIps(User $user, ?string $allowedIps): void
    {
        $normalized = $allowedIps !== null && trim($allowedIps) !== '' ? $allowedIps : null;
        $row = $this->findForUser($user);

        if ($normalized === null) {
            // Clearing the override: drop the row so "row exists iff override present" holds.
            if ($row !== null) {
                $this->getEntityManager()->remove($row);
                $this->getEntityManager()->flush();
            }

            return;
        }

        if ($row === null) {
            $row = new UserIpWhitelist($user, $normalized);
            $this->getEntityManager()->persist($row);
        } else {
            $row->setAllowedIps($normalized);
        }

        $this->getEntityManager()->flush();
    }
}
