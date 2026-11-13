<?php

declare(strict_types=1);

namespace App\Bundle\AuthSecurity\DependencyInjection\Compiler;

use App\Bundle\AuthSecurity\Repository\AccountLockoutRepository;
use App\Bundle\AuthSecurity\Security\EndpointRateLimiter;
use App\Security\AccountLockManagerInterface;
use App\Security\EndpointRateLimiterInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * When the bundle is registered, upgrade the two stable core ports from their null-object defaults
 * (defined in the app's config/services.yaml) to the real security services (FEATURE-144 / ADR-044):
 *   - EndpointRateLimiterInterface  → EndpointRateLimiter (the 4 non-login auth endpoints)
 *   - AccountLockManagerInterface   → AccountLockoutRepository (login enforcement + admin unlock)
 *
 * This runs as a compiler pass rather than a services.php alias because the app's config/services.yaml
 * — which defines the null-object defaults — is loaded AFTER every bundle extension, so a bundle-level
 * alias would be clobbered by it. A compiler pass mutates the fully-merged container and wins.
 */
final class RegisterAuthSecurityServicesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(EndpointRateLimiter::class)) {
            $container->setAlias(EndpointRateLimiterInterface::class, EndpointRateLimiter::class);
        }

        if ($container->hasDefinition(AccountLockoutRepository::class)) {
            $container->setAlias(AccountLockManagerInterface::class, AccountLockoutRepository::class);
        }
    }
}
