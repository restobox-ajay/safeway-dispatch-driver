<?php

declare(strict_types=1);

namespace App\Bundle\AuthMagicLink\DependencyInjection\Compiler;

use App\Bundle\AuthMagicLink\Repository\MagicLinkTokenRepository;
use App\Bundle\AuthMagicLink\Security\MagicLinkAuthenticator;
use App\Service\MagicLinkTokenMaintainerInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * When the bundle is registered, upgrade the two stable core aliases from their null-object defaults
 * (defined in the app's config/services.yaml) to the real magic-link services (FEATURE-140):
 *   - app.magic_link_authenticator          → MagicLinkAuthenticator (the `user` firewall's
 *                                             passwordless authenticator)
 *   - MagicLinkTokenMaintainerInterface     → MagicLinkTokenRepository (recovery-invalidation +
 *                                             ephemeral prune)
 *
 * This runs as a compiler pass rather than a services.php alias because the app's config/services.yaml
 * — which defines the null-object defaults — is loaded AFTER every bundle extension, so a bundle-level
 * alias would be clobbered by it. A compiler pass mutates the fully-merged container and wins.
 */
final class RegisterAuthMagicLinkServicesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(MagicLinkAuthenticator::class)) {
            $container->setAlias('app.magic_link_authenticator', MagicLinkAuthenticator::class);
        }

        if ($container->hasDefinition(MagicLinkTokenRepository::class)) {
            $container->setAlias(MagicLinkTokenMaintainerInterface::class, MagicLinkTokenRepository::class);
        }
    }
}
