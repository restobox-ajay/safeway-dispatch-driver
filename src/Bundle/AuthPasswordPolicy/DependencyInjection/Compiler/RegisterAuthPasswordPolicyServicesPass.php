<?php

declare(strict_types=1);

namespace App\Bundle\AuthPasswordPolicy\DependencyInjection\Compiler;

use App\Bundle\AuthPasswordPolicy\Security\PasswordPolicyManager;
use App\Security\PasswordPolicyManagerInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * When the bundle is registered, upgrade the stable core port from its null-object default (defined in the
 * app's config/services.yaml) to the real password-policy façade (FEATURE-145 / ADR-045):
 *   - PasswordPolicyManagerInterface → PasswordPolicyManager (validate / checkReuse / recordPasswordChange /
 *     isExpired for every core password flow)
 *
 * This runs as a compiler pass rather than a services.php alias because the app's config/services.yaml
 * — which defines the null-object default — is loaded AFTER every bundle extension, so a bundle-level alias
 * would be clobbered by it. A compiler pass mutates the fully-merged container and wins.
 */
final class RegisterAuthPasswordPolicyServicesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(PasswordPolicyManager::class)) {
            $container->setAlias(PasswordPolicyManagerInterface::class, PasswordPolicyManager::class);
        }
    }
}
