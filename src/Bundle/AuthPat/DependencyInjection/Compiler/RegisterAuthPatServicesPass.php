<?php

declare(strict_types=1);

namespace App\Bundle\AuthPat\DependencyInjection\Compiler;

use App\Bundle\AuthPat\Repository\PersonalAccessTokenRepository;
use App\Bundle\AuthPat\Security\TokenAuthenticator;
use App\Security\UserTokenRevokerInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * When the bundle is registered, upgrade the two stable core aliases from their null-object defaults
 * (defined in the app's config/services.yaml) to the real PAT services (FEATURE-138):
 *   - UserTokenRevokerInterface  → PersonalAccessTokenRepository (admin count/revoke)
 *   - app.api_authenticator      → TokenAuthenticator (the `api` firewall PAT auth)
 *
 * This runs as a compiler pass rather than a services.php alias because the app's config/services.yaml
 * — which defines the null-object defaults — is loaded AFTER every bundle extension, so a bundle-level
 * alias would be clobbered by it. A compiler pass mutates the fully-merged container and wins.
 */
final class RegisterAuthPatServicesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(PersonalAccessTokenRepository::class)) {
            $container->setAlias(UserTokenRevokerInterface::class, PersonalAccessTokenRepository::class);
        }

        if ($container->hasDefinition(TokenAuthenticator::class)) {
            $container->setAlias('app.api_authenticator', TokenAuthenticator::class);
        }
    }
}
