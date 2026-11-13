<?php

declare(strict_types=1);

namespace App\Bundle\AuthIpWhitelist\DependencyInjection\Compiler;

use App\Bundle\AuthIpWhitelist\Security\IpWhitelistManager;
use App\Security\IpWhitelistManagerInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * When the bundle is registered, upgrade the stable core port from its null-object default (defined in the
 * app's config/services.yaml) to the real IP-whitelist façade (FEATURE-146 / ADR-046):
 *   - IpWhitelistManagerInterface → IpWhitelistManager (getAllowedIps / setAllowedIps for the admin
 *     user-edit surface)
 *
 * This runs as a compiler pass rather than a services.php alias because the app's config/services.yaml
 * — which defines the null-object default — is loaded AFTER every bundle extension, so a bundle-level alias
 * would be clobbered by it. A compiler pass mutates the fully-merged container and wins.
 */
final class RegisterAuthIpWhitelistServicesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(IpWhitelistManager::class)) {
            $container->setAlias(IpWhitelistManagerInterface::class, IpWhitelistManager::class);
        }
    }
}
