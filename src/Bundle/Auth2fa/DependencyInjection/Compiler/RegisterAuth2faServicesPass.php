<?php

declare(strict_types=1);

namespace App\Bundle\Auth2fa\DependencyInjection\Compiler;

use App\Bundle\Auth2fa\Repository\TwoFactorSettingsRepository;
use App\Bundle\Auth2fa\Security\TwoFactorGuard;
use App\Security\TwoFactorChallengeGuardInterface;
use App\Security\UserTwoFactorManagerInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * When the bundle is registered, upgrade the two stable core ports from their null-object defaults
 * (defined in the app's config/services.yaml) to the real 2FA services (FEATURE-143 / ADR-043):
 *   - TwoFactorChallengeGuardInterface → TwoFactorGuard (AccountController's pre-2FA guard)
 *   - UserTwoFactorManagerInterface    → TwoFactorSettingsRepository (admin/user 2FA read + reset)
 *
 * This runs as a compiler pass rather than a services.php alias because the app's config/services.yaml
 * — which defines the null-object defaults — is loaded AFTER every bundle extension, so a bundle-level
 * alias would be clobbered by it. A compiler pass mutates the fully-merged container and wins.
 */
final class RegisterAuth2faServicesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(TwoFactorGuard::class)) {
            $container->setAlias(TwoFactorChallengeGuardInterface::class, TwoFactorGuard::class);
        }

        if ($container->hasDefinition(TwoFactorSettingsRepository::class)) {
            $container->setAlias(UserTwoFactorManagerInterface::class, TwoFactorSettingsRepository::class);
        }
    }
}
