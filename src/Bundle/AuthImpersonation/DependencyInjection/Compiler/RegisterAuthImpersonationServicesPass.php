<?php

declare(strict_types=1);

namespace App\Bundle\AuthImpersonation\DependencyInjection\Compiler;

use App\Bundle\AuthImpersonation\Security\ImpersonationAuthenticator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * When the bundle is registered, upgrade the stable core alias from its null-object default (defined
 * in the app's config/services.yaml) to the real impersonation authenticator (FEATURE-142):
 *   - app.impersonation_authenticator → ImpersonationAuthenticator (the `user` firewall's
 *                                       impersonation-handoff authenticator)
 *
 * This runs as a compiler pass rather than a services.php alias because the app's config/services.yaml
 * — which defines the null-object default — is loaded AFTER every bundle extension, so a bundle-level
 * alias would be clobbered by it. A compiler pass mutates the fully-merged container and wins.
 */
final class RegisterAuthImpersonationServicesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(ImpersonationAuthenticator::class)) {
            $container->setAlias('app.impersonation_authenticator', ImpersonationAuthenticator::class);
        }
    }
}
