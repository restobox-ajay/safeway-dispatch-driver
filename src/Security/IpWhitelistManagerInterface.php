<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;

/**
 * Stable core port for the per-user IP-whitelist override that CORE (non-feature) code needs to reach: the
 * admin user-edit surface reads it (to pre-fill the "Allowed IPs" field) and writes it (when the admin saves
 * the form / the API PATCHes the user).
 *
 * The per-user override used to be an `allowed_ips` column on the core `user` table; it now lives in the
 * auth-ip-whitelist-bundle's `user_ip_whitelist` satellite (unidirectional UserIpWhitelist -> User).
 * Core defaults this port to {@see NullIpWhitelistManager} (no per-user whitelist when the bundle is absent).
 * The bundle's {@see \App\Bundle\AuthIpWhitelist\Security\IpWhitelistManager} implements it, delegating to the
 * satellite repository; the bundle compiler pass aliases this interface to it (FEATURE-146 / ADR-046).
 *
 * The GLOBAL whitelist (ip_whitelist.user_ips / ip_whitelist.admin_ips) is NOT part of this port: it lives in
 * the core `config` key-value store and is edited through the bundle-owned IpWhitelistConfigPage. Enforcement
 * (global + per-user) happens entirely inside the bundle's moved IpWhitelistListener, so with the bundle
 * absent there is no IP restriction at all.
 */
interface IpWhitelistManagerInterface
{
    /**
     * The user's per-user allowed-IPs override (comma-separated IPs/CIDRs), or null when the user has no
     * override, the value is blank, or the bundle is absent.
     */
    public function getAllowedIps(User $user): ?string;

    /**
     * Set (or clear, when $allowedIps is null/blank) the user's per-user allowed-IPs override. Upserts the
     * satellite row; a blank value removes it. A no-op when the bundle is absent. Requires a flushed
     * (id-bearing) user.
     */
    public function setAllowedIps(User $user, ?string $allowedIps): void;
}
