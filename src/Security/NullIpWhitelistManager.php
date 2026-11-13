<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;

/**
 * Null-object default for {@see IpWhitelistManagerInterface}: with auth-ip-whitelist-bundle absent there is
 * no per-user IP whitelist at all — no override is ever stored (there is no satellite table to write) and
 * none is ever read. auth-ip-whitelist-bundle's compiler pass re-aliases the port to the real
 * IpWhitelistManager when registered (FEATURE-146 / ADR-046).
 */
final class NullIpWhitelistManager implements IpWhitelistManagerInterface
{
    public function getAllowedIps(User $user): ?string
    {
        return null;
    }

    public function setAllowedIps(User $user, ?string $allowedIps): void
    {
        // No-op: no user_ip_whitelist table exists when the bundle is absent.
    }
}
