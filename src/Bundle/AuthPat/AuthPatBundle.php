<?php

declare(strict_types=1);

namespace App\Bundle\AuthPat;

use App\Bundle\AuthPat\DependencyInjection\Compiler\RegisterAuthPatServicesPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * auth-pat-bundle — the Personal Access Token feature packaged as an OPTIONAL Symfony bundle
 * (FEATURE-138, C36 Option A). This is the proof-of-concept extraction: the bundle owns the PAT
 * entity, repository, authenticator, controllers, migration, the `api` firewall wiring, and its
 * doctrine mapping. Registered per deploy in config/bundles.php.
 *
 * When it is NOT registered, none of its services/routes exist (404-when-uninstalled) and core falls
 * back to {@see \App\Security\NullUserTokenRevoker}. See ADR-010.
 */
final class AuthPatBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Upgrade the core null-object aliases (UserTokenRevokerInterface + app.api_authenticator)
        // to the real PAT services. A compiler pass — not the extension — because the app's
        // services.yaml is loaded AFTER bundle extensions and would otherwise win.
        $container->addCompilerPass(new RegisterAuthPatServicesPass());
    }
}
