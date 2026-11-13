<?php

declare(strict_types=1);

namespace App\Bundle\AuthIpWhitelist\Security;

use App\Bundle\AuthIpWhitelist\Repository\UserIpWhitelistRepository;
use App\Entity\User;
use App\Security\IpWhitelistManagerInterface;

/**
 * The bundle's implementation of the core {@see IpWhitelistManagerInterface} port (FEATURE-146 / ADR-046):
 * the single façade core code (the admin user-edit surface) uses to read and write the per-user allowed-IPs
 * override, delegating to the `user_ip_whitelist` satellite repository so core controllers/services never
 * reference any bundle class directly. The bundle compiler pass aliases the interface to this service; when
 * the bundle is absent core falls back to {@see \App\Security\NullIpWhitelistManager} and neither operation
 * does anything.
 */
final class IpWhitelistManager implements IpWhitelistManagerInterface
{
    public function __construct(
        private readonly UserIpWhitelistRepository $repository,
    ) {}

    public function getAllowedIps(User $user): ?string
    {
        return $this->repository->getAllowedIps($user);
    }

    public function setAllowedIps(User $user, ?string $allowedIps): void
    {
        $this->repository->setAllowedIps($user, $allowedIps);
    }
}
