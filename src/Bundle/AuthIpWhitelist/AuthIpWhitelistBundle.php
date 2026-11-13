<?php

declare(strict_types=1);

namespace App\Bundle\AuthIpWhitelist;

use App\Bundle\AuthIpWhitelist\DependencyInjection\Compiler\RegisterAuthIpWhitelistServicesPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * auth-ip-whitelist-bundle — the login IP-whitelist feature packaged as an OPTIONAL Symfony bundle
 * (FEATURE-146, C36 Option A). The FOURTH and FINAL satellite-table extraction: it owns a NEW
 * `user_ip_whitelist` table (user_id FK) that replaces the old `allowed_ips` column on `user`, and moves the
 * login IP-whitelist listener and its admin /config sub-page into itself.
 *
 * When it is NOT registered none of its services/config sub-page exist, its migration never runs (so
 * user_ip_whitelist never exists and allowed_ips is never dropped) and — the listener being gone — there is
 * NO IP restriction on login at all. Core falls back to the null-object binding
 * (App\Security\NullIpWhitelistManager), so no per-user override is ever stored or read. See ADR-046.
 */
final class AuthIpWhitelistBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Upgrade the core null-object alias (IpWhitelistManagerInterface) to the real bundle service.
        // A compiler pass — not the extension — because the app's services.yaml is loaded AFTER bundle
        // extensions and would otherwise win.
        $container->addCompilerPass(new RegisterAuthIpWhitelistServicesPass());
    }
}
