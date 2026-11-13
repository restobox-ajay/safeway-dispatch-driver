<?php

declare(strict_types=1);

namespace App\Bundle\Auth2fa;

use App\Bundle\Auth2fa\DependencyInjection\Compiler\RegisterAuth2faServicesPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * auth-2fa-bundle — the USER two-factor (TOTP) feature packaged as an OPTIONAL Symfony bundle
 * (FEATURE-143, C36 Option A). The FIRST satellite-table extraction: it owns a NEW `two_factor_settings`
 * table (user_id FK) that replaces the old totp columns on `user`, and moves the user 2FA controller,
 * challenge listener, guard, trusted-device manager and its admin /config sub-page into itself.
 *
 * When it is NOT registered none of its services/routes/config sub-page exist (404-when-uninstalled),
 * its migration never runs (so two_factor_settings never exists), and core falls back to the null-object
 * bindings (App\Security\NullTwoFactorGuard + App\Security\NullUserTwoFactorManager). See ADR-043.
 */
final class Auth2faBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Upgrade the core null-object aliases (TwoFactorChallengeGuardInterface +
        // UserTwoFactorManagerInterface) to the real bundle services. A compiler pass — not the
        // extension — because the app's services.yaml is loaded AFTER bundle extensions and would
        // otherwise win.
        $container->addCompilerPass(new RegisterAuth2faServicesPass());
    }
}
